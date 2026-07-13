<?php

namespace QUITests\ERP\Currency;

use PHPUnit\Framework\TestCase;
use QUI;

class ImportTest extends TestCase
{
    public function testImport(): void
    {
        try {
            QUI\ERP\Currency\Import::importCurrenciesFromECB();
        } catch (QUI\Exception $Exception) {
            $this->markTestSkipped(
                'ECB import currently unavailable in test environment: ' . $Exception->getMessage()
            );
        }

        $result = QUI::getQueryBuilder()
            ->select('*')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(QUI\ERP\Currency\Handler::table()))
            ->executeQuery()
            ->fetchAllAssociative();

        $this->assertNotEmpty($result);
    }
}
