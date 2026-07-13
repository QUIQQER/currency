<?php

namespace QUI\ERP\Currency;

use Exception;
use Doctrine\DBAL\Exception as DbalException;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\Locale;

use function class_exists;
use function in_array;
use function is_a;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strtolower;
use function mb_substr;

/**
 * Currency class
 * Conversion and currency sign
 *
 * @author  www.pcsg.de (Henning Leutz)
 * @package quiqqer/currency
 */
class Handler
{
    /**
     * Currency types.
     */
    const CURRENCY_TYPE_DEFAULT = 'default';

    /**
     * currency temp list
     *
     * @var array<string, array<string, mixed>>
     */
    protected static array $currencies = [];

    protected static ?Currency $Default = null;
    protected static ?Currency $RuntimeCurrency = null;

    /**
     * Return the real table name
     *
     * @return string
     */
    public static function table(): string
    {
        return QUI::getDBTableName('currency');
    }

    /**
     * Create a new currency
     *
     * @param string $currency - currency code
     * @param float|int|string $rate - currency exchange rate, default = 1
     * @param string $type (optional) - Currency type
     *
     * @throws QUI\Exception
     * @throws QUI\Permissions\Exception
     */
    public static function createCurrency(
        string $currency,
        float | int | string $rate = 1,
        string $type = self::CURRENCY_TYPE_DEFAULT
    ): void {
        QUI\Permissions\Permission::checkPermission('currency.create');

        $Currency = null;

        try {
            $Currency = self::getCurrency($currency);
        } catch (QUI\Exception) {
        }

        if (!is_null($Currency)) {
            throw new QUI\Exception([
                'quiqqer/currency',
                'exception.already.exists',
                ['currency' => $currency]
            ]);
        }

        if (!is_numeric($rate)) {
            throw new QUI\Exception([
                'quiqqer/currency',
                'exception.currency.rate.wrong.format'
            ]);
        }

        QUI::getDataBaseConnection()->insert(QUI\Utils\Doctrine::quoteIdentifier(self::table()), [
            QUI\Utils\Doctrine::quoteIdentifier('currency') => $currency,
            QUI\Utils\Doctrine::quoteIdentifier('rate') => (float)$rate,
            QUI\Utils\Doctrine::quoteIdentifier('type') => self::existsCurrencyType($type)
                ? $type
                : self::CURRENCY_TYPE_DEFAULT
        ]);

        self::clearCaches();

        // create translations
        $localeGroup = 'quiqqer/currency';
        $localeText = 'currency.' . $currency . '.text';
        $localeSign = 'currency.' . $currency . '.sign';

        $textData = QUI\Translator::getVarData($localeGroup, $localeText);
        $signData = QUI\Translator::getVarData($localeGroup, $localeSign);

        foreach (QUI::availableLanguages() as $language) {
            if (!isset($textData[$language])) {
                $textData[$language] = $currency;
            }

            if (!isset($signData[$language])) {
                $signData[$language] = mb_substr($currency, 0, 1);
            }
        }


        // text
        try {
            QUI\Translator::add(
                'quiqqer/currency',
                'currency.' . $currency . '.text',
                'quiqqer/currency'
            );
        } catch (QUI\Exception) {
        }

        if (!empty($textData)) {
            QUI\Translator::edit(
                'quiqqer/currency',
                'currency.' . $currency . '.text',
                'quiqqer/currency',
                $textData
            );
        }

        // sign
        try {
            QUI\Translator::add(
                'quiqqer/currency',
                'currency.' . $currency . '.sign',
                'quiqqer/currency'
            );
        } catch (QUI\Exception) {
        }


        if (!empty($signData)) {
            QUI\Translator::edit(
                'quiqqer/currency',
                'currency.' . $currency . '.sign',
                'quiqqer/currency',
                $signData
            );
        }

        QUI\Translator::publish('quiqqer/currency');
    }

