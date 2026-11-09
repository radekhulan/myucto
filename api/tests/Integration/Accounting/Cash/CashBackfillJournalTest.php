<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Cash;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Backfill deníku pokladny (audit 2026-07, nález G5): historický pokladní
 * doklad zaúčtovaný v éře daňové evidence (status='posted', journal_entry_id
 * NULL) se po přepnutí firmy na double_entry doúčtuje přes
 * {@see CashDocumentService::backfillJournal()} — STEJNOU cestou jako běžné
 * zaúčtování (buildLines() + PostingService). Ověřuje:
 *   - doúčtování je vyvážené (Σ MD == Σ D) a mapuje na správné účty,
 *   - druhý běh je idempotentní (žádná duplicita, stejné journal_entry_id),
 *   - previewBackfillLines() (dry-run) nic nezapíše,
 *   - SettingsAction::modeSwitchPreview vrací správný počet čekajících dokladů.
 * Vše v transakci → tearDown rollbackne.
 */
#[Group('integration')]
final class CashBackfillJournalTest extends TestCase
{
    private const YEAR = 2097;

    private Connection $db;
    private CashDocumentService $cash;
    private CashRegisterService $registers;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;
    private SettingsAction $settings;

    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db        = $container->get(Connection::class);
            $this->cash       = $container->get(CashDocumentService::class);
            $this->registers  = $container->get(CashRegisterService::class);
            $this->journal    = $container->get(JournalEntryRepository::class);
            $this->periods    = $container->get(AccountingPeriodRepository::class);
            $this->settings   = $container->get(SettingsAction::class);
            $seeder           = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/user v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        // Izolace: bez cizích pokladních dokladů/zápisů v rámci téhle tx.
        $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM cash_documents WHERE supplier_id = ?')->execute([$this->supplierId]);

