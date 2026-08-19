<?php

namespace QUITests\ERP\Currency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Cache\Manager as CacheManager;
use QUI\ERP\Currency\Handler;
use QUI\Permissions\Permission;
use QUI\Update;
use ReflectionProperty;

abstract class DatabaseTestCase extends TestCase
{
    protected Connection $connection;
    private Connection $originalConnection;
    private mixed $originalPermissionUser;
    private bool $originalCacheNoClearing;
    private ?QUI\Package\Manager $originalPackageManager;
    private ?QUI\Events\Manager $originalEventsManager;
    private mixed $originalSessionCurrency;

    /** @var array<string, mixed> */
    private array $originalHandlerState = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = QUI::getDataBaseConnection();
        $this->originalPermissionUser = (new ReflectionProperty(Permission::class, 'User'))->getValue();
        $this->originalCacheNoClearing = CacheManager::$noClearing;
        $this->originalPackageManager = QUI::$PackageManager;
        $this->originalEventsManager = QUI::$Events;
        $this->originalSessionCurrency = QUI::getSession()->get('currency');
        $this->originalHandlerState = $this->getHandlerState();
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);

        try {
            $this->setConnection($this->connection);
            Permission::setUser(QUI::getUsers()->getSystemUser());
            CacheManager::$noClearing = true;
            $this->setHandlerState([
                'currencies' => [],
                'Default' => null,
                'RuntimeCurrency' => null
            ]);

            Update::importDatabase(dirname(__DIR__, 4) . '/database.xml');
            $this->insertCurrencyFixtures();
        } catch (\Throwable $Exception) {
            $this->restoreGlobalState();
            $this->connection->close();

            throw $Exception;
        }
    }

    protected function tearDown(): void
    {
        $this->restoreGlobalState();
        $this->connection->close();

        parent::tearDown();
    }

    protected function resetHandlerState(): void
    {
        $this->setHandlerState([
            'currencies' => [],
            'Default' => null,
            'RuntimeCurrency' => null
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function currencyFixture(
        string $code,
        float $rate,
        int $autoupdate = 1,
        int $precision = 2,
        ?string $customData = null
    ): array {
        return [
            'currency' => $code,
            'rate' => $rate,
            'autoupdate' => $autoupdate,
            'precision' => $precision,
            'type' => Handler::CURRENCY_TYPE_DEFAULT,
            'customData' => $customData
        ];
    }

    private function insertCurrencyFixtures(): void
    {
        foreach (
            [
            $this->currencyFixture('EUR', 1.0),
            $this->currencyFixture('USD', 1.2),
            $this->currencyFixture('GBP', 0.8),
            $this->currencyFixture('TST', 1.25, 0, 4, '{"source":"fixture"}')
            ] as $fixture
        ) {
            $this->connection->insert(Handler::table(), $fixture);
        }
    }

    private function restoreGlobalState(): void
    {
        $this->setConnection($this->originalConnection);
        (new ReflectionProperty(Permission::class, 'User'))->setValue(
            null,
            $this->originalPermissionUser
        );
        CacheManager::$noClearing = $this->originalCacheNoClearing;
        QUI::$PackageManager = $this->originalPackageManager;
        QUI::$Events = $this->originalEventsManager;

        if ($this->originalSessionCurrency === false) {
            QUI::getSession()->del('currency');
        } else {
            QUI::getSession()->set('currency', $this->originalSessionCurrency);
        }

        $this->setHandlerState($this->originalHandlerState);
    }

    private function setConnection(Connection $Connection): void
    {
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $Connection);
    }

    /**
     * @return array<string, mixed>
     */
    private function getHandlerState(): array
    {
        $state = [];

        foreach (['currencies', 'Default', 'RuntimeCurrency'] as $property) {
            $state[$property] = (new ReflectionProperty(Handler::class, $property))->getValue();
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    protected function setHandlerState(array $state): void
    {
        foreach ($state as $property => $value) {
            (new ReflectionProperty(Handler::class, $property))->setValue(null, $value);
        }
    }
}
