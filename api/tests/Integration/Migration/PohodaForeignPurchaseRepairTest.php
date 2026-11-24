<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaForeignPurchaseRepair;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use MyInvoice\Tests\Support\OwnedSandboxDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Oprava přijatých dokladů v cizí měně převzatých z POHODY v Kč ({@see PohodaForeignPurchaseRepair}).
 *
 * Oprava řídí vlastní transakce (kolo zápisu s porovnáním = samostatná transakce, nanečisto
 * ROLLBACK, naostro COMMIT) a v cizí transakci běžet nesmí: SAVEPOINT nad FULLTEXT tabulkou
 * `purchase_invoice_items` shodil MariaDB 12.3.3. Test proto běží nad vlastní kopií testovací
 * DB, kterou po třídě zahodí.
 */
#[Group('integration')]
final class PohodaForeignPurchaseRepairTest extends TestCase
{
    use OwnedSandboxDatabase;

    private ContainerInterface $container;
    private Connection $db;
    private PohodaImporter $importer;
    private string $tmp = '';
    private int $userId = 0;
    private int $anyCurrencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $container->set(Config::class, self::sandboxConfig('pohoda_fx_repair'));
        $container->set(Connection::class, $this->db = self::sandboxConnection('pohoda_fx_repair'));
        $this->container = $container;
        $this->importer = $container->get(PohodaImporter::class);
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->anyCurrencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_fx_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    /**
     * Doklad, který starší převod převzal v Kč s důvodem „samovyměření DPH" (řádek
     * samovyměření 2 500 Kč + rozdíl kurzu 30 Kč), oprava podle exportu převede na 100 EUR
     * kurzem 25,30 se základem samovyměření 2 500 Kč. Přiznání a KH se nezmění, změna jde
     * do auditní stopy, opakovaný běh nic nemění. Bez exportu kontrola doklad jen ukáže,
     * doklad za zámkem účtování oprava nemění.
     */
    public function testSelfAssessedForeignPurchaseTakenOverInCrownsIsRepairedFromExport(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withSelfAssessedForeignPurchase($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $pdo = $this->db->pdo();
        $id = $this->documentId('purchase_invoices', $supplierId, SyntheticPohodaExport::SELF_ASSESSED_PURCHASE);

        // Doklad tak, jak ho převzal převod před opravou: v Kč, rozdíl kurzu jako položka „mimo".
        $czk = (int) $pdo->query("SELECT currency_id FROM purchase_invoices WHERE supplier_id = {$supplierId} AND exchange_rate IS NULL LIMIT 1")->fetchColumn();
        $pdo->prepare("UPDATE purchase_invoices SET currency_id = ?, exchange_rate = NULL, total_without_vat = 2530, total_with_vat = 2530,
                              note_below_items = 'Převzato z Pohody, doklad 26PF0010; doklad v EUR, převzat v Kč (samovyměření DPH - zdroj daň vyměřil kurzem ke dni plnění, ne kurzem faktury)'
                        WHERE id = ?")->execute([$czk, $id]);
        $pdo->prepare('UPDATE purchase_invoice_items SET unit_price_without_vat = 2500, total_without_vat = 2500, total_with_vat = 2500, import_tax_base_czk = NULL
                        WHERE purchase_invoice_id = ?')->execute([$id]);
        $pdo->prepare("INSERT INTO purchase_invoice_items (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                              total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
                       SELECT purchase_invoice_id, 'Rozdíl proti základu samovyměření (kurz)', 1, 'ks', 30, vat_rate_id, 0, 30, 0, 30, 1, 'mimo'
                         FROM purchase_invoice_items WHERE purchase_invoice_id = ?")->execute([$id]);

        $container = $this->container;
        $dph = $container->get(\MyInvoice\Service\Report\DphPriznaniBuilder::class);
        $kh = $container->get(\MyInvoice\Service\Report\KontrolniHlaseniBuilder::class);
        $return = $dph->build($supplierId, SyntheticPohodaExport::YEAR, 2, 'monthly')['summary']['lines'];
        $statement = $kh->build($supplierId, SyntheticPohodaExport::YEAR, 2);
        self::assertEqualsWithDelta(2500.0, (float) ($return['5']['base'] ?? 0), 0.005, 'Kontrola fixture: ř. 5 ze základu samovyměření.');

        $check = $container->get(\MyInvoice\Service\Migration\Check\Checks\ForeignPurchaseInCzkCheck::class);
        $found = $check->detect($supplierId);
        self::assertSame([[$id, 'review', 'EUR', 2530.0]], array_map(static fn ($i): array => [$i->entityId, $i->state, $i->currency, $i->amountCzk], $found->items));

        $repair = $container->get(PohodaForeignPurchaseRepair::class);
        $pdo->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?) ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')
            ->execute([$supplierId, '2026-02-28']);
        self::assertSame(['locked'], array_column($repair->run($supplierId, PohodaExport::open($dir), true, $this->userId), 'status'));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "id = {$id} AND exchange_rate IS NULL"));
        $pdo->prepare('UPDATE accounting_supplier_settings SET locked_until = NULL WHERE supplier_id = ?')->execute([$supplierId]);

        $savepoints = $this->savepointCounters();
        $dry = $repair->run($supplierId, PohodaExport::open($dir), false);
        self::assertSame([['convert', 25.3, 100.0, 2500.0, 1]], array_map(static fn (array $r): array => [$r['status'], $r['rate'], $r['total_foreign'], $r['tax_base_czk'], $r['removed_items']], $dry));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "id = {$id} AND exchange_rate IS NULL"), 'Nanečisto nic nezapíše.');

