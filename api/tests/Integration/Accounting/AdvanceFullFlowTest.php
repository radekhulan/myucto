<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\JournalLinkService;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\AdvancePartialDrawdown;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Celý zálohový tok tak, jak ho projde firma bez ručních zásahů:
 *
 *   záloha (proforma) → příchozí platba z banky (automatické párování StatementMatcher,
 *   stejně jako po importu bank_api) → zaúčtování banky BankPostingService → daňový
 *   doklad k platbě, který párování založí jako koncept → jeho vystavení (IssueInvoiceAction,
 *   automatické zaúčtování) → vyúčtovací faktura z proformy s odpočtem § 37a → vystavení
 *   a zaúčtování.
 *
 * Běží pro výchozí předkontace (záloha na 324) i pro firmu, která zálohu vede přímo na
 * pohledávce 311. V obou musí sedět zápisy, vazby v deníku, saldokonto 311/324 i uzávěrková
 * kontrola K3 — po vyúčtování na nule.
 */
#[Group('integration')]
final class AdvanceFullFlowTest extends BankPostingTestCase
{
    private int $vatRateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->db->pdo();
        $this->vatRateId = (int) ($pdo->query(
            "SELECT id FROM vat_rates WHERE country = 'CZ' AND rate_percent = 21 AND is_reverse_charge = 0 ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Chybí sazba DPH 21 %.');
        }
        // Sklad vypnutý: vystavení se skladem si otevírá vlastní transakci, a ta v obalové
        // testové transakci (rollback v tearDown) nejde. Zálohový tok se skladem nesouvisí.
        $pdo->prepare("UPDATE supplier SET proforma_payment_document = 'always_tax_document', stock_enabled = 0 WHERE id = ?")
            ->execute([$this->supplierId]);
        // Vystavený doklad se zaúčtuje sám (DocumentAutoPoster), stejně jako u firmy
        // se zapnutým automatickým účtováním faktur.
        $pdo->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, 'document.invoice', 'auto', ?)
             ON DUPLICATE KEY UPDATE level = 'auto', updated_by = VALUES(updated_by)"
        )->execute([$this->supplierId, $this->userId]);
    }

    /** @return array<string,array{0:bool}> */
    public static function variants(): array
    {
        return ['zálohy přes 324 (výchozí)' => [false], 'záloha přímo na 311' => [true]];
    }

    #[DataProvider('variants')]
    public function testAdvanceCycleEndsSettledEverywhere(bool $advanceOn311): void
    {
        if ($advanceOn311) {
            $this->advanceRulesOn311();
        }
        $pdo = $this->db->pdo();
        $client = $this->client('Odběratel toku zálohy');
        $vs = (string) random_int(700000000, 799999999);
        $proforma = $this->proforma($vs, $client, 1000.00);

        // 1) Příchozí platba z banky, automatické párování a zaúčtování.
        $tx = $this->transaction($this->statement(), 1210.00, ['variable_symbol' => $vs]);
        $match = $this->container->get(StatementMatcher::class)->matchBatch([$tx])[$tx] ?? [];
        self::assertContains($match['status'] ?? null, ['auto_exact', 'auto_partial'], 'Platba se má spárovat se zálohou: ' . json_encode($match));
        $posted = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $posted['action'], 'Banka se má zaúčtovat: ' . ($posted['reason'] ?? ''));
        $bankEntry = $this->entryFor('bank', $tx);
        $advanceAcc = $advanceOn311 ? '311' : '324';
        self::assertEqualsWithDelta(1210.00, $this->side($bankEntry, $advanceAcc, 'credit'), 0.001, "Inkaso zálohy 221/{$advanceAcc}.");

        // 2) Daňový doklad k platbě vznikl jako koncept, vystaví se a zaúčtuje.
        $ddkp = (int) $pdo->query(
            "SELECT id FROM invoices WHERE parent_invoice_id = {$proforma} AND invoice_type = 'tax_document'"
        )->fetchColumn();
        self::assertGreaterThan(0, $ddkp, 'Párování má založit koncept daňového dokladu k platbě.');
        $this->issue($ddkp);
        $ddkpEntry = $this->ensurePosted($ddkp);
        self::assertEqualsWithDelta(210.00, $this->side($ddkpEntry, $advanceAcc, 'debit'), 0.001, "DDKP {$advanceAcc} MD = daň ze zálohy.");
        self::assertEqualsWithDelta(210.00, $this->side($ddkpEntry, '343.200', 'credit'), 0.001, 'DDKP 343 D.');

        $open = $this->receivableSaldo('2099-06-20', $client);
        self::assertSame(0, $open['difference'], 'Před vyúčtováním sedí saldokonto s HK (311).');

        // 3) Vyúčtovací faktura z proformy (§ 37a), vystavení a zaúčtování.
        $final = $this->container->get(FinalFromProformaCreator::class)->create($proforma, $this->userId, self::YEAR . '-06-25', self::YEAR . '-07-09');
        $this->issue($final);
        $finalEntry = $this->ensurePosted($final);
        self::assertEqualsWithDelta(1000.00, $this->side($finalEntry, '602', 'credit'), 0.001, 'Výnos za celý základ.');
        self::assertEqualsWithDelta(0.00, $this->side($finalEntry, '343.200', 'credit'), 0.001, 'Daň přiznal DDKP, vyúčtování ji neopakuje.');
        $this->assertSettlementPair($finalEntry, $advanceOn311, 1000.00);

        // 4) Obraty zálohového cyklu: 311 i 324 na nule, 343 nese daň právě jednou.
        $sum = $this->sums([$bankEntry, $ddkpEntry, $finalEntry]);
        self::assertSame(0, self::cents($sum['311'] ?? 0), '311 po vyúčtování na nule.');
        self::assertSame(0, self::cents($sum['324'] ?? 0), '324 po vyúčtování na nule.');
        self::assertSame(-21000, self::cents($sum['343.200'] ?? 0), '343 = daň 210 jednou.');
        self::assertSame(-100000, self::cents($sum['602'] ?? 0), '602 = výnos 1000.');

        // 5) Saldokonto a K3.
        foreach (['311', '324'] as $code) {
            $acc = $this->saldo($code, self::YEAR . '-12-31');
            self::assertSame(0, self::cents($acc['difference']), "Konfrontace {$code} po vyúčtování.");
            self::assertNull($this->partnerOf($acc, $client), "Partner na {$code} nemá otevřenou položku.");
        }
        $flagged = array_filter(
            (new ClosingRepository($this->db))->paidInvoicesOpenSaldo($this->supplierId, self::YEAR . '-12-31'),
            static fn (array $r): bool => in_array((int) $r['id'], [$proforma, $ddkp, $final], true),
        );
        self::assertSame([], array_values($flagged), 'K3 nesmí hlásit zálohový cyklus.');

        // 6) Vazby v deníku: banka ↔ DDKP a konečná faktura.
        $links = $this->container->get(JournalLinkService::class);
        $fromBank = array_map(
            static fn (array $i): string => $i['source_type'] . ':' . $i['source_id'],
            $links->related($this->supplierId, $this->journal->find($bankEntry, $this->supplierId))['items'],
        );
        self::assertContains('invoice:' . $ddkp, $fromBank);
        self::assertContains('invoice:' . $final, $fromBank);
        foreach ([$ddkpEntry, $finalEntry] as $entryId) {
            $bankRefs = array_filter(
                $links->related($this->supplierId, $this->journal->find($entryId, $this->supplierId))['items'],
                static fn (array $i): bool => $i['source_type'] === 'bank' && (int) $i['source_id'] === $tx,
            );
            self::assertCount(1, $bankRefs, "Zápis #{$entryId} vede na úhradu zálohy.");
        }
    }

    /**
     * Výchozí režim čisté instalace (`final_on_full_payment`): doplacená záloha rovnou
     * zakládá koncept vyúčtovací faktury, DDKP nevzniká. Vyúčtování nese plnou daň
     * a zúčtuje celou zálohu.
     */
    #[DataProvider('variants')]
    public function testFullPaymentCreatesFinalInvoiceWithoutTaxDocument(bool $advanceOn311): void
    {
        if ($advanceOn311) {
            $this->advanceRulesOn311();
        }
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET proforma_payment_document = 'final_on_full_payment' WHERE id = ?")
            ->execute([$this->supplierId]);
        $client = $this->client('Odběratel rychlý prodej');
        $vs = (string) random_int(700000000, 799999999);
        $proforma = $this->proforma($vs, $client, 1000.00);

        $tx = $this->transaction($this->statement(), 1210.00, ['variable_symbol' => $vs]);
        $this->container->get(StatementMatcher::class)->matchBatch([$tx]);
        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);
        $bankEntry = $this->entryFor('bank', $tx);

        $final = (int) $pdo->query(
            "SELECT id FROM invoices WHERE parent_invoice_id = {$proforma} AND invoice_type = 'invoice'"
        )->fetchColumn();
        self::assertGreaterThan(0, $final, 'Doplacená záloha má založit koncept vyúčtovací faktury.');
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM invoices WHERE parent_invoice_id = {$proforma} AND invoice_type = 'tax_document'"
        )->fetchColumn(), 'V tomhle režimu DDKP nevzniká.');
        $this->issue($final);
        $finalEntry = $this->ensurePosted($final);
        self::assertEqualsWithDelta(210.00, $this->side($finalEntry, '343.200', 'credit'), 0.001, 'Vyúčtování nese celou daň.');
        $this->assertSettlementPair($finalEntry, $advanceOn311, 1210.00);

        $sum = $this->sums([$bankEntry, $finalEntry]);
        self::assertSame(0, self::cents($sum['311'] ?? 0), '311 na nule.');
        self::assertSame(0, self::cents($sum['324'] ?? 0), '324 na nule.');
        self::assertSame(-100000, self::cents($sum['602'] ?? 0));
        foreach (['311', '324'] as $code) {
            $acc = $this->saldo($code, self::YEAR . '-12-31');
            self::assertSame(0, self::cents($acc['difference']), "Konfrontace {$code}.");
            self::assertNull($this->partnerOf($acc, $client), "Partner na {$code} bez otevřené položky.");
        }
    }

    /**
     * Konečná faktura čerpá dvě zálohy najednou (bez vazby dokladu, čerpání nesou odpočtové
     * řádky). Doplatek přijde pod VS první zálohy a párování ho přesměruje na konečnou
     * fakturu. Saldokonto 311 i 324, K3 a vazby v deníku musí skončit stejně jako
     * u vyúčtování jedné zálohy, i když firma vede zálohy přímo na 311.
     */
    #[DataProvider('variants')]
    public function testInvoiceDrawingTwoAdvancesEndsSettledEverywhere(bool $advanceOn311): void
    {
        if ($advanceOn311) {
            $this->advanceRulesOn311();
        }
        $client = $this->client('Odběratel dvou záloh');
        [$pfA, $vsA, $txA, $bankA, $ddkpA, $ddkpEntryA] = $this->paidAdvanceWithTaxDocument($client, 1000.00);
        [$pfB, , $txB, $bankB, $ddkpB, $ddkpEntryB] = $this->paidAdvanceWithTaxDocument($client, 500.00);

        $final = $this->draftInvoice($client, 3000.00);
        $drawdown = $this->container->get(AdvancePartialDrawdown::class);
        $drawdown->apply($this->supplierId, $final, $pfA, 1210.00);
        $drawdown->apply($this->supplierId, $final, $pfB, 605.00);
        $this->issue($final);
        $finalEntry = $this->ensurePosted($final);
        $this->assertSettlementPair($finalEntry, $advanceOn311, 1500.00);

        // Doplatek pod VS zálohy A: proforma je uhrazená, pohledávku nese konečná faktura.
        $tx = $this->transaction($this->statement(), 1815.00, ['variable_symbol' => $vsA]);
        $this->container->get(StatementMatcher::class)->matchBatch([$tx]);
        self::assertSame($final, (int) $this->db->pdo()->query("SELECT matched_invoice_id FROM bank_transactions WHERE id = {$tx}")->fetchColumn(),
            'Platba pod VS zálohy se přesměruje na konečnou fakturu, která zálohu čerpá.');
        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);
        $bankFinal = $this->entryFor('bank', $tx);

        $sum = $this->sums([$bankA, $bankB, $ddkpEntryA, $ddkpEntryB, $finalEntry, $bankFinal]);
        self::assertSame(0, self::cents($sum['311'] ?? 0), '311 po vyúčtování a doplatku na nule.');
        self::assertSame(0, self::cents($sum['324'] ?? 0), '324 po vyčerpání obou záloh na nule.');
        self::assertSame(-63000, self::cents($sum['343.200'] ?? 0), '343 = daň z plnění 3000 právě jednou.');
        self::assertSame(-300000, self::cents($sum['602'] ?? 0));

        foreach (['311', '324'] as $code) {
            $acc = $this->saldo($code, self::YEAR . '-12-31');
            self::assertSame(0, self::cents($acc['difference']), "Konfrontace {$code}.");
            self::assertNull($this->partnerOf($acc, $client), "Partner na {$code} nemá otevřenou položku.");
        }
        $flagged = array_filter(
            (new ClosingRepository($this->db))->paidInvoicesOpenSaldo($this->supplierId, self::YEAR . '-12-31'),
            static fn (array $r): bool => in_array((int) $r['id'], [$pfA, $pfB, $ddkpA, $ddkpB, $final], true),
        );
        self::assertSame([], array_values($flagged), 'K3 nesmí hlásit zálohový cyklus dvou záloh.');

        $links = $this->container->get(JournalLinkService::class);
        $fromBankB = array_map(
            static fn (array $i): string => $i['source_type'] . ':' . $i['source_id'],
            $links->related($this->supplierId, $this->journal->find($bankB, $this->supplierId))['items'],
        );
        self::assertContains('invoice:' . $final, $fromBankB, 'Z úhrady zálohy B vede vazba na konečnou fakturu.');
        $bankRefs = array_values(array_unique(array_map(
            static fn (array $i): int => (int) $i['source_id'],
            array_filter(
                $links->related($this->supplierId, $this->journal->find($finalEntry, $this->supplierId))['items'],
                static fn (array $i): bool => $i['source_type'] === 'bank',
            ),
        )));
        sort($bankRefs);
        self::assertContains($txA, $bankRefs, 'Z konečné faktury vede vazba na úhradu zálohy A.');
        self::assertContains($txB, $bankRefs, 'I na úhradu zálohy B.');
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    private function advanceRulesOn311(): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
             VALUES (?, ?, ?, ?, ?, 0, 1)
             ON DUPLICATE KEY UPDATE debit_account_code = VALUES(debit_account_code),
                                     credit_account_code = VALUES(credit_account_code), is_active = 1'
        );
        foreach ([
            ['advance.received.collection', '221', '311'],
            ['advance.received.vatdocument', '311', '343.200'],
            ['advance.received.settlement', '311', '311'],
        ] as [$key, $debit, $credit]) {
            $stmt->execute([$this->supplierId, $key, 'Záloha na 311', $debit, $credit]);
        }
    }

    /**
     * Záloha zaplacená bankou (automatické párování), daňový doklad k platbě vystavený
     * a zaúčtovaný.
     *
     * @return array{0:int, 1:string, 2:int, 3:int, 4:int, 5:int} [proforma, VS, transakce, zápis banky, DDKP, zápis DDKP]
     */
    private function paidAdvanceWithTaxDocument(int $client, float $base): array
    {
        $vs = (string) random_int(700000000, 799999999);
        $proforma = $this->proforma($vs, $client, $base);
        $tx = $this->transaction($this->statement(), round($base * 1.21, 2), ['variable_symbol' => $vs]);
        $this->container->get(StatementMatcher::class)->matchBatch([$tx]);
        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);
        $ddkp = (int) $this->db->pdo()->query(
            "SELECT id FROM invoices WHERE parent_invoice_id = {$proforma} AND invoice_type = 'tax_document'"
        )->fetchColumn();
        self::assertGreaterThan(0, $ddkp);
        $this->issue($ddkp);
        return [$proforma, $vs, $tx, $this->entryFor('bank', $tx), $ddkp, $this->ensurePosted($ddkp)];
    }

    private function draftInvoice(int $clientId, float $base): int
    {
        $pdo = $this->db->pdo();
        $vat = round($base * 0.21, 2);
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, prices_include_vat, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, NULL, "invoice", ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, 0, "draft", "1", ?)'
        )->execute([$this->supplierId, $clientId, self::YEAR . '-06-25', self::YEAR . '-06-25', self::YEAR . '-07-09',
            $this->currencyId, $base, $vat, $base + $vat, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Dílo', 1, 'ks', ?, ?, 21.00, ?, ?, ?, 0)"
        )->execute([$id, $base, $this->vatRateId, $base, $vat, $base + $vat]);
        return $id;
    }

    private function proforma(string $vs, int $clientId, float $base): int
    {
        $pdo = $this->db->pdo();
        $vat = round($base * 0.21, 2);
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, prices_include_vat, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "proforma", ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, 0, "issued", "1", ?)'
        )->execute([$this->supplierId, $vs, $clientId, self::YEAR . '-06-10', self::YEAR . '-06-10', self::YEAR . '-06-24',
            $this->currencyId, $base, $vat, $base + $vat, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Dílo', 1, 'ks', ?, ?, 21.00, ?, ?, ?, 0)"
        )->execute([$id, $base, $this->vatRateId, $base, $vat, $base + $vat]);
        return $id;
    }

    /**
     * Zúčtování zálohy v zápisu vyúčtování: přes 324 pár 324 MD / 311 D; u zálohy vedené
     * přímo na 311 se pár 311/311 nezapisuje (vyruší se a jen zdvojí obrat zápisu).
     */
    private function assertSettlementPair(int $entryId, bool $advanceOn311, float $amount): void
    {
        if ($advanceOn311) {
            self::assertEqualsWithDelta(0.00, $this->side($entryId, '311', 'credit'), 0.001, 'Záloha na 311: žádný pár 311/311.');
            return;
        }
        self::assertEqualsWithDelta($amount, $this->side($entryId, '324', 'debit'), 0.001, 'Zúčtování 324 MD.');
        self::assertEqualsWithDelta($amount, $this->side($entryId, '311', 'credit'), 0.001, 'Zúčtování 311 D.');
    }

    private function issue(int $invoiceId): void
    {
        $res = $this->callAction(
            $this->container->get(IssueInvoiceAction::class),
            '__invoke',
            'POST',
            'admin',
            [],
            ['id' => (string) $invoiceId],
        );
        self::assertLessThan(300, $res['status'], "Vystavení dokladu #{$invoiceId}: " . json_encode($res['body']));
    }

    /**
     * Zápis, který vystavení samo založilo. Automatické účtování chybu jen zaloguje,
     * takže když zápis chybí, vypíše se důvod z posledního auto_post_failed.
     */
    private function ensurePosted(int $invoiceId): int
    {
        $entry = $this->entryFor('invoice', $invoiceId, false);
        if ($entry === 0) {
            $reason = $this->db->pdo()->query(
                "SELECT details FROM activity_log WHERE action = 'accounting.auto_post_failed'
                    AND entity_id = {$invoiceId} ORDER BY id DESC LIMIT 1"
            )->fetchColumn();
            self::fail("Vystavený doklad #{$invoiceId} se nezaúčtoval: " . (is_string($reason) ? $reason : '?'));
        }
        return $entry;
    }

    private function entryFor(string $sourceType, int $sourceId, bool $required = true): int
    {
        $id = (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE supplier_id = {$this->supplierId} AND source_type = '{$sourceType}'
                AND source_id = {$sourceId} AND reversed_by IS NULL ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        if ($required) {
            self::assertGreaterThan(0, $id, "Chybí zápis {$sourceType} #{$sourceId}.");
        }
        return $id;
    }

    private function side(int $entryId, string $code, string $side): float
    {
        return (float) ($this->linesByAccountCode($entryId)[$code][$side] ?? 0.0);
    }

    /**
     * @param list<int> $entryIds
     * @return array<string,float>
     */
    private function sums(array $entryIds): array
    {
        $sum = [];
        foreach ($entryIds as $entryId) {
            foreach ($this->linesByAccountCode($entryId) as $code => $s) {
                $code = (string) $code;
                if (str_starts_with($code, '221')) {
                    continue;
                }
                $sum[$code] = round(($sum[$code] ?? 0.0) + $s['debit'] - $s['credit'], 2);
            }
        }
        return $sum;
    }

    /** @return array<string,mixed> */
    private function saldo(string $code, string $asOf): array
    {
        foreach ($this->container->get(SaldoService::class)->build($this->supplierId, $this->periodId, $asOf, $code)['accounts'] as $acc) {
            if ((string) $acc['account']['code'] === $code) {
                return $acc;
            }
        }
        self::fail("Saldokonto {$code} chybí.");
    }

    /** @return array{difference:int} */
    private function receivableSaldo(string $asOf, int $client): array
    {
        return ['difference' => self::cents($this->saldo('311', $asOf)['difference'])];
    }

    /**
     * @param array<string,mixed> $acc
     * @return array<string,mixed>|null
     */
    private function partnerOf(array $acc, int $partnerId): ?array
    {
        foreach ($acc['partners'] as $p) {
            if ((int) $p['partner_id'] === $partnerId) {
                return $p;
            }
        }
        return null;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round((float) $amount * 100.0);
    }
}
