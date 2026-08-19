<?php

namespace QUITests\ERP\Currency;

use QUI;
use QUI\ERP\Currency\Console;
use QUI\ERP\Currency\Cron;
use QUI\ERP\Currency\EventHandler;
use QUI\ERP\Currency\Handler;
use ReflectionProperty;

class ExtensionPointsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePackage();
    }

    public function testTemplateHeaderPublishesDefaultRuntimeAndUserCurrencies(): void
    {
        $Users = QUI::getUsers();
        $Session = new ReflectionProperty($Users, 'Session');
        $originalSessionUser = $Session->getValue($Users);
        $User = $this->createMock(QUI\Interfaces\Users\User::class);
        $User->method('getAttribute')->with('quiqqer.erp.currency')->willReturn('GBP');
        $headers = [];
        $Template = $this->createMock(QUI\Template::class);
        $Template->expects(self::exactly(2))
            ->method('extendHeader')
            ->willReturnCallback(static function (string $header) use (&$headers): void {
                $headers[] = $header;
            });

        try {
            $Session->setValue($Users, $User);
            Handler::setRuntimeCurrency(Handler::getCurrency('USD'));
            EventHandler::onTemplateGetHeader($Template);
        } finally {
            $Session->setValue($Users, $originalSessionUser);
        }

        self::assertStringContainsString('window.DEFAULT_CURRENCY = "EUR"', $headers[0]);
        self::assertStringContainsString('window.RUNTIME_CURRENCY = "USD"', $headers[0]);
        self::assertStringContainsString('window.DEFAULT_USER_CURRENCY', $headers[1]);
        self::assertStringContainsString('"code":"GBP"', $headers[1]);
    }

    public function testPackageConfigHandlerIgnoresOtherPackagesAndAcceptsCurrencyPackage(): void
    {
        $OtherPackage = $this->createMock(QUI\Package\Package::class);
        $OtherPackage->method('getName')->willReturn('vendor/other');
        EventHandler::onPackageConfigSave($OtherPackage, ['changed' => true]);

        $CurrencyPackage = $this->createMock(QUI\Package\Package::class);
        $CurrencyPackage->method('getName')->willReturn('quiqqer/currency');
        EventHandler::onPackageConfigSave($CurrencyPackage, ['changed' => true]);

        self::assertTrue(QUI\Cache\Manager::$noClearing);
    }

    public function testConsoleMetadataAndExecutionFireImportEvent(): void
    {
        $Console = new Console();
        self::assertSame('currency:import', $Console->getName());
        self::assertSame(
            'Execute the import of the new currency exchange rates',
            $Console->getDescription()
        );

        $Events = $this->createMock(QUI\Events\Manager::class);
        $Events->expects(self::once())->method('fireEvent')->with('quiqqerCurrencyImport');
        QUI::$Events = $Events;

        try {
            $Console->execute();
        } catch (QUI\Exception $Exception) {
            self::markTestSkipped('External ECB rates are unavailable: ' . $Exception->getMessage());
        }

        self::assertGreaterThan(0, (float)$this->connection->fetchOne(
            'SELECT rate FROM ' . Handler::table() . ' WHERE currency = ?',
            ['USD']
        ));
    }

    public function testCronExecutionUpdatesRatesAndFiresImportEvent(): void
    {
        $Events = $this->createMock(QUI\Events\Manager::class);
        $Events->expects(self::once())->method('fireEvent')->with('quiqqerCurrencyImport');
        QUI::$Events = $Events;

        try {
            Cron::import();
        } catch (QUI\Exception $Exception) {
            self::markTestSkipped('External ECB rates are unavailable: ' . $Exception->getMessage());
        }

        self::assertGreaterThan(0, (float)$this->connection->fetchOne(
            'SELECT rate FROM ' . Handler::table() . ' WHERE currency = ?',
            ['GBP']
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
}
