<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport as X;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vydané zálohové listy z POHODY v saldokontu ({@see X::withIssuedProformas()}): zálohový list
 * minulého roku bez zaúčtování se nenaváže na otevírací zápis (311 ho v počátečním stavu nemá)
 * a neuhrazená záloha, kterou konečná faktura čerpala před platbou, je položkou 324.
 * Izolovaná firma, transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class PohodaIssuedProformaSaldoTest extends TestCase
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
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_proforma_' . bin2hex(random_bytes(5));
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

    public function testUnbookedPreviousYearProformaStaysOutOfOpeningAndSaldo311(): void
    {
        [$protocol, $dir] = $this->import();
        $previous = $this->invoice(X::PROFORMA_PREVIOUS);
        self::assertGreaterThan(0, $previous, 'Zálohový list minulého roku je převedený: ' . $this->explain($protocol));
        self::assertFalse($this->onOpening($previous), 'Zálohový list bez zaúčtování se na otevírací zápis neváže.');
        self::assertSame(1, $this->linkCount($protocol, 'previous_period_unbooked'));

        $acc = $this->account('311');
        self::assertNotContains($previous, array_column($this->items($acc), 'doc_id'), 'Saldokonto 311 ho neukazuje jako pohledávku.');
        self::assertSame(0, self::cents($acc['difference']), 'Saldokonto 311 = hlavní kniha: ' . json_encode($acc, JSON_UNESCAPED_UNICODE));

        // Vazba z dřívější verze převodu se opakovaným převodem zruší.
        $pdo = $this->db->pdo();
        $opening = (int) $pdo->query("SELECT id FROM journal_entries WHERE supplier_id = {$this->supplierId} AND source_type = 'opening' AND reversed_by IS NULL ORDER BY id LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, $opening);
        $pdo->prepare("INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note, created_by) VALUES (?, ?, 'invoice', ?, 'Zastoupeno v počátečních stavech', ?)")
            ->execute([$this->supplierId, $opening, $previous, $this->userId]);
        $again = $this->importer->run($this->supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertFalse($this->onOpening($previous), 'Opakovaný převod vazbu z dřívějšího převodu zruší.');
    }

    public function testUnpaidProformaDrawnBeforePaymentIsOpenItemOf324(): void
    {
        [$protocol] = $this->import();
        $drawn = $this->invoice(X::PROFORMA_DRAWN_UNPAID);
        self::assertGreaterThan(0, $drawn, $this->explain($protocol));

        $acc = $this->account('324');
        self::assertSame(-60500, self::cents($acc['gl_balance']), 'Hlavní kniha 324 nese debet čerpání 605.');
        $items = $this->items($acc);
        self::assertSame([$drawn], array_column($items, 'doc_id'), 'Položkou 324 je čerpaná a nezaplacená záloha.');
        self::assertSame(-60500, self::cents($items[0]['remaining_czk']));
        self::assertFalse($items[0]['settleable']);
        self::assertSame(0, self::cents($acc['difference']), 'Saldokonto 324 = hlavní kniha.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0:ImportProtocol,1:string} */
    private function import(): array
    {
        $this->supplierId = $this->supplier();
        $dir = X::write($this->tmp);
        X::withIssuedProformas($dir);
        $protocol = $this->importer->run($this->supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        return [$protocol, $dir];
    }

    private function invoice(string $number): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ? AND invoice_type = 'proforma'");
        $stmt->execute([$this->supplierId, $number]);
        return (int) $stmt->fetchColumn();
    }

    private function onOpening(int $invoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM journal_entry_document_links dl JOIN journal_entries e ON e.id = dl.entry_id AND e.source_type = 'opening'
              WHERE dl.supplier_id = ? AND dl.doc_type = 'invoice' AND dl.doc_id = ?"
        );
        $stmt->execute([$this->supplierId, $invoiceId]);
        return $stmt->fetchColumn() !== false;
    }

    private function linkCount(ImportProtocol $protocol, string $key): int
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if ($step['key'] === 'link') {
                return (int) ($step['counts'][$key] ?? 0);
            }
        }
        return 0;
    }

    /** @return array<string,mixed> */
    private function account(string $code): array
    {
        $periodId = (int) $this->db->pdo()->query("SELECT id FROM accounting_periods WHERE supplier_id = {$this->supplierId} AND fiscal_year = " . X::YEAR)->fetchColumn();
        foreach ($this->saldo->build($this->supplierId, $periodId, X::YEAR . '-12-31', $code)['accounts'] as $acc) {
            if (str_starts_with((string) $acc['account']['code'], $code)) {
                return $acc;
            }
        }
        self::fail('Saldokonto ' . $code . ' chybí.');
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
                $out[] = $item;
            }
        }
        return $out;
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
