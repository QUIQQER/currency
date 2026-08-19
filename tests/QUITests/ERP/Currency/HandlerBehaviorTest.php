<?php

namespace QUITests\ERP\Currency;

use QUI;
use QUI\Cache\Manager as CacheManager;
use QUI\ERP\Currency\Currency;
use QUI\ERP\Currency\Handler;
use QUITests\ERP\Currency\Fixtures\TestCurrency;

class HandlerBehaviorTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePackage('EUR', 'USD,GBP');
    }

    public function testCreateRejectsDuplicateCurrencyAndInvalidRateWithoutWriting(): void
    {
        $before = (int)$this->connection->fetchOne('SELECT COUNT(*) FROM ' . Handler::table());

        try {
            Handler::createCurrency('EUR');
            self::fail('A duplicate currency must be rejected.');
        } catch (QUI\Exception $Exception) {
            self::assertStringContainsString('EUR', $Exception->getMessage());
        }

        try {
            Handler::createCurrency('NEW', 'not-numeric');
            self::fail('A non-numeric exchange rate must be rejected.');
        } catch (QUI\Exception $Exception) {
            self::assertNotSame('', $Exception->getMessage());
        }

        self::assertSame($before, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM ' . Handler::table()));
        self::assertFalse(Handler::existCurrency('NEW'));
    }

    public function testDataNormalizationAndCurrencyLookupVariants(): void
    {
        $this->connection->insert(Handler::table(), $this->currencyFixture('INV', 2.0, 1, 2, '{invalid'));
        $this->resetHandlerState();

        $data = Handler::getData();
        self::assertSame([], $data['INV']['customData']);
        self::assertSame('default', $data['INV']['type']);

        $Currency = Handler::getCurrency(['code' => 'USD']);
        self::assertSame('USD', $Currency->getCode());
        self::assertSame($Currency, Handler::getCurrency($Currency));
        self::assertTrue(Handler::existCurrency('GBP'));
        self::assertFalse(Handler::existCurrency('XXX'));

        $this->expectException(QUI\Exception::class);
        $this->expectExceptionCode(404);
        Handler::getCurrency([]);
    }

    public function testUserCurrencyHonorsAllowedListAndCountryFallback(): void
    {
        $AllowedUser = $this->createMock(QUI\Interfaces\Users\User::class);
        $AllowedUser->method('getAttribute')->with('quiqqer.erp.currency')->willReturn('USD');
        self::assertSame('USD', Handler::getUserCurrency($AllowedUser)?->getCode());

        $DisallowedUser = $this->createMock(QUI\Interfaces\Users\User::class);
        $DisallowedUser->method('getAttribute')->with('quiqqer.erp.currency')->willReturn('TST');
        self::assertSame('EUR', Handler::getUserCurrency($DisallowedUser)?->getCode());

        $UnknownUser = $this->createMock(QUI\Interfaces\Users\User::class);
        $UnknownUser->method('getAttribute')->with('quiqqer.erp.currency')->willReturn('XXX');
        $UnknownUser->method('getCountry')->willReturn(null);
        self::assertNull(Handler::getUserCurrency($UnknownUser));

        $Country = $this->createMock(QUI\Countries\Country::class);
        $Country->method('getCurrency')->willReturn(Handler::getCurrency('GBP'));
        $CountryUser = $this->createMock(QUI\Interfaces\Users\User::class);
        $CountryUser->method('getAttribute')->with('quiqqer.erp.currency')->willReturn(null);
        $CountryUser->method('getCountry')->willReturn($Country);
        self::assertSame('GBP', Handler::getUserCurrency($CountryUser)?->getCode());
    }

    public function testDefaultAllowedAndRuntimeCurrenciesUseConfiguredRules(): void
    {
        self::assertSame('EUR', Handler::getDefaultCurrency()?->getCode());
        self::assertSame(
            ['USD', 'GBP', 'EUR'],
            array_map(static fn(Currency $Currency): string => $Currency->getCode(), Handler::getAllowedCurrencies())
        );

        QUI::getSession()->set('currency', 'USD');
        $this->setHandlerState(['RuntimeCurrency' => null]);
        self::assertSame('USD', Handler::getRuntimeCurrency()->getCode());

        QUI::getSession()->set('currency', 'XXX');
        $this->setHandlerState(['RuntimeCurrency' => null]);
        self::assertSame('EUR', Handler::getRuntimeCurrency()->getCode());

        Handler::setRuntimeCurrency(Handler::getCurrency('TST'));
        self::assertSame('EUR', Handler::getRuntimeCurrency()->getCode());
    }

    public function testFrontendAndBackendCurrencyActivationAreIndependent(): void
    {
        $this->configurePackage('EUR', 'USD', 'GBP,TST');
        $this->resetHandlerState();

        self::assertSame(
            ['USD', 'EUR'],
            array_map(static fn(Currency $Currency): string => $Currency->getCode(), Handler::getFrontendCurrencies())
        );
        self::assertSame(
            ['GBP', 'TST', 'EUR'],
            array_map(static fn(Currency $Currency): string => $Currency->getCode(), Handler::getBackendCurrencies())
        );

        $BackendOnlyUser = $this->createMock(QUI\Interfaces\Users\User::class);
        $BackendOnlyUser->method('getAttribute')->with('quiqqer.erp.currency')->willReturn('TST');
        self::assertSame('EUR', Handler::getUserCurrency($BackendOnlyUser)?->getCode());

        $this->configurePackage('EUR', 'USD,GBP', '__frontend__');
        $this->resetHandlerState();

        self::assertSame(
            ['USD', 'GBP', 'EUR'],
            array_map(static fn(Currency $Currency): string => $Currency->getCode(), Handler::getBackendCurrencies())
        );

        $this->configurePackage('EUR', 'USD', false);
        $this->resetHandlerState();

        self::assertSame(
            ['USD', 'EUR'],
            array_map(static fn(Currency $Currency): string => $Currency->getCode(), Handler::getBackendCurrencies())
        );
    }

    public function testAllowedCurrencyActivationIsValidatedAndPersisted(): void
    {
        $values = [
            'defaultCurrency' => 'EUR',
            'allowedCurrencies' => 'USD',
            'allowedBackendCurrencies' => '__frontend__'
        ];
        $Config = $this->createMock(QUI\Config::class);
        $Config->method('getValue')->willReturnCallback(
            static function (string $section, string $key) use (&$values): mixed {
                return $values[$key] ?? false;
            }
        );
        $Config->expects(self::exactly(2))->method('setValue')->willReturnCallback(
            static function (string $section, ?string $key, string|int|float $value) use (&$values): bool {
                self::assertSame('currency', $section);
                self::assertNotNull($key);
                $values[$key] = $value;

                return true;
            }
        );
        $Config->expects(self::exactly(2))->method('save');

        $Package = $this->createPackage($Config, true, []);
        $Manager = $this->createMock(QUI\Package\Manager::class);
        $Manager->method('getInstalled')->willReturn([['name' => 'quiqqer/currency']]);
        $Manager->method('getInstalledPackage')->with('quiqqer/currency')->willReturn($Package);
        QUI::$PackageManager = $Manager;
        $this->resetHandlerState();

        Handler::setAllowedCurrencies(Handler::CONTEXT_FRONTEND, [' GBP ', 'GBP']);
        Handler::setAllowedCurrencies(Handler::CONTEXT_BACKEND, ['TST']);

        self::assertSame('GBP,EUR', $values['allowedCurrencies']);
        self::assertSame('TST,EUR', $values['allowedBackendCurrencies']);
        self::assertSame(
            ['GBP', 'EUR'],
            array_map(static fn(Currency $Currency): string => $Currency->getCode(), Handler::getFrontendCurrencies())
        );
        self::assertSame(
            ['TST', 'EUR'],
            array_map(static fn(Currency $Currency): string => $Currency->getCode(), Handler::getBackendCurrencies())
        );
    }

    public function testAllowedCurrencyActivationRejectsInvalidInput(): void
    {
        foreach (
            [
                static fn() => Handler::getAllowedCurrencies('invalid'),
                static fn() => Handler::setAllowedCurrencies('invalid', ['USD']),
                static fn() => Handler::setAllowedCurrencies(Handler::CONTEXT_FRONTEND, [12]),
                static fn() => Handler::setAllowedCurrencies(Handler::CONTEXT_FRONTEND, ['UNKNOWN'])
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Invalid activation input must be rejected.');
            } catch (QUI\Exception $Exception) {
                self::assertNotSame('', $Exception->getMessage());
            }
        }
    }

    public function testMissingDefaultConfigurationFallsBackToEuro(): void
    {
        $this->configurePackage('', 'USD');
        $this->resetHandlerState();

        self::assertSame('EUR', Handler::getDefaultCurrency()?->getCode());
    }

    public function testAllowedCurrenciesRejectInvalidAndUnavailableConfiguration(): void
    {
        $this->configurePackage('EUR', false);
        $this->resetHandlerState();
        self::assertSame([], Handler::getAllowedCurrencies());

        $Package = $this->createMock(QUI\Package\Package::class);
        $Package->method('getConfig')->willThrowException(new QUI\Exception('Configuration unavailable'));
        $Manager = $this->createMock(QUI\Package\Manager::class);
        $Manager->method('getInstalledPackage')->willReturn($Package);
        QUI::$PackageManager = $Manager;
        $this->resetHandlerState();
        self::assertSame([], Handler::getAllowedCurrencies());
    }

    public function testDatabaseReadFailureReturnsEmptyCurrencyData(): void
    {
        $this->connection->createSchemaManager()->dropTable(Handler::table());
        $this->resetHandlerState();

        self::assertSame([], Handler::getData());
    }

    public function testCurrencyListFallsBackToDatabaseWhenCacheIsDisabled(): void
    {
        $originalConfig = CacheManager::$Config;
        $CacheConfig = $this->createMock(QUI\Config::class);
        $CacheConfig->method('get')->willReturnCallback(
            static fn(string $section, ?string $key = null): mixed => $section === 'general' && $key === 'nocache'
        );

        try {
            CacheManager::$Config = $CacheConfig;
            $currencies = Handler::getCurrencies();
        } finally {
            CacheManager::$Config = $originalConfig;
        }

        self::assertSame(['EUR', 'USD', 'GBP', 'TST'], array_keys($currencies));
        self::assertSame('fixture', $currencies['TST']['customData']['source']);
    }

    public function testCustomCurrencyProviderControlsHydrationAndUpdates(): void
    {
        $Config = $this->createConfig('EUR', 'USD,GBP');
        $TypePackage = $this->createPackage($Config, true, [
            'currency' => [
                'Missing\\Currency\\ClassName',
                \stdClass::class,
                TestCurrency::class
            ]
        ]);
        $ForeignPackage = $this->createPackage($Config, false, []);
        $EmptyPackage = $this->createPackage($Config, true, []);
        $Manager = $this->createMock(QUI\Package\Manager::class);
        $Manager->method('getInstalled')->willReturn([
            ['name' => 'foreign/package'],
            ['name' => 'empty/package'],
            ['name' => 'types/package'],
            ['name' => 'broken/package']
        ]);
        $Manager->method('getInstalledPackage')->willReturnCallback(
            static function (string $name) use ($ForeignPackage, $EmptyPackage, $TypePackage): QUI\Package\Package {
                return match ($name) {
                    'foreign/package' => $ForeignPackage,
                    'empty/package' => $EmptyPackage,
                    'types/package' => $TypePackage,
                    default => throw new QUI\Exception('Broken package fixture')
                };
            }
        );
        QUI::$PackageManager = $Manager;

        $types = Handler::getCurrencyTypes();
        self::assertCount(1, $types);
        self::assertSame('test', $types[0]['type']);
        self::assertSame('Test currency', $types[0]['typeTitle']);

        $this->connection->insert(Handler::table(), [
            ...$this->currencyFixture('CUS', 2.5),
            'type' => 'test'
        ]);
        $this->resetHandlerState();
        self::assertInstanceOf(TestCurrency::class, Handler::getCurrency('CUS'));

        Handler::updateCurrency('TST', ['type' => 'test']);
        self::assertSame('test', $this->connection->fetchOne(
            'SELECT type FROM ' . Handler::table() . ' WHERE currency = ?',
            ['TST']
        ));

        Handler::updateCurrency('TST', ['type' => 'missing']);
        self::assertSame('test', $this->connection->fetchOne(
            'SELECT type FROM ' . Handler::table() . ' WHERE currency = ?',
            ['TST']
        ));
    }

    private function configurePackage(string $default, mixed $allowed, mixed $allowedBackend = false): void
    {
        $Config = $this->createConfig($default, $allowed, $allowedBackend);
        $Package = $this->createPackage($Config, true, []);
        $Manager = $this->createMock(QUI\Package\Manager::class);
        $Manager->method('getInstalled')->willReturn([['name' => 'quiqqer/currency']]);
        $Manager->method('getInstalledPackage')->with('quiqqer/currency')->willReturn($Package);
        QUI::$PackageManager = $Manager;
    }

    private function createConfig(string $default, mixed $allowed, mixed $allowedBackend = false): QUI\Config
    {
        $Config = $this->createMock(QUI\Config::class);
        $Config->method('getValue')->willReturnCallback(
            static function (string $section, string $key) use ($default, $allowed, $allowedBackend): mixed {
                return match ([$section, $key]) {
                    ['currency', 'defaultCurrency'] => $default,
                    ['currency', 'allowedCurrencies'] => $allowed,
                    ['currency', 'allowedBackendCurrencies'] => $allowedBackend,
                    default => false
                };
            }
        );

        return $Config;
    }

    /**
     * @param array<string, mixed> $provider
     */
    private function createPackage(QUI\Config $Config, bool $isQuiqqer, array $provider): QUI\Package\Package
    {
        $Package = $this->createMock(QUI\Package\Package::class);
        $Package->method('getConfig')->willReturn($Config);
        $Package->method('isQuiqqerPackage')->willReturn($isQuiqqer);
        $Package->method('getProvider')->willReturn($provider);

        return $Package;
    }
}
