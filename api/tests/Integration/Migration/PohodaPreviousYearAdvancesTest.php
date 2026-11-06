<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport as X;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Poskytnuté zálohy minulých let z POHODY ({@see X::withPreviousYearAdvances()}): na otevírací
 * zápis se navážou jen tehdy, když se jejich předpis vejde do počátečního zůstatku 314
 * (commit 3a5fcb9dd). Dál kontrola uhrazených záloh bez platby a opravný převod vazeb.
 * Izolovaná firma, transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class PohodaPreviousYearAdvancesTest extends TestCase
{
    private Connection $db;
    private PohodaImporter $importer;
    private SaldoService $saldo;
    private string $tmp = '';
    private int $userId = 0;
    private int $anyCurrencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(PohodaImporter::class);
            $this->saldo = $container->get(SaldoService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        if (!$this->db->hasColumn('pohoda_import_map', 'pohoda_key')) {
            $this->markTestSkipped('Chybí migrace 1844 (pohoda_import_map).');
        }
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->anyCurrencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_prev_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
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

    /** A) Zálohy (základ 1 500) se vejdou do počátečního stavu 314 = 1 500: vazba vznikne, saldo = HK. */
    public function testAdvancesCoveredByOpening314AreLinkedToOpeningEntry(): void
    {
        [$protocol, $dir] = $this->import(X::PREVIOUS_ADVANCES_BASE);

        self::assertSame(X::PREVIOUS_ADVANCES, $this->linkedAdvances(), 'Obě zálohy minulého roku jsou navázané na otevírací zápis.');
        self::assertNull($this->info($protocol), 'Bez hlášení o vyúčtovaných zálohách.');

        $acc = $this->account314();
        self::assertSame(150000, self::cents($acc['gl_balance']), 'HK 314 = počáteční stav.');
        self::assertSame(150000, self::cents($acc['open_items_total']), 'Zálohy jsou otevřenými položkami s předpisem = základ.');
        self::assertSame(0, self::cents($acc['difference']));
        self::assertEqualsCanonicalizing(X::PREVIOUS_ADVANCES, array_column($this->items($acc), 'doc_no_pohoda'));

        // Opakovaný převod vazbu nezdvojí ani nezruší.
        $again = $this->importer->run($this->supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame(X::PREVIOUS_ADVANCES, $this->linkedAdvances());
    }

    /**
     * B) Zálohy (1 500) se do počátečního stavu 314 = 1 000 nevejdou: vazba nevznikne, saldo
     * je bere jako vyúčtované (rozdíl = celý zůstatek bez dokladů), předvaha se nemění.
     */
    public function testAdvancesExceedingOpening314AreNotLinkedAndReportedAsSettled(): void
    {
        [$protocol] = $this->import(1000.0);

        self::assertSame([], $this->linkedAdvances(), 'Zálohy nad počáteční stav 314 se nenavážou.');
        $info = $this->info($protocol);
        self::assertNotNull($info, 'Protokol hlásí zálohy brané jako vyúčtované: ' . $this->explain($protocol));
        self::assertSame(0, $info['context']['unlinked'] ?? null);
        self::assertStringContainsString('1 500,00', (string) $info['text']);
        self::assertStringContainsString('1 000,00', (string) $info['text']);

        $acc = $this->account314();
        self::assertSame(100000, self::cents($acc['gl_balance']), 'Předvaha (HK 314) beze změny: počáteční stav 1 000.');
        self::assertSame(0, self::cents($acc['open_items_total']), 'Zálohy minulého roku nejsou otevřené položky.');
        self::assertSame(100000, self::cents($acc['difference']), 'Zůstatek 314 bez dokladů se ukáže jako rozdíl.');
        self::assertSame([], $this->items($acc));
        self::assertTrue((bool) ($protocol->get('reconciliation')[0]['ok'] ?? false), 'Rekonciliace s POHODOU sedí: ' . json_encode($protocol->get('reconciliation'), JSON_UNESCAPED_UNICODE));
    }

    /** 2) Převzatá záloha uhrazená před otevíracím zápisem se v kontrole nehlásí, nepřevzatá ano. */
    public function testPaidAdvanceCheckSkipsTakenOverAdvancesPaidBeforeOpening(): void
    {
        $this->import(1000.0);
        $pdo = $this->db->pdo();
        $vendor = (int) $pdo->query("SELECT vendor_id FROM purchase_invoices WHERE supplier_id = {$this->supplierId} AND varsymbol = '" . X::PREVIOUS_ADVANCES[0] . "'")->fetchColumn();
        $currency = (int) $pdo->query("SELECT currency_id FROM purchase_invoices WHERE supplier_id = {$this->supplierId} LIMIT 1")->fetchColumn();
        $pdo->prepare(
            "INSERT INTO purchase_invoices (supplier_id, varsymbol, vendor_id, vendor_invoice_number, document_kind, issue_date, due_date, received_at,
                                            currency_id, vendor_snapshot, total_with_vat, status, paid_at, created_by)
             VALUES (?, 'ZP-MYUCTO', ?, 'ZP-MYUCTO', 'advance', '2025-12-01', '2025-12-01', '2025-12-01', ?, '{}', 700, 'paid', '2025-12-05', ?)"
        )->execute([$this->supplierId, $vendor, $currency, $this->userId]);
        $own = (int) $pdo->lastInsertId();

        $flagged = array_map(static fn (array $r): int => (int) $r['id'],
            (new ClosingRepository($this->db))->paidAdvancesWithoutBookedPayment($this->supplierId, X::YEAR . '-12-31'));
        foreach (X::PREVIOUS_ADVANCES as $number) {
            self::assertNotContains($this->purchase($number), $flagged, "Převzatá záloha {$number} uhrazená před počátečním stavem se nehlásí.");
        }
        self::assertContains($own, $flagged, 'Záloha bez mapy převodu se hlásí dál.');
    }

    /**
     * 3) Opravný převod (relinkOnly) nad firmou, kde dřívější převod zálohy minulých let na
     * počáteční stav navázal, ačkoli se do něj nevejdou: vazba zmizí.
     */
    public function testRelinkRemovesEarlierOpeningLinkOfAdvancesExceedingOpening314(): void
    {
        [, $dir] = $this->import(1000.0);
        $pdo = $this->db->pdo();
        $opening = (int) $pdo->query("SELECT id FROM journal_entries WHERE supplier_id = {$this->supplierId} AND source_type = 'opening' AND reversed_by IS NULL ORDER BY id LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, $opening);
        // Stav po dřívější verzi převodu: zálohy minulých let navázané na otevírací zápis.
        $link = $pdo->prepare("INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note, created_by) VALUES (?, ?, 'purchase_invoice', ?, 'Zastoupeno v počátečních stavech', ?)");
        foreach (X::PREVIOUS_ADVANCES as $number) {
            $link->execute([$this->supplierId, $opening, $this->purchase($number), $this->userId]);
        }
        self::assertSame(X::PREVIOUS_ADVANCES, $this->linkedAdvances());
        self::assertSame(150000, self::cents($this->account314()['open_items_total']), 'Před opravou svítí zálohy jako otevřené nad počáteční stav.');

        $relink = $this->importer->run($this->supplierId, $this->userId, PohodaExport::open($dir), false, relinkOnly: true);
        self::assertFalse($relink->hasErrors(), $this->explain($relink));
        self::assertSame([], $this->linkedAdvances(), 'Opravný převod vazbu zálohy na počáteční stav zruší.');
        $info = $this->info($relink);
        self::assertNotNull($info, $this->explain($relink));
        self::assertSame(2, $info['context']['unlinked'] ?? null);
        $acc = $this->account314();
        self::assertSame(100000, self::cents($acc['gl_balance']));
        self::assertSame(0, self::cents($acc['open_items_total']));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0:ImportProtocol,1:string} */
    private function import(float $opening314): array
    {
        $this->supplierId = $this->supplier();
        $dir = X::write($this->tmp);
        X::withPreviousYearAdvances($dir, $opening314);
        $protocol = $this->importer->run($this->supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        foreach (X::PREVIOUS_ADVANCES as $number) {
            self::assertSame('advance', $this->column($number, 'document_kind'), "Záloha {$number} je převedená: " . $this->explain($protocol));
            self::assertSame('paid', $this->column($number, 'status'));
        }
        return [$protocol, $dir];
    }

    /** @return list<string> čísla záloh minulého roku navázaných na otevírací zápis */
    private function linkedAdvances(): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.varsymbol FROM journal_entry_document_links dl
               JOIN journal_entries e ON e.id = dl.entry_id AND e.source_type = 'opening'
               JOIN purchase_invoices p ON p.id = dl.doc_id AND dl.doc_type = 'purchase_invoice'
              WHERE dl.supplier_id = ? AND p.document_kind = 'advance' ORDER BY p.varsymbol"
        );
        $stmt->execute([$this->supplierId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return array<string,mixed>|null */
    private function info(ImportProtocol $protocol): ?array
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ($step['key'] === 'link' ? $step['messages'] : [] as $m) {
                if (($m['code'] ?? '') === 'opening_advances_settled') {
                    return $m;
                }
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function account314(): array
    {
        $periodId = (int) $this->db->pdo()->query("SELECT id FROM accounting_periods WHERE supplier_id = {$this->supplierId} AND fiscal_year = " . X::YEAR)->fetchColumn();
        foreach ($this->saldo->build($this->supplierId, $periodId, X::YEAR . '-01-31', '314')['accounts'] as $acc) {
            if (str_starts_with((string) $acc['account']['code'], '314')) {
                return $acc;
            }
        }
        self::fail('Saldokonto 314 chybí.');
    }

    /**
     * @param array<string,mixed> $acc
     * @return list<array<string,mixed>>
     */
    private function items(array $acc): array
    {
        $out = [];
        foreach ($acc['partners'] as $p) {
            foreach ($p['items'] as $item) {
                $item['doc_no_pohoda'] = (string) $this->db->pdo()->query('SELECT varsymbol FROM purchase_invoices WHERE id = ' . (int) $item['doc_id'])->fetchColumn();
                $out[] = $item;
            }
        }
        return $out;
    }

    private function purchase(string $number): int
    {
        return (int) $this->column($number, 'id');
    }

    private function column(string $number, string $column): ?string
    {
        $stmt = $this->db->pdo()->prepare("SELECT {$column} FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?");
        $stmt->execute([$this->supplierId, $number]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    private function supplier(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, ?, ?, "tax_evidence")'
        )->execute([X::NAME, $this->czId, X::ICO, 'CZ' . X::ICO, $this->anyCurrencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'reconciliation' => $protocol->get('reconciliation')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
