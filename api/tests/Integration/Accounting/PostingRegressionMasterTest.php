<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tests\Support\PostingRegressionScenarios;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Bez nastavení produktu, kategorie a účtu položky se účtuje BAJTOVĚ stejně jako před
 * F1 (Účtování podle dimenzí). Očekávaný výstup ({@see PostingRegressionScenarios},
 * soubor Fixtures/posting-regression-master-307179b4e.json) vznikl spuštěním týchž
 * scénářů nad kódem masteru 307179b4e: vydaná faktura, dobropis, cizí měna, sleva
 * z hlavičky, prodej majetku, vyúčtování proformy s odpočtem § 37a, přijatá faktura
 * s druhem výdaje, pořízení DHM, nedaňový náklad a razítkování dimenzí. Položky jsou
 * navázané na kartu a kategorii bez účtu a dimenzí.
 *
 * Jediná vědomá změna proti masteru: daň dokladu v cizí měně (343) je přepočet součtu
 * sazby jako v rekapitulaci dokladu (32,67 EUR × 25,125 = 820,83 Kč, po položkách
 * 820,84), haléř do celku dokladu jde na zaokrouhlení 648.
 */
#[Group('integration')]
final class PostingRegressionMasterTest extends TestCase
{
    public function testWithoutProductSettingsPostingMatchesMaster(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $db->pdo()->beginTransaction();
        try {
            $actual = (new PostingRegressionScenarios($container))->run();
        } finally {
            $db->pdo()->rollBack();
            $db->close();
        }
        self::assertMatchesMaster($actual);
    }

    /**
     * Účtotvorná dimenze (F2) zapnutá, ale bez mapování hodnot, které doklady nesou:
     * zaúčtování se nesmí změnit ani o bajt. Firma má účtotvorné Středisko a mapu pro
     * hodnotu, kterou žádný scénář nepoužije (518 → 518.100).
     */
    public function testDrivingDimensionWithoutMatchingMapMatchesMaster(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            $supplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
            $container->get(\MyInvoice\Service\Accounting\ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
            $dimensions = $container->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);
            $dimensions->setEnabled($supplierId, true);
            $center = $dimensions->ensureDefaultTypes($supplierId, ['stredisko'])['cost_center'];
            $dimensions->updateType($supplierId, $center, ['drives_accounts' => true]);
            // Dvě daňové analytiky — jediná by sama spustila přesměr syntetiky (mimo F2).
            foreach (['518.100', '518.200'] as $code) {
                $pdo->prepare(
                    "INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
                     SELECT supplier_id, ?, 'Regrese F2', account_type, normal_side, 0, id, 1, tax_deductibility
                       FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '518'
                     ON DUPLICATE KEY UPDATE is_active = 1"
                )->execute([$code, $supplierId]);
            }
            $unused = (int) $dimensions->createValue($supplierId, $center, ['code' => 'REGR-F2', 'name' => 'Nepoužitá'])['id'];
            $container->get(\MyInvoice\Service\Accounting\Dimension\DimensionAccountMapService::class)
                ->saveForValue($supplierId, $unused, [['synthetic_code' => '518', 'analytic_code' => '518.100']], null);
            $actual = (new PostingRegressionScenarios($container))->run();
        } finally {
            $pdo->rollBack();
            $db->close();
        }
        self::assertMatchesMaster($actual);
    }

    /** @param array<string,mixed> $actual */
    private static function assertMatchesMaster(array $actual): void
    {
        $expected = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/posting-regression-master-307179b4e.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (!isset($actual['foreign_currency'])) {
            unset($expected['foreign_currency']);   // instance bez měny EUR
        }
        self::assertSame(
            json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
        );
    }
}
