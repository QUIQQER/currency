<?php

if (!defined('QUIQQER_SYSTEM')) {
    define('QUIQQER_SYSTEM', true);
}

if (!defined('QUIQQER_AJAX')) {
    define('QUIQQER_AJAX', true);
}

require_once __DIR__ . '/QUITests/ERP/Currency/DatabaseEnvironment.php';
require_once __DIR__ . '/../../../../bootstrap.php';

if (QUITests\ERP\Currency\DatabaseEnvironment::usesCiDatabase()) {
    $databasePlatform = QUI::getDataBaseConnection()->getDatabasePlatform();
    $databasePlatformClass = $databasePlatform::class;
    $databaseVendor = QUITests\ERP\Currency\DatabaseEnvironment::getCiVendor();

    if (!$databasePlatform instanceof Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
        throw new RuntimeException(
            'GitLab currency tests expected a MySQL-compatible DBAL platform, got ' . $databasePlatformClass . '.'
        );
    }

    $isMariaDbPlatform = str_contains(strtolower($databasePlatformClass), 'maria');

    if (
        ($databaseVendor === 'mariadb' && !$isMariaDbPlatform)
        || ($databaseVendor === 'mysql' && $isMariaDbPlatform)
    ) {
        throw new RuntimeException(
            'GitLab DB_VENDOR=' . $databaseVendor . ' does not match DBAL platform ' . $databasePlatformClass . '.'
        );
    }
}

require_once __DIR__ . '/QUITests/ERP/Currency/DatabaseTestCase.php';
require_once __DIR__ . '/QUITests/ERP/Currency/Fixtures/TestCurrency.php';
