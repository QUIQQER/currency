<?php

namespace QUITests\ERP\Currency;

use PHPUnit\Framework\TestCase;
use QUI;

class HandlerTest extends TestCase
{
    private ?string $fixtureCurrency = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (empty(QUI\ERP\Currency\Handler::getData())) {
            $this->markTestSkipped('Handler tests require seeded currency data (DB-backed).');
        }
    }

    protected function tearDown(): void
    {
        if ($this->fixtureCurrency !== null && QUI\ERP\Currency\Handler::existCurrency($this->fixtureCurrency)) {
            QUI\ERP\Currency\Handler::deleteCurrency($this->fixtureCurrency);
        }

        parent::tearDown();
    }

    public function testGetDefaultCurrency(): void
    {
        $Currency = QUI\ERP\Currency\Handler::getDefaultCurrency();

        $this->assertNotEmpty($Currency->getText());
        $this->assertNotEmpty($Currency->getSign());
        $this->assertNotEmpty($Currency->getCode());

        // default config check
        $Config = QUI::getPackage('quiqqer/currency')->getConfig();
        $defaultFromSettings = $Config->getValue('currency', 'defaultCurrency');

        $this->assertSame($defaultFromSettings, $Currency->getCode());
    }

    public function testGetData(): void
    {
        $data = QUI\ERP\Currency\Handler::getData();

        $this->assertNotEmpty($data);
    }

    public function testGetCurrency(): void
    {
        $EUR = QUI\ERP\Currency\Handler::getCurrency('EUR');
        $USD = QUI\ERP\Currency\Handler::getCurrency('USD');

        $this->assertSame('EUR', $EUR->getCode());
        $this->assertSame('USD', $USD->getCode());

        $this->assertSame('€', $EUR->getSign());
        $this->assertSame('$', $USD->getSign());
    }

    public function testGetAllowedCurrencies(): void
    {
        $Config = QUI::getPackage('quiqqer/currency')->getConfig();

        $allowed = $Config->getValue('currency', 'allowedCurrencies');
        $allowed = explode(',', trim($allowed));
        $default = QUI\ERP\Currency\Handler::getDefaultCurrency()->getCode();

        $list = QUI\ERP\Currency\Handler::getAllowedCurrencies();
        $this->assertNotEmpty($list);

        foreach ($list as $Currency) {
            $this->assertTrue(
                in_array($Currency->getCode(), $allowed, true) || $Currency->getCode() === $default
            );
        }
    }

    public function testCreateUpdateAndDeleteCurrencyRefreshesCachedData(): void
    {
        $this->fixtureCurrency = 'T' . strtoupper(substr(md5((string)microtime(true)), 0, 4));

        QUI\ERP\Currency\Handler::createCurrency($this->fixtureCurrency, 1.25);

        $Currency = QUI\ERP\Currency\Handler::getCurrency($this->fixtureCurrency);
        $this->assertSame(1.25, $Currency->getExchangeRate());

        QUI\ERP\Currency\Handler::updateCurrency($Currency, [
            'rate' => 1.5,
            'precision' => 3
        ]);

        $UpdatedCurrency = QUI\ERP\Currency\Handler::getCurrency($this->fixtureCurrency);
        $this->assertSame(1.5, $UpdatedCurrency->getExchangeRate());
        $this->assertSame(3, $UpdatedCurrency->getPrecision());

        QUI\ERP\Currency\Handler::updateCurrency($UpdatedCurrency, []);

        QUI\ERP\Currency\Handler::deleteCurrency($this->fixtureCurrency);
        $this->fixtureCurrency = null;

        $this->assertFalse(QUI\ERP\Currency\Handler::existCurrency($UpdatedCurrency->getCode()));
    }
}
