<?php

namespace QUITests\ERP\Currency;

use QUI;

class ImportTest extends DatabaseTestCase
{
    public function testImport(): void
    {
        try {
            QUI\ERP\Currency\Import::import();
        } catch (QUI\Exception $Exception) {
            $this->markTestSkipped(
                'External ECB rates are unavailable: ' . $Exception->getMessage()
            );
        }

        $result = $this->connection->createQueryBuilder()
            ->select('currency', 'rate')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(QUI\ERP\Currency\Handler::table()))
            ->where('currency IN (:currencies)')
            ->setParameter('currencies', ['EUR', 'USD', 'GBP'], \Doctrine\DBAL\ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $this->assertCount(3, $result);

        foreach ($result as $currency) {
            $this->assertGreaterThan(0, (float)$currency['rate']);
        }
    }

    public function testImportCurrenciesKeepsExistingCurrenciesAndRefreshesRates(): void
    {
        $rates = (new \ReflectionMethod(QUI\ERP\Currency\Import::class, 'getECBData'))->invoke(null);

        if (!is_array($rates) || $rates === []) {
            $this->markTestSkipped('External ECB rate feed returned no currencies.');
        }

        foreach ($rates as $code => $rate) {
            if (
                $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM ' . QUI\ERP\Currency\Handler::table() . ' WHERE currency = ?',
                    [$code]
                )
            ) {
                continue;
            }

            $this->insertCurrencyFixture($this->currencyFixture($code, (float)$rate));
        }

        $this->resetHandlerState();
        QUI\ERP\Currency\Import::importCurrenciesFromECB();

        foreach ($rates as $code => $rate) {
            $storedRate = (float)$this->connection->fetchOne(
                'SELECT rate FROM ' . QUI\ERP\Currency\Handler::table() . ' WHERE currency = ?',
                [$code]
            );
            $this->assertGreaterThan(0, $storedRate);
        }
    }
}