    /**
     * Delete a currency
     *
     * @param string $currency - currency code
     * @throws QUI\Exception
     */
    public static function deleteCurrency(string $currency): void
    {
        QUI\Permissions\Permission::checkPermission('currency.delete');

        QUI::getDataBaseConnection()->delete(QUI\Utils\Doctrine::quoteIdentifier(self::table()), [
            QUI\Utils\Doctrine::quoteIdentifier('currency') => $currency
        ]);

        self::clearCaches();

        QUI\Translator::delete(
            'quiqqer/currency',
            'currency.' . $currency . '.text'
        );

        QUI\Translator::delete(
            'quiqqer/currency',
            'currency.' . $currency . '.sign'
        );

        QUI\Translator::publish('quiqqer/currency');
    }

    /**
     * Return the default currency
     *
     * @return Currency|null
     */
    public static function getDefaultCurrency(): ?Currency
    {
        if (self::$Default === null) {
            try {
                $Config = QUI::getPackage('quiqqer/currency')->getConfig();
                $defaultCurrency = $Config?->getValue('currency', 'defaultCurrency');

                if (!is_string($defaultCurrency) || $defaultCurrency === '') {
                    throw new QUI\Exception(
                        ['quiqqer/currency', 'currency.not.found'],
                        404
                    );
                }

                self::$Default = self::getCurrency($defaultCurrency);
            } catch (QUI\Exception) {
                QUI\System\Log::addWarning('Default currency is missing');

                try {
                    self::$Default = self::getCurrency('EUR');
                } catch (QUI\Exception $Exception) {
                    if ($Exception->getCode() === 404) {
                        // add EUR
                        self::createCurrency('EUR');
                        self::$Default = self::getCurrency('EUR');
                    }
                }
            }
        }

        return self::$Default;
    }

    /**
     * Return the currency of the user
     * - This currency can be switched, so the user can get the prices in its currency
     *
     * @param null|QUI\Interfaces\Users\User $User - optional
     * @return Currency|null
     */
    public static function getUserCurrency(null | QUI\Interfaces\Users\User $User = null): ?Currency
    {
        if ($User === null) {
            $User = QUI::getUserBySession();
        }

        if (!$User->getAttribute('quiqqer.erp.currency')) {
            $Currency = self::getUserCurrencyByCountry($User);

            if ($Currency) {
                return $Currency;
            }

            return null;
        }

        try {
            return self::getCurrency(
                $User->getAttribute('quiqqer.erp.currency')
            );
        } catch (Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
        }

        $Currency = self::getUserCurrencyByCountry($User);

        if ($Currency) {
            return $Currency;
        }

        return null;
    }

    /**
     * Return the currency of the user by its country
     *
     * @param User|null $User
     * @return Currency|null
     */
    public static function getUserCurrencyByCountry(null | QUI\Interfaces\Users\User $User = null): ?Currency
    {
        if ($User === null) {
            $User = QUI::getUserBySession();
        }

        try {
            $Config = QUI::getPackage('quiqqer/currency')->getConfig();
            $allowed = $Config?->getValue('currency', 'allowedCurrencies');
            if (!is_string($allowed)) {
                return null;
            }

            $allowed = explode(',', trim($allowed));
            $allowed = array_flip($allowed);

            $Country = $User->getCountry();

            if (!$Country) {
                return null;
            }

            $Currency = $Country->getCurrency();

            if (isset($allowed[$Currency->getCode()])) {
                return $Currency;
            }
        } catch (Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
        }

        return null;
    }

    /**
     * Return all allowed currencies
     *
     * @return Currency[] - [Currency, Currency, Currency]
     */
    public static function getAllowedCurrencies(): array
    {
        try {
            $Config = QUI::getPackage('quiqqer/currency')->getConfig();
            $allowed = $Config?->getValue('currency', 'allowedCurrencies');
            if (!is_string($allowed)) {
                return [];
            }

            $allowed = explode(',', trim($allowed));
            $list = [];
            $defaultCurrency = self::getDefaultCurrency();

            if (!$defaultCurrency instanceof Currency) {
                return [];
            }

            $default = $defaultCurrency->getCode();

            if (!in_array($default, $allowed)) {
                $allowed[] = $default;
            }
        } catch (QUI\Exception $e) {
            QUI\System\Log::addError($e->getMessage());
            return [];
        }

        foreach ($allowed as $currency) {
            try {
                $list[] = self::getCurrency($currency);
            } catch (QUI\Exception) {
            }
        }

        return $list;
    }

