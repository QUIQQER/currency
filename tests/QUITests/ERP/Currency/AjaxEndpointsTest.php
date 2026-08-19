<?php

namespace QUITests\ERP\Currency;

use QUI;
use QUI\Cache\Manager as CacheManager;
use QUI\ERP\Currency\Handler;
use ReflectionProperty;

class AjaxEndpointsTest extends DatabaseTestCase
{
    /** @var array<string, mixed> */
    private array $originalCallables;

    /** @var array<string, mixed> */
    private array $originalPermissions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalCallables = (new ReflectionProperty(QUI\Ajax::class, 'callables'))->getValue();
        $this->originalPermissions = (new ReflectionProperty(QUI\Ajax::class, 'permissions'))->getValue();
        $this->configurePackage();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(QUI\Ajax::class, 'callables'))->setValue(null, $this->originalCallables);
        (new ReflectionProperty(QUI\Ajax::class, 'permissions'))->setValue(null, $this->originalPermissions);

        parent::tearDown();
    }

    public function testReadEndpointsReturnCurrencyContracts(): void
    {
        $Default = $this->invokeEndpoint(
            'getDefault.php',
            'package_quiqqer_currency_ajax_getDefault',
            [],
            'Permission::checkAdminUser'
        );
        self::assertSame('EUR', $Default['code']);

        $Currency = $this->invokeEndpoint(
            'getCurrency.php',
            'package_quiqqer_currency_ajax_getCurrency',
            ['currency'],
            false,
            'USD'
        );
        self::assertSame('USD', $Currency['code']);

        $allowed = $this->invokeEndpoint(
            'getAllowedCurrencies.php',
            'package_quiqqer_currency_ajax_getAllowedCurrencies',
            [],
            false
        );
        self::assertSame(['USD', 'GBP', 'EUR'], array_column($allowed, 'code'));

        $types = $this->invokeEndpoint(
            'getCurrencyTypes.php',
            'package_quiqqer_currency_ajax_getCurrencyTypes',
            [],
            'Permission::checkAdminUser'
        );
        self::assertSame([], $types);

        $originalConfig = CacheManager::$Config;
        $CacheConfig = $this->createMock(QUI\Config::class);
        $CacheConfig->method('get')->willReturn(true);

        try {
            CacheManager::$Config = $CacheConfig;
            $currencies = $this->invokeEndpoint(
                'getCurrencies.php',
                'package_quiqqer_currency_ajax_getCurrencies',
                [],
                false
            );
        } finally {
            CacheManager::$Config = $originalConfig;
        }

        self::assertSame(['EUR', 'USD', 'GBP', 'TST'], array_keys($currencies));
    }

    public function testConversionEndpointsReturnConvertedAmountsAndDisplayValues(): void
    {
        $converted = $this->invokeEndpoint(
            'convert.php',
            'package_quiqqer_currency_ajax_convert',
            ['amount', 'currencyFrom', 'currencyTo'],
            false,
            10,
            'EUR',
            'USD'
        );
        self::assertSame(12.0, $converted);

        $result = $this->invokeEndpoint(
            'convertWithSign.php',
            'package_quiqqer_currency_ajax_convertWithSign',
            ['data'],
            false,
            json_encode([[
                'amount' => 10,
                'from' => 'EUR',
                'to' => 'USD',
                'id' => 'line-1'
            ]], JSON_THROW_ON_ERROR)
        );

        self::assertCount(1, $result);
        self::assertSame('line-1', $result[0]['id']);
        self::assertSame(
            QUI\ERP\Currency\Calc::convertWithSign(10, 'EUR', 'USD'),
            $result[0]['converted']
        );
        self::assertIsString($result[0]['convertedRound']);
        self::assertNotSame('', $result[0]['convertedRound']);
    }

    public function testUpdateEndpointsPersistOnlyToSqliteFixture(): void
    {
        $this->invokeEndpoint(
            'setAutoupdate.php',
            'package_quiqqer_currency_ajax_setAutoupdate',
            ['currency', 'autoupdate'],
            'Permission::checkAdminUser',
            'TST',
            1
        );
        self::assertSame(1, (int)$this->connection->fetchOne(
            'SELECT autoupdate FROM ' . Handler::table() . ' WHERE currency = ?',
            ['TST']
        ));

        $this->invokeEndpoint(
            'update.php',
            'package_quiqqer_currency_ajax_update',
            ['currency', 'code', 'rate', 'precision', 'type', 'customData'],
            'Permission::checkAdminUser',
            'TST',
            'IGNORED',
            2.75,
            5,
            '',
            json_encode(['channel' => 'ajax'], JSON_THROW_ON_ERROR)
        );

        $stored = $this->connection->fetchAssociative(
            'SELECT rate, precision, customData FROM ' . Handler::table() . ' WHERE currency = ?',
            ['TST']
        );
        self::assertIsArray($stored);
        self::assertSame(2.75, (float)$stored['rate']);
        self::assertSame(5, (int)$stored['precision']);
        self::assertSame(['channel' => 'ajax'], json_decode((string)$stored['customData'], true));
    }

    public function testUserCurrencyEndpointRejectsDisallowedAndPersistsAllowedSelection(): void
    {
        $Users = QUI::getUsers();
        $Session = new ReflectionProperty($Users, 'Session');
        $originalSessionUser = $Session->getValue($Users);
        $User = $this->createMock(QUI\Interfaces\Users\User::class);
        $User->expects(self::once())
            ->method('setAttribute')
            ->with('quiqqer.erp.currency', 'USD');
        $User->expects(self::once())->method('save');

        try {
            $Session->setValue($Users, $User);
            $this->invokeEndpoint(
                'setUserCurrency.php',
                'package_quiqqer_currency_ajax_setUserCurrency',
                ['currency'],
                false,
                'TST'
            );
            $this->invokeEndpoint(
                'setUserCurrency.php',
                'package_quiqqer_currency_ajax_setUserCurrency',
                ['currency'],
                false,
                'USD'
            );
        } finally {
            $Session->setValue($Users, $originalSessionUser);
        }

        self::assertSame('USD', Handler::getRuntimeCurrency()->getCode());
    }

    public function testMutationEndpointsHandleDuplicateAndEmptyRequestsWithoutSideEffects(): void
    {
        $countBefore = (int)$this->connection->fetchOne('SELECT COUNT(*) FROM ' . Handler::table());

        try {
            $this->invokeEndpoint(
                'create.php',
                'package_quiqqer_currency_ajax_create',
                ['currency'],
                'Permission::checkAdminUser',
                'EUR'
            );
            self::fail('The Ajax endpoint must propagate duplicate currency validation.');
        } catch (QUI\Exception $Exception) {
            self::assertStringContainsString('EUR', $Exception->getMessage());
        }

        $this->invokeEndpoint(
            'delete.php',
            'package_quiqqer_currency_ajax_delete',
            ['currencies'],
            'Permission::checkAdminUser',
            '[]'
        );

        self::assertSame($countBefore, (int)$this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . Handler::table()
        ));
    }

    public function testImportEndpointRefreshesPreseededEcbCurrenciesInSqlite(): void
    {
        $rates = (new \ReflectionMethod(QUI\ERP\Currency\Import::class, 'getECBData'))->invoke(null);

        if (!is_array($rates) || $rates === []) {
            self::markTestSkipped('External ECB rate feed returned no currencies.');
        }

        foreach ($rates as $code => $rate) {
            if (
                $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM ' . Handler::table() . ' WHERE currency = ?',
                    [$code]
                )
            ) {
                continue;
            }

            $this->connection->insert(
                Handler::table(),
                $this->currencyFixture($code, (float)$rate)
            );
        }

        $this->resetHandlerState();
        $this->invokeEndpoint(
            'importFromECB.php',
            'package_quiqqer_currency_ajax_importFromECB',
            [],
            'Permission::checkAdminUser'
        );

        self::assertGreaterThan(0, (float)$this->connection->fetchOne(
            'SELECT rate FROM ' . Handler::table() . ' WHERE currency = ?',
            ['USD']
        ));
    }

    private function configurePackage(): void
    {
        $Config = $this->createMock(QUI\Config::class);
        $Config->method('getValue')->willReturnCallback(
            static fn(string $section, string $key): mixed => match ($key) {
                'defaultCurrency' => 'EUR',
                'allowedCurrencies' => 'USD,GBP',
                default => false
            }
        );
        $Package = $this->createMock(QUI\Package\Package::class);
        $Package->method('getConfig')->willReturn($Config);
        $Package->method('isQuiqqerPackage')->willReturn(true);
        $Package->method('getProvider')->willReturn([]);
        $Manager = $this->createMock(QUI\Package\Manager::class);
        $Manager->method('getInstalled')->willReturn([['name' => 'quiqqer/currency']]);
        $Manager->method('getInstalledPackage')->willReturn($Package);
        QUI::$PackageManager = $Manager;
    }

    /**
     * @param list<string> $parameters
     */
    private function invokeEndpoint(
        string $file,
        string $name,
        array $parameters,
        bool|string $permission,
        mixed ...$arguments
    ): mixed {
        require dirname(__DIR__, 4) . '/ajax/' . $file;

        $callables = QUI::getAjax()::getRegisteredCallables();
        self::assertArrayHasKey($name, $callables);
        self::assertSame($parameters, $callables[$name]['params']);

        $permissions = (new ReflectionProperty(QUI\Ajax::class, 'permissions'))->getValue();
        if ($permission === false) {
            self::assertArrayNotHasKey($name, $permissions);
        } else {
            self::assertSame($permission, $permissions[$name]);
        }

        return $callables[$name]['callable'](...$arguments);
    }
}
