<?php

namespace QUI\ERP\Currency;

use QUI;

/**
 * Interface CurrencyInterface
 *
 * Interface for all currencies.
 */
interface CurrencyInterface
{
    /**
     * Get title of the type of this currency.
     *
     * @param QUI\Locale|null $Locale
     * @return string
     */
    public static function getCurrencyTypeTitle(null|QUI\Locale $Locale = null): string;

    /**
     * Get internal identifier of the currency type.
     *
     * @return string
     */
    public static function getCurrencyType(): string;

    /**
     * If the currency type has extra settings, use this form to set them.
     *
     * @return string|null
     */
    public static function getExtraSettingsFormHtml(): ?string;

    /**
     * Currency constructor.
     *
     * @param array<string, mixed> $data
     * @param QUI\Locale|null $Locale - Locale for the currency
     */
    public function __construct(array $data, null|QUI\Locale $Locale = null);

    /**
     * Set the locale for the currency
     *
     * @param QUI\Locale $Locale
     */
    public function setLocale(QUI\Locale $Locale): void;

    /**
     * Return the currency code
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Return the currency text
     *
     * @return string
     */
    public function getText(): string;

    /**
     * Return the currency text
     *
     * @return string
     */
    public function getSign(): string;

    /**
     * @return int
     */
    public function getPrecision(): int;

    /**
     * Return the currency data
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Return the float amount for the currency
     * example for the most currencies -> 0.11223 = 0.11
     *
     * @param float|string $amount
     * @param null|QUI\Locale $Locale -optional
     * @return float
     */
    public function amount($amount, null|QUI\Locale $Locale = null): float;

    /**
     * Format an amount
     *
     * @param float|int|string|null $amount
     * @param null|QUI\Locale $Locale - optional, locale object
     * @return string
     */
    public function format($amount, null|QUI\Locale $Locale = null): string;

    /**
     * updates the currency itself?
     *
     * @return boolean
     */
    public function autoupdate(): bool;

    /**
     * Convert the amount to the wanted currency
     *
     * @param float|string $amount
     * @param string|Currency $Currency
     * @return float
     *
     * @throws QUI\Exception
     */
    public function convert($amount, $Currency);

    /**
     *
     * @param float|string $amount
     * @param string|Currency $Currency
     * @return string
     *
     * @throws QUI\Exception
     */
    public function convertFormat($amount, $Currency): string;

    /**
     * Return the exchange rate to the EUR
     *
     * @param boolean|string|Currency $Currency - optional, default = false -> return own exchange rate
     * @return float|boolean
     */
    public function getExchangeRate($Currency = false);

    /**
     * Set the exchange rate
     * if you want to save it to the currency, use ->update()
     *
     * @param float|integer $rate
     */
    public function setExchangeRate($rate): void;

    /**
     * Get all custom data.
     *
     * @return array<string, mixed>
     */
    public function getCustomData(): array;

    /**
     * Set specific custom data entry.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setCustomDataEntry(string $key, mixed $value): void;

    /**
     * Get specific custom data entry.
     *
     * @param string $key
     * @return mixed
     */
    public function getCustomDataEntry(string $key);
}