    /**
     * Return the currency db data
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getData(): array
    {
        if (!self::$currencies) {
            try {
                $data = QUI::getQueryBuilder()
                    ->select('*')
                    ->from(QUI\Utils\Doctrine::quoteIdentifier(self::table()))
                    ->executeQuery()
                    ->fetchAllAssociative();
            } catch (DbalException $Exception) {
                QUI\System\Log::addError($Exception->getMessage());

                return [];
            }

            foreach ($data as $entry) {
                if (!isset($entry['currency']) || !is_string($entry['currency'])) {
                    continue;
                }

                $entry['type'] = isset($entry['type']) && is_string($entry['type'])
                    ? mb_strtolower($entry['type'])
                    : self::CURRENCY_TYPE_DEFAULT;

                if (!empty($entry['customData'])) {
                    $customData = json_decode((string)$entry['customData'], true);
                    $entry['customData'] = is_array($customData) ? $customData : [];
                }

                self::$currencies[$entry['currency']] = $entry;
            }
        }

        return self::$currencies;
    }

    /**
     * Return a currency
     *
     * @param Currency|string|array<string, mixed> $currency
     * @return Currency
     * @throws QUI\Exception
     */
    public static function getCurrency(Currency | string | array $currency): Currency
    {
        if ($currency instanceof Currency) {
            return $currency;
        }

        $data = self::getData();
        $code = null;

        if (is_string($currency)) {
            $code = $currency;
        } elseif (isset($currency['code']) && is_string($currency['code'])) {
            $code = $currency['code'];
        }

        if ($code !== null && isset($data[$code])) {
            $class = Currency::class;
            $currencyData = $data[$code];

            if (!empty($currencyData['type']) && is_string($currencyData['type'])) {
                $class = self::getCurrencyClassByType($currencyData['type']);
            }

            $Currency = new $class($currencyData);

            if ($Currency instanceof Currency) {
                return $Currency;
            }

            return new Currency($currencyData);
        }

        throw new QUI\Exception(
            ['quiqqer/currency', 'currency.not.found'],
            404
        );
    }

    /**
     * Check if an exchange rate for the currency exists
     *
     * @param string $currency - currency name
     *
     * @return bool
     */
    public static function existCurrency(string $currency): bool
    {
        $data = self::getData();

        return isset($data[$currency]);
    }

    /**
     * Return all currency entries
     *
     * @param Locale|null $Locale - optional, for translation
     * @return array<string, array<string, mixed>>
     */
    public static function getCurrencies(null | QUI\Locale $Locale = null): array
    {
        if (!$Locale) {
            $Locale = QUI::getLocale();
        }

        $cacheName = 'quiqqer/currency/list';
        $cacheNameLang = 'quiqqer/currency/list/' . $Locale->getCurrent();

        try {
            $cacheResult = QUI\Cache\Manager::get($cacheNameLang);
            if (is_array($cacheResult)) {
                return $cacheResult;
            }
        } catch (QUI\Exception) {
        }

        try {
            $currencies = QUI\Cache\Manager::get($cacheName);
            if (!is_array($currencies)) {
                $currencies = [];
            }
        } catch (QUI\Exception) {
            $currencies = [];
            $data = self::getData();

            foreach ($data as $entry) {
                $currencies[] = $entry['currency'];
            }
        }

        $result = [];

        foreach ($currencies as $currency) {
            try {
                $Currency = self::getCurrency($currency);
            } catch (QUI\Exception) {
                continue;
            }

            $result[$currency] = $Currency->toArray();
        }

        return $result;
    }

