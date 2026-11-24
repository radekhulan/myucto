<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\OffsetException;
use MyInvoice\Service\Accounting\OffsetService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Tests\Support\AnalyticAccountFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Integrační testy vzájemných zápočtů (Fáze F).
 *
 * Scénář: FV 10 000 + PF 6 000 (téhož partnera) → zápočet 6 000. Po potvrzení:
 *   • FV zůstane s otevřeným zbytkem 4 000 (částečná úhrada),
 *   • PF plně vyrovnaná (status='paid'),
 *   • jeden vyvážený žurnálový zápis 321 MD 6 000 / 311 D 6 000 (source_type='offset').
 * Druhé potvrzení je idempotentní (nezdvojí platbu ani žurnál).
 *
 * Vše v jedné transakci, tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class OffsetServiceTest extends TestCase
{
    use AnalyticAccountFixture;

    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private OffsetService $offsets;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;
    private InvoicePaymentService $invoicePayments;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db       = $container->get(Connection::class);
            $this->posting  = $container->get(PostingService::class);
            $this->offsets  = $container->get(OffsetService::class);
            $this->periods  = $container->get(AccountingPeriodRepository::class);
            $this->seeder   = $container->get(ChartOfAccountsSeeder::class);
            $this->invoicePayments = $container->get(InvoicePaymentService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $baseSupplier = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($baseSupplier === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $iso = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        );
        $iso->execute(['Zapocet test s.r.o.', 'zapocet@example.com', $baseSupplier]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $this->seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    public function testMutualOffsetDistributesAmountsByPredpisAccount(): void
    {
        $this->seedAnalyticAccounts($this->db->pdo(), $this->supplierId, '311.300', '311.301', '321.200', '321.201');
        $partner = $this->client('Protistrana vzájemného zápočtu');
        $date = self::YEAR . '-03-10';
        $due = self::YEAR . '-03-24';
        $first = $this->invoice($partner, 500.00, $date, $due);
        $second = $this->invoice($partner, 1000.00, $date, $due);
        $purchase = $this->purchaseInvoice($partner, 1500.00, $date, $due);
        $this->posting->postDocument($this->supplierId, 'invoice', $first, [
            self::l('311.300', 'debit', 500.00), self::l('602', 'credit', 500.00),
        ], $this->meta($date));
        $this->posting->postDocument($this->supplierId, 'invoice', $second, [
            self::l('311.301', 'debit', 1000.00), self::l('602', 'credit', 1000.00),
        ], $this->meta($date));
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchase, [
            self::l('518', 'debit', 1500.00), self::l('321.200', 'credit', 1500.00),
        ], $this->meta($date));

        $created = $this->offsets->create($this->supplierId, $partner, $due, [
            ['doc_type' => 'invoice', 'doc_id' => $first, 'amount' => 500.00],
            ['doc_type' => 'invoice', 'doc_id' => $second, 'amount' => 1000.00],
            ['doc_type' => 'purchase_invoice', 'doc_id' => $purchase, 'amount' => 1500.00],
        ], null, $this->userId);
        $confirmed = $this->offsets->confirm($this->supplierId, (int) $created['agreement']['id'], $this->meta($due));
        $stmt = $this->db->pdo()->prepare(
            'SELECT CONCAT(l.side, ?, a.account_code), l.amount
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? ORDER BY l.side, a.account_code'
        );
        $stmt->execute(['|', $confirmed['agreement']['journal_entry_id']]);
        $lines = array_map(self::cents(...), $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        ksort($lines);
        self::assertSame(['credit|311.300' => 50000, 'credit|311.301' => 100000, 'debit|321.200' => 150000], $lines);
    }

    public function testOffsetSettlesBothSidesAndPostsBalancedEntry(): void
    {
        $partner = $this->client('Partner s.r.o.');
        $fv = $this->invoice($partner, 10000.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $pf = $this->purchaseInvoice($partner, 6000.00, self::YEAR . '-03-11', self::YEAR . '-03-25');

        // Zaúčtuj oba doklady (realistický GL).
        $this->posting->postDocument($this->supplierId, 'invoice', $fv, [
            self::l('311', 'debit', 10000.00), self::l('602', 'credit', 10000.00),
        ], $this->meta(self::YEAR . '-03-10'));
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pf, [
            self::l('518', 'debit', 6000.00), self::l('321', 'credit', 6000.00),
        ], $this->meta(self::YEAR . '-03-11'));

        // Otevřené položky partnera obsahují obě strany.
        $open = $this->offsets->openItemsForPartner($this->supplierId, $partner);
        self::assertCount(1, $open['receivables']);
        self::assertCount(1, $open['payables']);
        self::assertSame(self::cents(10000.00), self::cents($open['receivables'][0]['remaining']));
        self::assertSame(self::cents(6000.00), self::cents($open['payables'][0]['remaining']));

        // Sestav dohodu o zápočtu na 6000 (FV 6000 vs PF 6000).
        $created = $this->offsets->create($this->supplierId, $partner, self::YEAR . '-04-01', [
            ['doc_type' => 'invoice', 'doc_id' => $fv, 'amount' => 6000.00],
            ['doc_type' => 'purchase_invoice', 'doc_id' => $pf, 'amount' => 6000.00],
        ], 'Test zápočtu', $this->userId);
        $agreementId = (int) $created['agreement']['id'];
        self::assertSame('draft', $created['agreement']['status']);
        self::assertSame(self::cents(6000.00), self::cents($created['agreement']['total_amount']));
        self::assertStringStartsWith('ZAP-' . self::YEAR . '-', (string) $created['agreement']['document_no']);

        // Potvrzení: vyrovná doklady + zaúčtuje 321/311.
        $confirmed = $this->offsets->confirm($this->supplierId, $agreementId, $this->meta(self::YEAR . '-04-01'));
        self::assertSame('confirmed', $confirmed['agreement']['status']);

        // FV: paid_total 6000, zbytek 4000, stále vystavená (ne plně uhrazená).
        $fvRow = $this->row('SELECT status, paid_total, amount_to_pay FROM invoices WHERE id = ?', [$fv]);
        self::assertSame(self::cents(6000.00), self::cents($fvRow['paid_total']));
        self::assertSame(self::cents(4000.00), self::cents((float) $fvRow['amount_to_pay'] - (float) $fvRow['paid_total']));
        self::assertContains((string) $fvRow['status'], ['issued', 'sent'], 'FV není plně uhrazená → zůstává vystavená.');

        // PF: plně vyrovnaná zápočtem → paid.
        $pfRow = $this->row('SELECT status FROM purchase_invoices WHERE id = ?', [$pf]);
        self::assertSame('paid', (string) $pfRow['status']);

        // Žurnál: jeden zápis source_type='offset', 321 MD 6000 / 311 D 6000, vyvážený.
        $entry = $this->row(
            "SELECT id FROM journal_entries WHERE supplier_id = ? AND source_type = 'offset' AND source_id = ? AND reversed_by IS NULL",
            [$this->supplierId, $agreementId],
        );
        self::assertNotSame([], $entry, 'Zápočet má zaúčtování.');
        $entryId = (int) $entry['id'];
        $sums = $this->row(
            "SELECT SUM(CASE WHEN side='debit' THEN amount ELSE 0 END) AS md,
                    SUM(CASE WHEN side='credit' THEN amount ELSE 0 END) AS d
               FROM journal_entry_lines WHERE entry_id = ?",
            [$entryId],
        );
        self::assertSame(self::cents(6000.00), self::cents($sums['md']), 'Σ MD = 6000.');
        self::assertSame(self::cents(6000.00), self::cents($sums['d']), 'Σ D = 6000.');
        self::assertSame(self::cents($sums['md']), self::cents($sums['d']), 'Zápis je vyvážený.');

        // 321 na straně MD, 311 na straně D.
        $md321 = $this->row(
            "SELECT l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id
              WHERE l.entry_id=? AND a.account_code='321' AND l.side='debit'", [$entryId]);
        $d311 = $this->row(
            "SELECT l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id
              WHERE l.entry_id=? AND a.account_code='311' AND l.side='credit'", [$entryId]);
        self::assertSame(self::cents(6000.00), self::cents($md321['amount'] ?? 0));
        self::assertSame(self::cents(6000.00), self::cents($d311['amount'] ?? 0));
    }

    public function testConfirmIsIdempotent(): void
    {
        $partner = $this->client('Partner2 s.r.o.');
        $fv = $this->invoice($partner, 10000.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $pf = $this->purchaseInvoice($partner, 6000.00, self::YEAR . '-03-11', self::YEAR . '-03-25');

        $created = $this->offsets->create($this->supplierId, $partner, self::YEAR . '-04-01', [
            ['doc_type' => 'invoice', 'doc_id' => $fv, 'amount' => 6000.00],
            ['doc_type' => 'purchase_invoice', 'doc_id' => $pf, 'amount' => 6000.00],
        ], null, $this->userId);
        $agreementId = (int) $created['agreement']['id'];

        $this->offsets->confirm($this->supplierId, $agreementId, $this->meta(self::YEAR . '-04-01'));
        $this->offsets->confirm($this->supplierId, $agreementId, $this->meta(self::YEAR . '-04-01'));

        // Druhé potvrzení nezdvojí platbu ani žurnál.
        $payCount = (int) $this->row('SELECT COUNT(*) AS c FROM invoice_payments WHERE invoice_id = ?', [$fv])['c'];
        self::assertSame(1, $payCount, 'Jen jedna evidovaná platba (idempotence).');

        $entryCount = (int) $this->row(
            "SELECT COUNT(*) AS c FROM journal_entries WHERE supplier_id = ? AND source_type = 'offset' AND source_id = ?",
            [$this->supplierId, $agreementId],
        )['c'];
        self::assertSame(1, $entryCount, 'Jen jeden žurnálový zápis (idempotence).');

        $fvRow = $this->row('SELECT paid_total FROM invoices WHERE id = ?', [$fv]);
        self::assertSame(self::cents(6000.00), self::cents($fvRow['paid_total']), 'paid_total se nezdvojnásobil.');
    }

    public function testUnbalancedOffsetRejected(): void
    {
        $partner = $this->client('Partner3 s.r.o.');
        $fv = $this->invoice($partner, 10000.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $pf = $this->purchaseInvoice($partner, 6000.00, self::YEAR . '-03-11', self::YEAR . '-03-25');

        $this->expectException(OffsetException::class);
        $this->offsets->create($this->supplierId, $partner, self::YEAR . '-04-01', [
            ['doc_type' => 'invoice', 'doc_id' => $fv, 'amount' => 5000.00],
            ['doc_type' => 'purchase_invoice', 'doc_id' => $pf, 'amount' => 6000.00],
        ], null, $this->userId);
    }

    // ── T4 (KRITICKÝ, adversariální review 2026-07): draft nese zbytek ZE SESTAVENÍ,
    //     ne z okamžiku potvrzení — jiná úhrada mezitím musí confirm() odmítnout,
    //     ne vytvořit tichý přeplatek ────────────────────────────────────────────

    public function testConfirmRejectsWhenRemainingReducedSinceDraft(): void
    {
        $partner = $this->client('Partner4 s.r.o.');
        $fv = $this->invoice($partner, 10000.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $pf = $this->purchaseInvoice($partner, 10000.00, self::YEAR . '-03-11', self::YEAR . '-03-25');

        $created = $this->offsets->create($this->supplierId, $partner, self::YEAR . '-04-01', [
            ['doc_type' => 'invoice', 'doc_id' => $fv, 'amount' => 10000.00],
            ['doc_type' => 'purchase_invoice', 'doc_id' => $pf, 'amount' => 10000.00],
        ], null, $this->userId);
        $agreementId = (int) $created['agreement']['id'];

        // Mezitím dorazí JINÁ úhrada FV (banka/pokladna) — zbytek klesne na 5000,
        // ale dohoda pořád nese původních 10000 ze sestavení draftu.
        $this->invoicePayments->recordPayment($fv, 5000.00, self::YEAR . '-04-02', ['source' => 'manual']);

        try {
            $this->offsets->confirm($this->supplierId, $agreementId, $this->meta(self::YEAR . '-04-03'));
            self::fail('Potvrzení mělo být odmítnuto — zbytek dokladu se mezitím snížil.');
        } catch (OffsetException $e) {
            self::assertSame('remaining_changed_since_draft', $e->errorCode);
        }

        // Dohoda zůstává draft, žádné zaúčtování, žádná druhá (zápočtová) platba navíc.
        $agreementRow = $this->row('SELECT status FROM offset_agreements WHERE id = ?', [$agreementId]);
        self::assertSame('draft', (string) $agreementRow['status']);

        $entryCount = (int) $this->row(
            "SELECT COUNT(*) AS c FROM journal_entries WHERE supplier_id = ? AND source_type = 'offset' AND source_id = ?",
            [$this->supplierId, $agreementId],
        )['c'];
        self::assertSame(0, $entryCount, 'Odmítnutý zápočet se nezaúčtoval.');

        $payCount = (int) $this->row('SELECT COUNT(*) AS c FROM invoice_payments WHERE invoice_id = ?', [$fv])['c'];
        self::assertSame(1, $payCount, 'Jen ta jedna mezitímní platba — žádná z odmítnutého zápočtu (žádný tichý přeplatek).');
    }

    // ── T5 (KRITICKÝ, adversariální review 2026-07): dvě souběžné draft dohody na
    //     TÝŽ doklad — potvrzení první ho vyčerpá, potvrzení druhé se MUSÍ odmítnout,
    //     jinak by se doklad naúčtoval dvakrát ─────────────────────────────────────

    public function testSecondDraftOnSameInvoiceRejectedAfterFirstConfirmed(): void
    {
        $partner = $this->client('Partner5 s.r.o.');
        $fv  = $this->invoice($partner, 10000.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $pfA = $this->purchaseInvoice($partner, 10000.00, self::YEAR . '-03-11', self::YEAR . '-03-25');
        $pfB = $this->purchaseInvoice($partner, 10000.00, self::YEAR . '-03-12', self::YEAR . '-03-26');

        // Dvě NEZÁVISLÉ draft dohody, obě proti CELÉMU zbytku téže FV (10000) —
        // create() kontroluje zbytek jen proti CONFIRMED zápočtům, takže obě v
        // okamžiku sestavení projdou (žádná z nich ještě confirmed není).
        $draftA = $this->offsets->create($this->supplierId, $partner, self::YEAR . '-04-01', [
            ['doc_type' => 'invoice', 'doc_id' => $fv, 'amount' => 10000.00],
            ['doc_type' => 'purchase_invoice', 'doc_id' => $pfA, 'amount' => 10000.00],
        ], null, $this->userId);
        $draftB = $this->offsets->create($this->supplierId, $partner, self::YEAR . '-04-01', [
            ['doc_type' => 'invoice', 'doc_id' => $fv, 'amount' => 10000.00],
            ['doc_type' => 'purchase_invoice', 'doc_id' => $pfB, 'amount' => 10000.00],
        ], null, $this->userId);
        $agreementA = (int) $draftA['agreement']['id'];
        $agreementB = (int) $draftB['agreement']['id'];

        // Potvrzení A projde a vyčerpá celý zbytek FV.
        $this->offsets->confirm($this->supplierId, $agreementA, $this->meta(self::YEAR . '-04-01'));

        // Potvrzení B na TÝŽ doklad musí být odmítnuto — jinak by FV dostala
        // druhou platbu 10000 nad rámec skutečné hodnoty (přeplatek).
        try {
            $this->offsets->confirm($this->supplierId, $agreementB, $this->meta(self::YEAR . '-04-01'));
            self::fail('Potvrzení druhé dohody na tentýž doklad mělo být odmítnuto.');
        } catch (OffsetException $e) {
            self::assertSame('remaining_changed_since_draft', $e->errorCode);
        }

        $fvRow = $this->row('SELECT paid_total FROM invoices WHERE id = ?', [$fv]);
        self::assertSame(self::cents(10000.00), self::cents($fvRow['paid_total']), 'FV je uhrazena jen jednou, ne dvakrát.');

        $agreementBRow = $this->row('SELECT status FROM offset_agreements WHERE id = ?', [$agreementB]);
        self::assertSame('draft', (string) $agreementBRow['status'], 'Odmítnutá dohoda zůstává draft, ne confirmed.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function client(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, "Ulice 1", "Praha", "11000", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, 'c' . uniqid() . '@example.com', $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function invoice(int $clientId, float $total, string $issue, string $due): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, client_id, issue_date, due_date, currency_id, created_by, total_with_vat, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "issued")'
        );
        $vs = (string) random_int(1000000000, 1999999999);
        $stmt->execute([$this->supplierId, $vs, $clientId, $issue, $due, $this->currencyId, $this->userId, $total]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchaseInvoice(int $vendorId, float $total, string $issue, string $due): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, varsymbol, vendor_id, vendor_invoice_number, document_kind,
                 issue_date, due_date, received_at, currency_id, vendor_snapshot, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, "invoice", ?, ?, ?, ?, "{}", ?, "received", ?)'
        );
        $vs = 'PF-' . random_int(1000000000, 1999999999);
        $stmt->execute([$this->supplierId, $vs, $vendorId, $vs, $issue, $due, $issue, $this->currencyId, $total, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{entry_date:string, posted_by:int, user_id:int} */
    private function meta(string $date): array
    {
        return ['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId];
    }

    /** @return array{account_code:string, side:string, amount:float} */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>
     */
    private function row(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? [] : $r;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
