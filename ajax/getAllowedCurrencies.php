<?php

/**
 * This file contains package_quiqqer_currency_ajax_getAllowedCurrencies
 */

/**
 * Returns the allowed currencies
 *
 * @return array
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_currency_ajax_getAllowedCurrencies',
    function ($context = null) {
        if ($context === '') {
            $context = null;
        }

        $allowed = QUI\ERP\Currency\Handler::getAllowedCurrencies($context);
        $result = [];

        foreach ($allowed as $Currency) {
            $result[] = $Currency->toArray();
        }

        return $result;
    },
    ['context']
);