    /**
     * @param Currency|string|array<string, mixed> $currency
     * @param array<string, mixed> $data
     *
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     */
    public static function updateCurrency(Currency | string | array $currency, array $data): void
    {
        QUI\Permissions\Permission::checkPermission('currency.edit');

        // check if currency exists
        $Currency = self::getCurrency($currency);

        $dbData = [];

        if (isset($data['autoupdate'])) {
            $dbData['autoupdate'] = empty($data['autoupdate']) ? 0 : 1;
        }

        if (!empty($data['type']) && is_string($data['type']) && self::existsCurrencyType($data['type'])) {
            $dbData['type'] = $data['type'];
        }

        if (!empty($data['customData'])) {
            $dbData['customData'] = json_encode($data['customData']);
        }

        if (isset($data['rate'])) {
            $dbData['rate'] = floatval($data['rate']);
        }

        if (isset($data['precision'])) {
            $dbData['precision'] = (int)$data['precision'];
        }

        if ($dbData === []) {
            return;
        }

        $quotedDbData = [];

        foreach ($dbData as $column => $value) {
            $quotedDbData[QUI\Utils\Doctrine::quoteIdentifier($column)] = $value;
        }

        QUI::getDataBaseConnection()->update(
            QUI\Utils\Doctrine::quoteIdentifier(self::table()),
            $quotedDbData,
            [QUI\Utils\Doctrine::quoteIdentifier('currency') => $Currency->getCode()]
        );

        self::clearCaches();
    }

    /**
     * Clear request-local and shared currency caches after a database mutation.
     */
    private static function clearCaches(): void
    {
        self::$currencies = [];
        self::$Default = null;
        self::$RuntimeCurrency = null;

        QUI\Cache\Manager::clear('quiqqer/currency/list');
    }

    // region Currency types

    /**
     * @param string $type
     * @return string - Class path
     */
    protected static function getCurrencyClassByType(string $type): string
    {
        foreach (self::getCurrencyTypes() as $currencyType) {
            if ($currencyType['type'] === $type) {
                return $currencyType['class'];
            }
        }

        return Currency::class;
    }

    /**
     * @param string $type
     * @return bool
     */
    protected static function existsCurrencyType(string $type): bool
    {
        foreach (self::getCurrencyTypes() as $currencyType) {
            if ($currencyType['type'] === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get available currency types (provided by <currency> package.xml providers).
     *
     * @return array<int, array{
     *     type: string,
     *     typeTitle: string,
     *     class: string,
     *     settingsFormHtml: string|null
     * }>
     */
    public static function getCurrencyTypes(): array
    {
        // @todo cache einbauen

        $packages = QUI::getPackageManager()->getInstalled();
        $currencyTypes = [];

        foreach ($packages as $installedPackage) {
            try {
                $Package = QUI::getPackage($installedPackage['name']);

                if (!$Package->isQuiqqerPackage()) {
                    continue;
                }

                $packageProvider = $Package->getProvider();

                if (empty($packageProvider['currency'])) {
                    continue;
                }

                foreach ($packageProvider['currency'] as $class) {
                    if (!class_exists($class)) {
                        continue;
                    }

                    if (!is_a($class, CurrencyInterface::class, true)) {
                        continue;
                    }

                    $currencyTypes[] = [
                        'type' => $class::getCurrencyType(),
                        'typeTitle' => $class::getCurrencyTypeTitle(),
                        'class' => $class,
                        'settingsFormHtml' => $class::getExtraSettingsFormHtml()
                    ];
                }
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addNotice($Exception->getMessage());
            }
        }

        return $currencyTypes;
    }
    // endregion

    //region runtime

    public static function getRuntimeCurrency(): Currency
    {
        if (self::$RuntimeCurrency) {
            return self::$RuntimeCurrency;
        }

        $Session = QUI::getSession();
        $runtimeCode = $Session?->get('currency');

        if (is_string($runtimeCode) && $runtimeCode !== '') {
            try {
                $Currency = self::getCurrency($runtimeCode);
                self::$RuntimeCurrency = $Currency;
                return self::$RuntimeCurrency;
            } catch (QUI\Exception) {
            }
        }

        if (QUI::isFrontend()) {
            $Currency = self::getUserCurrency(QUI::getUserBySession());

            if ($Currency instanceof Currency) {
                self::$RuntimeCurrency = $Currency;
                return self::$RuntimeCurrency;
            }
        }

        $Currency = self::getDefaultCurrency();

        if ($Currency instanceof Currency) {
            self::$RuntimeCurrency = $Currency;
            return $Currency;
        }

        self::$RuntimeCurrency = self::getCurrency('EUR');
        return self::$RuntimeCurrency;
    }

    public static function setRuntimeCurrency(Currency $currency): void
    {
        self::$RuntimeCurrency = $currency;

        if (QUI::isFrontend()) {
            QUI::getSession()?->set('currency', $currency->getCode());
        }
    }

    //endregion
}
