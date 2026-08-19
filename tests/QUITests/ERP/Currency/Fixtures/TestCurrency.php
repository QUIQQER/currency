<?php

namespace QUITests\ERP\Currency\Fixtures;

use QUI;
use QUI\ERP\Currency\Currency;

class TestCurrency extends Currency
{
    public static function getCurrencyTypeTitle(?QUI\Locale $Locale = null): string
    {
        return 'Test currency';
    }

    public static function getCurrencyType(): string
    {
        return 'test';
    }

    public static function getExtraSettingsFormHtml(): ?string
    {
        return '<label>Test</label>';
    }
}