        self::assertSame(['converted'], array_column($repair->run($supplierId, PohodaExport::open($dir), true, $this->userId), 'status'));
        $doc = $pdo->query("SELECT c.code, pi.exchange_rate, pi.total_with_vat, pi.amount_to_pay, pi.note_below_items FROM purchase_invoices pi JOIN currencies c ON c.id = pi.currency_id WHERE pi.id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['EUR', '25.300000', '100.00', '100.00'], [$doc['code'], $doc['exchange_rate'], $doc['total_with_vat'], $doc['amount_to_pay']]);
        self::assertSame('Převzato z Pohody, doklad 26PF0010; doklad v EUR, převzat v měně dokladu kurzem 25,3 Kč; '
            . 'samovyměření DPH ze základu 2 500,00 Kč kurzem ke dni plnění jako v POHODĚ, ne přepočtem EUR kurzem dokladu', $doc['note_below_items']);
        self::assertSame([['100.000000', '100.00', '0.00', '2500.00']], $pdo->query("SELECT unit_price_without_vat, total_without_vat, total_vat, import_tax_base_czk FROM purchase_invoice_items WHERE purchase_invoice_id = {$id}")->fetchAll(\PDO::FETCH_NUM));
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'purchase_invoice.pohoda_foreign_currency_repaired' AND entity_type = 'purchase_invoice' AND entity_id = {$id} AND supplier_id = {$supplierId}")->fetchColumn());

        self::assertSame($return, $dph->build($supplierId, SyntheticPohodaExport::YEAR, 2, 'monthly')['summary']['lines']);
        $after = $kh->build($supplierId, SyntheticPohodaExport::YEAR, 2);
        self::assertSame($statement['summary'], $after['summary']);
        self::assertSame([], $repair->run($supplierId, PohodaExport::open($dir), true, $this->userId), 'Opakovaný běh nic nemění.');
        self::assertSame([], $check->detect($supplierId)->items);
        self::assertSame($savepoints, $this->savepointCounters(), 'Oprava nesmí používat SAVEPOINT: purchase_invoice_items má FULLTEXT a InnoDB po ROLLBACK TO SAVEPOINT padá.');
    }

