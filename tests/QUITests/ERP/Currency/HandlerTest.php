<?php

namespace QUITests\ERP\Currency;

use QUI;

class HandlerTest extends DatabaseTestCase
{
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

    public function testUpdateCurrencyPersistsAndRefreshesCachedData(): void
    {
        $Currency = QUI\ERP\Currency\Handler::getCurrency('TST');
        $this->assertSame(1.25, $Currency->getExchangeRate());

        QUI\ERP\Currency\Handler::updateCurrency($Currency, [
            'rate' => 1.5,
            'precision' => 3,
            'autoupdate' => 1,
            'customData' => ['source' => 'updated']
        ]);

        $stored = $this->connection->fetchAssociative(
            'SELECT rate, precision, autoupdate, customData FROM ' . QUI\ERP\Currency\Handler::table()
            . ' WHERE currency = ?',
            ['TST']
        );
        $this->assertIsArray($stored);
        $this->assertSame(1.5, (float)$stored['rate']);
        $this->assertSame(3, (int)$stored['precision']);
        $this->assertSame(1, (int)$stored['autoupdate']);
        $this->assertSame(['source' => 'updated'], json_decode((string)$stored['customData'], true));

        $UpdatedCurrency = QUI\ERP\Currency\Handler::getCurrency('TST');
        $this->assertSame(1.5, $UpdatedCurrency->getExchangeRate());
        $this->assertSame(3, $UpdatedCurrency->getPrecision());
        $this->assertSame('updated', $UpdatedCurrency->getCustomDataEntry('source'));

        QUI\ERP\Currency\Handler::updateCurrency($UpdatedCurrency, []);
        $this->assertSame(1.5, (float)$this->connection->fetchOne(
            'SELECT rate FROM ' . QUI\ERP\Currency\Handler::table() . ' WHERE currency = ?',
            ['TST']
        ));
    }
}
