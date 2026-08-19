<?php

namespace QUITests\ERP\Currency;

use QUI;
use QUI\ERP\Currency\Conf;

class ConfTest extends DatabaseTestCase
{
    public function testAccountingCurrencyFallsBackAndResolvesConfiguredCurrency(): void
    {
        $this->configure([
            'accountingCurrencyDiffers' => 0,
            'accountingCurrency' => 'USD'
        ]);
        self::assertFalse(Conf::accountingCurrencyEnabled());
        self::assertSame('EUR', Conf::getAccountingCurrency()?->getCode());

        $this->configure([
            'accountingCurrencyDiffers' => 1,
            'accountingCurrency' => ''
        ]);
        self::assertTrue(Conf::accountingCurrencyEnabled());
        self::assertSame('EUR', Conf::getAccountingCurrency()?->getCode());

        $this->configure([
            'accountingCurrencyDiffers' => 1,
            'accountingCurrency' => 'USD'
        ]);
        self::assertSame('USD', Conf::getAccountingCurrency()?->getCode());

        $this->configure([
            'accountingCurrencyDiffers' => 1,
            'accountingCurrency' => 'UNKNOWN'
        ]);
        self::assertSame('EUR', Conf::getAccountingCurrency()?->getCode());
    }

    public function testConfReturnsSectionsAndHandlesUnavailableConfiguration(): void
    {
        $this->configure(['feature' => ['enabled' => true]]);
        self::assertSame(['enabled' => true], Conf::conf('currency', 'feature'));

        $Package = $this->createMock(QUI\Package\Package::class);
        $Package->method('getConfig')->willReturn(null);
        $Manager = $this->createMock(QUI\Package\Manager::class);
        $Manager->method('getInstalledPackage')->willReturn($Package);
        QUI::$PackageManager = $Manager;
        self::assertFalse(Conf::conf('currency', 'feature'));

        $Manager = $this->createMock(QUI\Package\Manager::class);
        $Manager->method('getInstalledPackage')->willThrowException(new QUI\Exception('Missing package'));
        QUI::$PackageManager = $Manager;
        self::assertFalse(Conf::conf('currency', 'feature'));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function configure(array $values): void
    {
        $Config = $this->createMock(QUI\Config::class);
        $Config->method('get')->willReturnCallback(
            static fn(string $section, ?string $key = null): mixed => $values[$key ?? $section] ?? false
        );
        $Config->method('getValue')->willReturnCallback(
            static fn(string $section, string $key): mixed => match ($key) {
                'defaultCurrency' => 'EUR',
                'allowedCurrencies' => 'USD,GBP',
                default => $values[$key] ?? false
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

        $this->resetHandlerState();
    }
}
