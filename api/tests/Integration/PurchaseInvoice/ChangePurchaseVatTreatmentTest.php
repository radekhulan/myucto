<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\ChangePurchaseInvoiceVatTreatmentAction;
use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceExpenseKindsAction;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Tests\Support\PurchaseVatFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * POST /api/purchase-invoices/{id}/vat-treatment — oprava DPH zařazení přijaté faktury
 * bez storna, i u zaúčtovaného dokladu. Data jsou syntetická, vše v transakci.
 */
#[Group('integration')]
final class ChangePurchaseVatTreatmentTest extends TestCase
{
    use PurchaseVatFixtureTrait;

    protected function setUp(): void
    {
        $this->setUpPurchaseVatFixture();
    }

    protected function tearDown(): void
    {
        $this->tearDownPurchaseVatFixture();
    }

    public function testAccountantChangesDeductionOfReceivedInvoiceWithAudit(): void
    {
        $id = $this->receivedPurchase();
        self::assertCount(1, $this->ledgerList($id));

        $res = $this->change($id, ['vat_deduction' => 'none'], $this->accountant());

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('none', $res['body']['vat_deduction']);
        self::assertSame([], $this->ledgerList($id), 'Bez nároku → mimo evidenci DPH.');
        $log = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log WHERE action = 'purchase_invoice.vat_treatment_changed' AND entity_id = ? ORDER BY id DESC LIMIT 1"
        );
        $log->execute([$id]);
        $payload = json_decode((string) $log->fetchColumn(), true);
        self::assertSame('full', $payload['before']['vat_deduction'] ?? null);
        self::assertSame('none', $payload['after']['vat_deduction'] ?? null);
        self::assertTrue($payload['vat_ledger_changed'] ?? false);
    }

    /** Případ A: hlavička „bez nároku", rozpis s plným nárokem → oprava rozpisu bez editoru. */
    public function testFixesAllocationDeduction(): void
    {
        $id = $this->receivedPurchase([
            'vat_deduction' => 'none',
            'vat_allocations' => [$this->allocation('Celý doklad', 1000.0, 210.0, 'full')],
        ]);
        $allocationId = (int) $this->repo->find($id, $this->supplierId)['vat_allocations'][0]['id'];

        $res = $this->change($id, ['vat_allocations' => [['id' => $allocationId, 'vat_deduction' => 'none']]], $this->accountant());

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('none', $res['body']['vat_treatment']['effective']);
        self::assertSame([], $res['body']['vat_treatment']['warnings']);
        self::assertSame([], $this->ledgerList($id));
    }

    public function testChangesItemAndHeaderClassification(): void
    {
        $id = $this->receivedPurchase();
        $itemId = (int) $this->repo->find($id, $this->supplierId)['items'][0]['id'];

        $res = $this->change($id, [
            'vat_classification_code' => '42',
            'items' => [['id' => $itemId, 'vat_classification_code' => '42']],
        ], $this->accountant());

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('42', $res['body']['vat_classification_code']);
        self::assertSame('42', $res['body']['items'][0]['vat_classification_code']);
        self::assertNull($this->ledgerList($id)[0]['dphdp3_line'] ?? null);
    }

    public function testRejectsInvalidInputAndForeignItems(): void
    {
        $id = $this->receivedPurchase();
        $other = $this->receivedPurchase();
        $foreignItem = (int) $this->repo->find($other, $this->supplierId)['items'][0]['id'];

        foreach ([
            ['vat_deduction' => 'half'],
            ['vat_deduction' => 'proportional', 'vat_deduction_percent' => 140],
            ['vat_classification_code' => 'ZZZ'],
            ['items' => [['id' => $foreignItem, 'vat_classification_code' => '41']]],
        ] as $body) {
            $res = $this->change($id, $body, $this->accountant());
            self::assertSame(400, $res['status'], json_encode([$body, $res['body']], JSON_UNESCAPED_UNICODE));
        }
        self::assertSame('full', $this->repo->find($id, $this->supplierId)['vat_deduction']);
    }

    public function testRoleWithoutPermissionAndClientAreDenied(): void
    {
        $id = $this->receivedPurchase();
        $catalog = new PermissionCatalog();

        $readonly = $this->change($id, ['vat_deduction' => 'none'],
            new EffectiveRole(5, 'Jen čtení', 'staff', true, $catalog->legacyPreset('readonly'), 'readonly'));
        $client = $this->change($id, ['vat_deduction' => 'none'], new EffectiveRole(6, 'Klient', 'client', true, [
            'purchase_invoices' => AccessLevel::WRITE->value,
            'purchase_invoices.vat_treatment' => AccessLevel::WRITE->value,
        ], 'client'));

        self::assertSame(403, $readonly['status']);
        self::assertSame(403, $client['status'], 'Klient právo nedostane ani poškozeným řádkem matice.');
        self::assertSame('full', $this->repo->find($id, $this->supplierId)['vat_deduction']);
    }

    public function testCancelledInvoiceIsImmutable(): void
    {
        $id = $this->receivedPurchase();
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'cancelled' WHERE id = ?")->execute([$id]);

        self::assertSame(409, $this->change($id, ['vat_deduction' => 'none'], $this->accountant())['status']);
    }

    /** Zaúčtovaný doklad v otevřeném období: přeúčtuje se na místě, DPH z 343 jde do nákladu. */
    public function testPostedInvoiceIsRepostedWithoutCancellation(): void
    {
        [$id, $entryId] = $this->postedPurchase();
        self::assertSame(210.0, $this->vatOn343($entryId));

        $res = $this->change($id, ['vat_deduction' => 'none'], $this->accountant());

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame($entryId, $res['body']['_repost']['entry_id'] ?? null, 'Bez storna — týž zápis.');
        self::assertSame(0.0, $this->vatOn343($entryId));
        self::assertNotSame('cancelled', $res['body']['status']);
    }

    /** Uzavřené období: účetní odmítnuta, správce jen v režimu reconcile. */
    public function testClosedPeriodRequiresAdminAndReconcile(): void
    {
        [$id] = $this->postedPurchase();
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE supplier_id = ? AND fiscal_year = 2096")
            ->execute([$this->supplierId]);
        // Nový požadavek = nový kontejner (zámek dokladu si stav období pamatuje v rámci požadavku).
        $this->container = \MyInvoice\Bootstrap::buildContainer();

        $accountant = $this->change($id, ['vat_deduction' => 'none'], $this->accountant(), ['force' => '1']);
        self::assertSame(409, $accountant['status'], json_encode($accountant['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('period_closed', $accountant['body']['error']['code'] ?? null);

        $noMode = $this->change($id, ['vat_deduction' => 'none'], $this->admin(), ['force' => '1']);
        self::assertSame(422, $noMode['status']);
        self::assertSame('force_mode_required', $noMode['body']['error']['code'] ?? null);
        self::assertSame('full', $this->repo->find($id, $this->supplierId)['vat_deduction']);

        $ok = $this->change($id, ['vat_deduction' => 'none'], $this->admin(), ['force' => '1', 'force_mode' => 'reconcile']);
        self::assertSame(200, $ok['status'], json_encode($ok['body'], JSON_UNESCAPED_UNICODE));
        self::assertArrayHasKey('new_entry_id', $ok['body']['_reconcile'] ?? []);
    }

    /**
     * Podané období DPH: přijatá faktura je chráněná stejně jako vydaná — delegovaná role
     * dostane 409 period_filed (oprava DPH zařazení, členění DPH položek i vynucená úprava
     * vydané faktury), správce projde jen s výslovným potvrzením.
     */
    public function testFiledVatPeriodIsGuardedSymmetricallyWithIssuedInvoices(): void
    {
        $id = $this->receivedPurchase();
        $itemId = (int) $this->repo->find($id, $this->supplierId)['items'][0]['id'];
        $issuedId = $this->issuedInvoice();
        $this->fileVatReturn();

        $accountant = $this->change($id, ['vat_deduction' => 'none'], $this->accountant());
        self::assertSame(409, $accountant['status'], json_encode($accountant['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('period_filed', $accountant['body']['error']['code'] ?? null);
        self::assertStringContainsString('přiznání k DPH za 4/2096', (string) ($accountant['body']['error']['message'] ?? ''));
        self::assertSame('full', $this->repo->find($id, $this->supplierId)['vat_deduction'], 'Odmítnutá změna se vrátí.');

        $kinds = $this->call($this->container->get(SetPurchaseInvoiceExpenseKindsAction::class), 'PUT',
            ['items' => [['id' => $itemId, 'vat_classification_code' => '41']]], ['id' => (string) $id], 'accountant', [], $this->accountant());
        self::assertSame(409, $kinds['status'], json_encode($kinds['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('period_filed', $kinds['body']['error']['code'] ?? null);

        $issuedEditor = new EffectiveRole(7, 'Úprava vystavených', 'staff', true, [
            'invoices' => AccessLevel::WRITE->value,
            'invoices.edit_issued' => AccessLevel::WRITE->value,
        ]);
        $issued = $this->call($this->container->get(UpdateInvoiceAction::class), 'PUT',
            ['due_date' => '2096-06-30'], ['id' => (string) $issuedId], 'accountant', ['force' => '1'], $issuedEditor);
        self::assertSame([409, 'period_filed'], [$issued['status'], $issued['body']['error']['code'] ?? null],
            'Vydaná faktura v témže podaném období vrací týž kód: ' . json_encode($issued['body'], JSON_UNESCAPED_UNICODE));

        $unconfirmed = $this->change($id, ['vat_deduction' => 'none'], $this->admin());
        self::assertSame(409, $unconfirmed['status']);
        self::assertSame('period_filed_confirm_required', $unconfirmed['body']['error']['code'] ?? null);
        self::assertSame('full', $this->repo->find($id, $this->supplierId)['vat_deduction'], 'Bez potvrzení se nic neuloží.');

        $confirmed = $this->change($id, ['vat_deduction' => 'none', 'confirm_filed_period' => true], $this->admin());
        self::assertSame(200, $confirmed['status'], json_encode($confirmed['body'], JSON_UNESCAPED_UNICODE));

        // Změna, která evidencí DPH nepohne, podané období neřeší.
        $other = $this->receivedPurchase([], [$this->item('Mimo předmět', 100.0, null, 'mimo')]);
        $neutral = $this->change($other, ['vat_classification_code' => 'mimo'], $this->accountant());
        self::assertSame(200, $neutral['status'], json_encode($neutral['body'], JSON_UNESCAPED_UNICODE));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $query
     * @return array{status:int, body:array<string,mixed>}
     */
    private function change(int $id, array $body, EffectiveRole $role, array $query = []): array
    {
        return $this->call($this->container->get(ChangePurchaseInvoiceVatTreatmentAction::class), 'POST', $body,
            ['id' => (string) $id], (string) ($role->systemKey ?? 'accountant'), $query, $role);
    }

    private function accountant(): EffectiveRole
    {
        return new EffectiveRole(4, 'Účetní', 'staff', true, (new PermissionCatalog())->legacyPreset('accountant'), 'accountant');
    }

    private function admin(): EffectiveRole
    {
        return new EffectiveRole(2, 'Admin', 'staff', true, [], 'admin');
    }

    /**
     * @param array<string,mixed> $overrides
     * @param list<array<string,mixed>>|null $items
     */
    private function receivedPurchase(array $overrides = [], ?array $items = null): int
    {
        $id = $this->createPurchase($overrides, $items);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'received' WHERE id = ?")->execute([$id]);

        return $id;
    }

    /** @return array{0:int, 1:int} [doklad, zápis] */
    private function postedPurchase(): array
    {
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $periods = $this->container->get(AccountingPeriodRepository::class);
        if ($periods->findForDate($this->supplierId, self::VAT_DATE) === null) {
            $periods->create($this->supplierId, 2096, '2096-01-01', '2096-12-31');
        }
        $id = $this->receivedPurchase();
        $posting = $this->container->get(PostingService::class);
        $entryId = $posting->postDocument($this->supplierId, 'purchase_invoice', $id,
            $posting->buildFromPurchaseInvoice($this->supplierId, $id), [
                'entry_date' => self::VAT_DATE, 'posted' => true, 'posted_by' => $this->userId,
            ]);

        return [$id, $entryId];
    }

    private function vatOn343(int $entryId): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN jel.side = 'debit' THEN jel.amount ELSE -jel.amount END), 0)
               FROM journal_entry_lines jel
               JOIN chart_of_accounts coa ON coa.id = jel.account_id AND coa.supplier_id = jel.supplier_id
              WHERE jel.entry_id = ? AND jel.supplier_id = ? AND coa.account_code LIKE '343%'"
        );
        $stmt->execute([$entryId, $this->supplierId]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    private function issuedInvoice(): int
    {
        $pdo = $this->db->pdo();
        $clientId = (int) $pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn();
        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, invoice_type, client_id, varsymbol, issue_date, tax_date,
                                   due_date, currency_id, status, total_with_vat, created_by)
             VALUES (?, "invoice", ?, ?, ?, ?, "2096-05-10", ?, "issued", 0, ?)'
        )->execute([$this->supplierId, $clientId, 'DPHPI-V-' . bin2hex(random_bytes(3)), self::VAT_DATE, self::VAT_DATE,
            $this->currencyId, $this->userId]);

        return (int) $pdo->lastInsertId();
    }

    private function fileVatReturn(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions
                (supplier_id, form_code, period_year, period_month, period_quarter, form_variant,
                 xml_content, xml_size_bytes, xml_sha256, validation_status, status, submitted_at,
                 summary_json, generated_by)
             VALUES (?, 'dphdp3', 2096, 4, NULL, 'B', '<test/>', 7, ?, 'passed', 'submitted', NOW(), '{}', NULL)"
        )->execute([$this->supplierId, hash('sha256', 'dphpi-test')]);
    }
}
