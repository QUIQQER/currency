<?php

namespace QUITests\ERP\Currency;

use QUI;

class CurrencyTest extends DatabaseTestCase
{
    public function testCurrency(): void
    {
        $EUR = QUI\ERP\Currency\Handler::getCurrency('EUR');

        $this->assertInstanceOf(QUI\ERP\Currency\Currency::class, $EUR);
        $this->assertNotEmpty($EUR->getSign());
        $this->assertGreaterThan(0, $EUR->getExchangeRate());
        $this->assertNotEmpty($EUR->getCode());
        $this->assertNotEmpty($EUR->getText());
    }

    public function testUnknownCurrency(): void
    {
        $this->expectException(QUI\Exception::class);
        QUI\ERP\Currency\Handler::getCurrency('NaN');
    }

    public function testGetCode(): void
    {
        $EUR = QUI\ERP\Currency\Handler::getCurrency('EUR');

        $this->assertSame('EUR', $EUR->getCode());
    }

    public function testGetSign(): void
    {
        $EUR = QUI\ERP\Currency\Handler::getCurrency('EUR');

        $this->assertSame('€', $EUR->getSign());
    }

    public function testGetText(): void
    {
        $EUR = QUI\ERP\Currency\Handler::getCurrency('EUR');

        $this->assertNotEmpty($EUR->getText());
    }

    public function testFormat(): void
    {
        $EUR    = QUI\ERP\Currency\Handler::getCurrency('EUR');
        $format = $EUR->format(14.99);

        $this->assertIsString($format);
    }

    public function testGetExchangeRate(): void
    {
        $EUR = QUI\ERP\Currency\Handler::getCurrency('EUR');
        $USD = QUI\ERP\Currency\Handler::getCurrency('USD');

        $eurRate = $EUR->getExchangeRate();
        $usdRate = $EUR->getExchangeRate($USD);

        $this->assertIsFloat($eurRate);

        // to another currency
        $this->assertIsFloat($usdRate);
        $this->assertNotEquals($eurRate, $usdRate);
    }

    public function testConvert(): void
    {
        $EUR = QUI\ERP\Currency\Handler::getCurrency('EUR');
        $USD = QUI\ERP\Currency\Handler::getCurrency('USD');
        $GBP = QUI\ERP\Currency\Handler::getCurrency('GBP');

        $usd = $EUR->convert(1, 'USD');

        $this->assertNotEquals($usd, $EUR->getExchangeRate());
        $this->assertIsFloat($usd);


        // some calc tests
        $oneDollarInEuro = $USD->convert(1, 'EUR');
        $onePoundInEuro  = $GBP->convert(1, 'EUR');

        $this->assertIsFloat($oneDollarInEuro);
        $this->assertIsFloat($onePoundInEuro);

        // dollar in pound
        $oneDollarInPound = $USD->convert(1, 'GBP');

        $this->assertIsFloat($oneDollarInPound);

        // alles wieder zurück, wenn das klappt, passen die rechnung
        $PoundToDollar = $GBP->convert($oneDollarInPound, 'USD');
        $DollarToEuro  = $USD->convert($usd, 'EUR');

        $this->assertEqualsWithDelta(1.0, $PoundToDollar, 0.0001);
        $this->assertEqualsWithDelta(1.0, $DollarToEuro, 0.0001);
    }

    public function testConvertFormat(): void
    {
        $EUR = QUI\ERP\Currency\Handler::getCurrency('EUR');
        $usd = $EUR->convertFormat(1, 'USD');

        $this->assertIsString($usd);
    }
}