    /**
     * Pojistky opravy dokladů v cizí měně převzatých v Kč: doklad se zápočtem v Kč, ručně
     * uhrazený bez bankovního pohybu, se zápisem v deníku za zámkem účtování, s dimenzí na
     * položce, z exportu cizí agendy (soubor jiného IČO) nebo z jiného běhu převodu se
     * nepřevede a ohlásí. Saldo zápočtu by po převodu četlo koruny v měně dokladu, peněžní
     * deník by úhradu přecenil, dimenze by se posunula na jinou položku.
     */
    public function testForeignPurchaseRepairSkipsDocumentsItCannotConvertSafely(): void
    {
        $cases = [
            'settlement' => ['review', 'zápočet'],
            'manual_paid' => ['review', 'bez bankovního pohybu'],
            'entry_locked' => ['locked', 'zámkem účtování'],
            'item_dimension' => ['review', 'dimenze přiřazené položkám'],
            'foreign_file' => ['review', 'je z agendy IČO 99999999'],
            'other_run' => ['review', 'běh převodu agendy'],
        ];
        foreach ($cases as $case => [$status, $reason]) {
            [$supplierId, $dir, $id] = $this->foreignPurchaseTakenOverInCrowns($case);
            $pdo = $this->db->pdo();
            match ($case) {
                'settlement' => $pdo->prepare("INSERT INTO invoice_settlements (supplier_id, doc_type, doc_id, settled_on, amount, account_id, note, status)
                        SELECT ?, 'purchase_invoice', ?, '2026-02-20', 1000, id, 'Zápočet', 'confirmed' FROM chart_of_accounts WHERE supplier_id = ? ORDER BY id LIMIT 1")
                    ->execute([$supplierId, $id, $supplierId]),
                'manual_paid' => $pdo->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = '2026-03-01' WHERE id = ?")->execute([$id]),
                'entry_locked' => (function () use ($pdo, $supplierId, $id): void {
                    $pdo->prepare("UPDATE journal_entries SET entry_date = '2026-01-30' WHERE supplier_id = ? AND source_type = 'purchase_invoice' AND source_id = ?")->execute([$supplierId, $id]);
                    $pdo->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?) ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')
                        ->execute([$supplierId, '2026-01-31']);
                })(),
                'item_dimension' => (function () use ($pdo, $supplierId, $id): void {
                    $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind) VALUES (?, 'STR', 'Středisko', 'cost_center')")->execute([$supplierId]);
                    $type = (int) $pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, 'S1', 'Středisko 1')")->execute([$type, $supplierId]);
                    $pdo->prepare("INSERT INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id)
                        VALUES (?, 'purchase_invoice', ?, 2, ?, ?)")->execute([$supplierId, $id, $type, (int) $pdo->lastInsertId()]);
                })(),
                'foreign_file' => file_put_contents($dir . '/20_faktury_receivedInvoice.xml', str_replace('ico="' . SyntheticPohodaExport::ICO . '"', 'ico="99999999"',
                    (string) file_get_contents($dir . '/20_faktury_receivedInvoice.xml'))),
                'other_run' => (function () use ($pdo, $supplierId, $id): void {
                    $pdo->prepare("INSERT INTO pohoda_imports (supplier_id, mode, status, agenda_ico, agenda_year) VALUES (?, 'import', 'completed', ?, 2019)")
                        ->execute([$supplierId, SyntheticPohodaExport::ICO]);
                    $pdo->prepare('UPDATE pohoda_import_map SET run_id = ? WHERE supplier_id = ? AND target_id = ? AND kind = ?')
                        ->execute([(int) $pdo->lastInsertId(), $supplierId, $id, PohodaImportRepository::KIND_PURCHASE_INVOICE]);
                })(),
            };
            $repair = $this->container->get(PohodaForeignPurchaseRepair::class);
            $rows = $repair->run($supplierId, PohodaExport::open($dir), true, $this->userId);
            self::assertSame([$status], array_column($rows, 'status'), $case . ': ' . json_encode($rows, JSON_UNESCAPED_UNICODE));
            self::assertStringContainsString($reason, (string) $rows[0]['reason'], $case);
            self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "id = {$id} AND exchange_rate IS NULL AND total_with_vat = 2530"), $case);
        }
    }

    /**
     * Firma se službou z EU 100 EUR / 2 530 Kč (samovyměření ze základu 2 500 Kč) převzatou
     * starším převodem v Kč: řádek samovyměření a položka rozdílu kurzu „mimo".
     *
     * @return array{int,string,int} firma, adresář exportu, doklad
     */
    private function foreignPurchaseTakenOverInCrowns(string $tag): array
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp . '/' . $tag);
        SyntheticPohodaExport::withSelfAssessedForeignPurchase($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $pdo = $this->db->pdo();
        $id = $this->documentId('purchase_invoices', $supplierId, SyntheticPohodaExport::SELF_ASSESSED_PURCHASE);
        $czk = (int) $pdo->query("SELECT currency_id FROM purchase_invoices WHERE supplier_id = {$supplierId} AND exchange_rate IS NULL LIMIT 1")->fetchColumn();
        $pdo->prepare("UPDATE purchase_invoices SET currency_id = ?, exchange_rate = NULL, total_without_vat = 2530, total_with_vat = 2530,
                              note_below_items = 'Převzato z Pohody, doklad 26PF0010; doklad v EUR, převzat v Kč (samovyměření DPH - zdroj daň vyměřil kurzem ke dni plnění, ne kurzem faktury)'
                        WHERE id = ?")->execute([$czk, $id]);
        $pdo->prepare('UPDATE purchase_invoice_items SET unit_price_without_vat = 2500, total_without_vat = 2500, total_with_vat = 2500, import_tax_base_czk = NULL
                        WHERE purchase_invoice_id = ?')->execute([$id]);
        $pdo->prepare("INSERT INTO purchase_invoice_items (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                              total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
                       SELECT purchase_invoice_id, 'Rozdíl proti základu samovyměření (kurz)', 1, 'ks', 30, vat_rate_id, 0, 30, 0, 30, 1, 'mimo'
                         FROM purchase_invoice_items WHERE purchase_invoice_id = ?")->execute([$id]);
        return [$supplierId, $dir, $id];
    }

    /**
     * V cizí transakci oprava nezapisuje ani nanečisto: vrátit by ji šlo jen přes SAVEPOINT.
     */
    public function testRepairRefusesToRunInsideForeignTransaction(): void
    {
        [$supplierId, $dir, $id] = $this->foreignPurchaseTakenOverInCrowns('outer');
        $repair = $this->container->get(PohodaForeignPurchaseRepair::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $repair->run($supplierId, PohodaExport::open($dir), false);
            self::fail('Oprava v cizí transakci musí skončit výjimkou.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('cizí transakci', $e->getMessage());
        } finally {
            $pdo->rollBack();
        }
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "id = {$id} AND exchange_rate IS NULL AND total_with_vat = 2530"));
    }

    /** @return array<string,int> */
    private function savepointCounters(): array
    {
        $out = [];
        foreach ($this->db->pdo()->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_savepoint', 'Com_rollback_to_savepoint', 'Com_release_savepoint')")
            ->fetchAll(\PDO::FETCH_KEY_PAIR) as $name => $value) {
            $out[(string) $name] = (int) $value;
        }
        ksort($out);
        return $out;
    }

    private function supplier(string $ico = SyntheticPohodaExport::ICO): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, ?, ?, "tax_evidence")'
        )->execute([SyntheticPohodaExport::NAME, $this->czId, $ico, 'CZ' . $ico, $this->anyCurrencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    private function documentId(string $table, int $supplierId, string $number): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND varsymbol = ?");
        $stmt->execute([$supplierId, $number]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id, $number);
        return $id;
    }

    private function rows(string $table, int $supplierId, string $where = '1 = 1'): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$where}");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'preflight' => $protocol->get('preflight')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
