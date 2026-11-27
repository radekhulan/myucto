<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Client\UpdateClientAction;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\PartnerSettlementAccount;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Účet závazku a pohledávky podle partnera ({@see PartnerSettlementAccount}): karta
 * dodavatele vede závazky na 321.200, firma vede zahraniční dodavatele na 321.300,
 * karta odběratele vede pohledávky na 311.200. Úhrady odúčtují tentýž účet, dobropis
 * jde na účet faktury a bez nastavení se účtuje beze změny podle předkontace.
 *
 * Pod 321 i 311 jsou vždy dvě analytiky, aby se syntetika nepřesměrovala na jedinou
 * analytiku a zápis bez nastavení zůstal na syntetice jako dosud.
 */
#[Group('integration')]
final class PartnerSettlementAccountTest extends BankPostingTestCase
{
    private const DOMESTIC = '321.200';
    private const FOREIGN = '321.300';
    private const RECEIVABLE = '311.200';

    private int $vatRateId = 0;
    private int $deId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->db->pdo();
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 21 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->deId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'DE' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->vatRateId === 0 || $this->deId === 0) {
            self::markTestSkipped('Chybí sazba 21 % nebo země DE v DB.');
        }
        $pdo->prepare("UPDATE supplier SET is_vat_payer = 1, is_identified = 0, dic = 'CZ12345678', country_id = ?,
                              receipt_payable_account_code = NULL, foreign_payable_account_code = NULL WHERE id = ?")
            ->execute([$this->czId, $this->supplierId]);
        $this->analytic('321', '321.100', 'Závazky kupní smlouvy');
        $this->analytic('321', self::DOMESTIC, 'Závazky tuzemské faktury');
        $this->analytic('321', self::FOREIGN, 'Závazky zahraniční faktury');
        $this->analytic('311', '311.100', 'Pohledávky pozemky');
        $this->analytic('311', self::RECEIVABLE, 'Pohledávky pachtovné');
    }

    /** BEZ OPRAVY PADÁ: přijatá faktura i účtenka dodavatele s kartou jdou na 321.200, úhrady ho vyrovnají. */
    public function testVendorCardPostsInvoiceAndReceiptAndPaymentsSettleIt(): void
    {
        $vendor = $this->vendor('Dodavatel tuzemský', self::DOMESTIC);
        $invoice = $this->postedPurchase('SYN-PSA-INV', $vendor, 'invoice');
        $invoiceEntry = $this->documentEntry('purchase_invoice', $invoice);
        $lines = $this->linesByAccountCode($invoiceEntry);
        self::assertSame(['debit' => 0.0, 'credit' => 121.0], $lines[self::DOMESTIC] ?? null, 'Závazek faktury na účtu z karty.');
        self::assertArrayNotHasKey('321', $lines);

        $tx = $this->transaction($this->statement(), -121.0, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $invoice, 121.0);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], (string) json_encode($res, JSON_UNESCAPED_UNICODE));
        $bankEntry = $this->documentEntry('bank', $tx);
        $bank = $this->linesByAccountCode($bankEntry);
        self::assertSame(['debit' => 121.0, 'credit' => 0.0], $bank[self::DOMESTIC] ?? null, 'Úhrada bankou odúčtuje účet z karty.');
        self::assertArrayNotHasKey('321', $bank);
        self::assertSame(0.0, $this->balance(self::DOMESTIC, [$invoiceEntry, $bankEntry]));

        // Karta má přednost i před účtem závazku pro účtenky.
        $this->db->pdo()->prepare("UPDATE supplier SET receipt_payable_account_code = '325' WHERE id = ?")->execute([$this->supplierId]);
        $receipt = $this->postedPurchase('SYN-PSA-RCPT', $vendor, 'receipt');
        $receiptEntry = $this->documentEntry('purchase_invoice', $receipt);
        $receiptLines = $this->linesByAccountCode($receiptEntry);
        self::assertSame(['debit' => 0.0, 'credit' => 121.0], $receiptLines[self::DOMESTIC] ?? null, 'Účtenka na účtu z karty.');
        self::assertArrayNotHasKey('325', $receiptLines);

        $cashEntry = $this->payCash($receipt, 121.0);
        self::assertSame(['debit' => 121.0, 'credit' => 0.0], $this->linesByAccountCode($cashEntry)[self::DOMESTIC] ?? null, 'Úhrada hotově odúčtuje účet z karty.');
        self::assertSame(0.0, $this->balance(self::DOMESTIC, [$receiptEntry, $cashEntry]));
    }

    /** BEZ OPRAVY PADÁ: zahraniční dodavatel bez karty jde na firemní 321.300, karta má přednost. */
    public function testForeignVendorUsesCompanySettingUnlessCardSet(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET foreign_payable_account_code = ? WHERE id = ?')
            ->execute([self::FOREIGN, $this->supplierId]);

        $foreign = $this->vendor('Dodavatel DE', null, $this->deId);
        $lines = $this->linesByAccountCode($this->documentEntry('purchase_invoice', $this->postedPurchase('SYN-PSA-DE', $foreign, 'invoice')));
        self::assertSame(121.0, $lines[self::FOREIGN]['credit'] ?? null, 'Zahraniční dodavatel na účtu firmy pro zahraniční dodavatele.');

        $foreignWithCard = $this->vendor('Dodavatel DE s kartou', self::DOMESTIC, $this->deId);
        $lines = $this->linesByAccountCode($this->documentEntry('purchase_invoice', $this->postedPurchase('SYN-PSA-DEC', $foreignWithCard, 'invoice')));
        self::assertSame(121.0, $lines[self::DOMESTIC]['credit'] ?? null, 'Karta dodavatele má přednost před zahraničním nastavením.');
        self::assertArrayNotHasKey(self::FOREIGN, $lines);

        $clients = $this->container->get(ClientRepository::class);
        self::assertSame(self::FOREIGN, $clients->find($foreign)['effective_payable_account_code'] ?? null, 'Karta klienta nese závazek pro nápovědu v editoru.');
        self::assertSame(self::DOMESTIC, $clients->find($foreignWithCard)['effective_payable_account_code'] ?? null);

        $domestic = $this->vendor('Dodavatel CZ bez karty', null);
        self::assertNull($clients->find($domestic)['effective_payable_account_code'] ?? null);
        $lines = $this->linesByAccountCode($this->documentEntry('purchase_invoice', $this->postedPurchase('SYN-PSA-CZ', $domestic, 'invoice')));
        self::assertSame(121.0, $lines['321']['credit'] ?? null, 'Tuzemský dodavatel bez karty zůstává na předkontaci.');
    }

    /** Protiúčet řádku a ručně zvolená předkontace mají přednost před kartou. */
    public function testLineCounterAndExplicitRuleBeatCard(): void
    {
        $vendor = $this->vendor('Dodavatel s protiúčtem', self::DOMESTIC);
        $withCounter = $this->purchase('SYN-PSA-CNT', $vendor, 'invoice', 121.0, '325');
        $lines = $this->signature($this->posting->buildFromPurchaseInvoice($this->supplierId, $withCounter));
        self::assertContains('325|credit|121.00', $lines, 'Protiúčet řádku má přednost před kartou.');

        $plain = $this->purchase('SYN-PSA-RULE', $vendor, 'invoice', 121.0);
        $lines = $this->signature($this->posting->buildFromPurchaseInvoice($this->supplierId, $plain, ['rule_key' => 'invoice.services.received']));
        self::assertContains('321|credit|121.00', $lines, 'Ručně zvolená předkontace má přednost před kartou.');
    }

    /** BEZ OPRAVY PADÁ: odběratel s 311.200 — celý předpis včetně DPH, úhrada i dobropis na 311.200. */
    public function testCustomerCardCarriesWholeReceivableAndPaymentAndCreditNote(): void
    {
        $customer = $this->customer('Odběratel pacht', self::RECEIVABLE);
        $invoice = $this->issued('SYNPSA01', $customer, 'invoice', 10000.0, 2100.0);
        $invoiceEntry = $this->postIssued($invoice);
        $lines = $this->linesByAccountCode($invoiceEntry);
        self::assertSame(['debit' => 12100.0, 'credit' => 0.0], $lines[self::RECEIVABLE] ?? null, 'Pohledávka včetně DPH na účtu z karty.');
        self::assertArrayNotHasKey('311', $lines, 'DPH nezakládá druhou pohledávku na 311.');
        $vat = 0.0;
        foreach ($lines as $code => $sides) {
            $vat += str_starts_with((string) $code, '343') ? $sides['credit'] : 0.0;
        }
        self::assertSame(2100.0, round($vat, 2), 'DPH na výstupu beze změny.');

        $tx = $this->transaction($this->statement(), 12100.0, ['match_status' => 'auto_exact', 'matched_invoice_id' => $invoice]);
        $this->invoicePayment($invoice, $tx, 12100.0);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], (string) json_encode($res, JSON_UNESCAPED_UNICODE));
        $bankEntry = $this->documentEntry('bank', $tx);
        $bank = $this->linesByAccountCode($bankEntry);
        self::assertSame(['debit' => 0.0, 'credit' => 12100.0], $bank[self::RECEIVABLE] ?? null, 'Úhrada bankou odúčtuje 311.200.');
        self::assertSame(0.0, $this->balance(self::RECEIVABLE, [$invoiceEntry, $bankEntry]));

        $creditNote = $this->issued('SYNPSA02', $customer, 'credit_note', -1000.0, -210.0, $invoice);
        $creditLines = $this->linesByAccountCode($this->postIssued($creditNote));
        self::assertSame(['debit' => 0.0, 'credit' => 1210.0], $creditLines[self::RECEIVABLE] ?? null, 'Dobropis na účtu faktury.');
        self::assertArrayNotHasKey('311', $creditLines);
    }

    /** BEZ OPRAVY PADÁ: dobropis jde na účet, na kterém faktura v deníku leží, i po pozdější změně karty. */
    public function testPurchaseCreditNoteFollowsPostedInvoiceAccount(): void
    {
        $vendor = $this->vendor('Dodavatel dobropis', null);
        $invoice = $this->postedPurchase('SYN-PSA-CN', $vendor, 'invoice');
        self::assertSame(121.0, $this->linesByAccountCode($this->documentEntry('purchase_invoice', $invoice))['321']['credit'] ?? null);

        $this->container->get(ClientRepository::class)->setPartnerSettlementAccounts($vendor, $this->supplierId, [
            PartnerSettlementAccount::PAYABLE_FIELD => self::DOMESTIC,
        ]);
        $creditNote = $this->postedPurchase('SYN-PSA-CN-D', $vendor, 'credit_note', -121.0, $invoice);
        $lines = $this->linesByAccountCode($this->documentEntry('purchase_invoice', $creditNote));
        self::assertSame(['debit' => 121.0, 'credit' => 0.0], $lines['321'] ?? null, 'Dobropis vyrovná 321, na kterém faktura leží.');
        self::assertArrayNotHasKey(self::DOMESTIC, $lines);

        $next = $this->postedPurchase('SYN-PSA-CN-N', $vendor, 'invoice');
        self::assertSame(121.0, $this->linesByAccountCode($this->documentEntry('purchase_invoice', $next))[self::DOMESTIC]['credit'] ?? null, 'Nová faktura už jde na kartu.');
    }

    /** BEZ OPRAVY PADÁ: zúčtování zaplacené zálohy jde proti závazku z karty, na kterém je faktura. */
    public function testAdvanceSettlementUsesSamePayable(): void
    {
        $vendor = $this->vendor('Dodavatel záloha', self::DOMESTIC);
        $advance = $this->purchase('SYN-PSA-ZAL', $vendor, 'advance', 890.0);
        $tx = $this->transaction($this->statement(), -890.0, ['match_status' => 'manual', 'posted_at' => self::YEAR . '-06-05']);
        $this->paymentMatch($tx, $advance, 890.0);
        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);

        $final = $this->purchaseWithVat('SYN-PSA-FIN', $vendor, $advance, 1000.0, 210.0);
        $entry = $this->posting->postDocument(
            $this->supplierId, 'purchase_invoice', $final,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $final),
            ['entry_date' => self::YEAR . '-06-15'],
        );
        $lines = $this->linesByAccountCode($entry);
        self::assertSame(['debit' => 890.0, 'credit' => 1210.0], $lines[self::DOMESTIC] ?? null, 'Předpis i zúčtování zálohy na 321.200.');
        self::assertArrayNotHasKey('321', $lines);
    }

    /** BEZ OPRAVY PADÁ: penále jde na pohledávku z karty odběratele, ruční předkontace má přednost. */
    public function testPenaltyUsesCustomerCard(): void
    {
        $customer = $this->customer('Odběratel penále', self::RECEIVABLE);
        $penalty = $this->issued('SYNPSA04', $customer, 'penalty', 500.0, 0.0);
        $lines = $this->signature($this->posting->buildFromInvoice($this->supplierId, $penalty));
        self::assertContains(self::RECEIVABLE . '|debit|500.00', $lines, 'Penále na pohledávce z karty.');
        self::assertNotContains('311|debit|500.00', $lines);

        $manual = $this->signature($this->posting->buildFromInvoice($this->supplierId, $penalty, ['rule_key' => 'invoice.penalty.issued']));
        self::assertContains('311|debit|500.00', $manual, 'Ručně zvolená předkontace má přednost.');

        $plain = $this->issued('SYNPSA05', $this->customer('Odběratel penále bez karty', null), 'penalty', 500.0, 0.0);
        self::assertContains('311|debit|500.00', $this->signature($this->posting->buildFromInvoice($this->supplierId, $plain)), 'Bez karty beze změny.');
    }

    /**
     * BEZ OPRAVY PADÁ: uhrazená přijatá faktura zůstane při přeúčtování na účtu, na kterém
     * je zaúčtovaná a který úhrada vyrovnala; neuhrazená přejde na nový účet z karty.
     */
    public function testRepostKeepsPostedPayableOfPaidPurchaseOnly(): void
    {
        $vendor = $this->vendor('Dodavatel přeúčtování', null);
        $paid = $this->postedPurchase('SYN-PSA-RP-PAID', $vendor, 'invoice');
        $unpaid = $this->postedPurchase('SYN-PSA-RP-OPEN', $vendor, 'invoice');
        $tx = $this->transaction($this->statement(), -50.0, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $paid, 50.0);
        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);

        $this->container->get(ClientRepository::class)->setPartnerSettlementAccounts($vendor, $this->supplierId, [
            PartnerSettlementAccount::PAYABLE_FIELD => self::DOMESTIC,
        ]);
        $sync = $this->container->get(DocumentJournalSync::class);
        $sync->repostForceEdit($this->supplierId, 'purchase_invoice', $paid, ['user_id' => $this->userId, 'posted_by' => $this->userId]);
        $sync->repostForceEdit($this->supplierId, 'purchase_invoice', $unpaid, ['user_id' => $this->userId, 'posted_by' => $this->userId]);

        $paidLines = $this->linesByAccountCode($this->documentEntry('purchase_invoice', $paid));
        self::assertSame(121.0, $paidLines['321']['credit'] ?? null, 'Částečně uhrazená faktura zůstává na 321.');
        self::assertArrayNotHasKey(self::DOMESTIC, $paidLines);
        self::assertSame(121.0, $this->linesByAccountCode($this->documentEntry('purchase_invoice', $unpaid))[self::DOMESTIC]['credit'] ?? null,
            'Neuhrazená faktura přejde na účet z karty.');
    }

    /** BEZ OPRAVY PADÁ: přeúčtování stornem a novým zápisem předá účet stornovaného zápisu. */
    public function testRepostAfterReversalKeepsPostedPayableWhenPassed(): void
    {
        $vendor = $this->vendor('Dodavatel storno', null);
        $paid = $this->postedPurchase('SYN-PSA-RV', $vendor, 'invoice');
        $this->payCash($paid, 121.0);
        $this->container->get(ClientRepository::class)->setPartnerSettlementAccounts($vendor, $this->supplierId, [
            PartnerSettlementAccount::PAYABLE_FIELD => self::DOMESTIC,
        ]);
        $posted = (new PartnerSettlementAccount($this->db))->postedSettlementAccount($this->supplierId, 'purchase_invoice', $paid);
        self::assertSame('321', $posted);
        $this->posting->reverse($this->supplierId, $this->documentEntry('purchase_invoice', $paid), ['user_id' => $this->userId]);

        self::assertContains(self::DOMESTIC . '|credit|121.00', $this->signature($this->posting->buildFromPurchaseInvoice($this->supplierId, $paid)),
            'Bez živého zápisu a bez předaného účtu platí karta.');
        self::assertContains('321|credit|121.00', $this->signature($this->posting->buildFromPurchaseInvoice(
            $this->supplierId, $paid, ['posted_saldo_account' => $posted],
        )), 'S účtem stornovaného zápisu zůstává uhrazený doklad na něm.');
    }

    /** BEZ OPRAVY PADÁ: uhrazená vydaná faktura zůstává na pohledávce svého zápisu. */
    public function testRepostKeepsPostedReceivableOfPaidInvoice(): void
    {
        $customer = $this->customer('Odběratel přeúčtování', null);
        $invoice = $this->issued('SYNPSA06', $customer, 'invoice', 1000.0, 210.0);
        $this->postIssued($invoice);
        $tx = $this->transaction($this->statement(), 500.0, ['match_status' => 'auto_exact', 'matched_invoice_id' => $invoice]);
        $this->invoicePayment($invoice, $tx, 500.0);
        $this->db->pdo()->prepare('UPDATE invoices SET paid_total = 500 WHERE id = ?')->execute([$invoice]);
        $open = $this->issued('SYNPSA07', $customer, 'invoice', 1000.0, 210.0);
        $this->postIssued($open);

        $this->db->pdo()->prepare('UPDATE clients SET receivable_account_code = ? WHERE id = ?')->execute([self::RECEIVABLE, $customer]);
        self::assertSame('311|debit|1210.00', $this->signature($this->posting->buildFromInvoice($this->supplierId, $invoice))[0], 'Uhrazená zůstává na 311.');
        self::assertSame(self::RECEIVABLE . '|debit|1210.00', $this->signature($this->posting->buildFromInvoice($this->supplierId, $open))[0], 'Neuhrazená přejde na kartu.');
    }

    /** BEZ OPRAVY PADÁ: totéž pravidlo platí pro účet závazku pro účtenky. */
    public function testRepostKeepsPostedPayableOfPaidReceiptWithReceiptSetting(): void
    {
        $vendor = $this->vendor('Dodavatel účtenky', null);
        $paid = $this->postedPurchase('SYN-PSA-RC-PAID', $vendor, 'receipt');
        $unpaid = $this->postedPurchase('SYN-PSA-RC-OPEN', $vendor, 'receipt');
        $this->payCash($paid, 121.0);
        $this->db->pdo()->prepare("UPDATE supplier SET receipt_payable_account_code = '325' WHERE id = ?")->execute([$this->supplierId]);

        self::assertContains('321|credit|121.00', $this->signature($this->posting->buildFromPurchaseInvoice($this->supplierId, $paid)), 'Uhrazená účtenka zůstává na 321.');
        self::assertContains('325|credit|121.00', $this->signature($this->posting->buildFromPurchaseInvoice($this->supplierId, $unpaid)), 'Neuhrazená přejde na 325.');
    }

    /** Bez nastavení na kartě i ve firmě: zápis přijaté i vydané faktury beze změny. */
    public function testWithoutSettingsPostingIsUnchanged(): void
    {
        $vendor = $this->vendor('Dodavatel bez nastavení', null, $this->deId);
        $purchase = $this->purchase('SYN-PSA-DEF', $vendor, 'invoice', 121.0);
        self::assertSame(['518|debit|121.00', '321|credit|121.00'], $this->signature($this->posting->buildFromPurchaseInvoice($this->supplierId, $purchase)));

        $customer = $this->customer('Odběratel bez nastavení', null);
        $invoice = $this->issued('SYNPSA03', $customer, 'invoice', 10000.0, 2100.0);
        $lines = $this->signature($this->posting->buildFromInvoice($this->supplierId, $invoice));
        self::assertSame('311|debit|12100.00', $lines[0]);
        self::assertSame([], array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, '311.'))));
    }

    public function testValidation(): void
    {
        $resolver = new PartnerSettlementAccount($this->db);
        self::assertSame(self::DOMESTIC, $resolver->validatePayable($this->supplierId, ' 321.200 ', 'payable_account_code'));
        self::assertSame(self::RECEIVABLE, $resolver->validateReceivable($this->supplierId, self::RECEIVABLE));
        self::assertNull($resolver->validatePayable($this->supplierId, '', 'payable_account_code'));

        $other = $this->otherSupplierId();
        $this->accounts->insert($other, [
            'account_code' => '321.999', 'name' => 'Cizí závazek', 'account_type' => 'liability', 'normal_side' => 'credit',
            'parent_id' => null, 'is_synthetic' => false, 'is_active' => true,
        ]);
        foreach ([
            ['payable', '321', 'account_not_posting_level'],  // syntetika s analytikami
            ['payable', self::RECEIVABLE, 'invalid_account'],  // pohledávka není závazek
            ['payable', '343', 'invalid_account'],              // DPH
            ['payable', '518', 'invalid_account'],              // náklad
            ['payable', '321.999', 'invalid_account'],          // účet jen v osnově jiné firmy
            ['receivable', '311', 'account_not_posting_level'],
            ['receivable', self::DOMESTIC, 'invalid_account'],
            ['receivable', '314', 'invalid_account'],
            ['receivable', '315', 'invalid_account'],           // mimo syntetiku úhrady pohledávek
        ] as [$kind, $code, $error]) {
            try {
                $kind === 'payable'
                    ? $resolver->validatePayable($this->supplierId, $code, 'foreign_payable_account_code')
                    : $resolver->validateReceivable($this->supplierId, $code);
                self::fail('Účet ' . $code . ' se nesmí přijmout jako ' . $kind . '.');
            } catch (PostingException $e) {
                self::assertSame($error, $e->errorCode, $kind . ' ' . $code);
                self::assertSame(422, $e->httpStatus);
                self::assertSame($kind === 'payable' ? 'foreign_payable_account_code' : 'receivable_account_code', $e->context['field'] ?? null);
            }
        }
    }

    public function testClientUpdateStoresValidatesAndGuardsSettlementAccounts(): void
    {
        $client = $this->client('Partner karta');
        $action = $this->container->get(UpdateClientAction::class);
        $args = ['id' => (string) $client];

        $ok = $this->callAction($action, '__invoke', 'PUT', 'admin', [
            'payable_account_code' => self::DOMESTIC, 'receivable_account_code' => self::RECEIVABLE,
        ], $args);
        self::assertSame(200, $ok['status'], json_encode($ok['body'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(self::DOMESTIC, $ok['body']['payable_account_code'] ?? null, 'Odpověď PUT nese uložený účet závazku.');
        self::assertSame(self::RECEIVABLE, $ok['body']['receivable_account_code'] ?? null);

        $bad = $this->callAction($action, '__invoke', 'PUT', 'admin', ['receivable_account_code' => self::DOMESTIC], $args);
        self::assertSame(422, $bad['status']);
        self::assertSame('invalid_account', $bad['body']['error']['code'] ?? null);
        self::assertArrayHasKey('receivable_account_code', $bad['body']['error']['fields'] ?? []);

        $readonly = $this->callAction($action, '__invoke', 'PUT', 'readonly', ['payable_account_code' => null], $args);
        self::assertSame(403, $readonly['status'], 'Účet závazku mění jen role s právem zápisu do účetnictví.');
        self::assertSame(self::DOMESTIC, $this->container->get(ClientRepository::class)->find($client)['payable_account_code']);

        $same = $this->callAction($action, '__invoke', 'PUT', 'readonly', ['payable_account_code' => self::DOMESTIC], $args);
        self::assertNotSame(403, $same['status'], 'Nezměněný účet z formuláře oprávnění účetnictví nevyžaduje.');

        $cleared = $this->callAction($action, '__invoke', 'PUT', 'admin', ['payable_account_code' => ''], $args);
        self::assertSame(200, $cleared['status']);
        self::assertNull($this->container->get(ClientRepository::class)->find($client)['payable_account_code']);
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    private function analytic(string $parentCode, string $code, string $name): void
    {
        if ($this->accounts->findByCode($this->supplierId, $code) !== null) {
            return;
        }
        $parent = $this->accounts->findByCode($this->supplierId, $parentCode);
        self::assertNotNull($parent, 'Osnova firmy musí mít účet ' . $parentCode . '.');
        $this->accounts->insert($this->supplierId, [
            'account_code' => $code, 'name' => $name, 'account_type' => $parent['account_type'], 'normal_side' => $parent['normal_side'],
            'parent_id' => (int) $parent['id'], 'is_synthetic' => false, 'is_active' => true,
        ]);
    }

    private function vendor(string $name, ?string $payable, ?int $countryId = null): int
    {
        $id = $this->client($name);
        $this->db->pdo()->prepare('UPDATE clients SET country_id = ?, payable_account_code = ? WHERE id = ?')
            ->execute([$countryId ?? $this->czId, $payable, $id]);
        return $id;
    }

    private function customer(string $name, ?string $receivable): int
    {
        $id = $this->client($name);
        $this->db->pdo()->prepare('UPDATE clients SET receivable_account_code = ? WHERE id = ?')->execute([$receivable, $id]);
        return $id;
    }

    /** Přijatý doklad bez DPH (jedna položka), volitelně s protiúčtem řádku. */
    private function purchase(string $number, int $vendorId, string $kind, float $total, ?string $counter = null, ?int $parentId = null): int
    {
        $id = $this->purchaseInvoice($number, $vendorId, $total, $kind);
        if ($parentId !== null) {
            $this->db->pdo()->prepare('UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')->execute([$parentId, $id]);
        }
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, counter_account_code)
             VALUES (?, 'Syntetická položka', 1, 'ks', ?, (SELECT id FROM vat_rates ORDER BY id LIMIT 1), 0, ?, 0, ?, 0, ?)"
        )->execute([$id, $total, $total, $total, $counter]);
        return $id;
    }

    private function postedPurchase(string $number, int $vendorId, string $kind, float $total = 121.0, ?int $parentId = null): int
    {
        $id = $this->purchase($number, $vendorId, $kind, $total, null, $parentId);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $id, $this->posting->buildFromPurchaseInvoice($this->supplierId, $id), [
            'entry_date' => self::YEAR . '-06-10', 'posted' => true, 'posted_by' => $this->userId,
        ]);
        return $id;
    }

    /** Konečná přijatá faktura s DPH navázaná na zálohu (odečet zálohy v hlavičce). */
    private function purchaseWithVat(string $number, int $vendorId, int $advanceId, float $base, float $vat): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, advance_purchase_invoice_id,
                 vat_deduction, issue_date, tax_date, due_date, received_at, currency_id,
                 reverse_charge, is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", "invoice", ?, "full", ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $advanceId, self::YEAR . '-06-15', self::YEAR . '-06-15', self::YEAR . '-06-15',
            self::YEAR . '-06-15', $this->currencyId, $base, $vat, round($base + $vat, 2), $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Plnění', 1, 'ks', ?, ?, 21.00, ?, ?, ?, 0)"
        )->execute([$id, $base, $this->vatRateId, $base, $vat, round($base + $vat, 2)]);
        return $id;
    }

    private function issued(string $varsymbol, int $clientId, string $type, float $base, float $vat, ?int $parentId = null): int
    {
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-06-10';
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, parent_invoice_id, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, prices_include_vat, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, 0, "issued", "1", ?)'
        )->execute([$this->supplierId, $varsymbol, $type, $parentId, $clientId, $date, $date, $date,
            $this->currencyId, $base, $vat, round($base + $vat, 2), $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Pachtovné', 1, 'ks', ?, ?, 21.00, ?, ?, ?, 0)"
        )->execute([$id, $base, $this->vatRateId, $base, $vat, round($base + $vat, 2)]);
        return $id;
    }

    private function postIssued(int $invoiceId): int
    {
        return $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $this->posting->buildFromInvoice($this->supplierId, $invoiceId), [
            'entry_date' => self::YEAR . '-06-10', 'posted' => true, 'posted_by' => $this->userId,
        ]);
    }

    private function payCash(int $purchaseId, float $amount): int
    {
        $register = $this->container->get(CashRegisterService::class)->create(
            $this->supplierId,
            ['name' => 'Pokladna ' . $purchaseId, 'account_code' => '211', 'is_default' => false],
        );
        $doc = $this->container->get(CashDocumentService::class)->create($this->supplierId, [
            'register_id' => $register, 'issue_date' => self::YEAR . '-06-12', 'description' => 'Úhrada účtenky',
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => $amount,
            'purchase_invoice_id' => $purchaseId, 'post' => true,
        ], $this->userId);
        return $this->documentEntry('cash', (int) $doc['id']);
    }

    private function documentEntry(string $sourceType, int $sourceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND reversed_by IS NULL
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$this->supplierId, $sourceType, $sourceId]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id, 'Chybí zápis ' . $sourceType . ' #' . $sourceId);
        return $id;
    }

    /** @param list<int> $entryIds MD − D účtu přes zadané zápisy. */
    private function balance(string $code, array $entryIds): float
    {
        $in = implode(',', array_map('intval', $entryIds));
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.entry_id IN ({$in}) AND a.account_code = ?"
        );
        $stmt->execute([$this->supplierId, $code]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<string>
     */
    private function signature(array $lines): array
    {
        return array_map(
            static fn (array $l): string => $l['account_code'] . '|' . $l['side'] . '|' . number_format((float) $l['amount'], 2, '.', ''),
            $lines,
        );
    }
}
