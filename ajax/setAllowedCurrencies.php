<?php

/**
 * Persist the currencies that are active in one application context.
 */

use QUI\ERP\Currency\Handler;

QUI::getAjax()->registerFunction(
    'package_quiqqer_currency_ajax_setAllowedCurrencies',
    function ($context, $currencies) {
        try {
            $currencies = json_decode((string)$currencies, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new QUI\Exception('Invalid currency list.');
        }

        if (!is_array($currencies)) {
            throw new QUI\Exception('Invalid currency list.');
        }

        $context = (string)$context;
        Handler::setAllowedCurrencies($context, $currencies);

        return array_map(
            static fn(QUI\ERP\Currency\Currency $Currency): array => $Currency->toArray(),
            Handler::getAllowedCurrencies($context)
        );
    },
    ['context', 'currencies'],
    'Permission::checkAdminUser'
);