        $seeder->seedForSupplier($this->supplierId);
        $this->periods->ensureOpenPeriodFor($this->supplierId, self::YEAR . '-06-15');
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
    }

    public function testBackfillPostsHistoricalDeEraDocAndIsIdempotent(): void
    {
        $register = $this->makeRegister();
        $docId = $this->createHistoricalDeDoc($register['id'], 1210.00);

        // Fixture: doklad byl zaúčtován v daňové evidenci (žurnal-free post path).
        self::assertSame('posted', $this->docStatus($docId));
        self::assertNull($this->docJournalEntryId($docId));

        // Firma teď přepíná na podvojné účetnictví (SettingsAction hook by tohle
        // provedl přes coaSeeder — tady jen simulujeme přepnutý accounting_mode).
        $this->setAccountingMode('double_entry');

        $result = $this->cash->backfillJournal($this->supplierId, $docId);
        self::assertFalse($result['already']);
        self::assertGreaterThan(0, $result['journal_entry_id']);

        $byAcc = $this->linesByAccountCode($result['journal_entry_id']);
        self::assertEqualsWithDelta(1210.00, $byAcc[$register['code']]['debit'], 0.001);
        self::assertEqualsWithDelta(1000.00, $byAcc['602']['credit'], 0.001);
        self::assertEqualsWithDelta(210.00, $byAcc['343.200']['credit'], 0.001);

        self::assertSame($result['journal_entry_id'], $this->docJournalEntryId($docId), 'Doklad je propojen na nový zápis.');
        self::assertSame(1, $this->entryCount(), 'Vznikl právě jeden zápis.');

        $bal = $this->balanceCents();
        self::assertSame($bal['debit'], $bal['credit'], 'Deník je vyvážený (Σ MD == Σ D).');

        // 2. běh — idempotence: doklad má už journal_entry_id, vrací se beze změny.
        $result2 = $this->cash->backfillJournal($this->supplierId, $docId);
        self::assertTrue($result2['already']);
        self::assertSame($result['journal_entry_id'], $result2['journal_entry_id']);
        self::assertSame(1, $this->entryCount(), 'Po druhém běhu stále jen jeden zápis (žádná duplicita).');
    }

    public function testPreviewBackfillLinesIsPureAndWritesNothing(): void
    {
        $register = $this->makeRegister();
        $docId = $this->createHistoricalDeDoc($register['id'], 605.00, ['vat_rate' => 21, 'base_amount' => 500.00, 'vat_amount' => 105.00]);

        $this->setAccountingMode('double_entry');

        $lines = $this->cash->previewBackfillLines($this->supplierId, $docId);
        PostingService::assertBalanced($lines);

        $byCode = [];
        foreach ($lines as $l) {
            $byCode[$l['account_code']][$l['side']] = ($byCode[$l['account_code']][$l['side']] ?? 0.0) + $l['amount'];
        }
        self::assertEqualsWithDelta(605.00, $byCode[$register['code']]['debit'], 0.001);
        self::assertEqualsWithDelta(500.00, $byCode['602']['credit'], 0.001);
        self::assertEqualsWithDelta(105.00, $byCode['343.200']['credit'], 0.001);

        // Dry-run — nic se do dokladu ani deníku nezapsalo.
        self::assertNull($this->docJournalEntryId($docId));
        self::assertSame(0, $this->entryCount());
    }

    public function testBackfillNonPostedDocThrows(): void
    {
        $register = $this->makeRegister();
        $draft = $this->cash->create($this->supplierId, $this->doc([
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 100.00, 'post' => false,
        ], $register['id']), $this->userId);

        $this->setAccountingMode('double_entry');

        $this->expectException(CashException::class);
        $this->expectExceptionMessage('Doúčtovat historii lze jen u zaúčtovaného dokladu.');
        $this->cash->backfillJournal($this->supplierId, (int) $draft['id']);
    }

    public function testModeSwitchPreviewCountsPendingCashDocuments(): void
    {
        $register = $this->makeRegister();
        $this->createHistoricalDeDoc($register['id'], 1210.00);
        $docId2 = $this->createHistoricalDeDoc($register['id'], 605.00, ['vat_rate' => 21, 'base_amount' => 500.00, 'vat_amount' => 105.00]);

        $this->setAccountingMode('double_entry');

        // Doúčtuj jen první — druhý zůstává pending.
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT id FROM cash_documents WHERE supplier_id = ? AND status = 'posted' AND journal_entry_id IS NULL ORDER BY id LIMIT 1");
        $stmt->execute([$this->supplierId]);
        $firstPendingId = (int) $stmt->fetchColumn();
        $this->cash->backfillJournal($this->supplierId, $firstPendingId);

        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/settings/mode-switch-preview')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $resp = $this->settings->modeSwitchPreview($req, new Psr7Response());
        self::assertSame(200, $resp->getStatusCode());

        $body = json_decode((string) $resp->getBody(), true);
        self::assertSame(1, $body['cash_documents'], 'Právě jeden pokladní doklad zůstává bez zápisu v deníku.');
        self::assertGreaterThanOrEqual(1, $body['total']);

        self::assertNull($this->docJournalEntryId($docId2));
    }

    /**
     * BEZ OPRAVY PADÁ: stránka Doúčtování hlásila pokladní doklad (nebo bankovní pohyb)
     * k doúčtování a zároveň „Všechno je zaúčtované" s neaktivním tlačítkem, protože úloha
     * zpracuje jen faktury. Odpověď rozlišuje, co doúčtuje úloha a co čeká na ruční zaúčtování.
     */
    public function testPostingBackfillStatusSeparatesManualQueueFromBackfill(): void
    {
        $register = $this->makeRegister();
        $this->createHistoricalDeDoc($register['id'], 1210.00);
        $this->setAccountingMode('double_entry');

        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/posting-backfill')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $resp = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\PostingBackfillAction::class)
            ->status($req, new Psr7Response());
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());

        $body = json_decode((string) $resp->getBody(), true);
        $pending = $body['pending'];
        self::assertSame(1, $pending['cash_documents']);
        self::assertSame($pending['invoices'] + $pending['purchase_invoices'], $pending['backfill_total']);
        self::assertSame($pending['cash_documents'] + $pending['bank_transactions'] + $pending['settlements'], $pending['manual_total']);
        self::assertSame($pending['total'], $pending['backfill_total'] + $pending['manual_total']);
        self::assertSame($pending['backfill_total'] > 0 ? 'backfill' : 'manual_only', $body['state']);

        // Firma, kde zbývá jen ruční fronta: žádné faktury bez zápisu.
        $this->db->pdo()->prepare('UPDATE invoices SET status = \'draft\' WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET status = \'draft\' WHERE supplier_id = ?')->execute([$this->supplierId]);
        $body = json_decode((string) Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\PostingBackfillAction::class)
            ->status($req, new Psr7Response())->getBody(), true);
        self::assertSame(0, $body['pending']['backfill_total'], json_encode($body['pending']));
        self::assertSame('manual_only', $body['state']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Vytvoří testovací pokladnu na volné analytice 211xxx — supplier v DB může
     * mít z jiné (souběžné) práce už reálnou pokladnu na '211' samotném, proto
     * si test najde volnou analytiku a chybějící účet si sám naseeduje.
     *
     * @return array{id:int, code:string}
     */
    private function makeRegister(): array
    {
        $code = $this->reserveCashAccountCode();
        $id = $this->registers->create($this->supplierId, ['name' => 'Pokladna BF ' . $code, 'account_code' => $code, 'is_default' => true]);
        return ['id' => $id, 'code' => $code];
    }

    private function reserveCashAccountCode(): string
    {
        $pdo = $this->db->pdo();
        foreach (['211900', '211901', '211902', '211903', '211904'] as $code) {
            $stmt = $pdo->prepare('SELECT id FROM cash_registers WHERE supplier_id = ? AND account_code = ?');
            $stmt->execute([$this->supplierId, $code]);
            if ($stmt->fetchColumn() !== false) {
                continue;
            }
            $exists = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
            $exists->execute([$this->supplierId, $code]);
            if ($exists->fetchColumn() === false) {
                $pdo->prepare(
                    'INSERT INTO chart_of_accounts
                        (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
                     VALUES (?, ?, ?, "asset", "debit", 1, NULL, 1, "deductible")'
                )->execute([$this->supplierId, $code, 'Pokladna BF test']);
            }
            return $code;
        }
        self::fail('Nepodařilo se najít volnou testovací analytiku pokladny 211xxx.');
    }

    /**
     * Vytvoří a zaúčtuje pokladní doklad JAKO KDYBY firma v okamžiku vzniku
     * byla v daňové evidenci (žurnal-free post path) — status='posted',
     * journal_entry_id NULL. Simuluje historický doklad z DE éry.
     */
    private function createHistoricalDeDoc(int $registerId, float $total, ?array $vatLine = null): int
    {
        $this->setAccountingMode('tax_evidence');
        $base = round($total / 1.21, 2);
        $vatLine ??= ['vat_rate' => 21, 'base_amount' => $base, 'vat_amount' => round($total - $base, 2)];
        $data = $this->doc([
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => $total,
            'vat_mode' => 'vat', 'vat_lines' => [$vatLine],
        ], $registerId);
        $res = $this->cash->create($this->supplierId, $data, $this->userId);
        return (int) $res['id'];
    }

    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function doc(array $over, int $registerId): array
    {
        return array_merge([
            'register_id' => $registerId,
            'issue_date'  => self::YEAR . '-06-15',
            'description' => 'Pokladní pohyb (historie DE)',
            'post'        => true,
        ], $over);
    }

    private function setAccountingMode(string $mode): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET accounting_mode = ? WHERE id = ?')
            ->execute([$mode, $this->supplierId]);
    }

    private function docStatus(int $id): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM cash_documents WHERE id = ?');
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }

    private function docJournalEntryId(int $id): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT journal_entry_id FROM cash_documents WHERE id = ?');
        $stmt->execute([$id]);
        $v = $stmt->fetchColumn();
        return $v === null || $v === false ? null : (int) $v;
    }

    private function entryCount(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}"
        )->fetchColumn();
    }

    /** @return array{debit:int, credit:int} součty stran v haléřích */
    private function balanceCents(): array
    {
        $row = $this->db->pdo()->query(
            "SELECT
                CAST(ROUND(COALESCE(SUM(CASE WHEN side = 'debit'  THEN amount END), 0) * 100) AS SIGNED) AS debit_cents,
                CAST(ROUND(COALESCE(SUM(CASE WHEN side = 'credit' THEN amount END), 0) * 100) AS SIGNED) AS credit_cents
               FROM journal_entry_lines
              WHERE supplier_id = {$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC);
        return ['debit' => (int) $row['debit_cents'], 'credit' => (int) $row['credit_cents']];
    }

    /**
     * @return array<string,array{debit:float,credit:float}>
     */
    private function linesByAccountCode(int $entryId): array
    {
        $entry = $this->journal->find($entryId, $this->supplierId);
        $out = [];
        foreach ($entry['lines'] as $l) {
            $code = $this->code((int) $l['account_id']);
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][$l['side']] += (float) $l['amount'];
        }
        return $out;
    }

    private function code(int $accountId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_code FROM chart_of_accounts WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$accountId, $this->supplierId]);
        $v = $stmt->fetchColumn();
        return $v === false ? '?' : (string) $v;
    }
}
