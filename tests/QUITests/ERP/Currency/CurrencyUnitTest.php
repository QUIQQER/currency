<?php

namespace QUITests\ERP\Currency;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Currency\Currency;
use QUI\ERP\Currency\CurrencyInterface;
use QUI\ERP\Currency\Handler;
use ReflectionProperty;

class CurrencyUnitTest extends TestCase
{
    private Currency $EUR;
    private Currency $USD;
    private Currency $GBP;
    private ?Currency $originalDefault = null;
    /** @var array<string, array<string, mixed>> */
    private array $originalCurrencies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->EUR = $this->createCurrency('EUR', 1.0);
        $this->USD = $this->createCurrency('USD', 1.2);
        $this->GBP = $this->createCurrency('GBP', 0.8);

        $this->originalCurrencies = $this->getCurrenciesFromHandler();
        $this->setCurrenciesOnHandler([
            'EUR' => $this->createCurrencyData('EUR', 1.0),
            'USD' => $this->createCurrencyData('USD', 1.2),
            'GBP' => $this->createCurrencyData('GBP', 0.8)
        ]);

        $this->originalDefault = $this->getDefaultCurrencyFromHandler();
        $this->setDefaultCurrencyOnHandler($this->EUR);
    }

    protected function tearDown(): void
    {
        $this->setDefaultCurrencyOnHandler($this->originalDefault);
        $this->setCurrenciesOnHandler($this->originalCurrencies);
        parent::tearDown();
    }

    public function testConvertWithCurrencyObjects(): void
    {
        $usd = $this->EUR->convert(10, $this->USD);
        $eur = $this->USD->convert(12, $this->EUR);
        $gbp = $this->USD->convert(12, $this->GBP);

        $this->assertEqualsWithDelta(12.0, (float)$usd, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float)$eur, 0.0001);
        $this->assertEqualsWithDelta(8.0, (float)$gbp, 0.0001);
    }

    public function testGetExchangeRateToOtherCurrency(): void
    {
        $rate = $this->USD->getExchangeRate($this->EUR);

        $this->assertIsFloat($rate);
        $this->assertEqualsWithDelta(1.2, $rate, 0.0001);
    }

    public function testCustomDataRoundtrip(): void
    {
        $this->EUR->setCustomDataEntry('foo', 'bar');
        $this->assertSame('bar', $this->EUR->getCustomDataEntry('foo'));
        $this->assertNull($this->EUR->getCustomDataEntry('missing'));
    }

    public function testConstructorAcceptsCodeAliasAndRejectsMissingCode(): void
    {
        $Currency = new Currency([
            'code' => 'ALT',
            'rate' => 2,
            'autoupdate' => 0
        ]);

        $this->assertSame('ALT', $Currency->getCode());
        $this->assertFalse($Currency->autoupdate());
        $this->assertSame(2.0, $Currency->getExchangeRate());

        $this->expectException(\QUI\Exception::class);
        $this->expectExceptionCode(404);
        new Currency(['rate' => 1, 'autoupdate' => 1]);
    }

    public function testFormattingAndAmountUseLocaleAndPrecision(): void
    {
        $Locale = $this->createMock(\QUI\Locale::class);
        $Locale->method('getCurrent')->willReturn('en');
        $Locale->method('getLocalesByLang')->with('en')->willReturn(['en_US']);
        $Locale->method('getAccountingCurrencyPattern')->willReturn('¤#,##0.00');
        $Locale->method('getDecimalSeparator')->willReturn('.');
        $Locale->method('getGroupingSeparator')->willReturn(',');

        $Currency = new Currency([
            'currency' => 'LONG',
            'rate' => 1,
            'autoupdate' => 1,
            'precision' => 4
        ], $Locale);

        $this->assertSame(12.3456, $Currency->amount(12.3456));
        $this->assertEqualsWithDelta(12.5, $Currency->amount('12.5'), 0.0001);
        $this->assertStringContainsString('LONG', $Currency->format(null));
        $this->assertStringContainsString('12.3456', $Currency->format('12.3456'));

        $Currency->setLocale($Locale);
        $this->assertSame(4, $Currency->getPrecision());
    }

    public function testSerializationAndStaticTypeMetadata(): void
    {
        $data = $this->EUR->toArray();

        $this->assertSame('EUR', $data['code']);
        $this->assertSame(1.0, $data['rate']);
        $this->assertSame('default', $data['type']);
        $this->assertArrayHasKey('text', $data);
        $this->assertArrayHasKey('sign', $data);
        $this->assertSame('default', Currency::getCurrencyType());
        $this->assertNull(Currency::getExtraSettingsFormHtml());
    }

    public function testConversionHandlesSameCurrencyInterfaceAndUnavailableRates(): void
    {
        $this->assertSame(7.5, $this->EUR->convert(7.5, $this->EUR));

        $Target = $this->createMock(CurrencyInterface::class);
        $Target->method('getCode')->willReturn('GBP');
        $this->assertEqualsWithDelta(8.0, (float)$this->EUR->convert(10, $Target), 0.0001);

        $UnavailableTarget = $this->createCurrency('ZER', 1.0);
        (new ReflectionProperty($UnavailableTarget, 'exchangeRate'))->setValue($UnavailableTarget, false);
        $this->setCurrenciesOnHandler([
            'EUR' => $this->createCurrencyData('EUR', 1.0),
            'USD' => $this->createCurrencyData('USD', 1.2),
            'GBP' => $this->createCurrencyData('GBP', 0.8),
            'ZER' => $this->createCurrencyData('ZER', 1.0)
        ]);

        $this->assertSame(10.0, $this->EUR->convert(10, $UnavailableTarget));
        $this->assertSame(10.0, $this->GBP->convert(8, $UnavailableTarget));

        (new ReflectionProperty($this->USD, 'exchangeRate'))->setValue($this->USD, false);
        $this->assertSame(10.0, $this->USD->convert(10, $this->EUR));
    }

    public function testInvalidConversionAndExchangeRateInputsAreRejected(): void
    {
        try {
            $this->EUR->convert('not-a-number', $this->USD);
            $this->fail('A non-numeric amount must be rejected.');
        } catch (\QUI\Exception $Exception) {
            $this->assertNotSame('', $Exception->getMessage());
        }

        $this->assertFalse($this->EUR->getExchangeRate(true));
        $this->assertFalse($this->EUR->getExchangeRate('UNKNOWN'));

        $Zero = $this->createCurrency('ZER', 0.0);
        $this->assertFalse($this->EUR->getExchangeRate($Zero));

        $this->EUR->setExchangeRate(1.75);
        $this->assertSame(1.75, $this->EUR->getExchangeRate());
    }

    private function createCurrency(string $code, float $rate): Currency
    {
        return new Currency($this->createCurrencyData($code, $rate));
    }

    /**
     * @return array<string, mixed>
     */
    private function createCurrencyData(string $code, float $rate): array
    {
        return [
            'currency' => $code,
            'rate' => $rate,
            'autoupdate' => 1,
            'precision' => 2,
            'customData' => [],
            'type' => 'default'
        ];
    }

    private function setDefaultCurrencyOnHandler(?Currency $Currency): void
    {
        $reflection = new \ReflectionClass(Handler::class);
        $property = $reflection->getProperty('Default');
        $property->setAccessible(true);
        $property->setValue(null, $Currency);
    }

    private function getDefaultCurrencyFromHandler(): ?Currency
    {
        $reflection = new \ReflectionClass(Handler::class);
        $property = $reflection->getProperty('Default');
        $property->setAccessible(true);

        return $property->getValue();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getCurrenciesFromHandler(): array
    {
        $reflection = new \ReflectionClass(Handler::class);
        $property = $reflection->getProperty('currencies');
        $property->setAccessible(true);
        $value = $property->getValue();

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, array<string, mixed>> $currencies
     */
    private function setCurrenciesOnHandler(array $currencies): void
    {
        $reflection = new \ReflectionClass(Handler::class);
        $property = $reflection->getProperty('currencies');
        $property->setAccessible(true);
        $property->setValue(null, $currencies);
    }
}
