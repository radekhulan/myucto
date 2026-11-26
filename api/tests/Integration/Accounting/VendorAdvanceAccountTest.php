<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Client\UpdateClientAction;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\Accounting\AdvancePaidAccount;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\InvoiceSettlementService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Účty poskytnutých záloh podle dodavatele ({@see AdvancePaidAccount}): karta dodavatele
 * vede zálohy na 314.610 a daň z daňového dokladu k platbě (DDKP) na 314.611.
 *
 * Syntetický případ shodný s {@see PurchaseAdvanceDeductionTest}: záloha 890,00 zaplacená
 * v září, DDKP 735,54 + 154,46, konečná faktura v říjnu 665,66 + 139,79 s odpočtem zálohy.
 * Úhrada jde na 314.610, DDKP čerpá daň z 314.611 a zúčtování konečnou fakturou nechá
 * obě analytiky na nule. Dodavatel bez nastavení se účtuje beze změny podle předkontace.
 */
#[Group('integration')]
final class VendorAdvanceAccountTest extends BankPostingTestCase
{
    private const ADVANCE = '314.610';
    private const ADVANCE_VAT = '314.611';

    private int $vatRateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->db->pdo();
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 21 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Chybí sazba 21 % v DB.');
        }
        $pdo->prepare("UPDATE supplier SET is_vat_payer = 1, is_identified = 0, dic = 'CZ12345678' WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->analytic(self::ADVANCE, 'Zálohy dodavatel A');
        $this->analytic(self::ADVANCE_VAT, 'Zálohy dodavatel A, DPH');
    }

    public function testBankPaymentOfAdvanceDebitsVendorAdvanceAccount(): void
    {
        $vendor = $this->vendorWithAccounts('Dodavatel banka');
        $advance = $this->purchase('ZPF-BNK', $vendor, 'advance', null, null, '2099-09-05', [[890.00, 0.00]]);

        $byAcc = $this->linesByAccountCode($this->payAdvance($advance, 890.00, '2099-09-05'));

        self::assertSame(89000, self::cents($byAcc[self::ADVANCE]['debit'] ?? 0), 'Úhrada zálohy jde na účet zálohy z karty dodavatele.');
        self::assertSame(89000, self::cents($byAcc['221']['credit'] ?? 0));
        self::assertArrayNotHasKey('314', $byAcc);
    }

    public function testCashPaymentOfAdvanceDebitsVendorAdvanceAccount(): void
    {
        $vendor = $this->vendorWithAccounts('Dodavatel pokladna');
        $advance = $this->purchase('ZPF-CSH', $vendor, 'advance', null, null, '2099-09-05', [[500.00, 0.00]]);
        $register = $this->container->get(CashRegisterService::class)
            ->create($this->supplierId, ['name' => 'Pokladna zálohy', 'account_code' => '211', 'is_default' => true]);

        $doc = $this->container->get(CashDocumentService::class)->create($this->supplierId, [
            'register_id' => $register, 'issue_date' => '2099-09-05', 'description' => 'Záloha hotově', 'post' => true,
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 500.00, 'purchase_invoice_id' => $advance,
        ], $this->userId);

        $byAcc = $this->linesByAccountCode((int) $doc['journal_entry_id']);
        self::assertSame(50000, self::cents($byAcc[self::ADVANCE]['debit'] ?? 0), 'Pokladní úhrada zálohy jde na účet zálohy dodavatele.');
        self::assertArrayNotHasKey('314', $byAcc);
    }

    public function testOffsetOfAdvanceUsesVendorAdvanceAccount(): void
    {
        $vendor = $this->vendorWithAccounts('Dodavatel zápočet');
        $advance = $this->purchase('ZPF-OFS', $vendor, 'advance', null, null, '2099-09-05', [[400.00, 0.00]]);
        $account365 = (int) $this->accounts->findByCode($this->supplierId, '365')['id'];

        $res = $this->container->get(InvoiceSettlementService::class)->create($this->supplierId, 'purchase_invoice', $advance, [
            'settled_on' => '2099-09-06', 'amount' => 400.00, 'account_id' => $account365,
        ], $this->userId);

        $byAcc = $this->linesByAccountCode((int) $res['journal_entry_id']);
        self::assertSame(40000, self::cents($byAcc[self::ADVANCE]['debit'] ?? 0), 'Zápočet zálohy jde na účet zálohy dodavatele.');
        self::assertSame(40000, self::cents($byAcc['365']['credit'] ?? 0));
    }

    public function testTaxDocumentCreditsVendorVatAccount(): void
    {
        [, , $ddkpEntry] = $this->paidAdvanceWithDdkp('ZPF-DPP', $this->vendorWithAccounts('Dodavatel DDKP'));

        $byAcc = $this->linesByAccountCode($ddkpEntry);
        self::assertSame(15446, self::cents($byAcc[self::ADVANCE_VAT]['credit'] ?? 0), 'DDKP čerpá daň z účtu DPH zálohy dodavatele.');
        self::assertArrayNotHasKey(self::ADVANCE, $byAcc);
        self::assertArrayNotHasKey('314', $byAcc);
    }

    /**
     * Úhrada 314.610 MD 890,00, DDKP 314.611 D 154,46. Konečná faktura zúčtuje 321 MD /
     * 314.610 D zbytek 735,54 a daň DDKP převede 314.611 MD / 314.610 D 154,46.
     */
    public function testSettlementClearsBothVendorAnalytics(): void
    {
        [$advance, $txEntry, $ddkpEntry] = $this->paidAdvanceWithDdkp('ZPF-SET', $this->vendorWithAccounts('Dodavatel vyúčtování'));
        $final = $this->finalInvoice('PF-SET', $advance);

        $entryId = $this->postFinal($final);
        $byAcc = $this->linesByAccountCode($entryId);

        self::assertSame(73554, self::cents($byAcc['321']['debit'] ?? 0), '321 MD = zbytek zálohy po DDKP.');
        self::assertSame(89000, self::cents($byAcc[self::ADVANCE]['credit'] ?? 0), '314.610 D = celá úhrada zálohy.');
        self::assertSame(15446, self::cents($byAcc[self::ADVANCE_VAT]['debit'] ?? 0), '314.611 MD = daň DDKP.');
        self::assertArrayNotHasKey('314', $byAcc);
        $this->assertBalanced($entryId);

        $sum = $this->accountSums([$txEntry, $ddkpEntry, $entryId]);
        self::assertSame(0, self::cents($sum[self::ADVANCE] ?? 0), 'Účet zálohy po vyúčtování na nule.');
        self::assertSame(0, self::cents($sum[self::ADVANCE_VAT] ?? 0), 'Účet DPH zálohy po vyúčtování na nule.');
        self::assertSame(8455, self::cents($sum['321'] ?? 0), 'Přeplatek na 321 beze změny proti zálohám na 314.');
    }

    /** Zúčtování odúčtuje účet, na který úhrada skutečně šla, i po pozdější změně karty. */
    public function testSettlementFollowsPostedPaymentAccountAfterVendorChange(): void
    {
        $vendor = $this->vendorWithAccounts('Dodavatel změna');
        $advance = $this->purchase('ZPF-CHG', $vendor, 'advance', null, null, '2099-09-05', [[890.00, 0.00]]);
        $txEntry = $this->payAdvance($advance, 890.00, '2099-09-05');
        $this->container->get(ClientRepository::class)->setAdvancePaidAccounts($vendor, $this->supplierId, [
            'advance_paid_account_code' => self::ADVANCE_VAT,
        ]);

        $final = $this->purchase('PF-CHG', $vendor, 'invoice', $advance, null, '2099-10-15', [[1000.00, 210.00]]);
        $entryId = $this->postFinal($final);
        $byAcc = $this->linesByAccountCode($entryId);

        self::assertSame(89000, self::cents($byAcc['321']['debit'] ?? 0), 'Zúčtování celé zaplacené zálohy.');
        self::assertSame(89000, self::cents($byAcc[self::ADVANCE]['credit'] ?? 0), 'Odúčtuje se účet zaúčtované úhrady.');
        self::assertArrayNotHasKey(self::ADVANCE_VAT, $byAcc);
        self::assertSame(0, self::cents($this->accountSums([$txEntry, $entryId])[self::ADVANCE] ?? 0));
    }

    /**
     * Konečná faktura zaúčtovaná dřív než úhrada zálohy: dorovnání zúčtování
     * ({@see \MyInvoice\Service\Accounting\AdvanceSettlementSync}) dopíše pár na účet zálohy dodavatele.
     */
    public function testPaymentAfterPostedFinalSyncsSettlementToVendorAccount(): void
    {
        $vendor = $this->vendorWithAccounts('Dodavatel dorovnání');
        $advance = $this->purchase('ZPF-SYN', $vendor, 'advance', null, null, '2099-09-05', [[890.00, 0.00]]);
        $final = $this->purchase('PF-SYN', $vendor, 'invoice', $advance, null, '2099-10-15', [[1000.00, 210.00]]);
        $entryId = $this->postFinal($final);
        self::assertArrayNotHasKey(self::ADVANCE, $this->linesByAccountCode($entryId), 'Bez úhrady zálohy se nezúčtovává.');

        $txEntry = $this->payAdvance($advance, 890.00, '2099-10-20');

        $byAcc = $this->linesByAccountCode($entryId);
        self::assertSame(89000, self::cents($byAcc['321']['debit'] ?? 0), 'Dorovnání dopíše 321 MD.');
        self::assertSame(89000, self::cents($byAcc[self::ADVANCE]['credit'] ?? 0), 'A D na účet zálohy dodavatele.');
        $this->assertBalanced($entryId);
        self::assertSame(0, self::cents($this->accountSums([$txEntry, $entryId])[self::ADVANCE] ?? 0));
    }

    /** Dodavatel bez nastavení: úhrada, DDKP i zúčtování podle předkontace firmy. */
    public function testVendorWithoutSettingKeepsCompanyRules(): void
    {
        $default = $this->posting->redirectedAccountCode(
            $this->supplierId,
            (new AdvancePaidAccount($this->db))->rulePaymentAccount($this->supplierId),
        );
        [$advance, $txEntry, $ddkpEntry] = $this->paidAdvanceWithDdkp('ZPF-DEF', $this->client('Dodavatel bez nastavení'));
        $entryId = $this->postFinal($this->finalInvoice('PF-DEF', $advance));

        $payment = $this->linesByAccountCode($txEntry);
        $ddkp = $this->linesByAccountCode($ddkpEntry);
        $final = $this->linesByAccountCode($entryId);
        self::assertSame(89000, self::cents($payment[$default]['debit'] ?? 0));
        self::assertSame(15446, self::cents($ddkp[$default]['credit'] ?? 0));
        self::assertSame(73554, self::cents($final[$default]['credit'] ?? 0), 'Zúčtování jediným párem jako dosud.');
        self::assertSame(0, self::cents($final[$default]['debit'] ?? 0), 'Bez převodu daně mezi účty.');
        foreach ([$payment, $ddkp, $final] as $lines) {
            self::assertArrayNotHasKey(self::ADVANCE, $lines);
            self::assertArrayNotHasKey(self::ADVANCE_VAT, $lines);
        }
        self::assertSame(0, self::cents($this->accountSums([$txEntry, $ddkpEntry, $entryId])[$default] ?? 0));
    }

    public function testVendorSettingValidation(): void
    {
        $resolver = new AdvancePaidAccount($this->db);
        self::assertSame(self::ADVANCE, $resolver->validateVendorSetting($this->supplierId, ' 314.610 ', 'advance_paid_account_code'));
        self::assertNull($resolver->validateVendorSetting($this->supplierId, '', 'advance_paid_account_code'));

        $other = $this->otherSupplierId();
        $this->accounts->insert($other, [
            'account_code' => '314.699', 'name' => 'Cizí záloha', 'account_type' => 'asset', 'normal_side' => 'debit',
            'parent_id' => null, 'is_synthetic' => false, 'is_active' => true,
        ]);
        foreach ([
            '321' => 'invalid_account',            // jiná syntetika
            '3140' => 'invalid_account',           // ne analytika 314 v osnově firmy
            '314.699' => 'invalid_account',        // účet jen v osnově jiné firmy
            '314' => 'account_not_posting_level',  // syntetika s víc analytikami
        ] as $code => $error) {
            try {
                $resolver->validateVendorSetting($this->supplierId, $code, 'advance_paid_vat_account_code');
                self::fail('Účet ' . $code . ' se nesmí přijmout.');
            } catch (PostingException $e) {
                self::assertSame($error, $e->errorCode, 'Účet ' . $code);
                self::assertSame('advance_paid_vat_account_code', $e->context['field'] ?? null);
            }
        }
    }

    public function testClientUpdateStoresValidatesAndGuardsAdvanceAccounts(): void
    {
        $vendor = $this->client('Dodavatel karta');
        $action = $this->container->get(UpdateClientAction::class);
        $args = ['id' => (string) $vendor];

        $ok = $this->callAction($action, '__invoke', 'PUT', 'admin', [
            'advance_paid_account_code' => self::ADVANCE, 'advance_paid_vat_account_code' => self::ADVANCE_VAT,
        ], $args);
        self::assertSame(200, $ok['status'], json_encode($ok['body'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(self::ADVANCE, $ok['body']['advance_paid_account_code'] ?? null, 'Odpověď PUT nese uložený účet zálohy.');
        $stored = $this->container->get(ClientRepository::class)->find($vendor);
        self::assertSame(self::ADVANCE, $stored['advance_paid_account_code']);
        self::assertSame(self::ADVANCE_VAT, $stored['advance_paid_vat_account_code']);

        $bad = $this->callAction($action, '__invoke', 'PUT', 'admin', ['advance_paid_vat_account_code' => '321'], $args);
        self::assertSame(422, $bad['status']);
        self::assertSame('invalid_account', $bad['body']['error']['code'] ?? null);
        self::assertArrayHasKey('advance_paid_vat_account_code', $bad['body']['error']['fields'] ?? []);

        $readonly = $this->callAction($action, '__invoke', 'PUT', 'readonly', ['advance_paid_account_code' => null], $args);
        self::assertSame(403, $readonly['status'], 'Účet záloh mění jen role s právem zápisu do účetnictví.');
        self::assertSame(self::ADVANCE, $this->container->get(ClientRepository::class)->find($vendor)['advance_paid_account_code']);

        $cleared = $this->callAction($action, '__invoke', 'PUT', 'admin', ['advance_paid_account_code' => ''], $args);
        self::assertSame(200, $cleared['status']);
        self::assertNull($this->container->get(ClientRepository::class)->find($vendor)['advance_paid_account_code']);
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    private function analytic(string $code, string $name): void
    {
        if ($this->accounts->findByCode($this->supplierId, $code) !== null) {
            return;
        }
        $parent = $this->accounts->findByCode($this->supplierId, '314');
        self::assertNotNull($parent, 'Osnova firmy musí mít účet 314.');
        $this->accounts->insert($this->supplierId, [
            'account_code' => $code, 'name' => $name, 'account_type' => 'asset', 'normal_side' => 'debit',
            'parent_id' => (int) $parent['id'], 'is_synthetic' => false, 'is_active' => true,
        ]);
    }

    private function vendorWithAccounts(string $name): int
    {
        $vendor = $this->client($name);
        $this->container->get(ClientRepository::class)->setAdvancePaidAccounts($vendor, $this->supplierId, [
            'advance_paid_account_code' => self::ADVANCE,
            'advance_paid_vat_account_code' => self::ADVANCE_VAT,
        ]);
        return $vendor;
    }

    /** @return array{0:int, 1:int, 2:int} záloha, zápis úhrady, zápis DDKP */
    private function paidAdvanceWithDdkp(string $number, int $vendor): array
    {
        $advance = $this->purchase($number, $vendor, 'advance', null, null, '2099-09-05', [[890.00, 0.00]]);
        $txEntry = $this->payAdvance($advance, 890.00, '2099-09-05');
        $ddkp = $this->purchase('DDKP-' . $number, $vendor, 'tax_document', null, $advance, '2099-09-10', [[735.54, 154.46]]);
        $ddkpEntry = $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $ddkp,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $ddkp),
            ['entry_date' => '2099-09-10'],
        );
        return [$advance, $txEntry, $ddkpEntry];
    }

    private function finalInvoice(string $number, int $advance): int
    {
        $vendor = (int) $this->db->pdo()->query('SELECT vendor_id FROM purchase_invoices WHERE id = ' . $advance)->fetchColumn();
        $id = $this->purchase($number, $vendor, 'invoice', $advance, null, '2099-10-15', [[665.66, 139.79], [-735.54, -154.46, $advance]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET rounding = 0.17 WHERE id = ?')->execute([$id]);
        return $id;
    }

    /** @param list<array{0:float, 1:float, 2?:?int}> $items základ, daň, záloha odpočtu */
    private function purchase(string $number, int $vendorId, string $kind, ?int $advanceId, ?int $parentId, string $date, array $items): int
    {
        $base = round(array_sum(array_column($items, 0)), 2);
        $vat = round(array_sum(array_column($items, 1)), 2);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, advance_purchase_invoice_id,
                 parent_purchase_invoice_id, vat_deduction, issue_date, tax_date, due_date, received_at, currency_id,
                 reverse_charge, is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", ?, ?, ?, "full", ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, "received", ?, ?)'
        )->execute([$this->supplierId, $vendorId, $number, $kind, $advanceId, $parentId, $date, $date, $date, $date,
            $this->currencyId, $base, $vat, round($base + $vat, 2), $kind === 'advance' ? null : '40', $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, advance_purchase_invoice_id)
             VALUES (?, ?, 1, 'ks', ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $i => $it) {
            $insert->execute([$id, isset($it[2]) ? 'Odpočet zálohy' : 'Položka', $it[0], $this->vatRateId,
                abs($it[1]) > 0.0 ? 21.00 : 0.00, $it[0], $it[1], round($it[0] + $it[1], 2), $i, $it[2] ?? null]);
        }
        return $id;
    }

    private function payAdvance(int $advanceId, float $amount, string $date): int
    {
        $tx = $this->transaction($this->statement(), -$amount, ['match_status' => 'manual', 'posted_at' => $date]);
        $this->paymentMatch($tx, $advanceId, $amount);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'Úhrada zálohy se má zaúčtovat: ' . ($res['reason'] ?? ''));
        return (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE supplier_id={$this->supplierId} AND source_type='bank' AND source_id={$tx} AND reversed_by IS NULL LIMIT 1"
        )->fetchColumn();
    }

    private function postFinal(int $finalId): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $finalId,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $finalId),
            ['entry_date' => '2099-10-15'],
        );
    }

    /**
     * @param list<int> $entryIds
     * @return array<string,float> Σ (MD − D) po účtech
     */
    private function accountSums(array $entryIds): array
    {
        $sum = [];
        foreach ($entryIds as $entryId) {
            foreach ($this->linesByAccountCode($entryId) as $code => $sides) {
                if ((string) $code === '221') {
                    continue; // alias analytiky banky
                }
                $sum[(string) $code] = round(($sum[(string) $code] ?? 0.0) + $sides['debit'] - $sides['credit'], 2);
            }
        }
        return $sum;
    }

    private function assertBalanced(int $entryId): void
    {
        $debit = 0;
        $credit = 0;
        foreach ($this->journal->find($entryId, $this->supplierId)['lines'] as $l) {
            $cents = self::cents($l['amount']);
            $l['side'] === 'debit' ? $debit += $cents : $credit += $cents;
        }
        self::assertSame($debit, $credit, 'Σ MD == Σ D (v haléřích).');
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round((float) $amount * 100.0);
    }
}
