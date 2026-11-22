<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Action\Bank\BankPaymentCandidatesAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Peněžní deník daňové evidence (Epic DE, A2) — obrana proti dvojímu započtení a
 * FX chybám v kasové bázi (§4/§5 spec). Každý ekonomický příjem/výdaj MUSÍ být
 * započten PRÁVĚ JEDNOU, v CZK. Pokrývá defekty C1/C2/H1/H2/H3/M1/M2/M3.
 */
#[Group('integration')]
final class CashJournalDedupFxTest extends CashJournalTestCase
{
    public static function batchInvoiceDirections(): array
    {
        return ['vydaná' => [true], 'přijatá' => [false]];
    }

    #[DataProvider('batchInvoiceDirections')]
    public function testBatchPaymentsUseOriginalDaysAndAppearOnceInCashJournal(bool $issued): void
    {
        $supplier = $this->supplierId;
        $opts = ['without' => 5000.0, 'with' => 5000.0];
        $id = $issued ? $this->saleInvoice($supplier, $opts) : $this->purchaseInvoice($supplier, $opts);
        $statement = $this->statement($supplier, $this->accountA);
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE bank_statements SET supplier_id = ? WHERE id = ?')->execute([$supplier, $statement]);
        $ids = [];
        $dates = [self::YEAR . '-06-12', self::YEAR . '-06-13', self::YEAR . '-06-14'];
        foreach ([2000, 1500, 1500] as $i => $amount) {
            $ids[] = $this->bankTx($statement, $issued ? $amount : -$amount, ['posted_at' => $dates[$i]]);
        }
        $body = [$issued ? 'invoice_id' : 'purchase_invoice_id' => $id, 'transaction_ids' => $ids];
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/bank-transactions/match-document-batch')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $action = $this->container->get(BankPaymentCandidatesAction::class);
        $preview = $action->batch($request->withParsedBody($body), new Response());
        self::assertSame(200, $preview->getStatusCode(), (string) $preview->getBody());
        $version = json_decode((string) $preview->getBody(), true, flags: JSON_THROW_ON_ERROR)['version'];
        $confirmed = $action->batch($request->withParsedBody($body + ['confirm_version' => $version]), new Response());
        self::assertSame(200, $confirmed->getStatusCode(), (string) $confirmed->getBody());
        $items = json_decode((string) $confirmed->getBody(), true, flags: JSON_THROW_ON_ERROR)['items'];
        self::assertSame($ids, array_column($items, 'id'));
        foreach ($items as $item) {
            self::assertSame('not_double_entry', $item['posting']['reason'] ?? null);
        }
        $journal = $this->fullYear($supplier);
        self::assertCount(3, $journal['rows']);
        foreach ($ids as $movement) {
            self::assertSame(1, $this->countRows($journal, 'bank', $movement));
        }
        self::assertSame($dates, array_column($journal['rows'], 'date'));
        $total = $issued ? 'prijem_danovy' : 'vydaj_danovy';
        self::assertEqualsWithDelta(5000.0, $journal['totals'][$total], 0.01);
        $table = $issued ? 'invoices' : 'purchase_invoices';
        $status = $pdo->prepare("SELECT status FROM $table WHERE id = ?");
        $status->execute([$id]);
        self::assertSame('paid', $status->fetchColumn());
    }

    /** EUR měna daného supplieru (nedefaultní), pro FX scénáře. */
    private function eurCurrency(int $supplierId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "EUR", "EUR", "€", "Euro", "Euro", 2, 1, 0)'
        )->execute([$supplierId]);
        return (int) $pdo->lastInsertId();
    }

    /** Vydaná faktura s explicitním currency_id + exchange_rate (FX). */
    private function invoiceFx(int $supplierId, int $currencyId, float $rate, float $without, float $with, string $status = 'paid'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, paid_at, income_tax_exempt, vat_classification_code, created_by)
             VALUES (?, ?, 'invoice', ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 0, '1', ?)"
        )->execute([
            $supplierId, (string) random_int(100000, 999999), $this->clientId,
            self::YEAR . '-06-10', self::YEAR . '-06-10', self::YEAR . '-06-10',
            $currencyId, $rate, $without, round($with - $without, 2), $with,
            $status, self::YEAR . '-06-15', $this->userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** invoice_payment s libovolnou měnou (helper v TestCase hardcoduje CZK). */
    private function paymentCcy(int $supplierId, int $invoiceId, float $amount, string $ccy, string $source, ?int $bankTxId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, $invoiceId, self::YEAR . '-06-15', $amount, $ccy, $source, $bankTxId]);
        return (int) $pdo->lastInsertId();
    }

    /** Denní kurz ČNB pro G4 testy (jinak by build() vždy padal na fallback větev). */
    private function exchangeRate(string $currency, string $date, float $rate): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)'
        )->execute([$date, $currency, $rate]);
    }

    /**
     * Měna BEZ reálných dat v exchange_rates (na rozdíl od EUR, kterou tahle
     * sdílená dev DB průběžně plní přes reálný CNB cron — G4 subselect dělá
     * `rate_date <= paid_on ORDER BY rate_date DESC LIMIT 1`, takže by u EUR
     * i pro rok 2099 „nejbližší předchozí" byl poslední reálně stažený kurz,
     * ne NULL). XTS je ISO 4217 kód rezervovaný pro testování, CNB ho nikdy
     * nepublikuje → garantovaně žádný řádek v exchange_rates.
     */
    private function noHistoryCurrency(int $supplierId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "XTS", "XTS", "XTS", "XTS", "XTS", 2, 1, 0)'
        )->execute([$supplierId]);
        return (int) $pdo->lastInsertId();
    }

    // ── C1: mark_paid/manual later matched → income NOT doubled ──────────────

    /** C1(i): mark_paid, pak StatementMatcher (alreadyPaid — jen matched_invoice_id) → příjem 10 000 JEDNOU. */
    public function testMarkPaidThenAlreadyPaidBankMatchCountsOnce(): void
    {
        $inv = $this->saleInvoice($this->supplierId, ['without' => 10000.0, 'with' => 12100.0, 'status' => 'paid', 'paid_at' => self::YEAR . '-06-15']);
        // virtuální mark_paid platba (bez bankovní vazby) — plný brutto 12 100
        $this->invoicePayment($this->supplierId, $inv, 12100.0, 'mark_paid');
        // alreadyPaid stav: banka jen navázala matched_invoice_id, žádná source='bank' platba
        $st = $this->statement($this->supplierId, $this->accountA);
        $this->bankTx($st, 12100.0, ['matched_invoice_id' => $inv, 'match_status' => 'auto_exact']);

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(10000.0, $res['totals']['prijem_danovy'], 0.01, 'alreadyPaid: příjem 10 000 JEDNOU, ne 20 000.');
        self::assertSame(0, $this->countRows($res, 'bank'), 'Bankovní pohyb se z nohy B vypustí (počítá noha C1).');
        self::assertSame(1, $this->countRows($res, 'invoice_payment'), 'Receipt nese noha C1.');
    }

    /** C1(ii): mark_paid, pak reconcileToBankTransaction (bt_id doplněn) → příjem 10 000 JEDNOU. */
    public function testMarkPaidThenReconcileBankTxCountsOnce(): void
    {
        $inv = $this->saleInvoice($this->supplierId, ['without' => 10000.0, 'with' => 12100.0, 'status' => 'paid', 'paid_at' => self::YEAR . '-06-15']);
        $st  = $this->statement($this->supplierId, $this->accountA);
        $tx  = $this->bankTx($st, 12100.0, ['matched_invoice_id' => $inv, 'match_status' => 'manual']);
        // reconcile: existující mark_paid platba dostane bank_transaction_id (source zůstává mark_paid)
        $this->invoicePayment($this->supplierId, $inv, 12100.0, 'mark_paid', $tx);

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(10000.0, $res['totals']['prijem_danovy'], 0.01, 'reconcile: příjem 10 000 JEDNOU, ne 20 000.');
        self::assertSame(1, $this->countRows($res, 'bank'), 'Receipt nese fyzická noha B (bt_id doplněn).');
        self::assertSame(0, $this->countRows($res, 'invoice_payment'), 'Rekonciliovaná manual platba se z C1 vypustí.');
    }

    // ── C2: split payment (#89) — one bank tx settling N invoices = one row ──

    /** C2: jedna příchozí platba 36 300 settluje 3 FV à 12 100 → příjem 30 000 JEDNOU (jeden řádek). */
    public function testSplitPaymentAggregatesToSingleRow(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($st, 36300.0);
        for ($i = 0; $i < 3; $i++) {
            $inv = $this->saleInvoice($this->supplierId, ['without' => 10000.0, 'with' => 12100.0, 'status' => 'paid', 'paid_at' => self::YEAR . '-06-15']);
            $this->invoicePayment($this->supplierId, $inv, 12100.0, 'bank', $tx);
        }

        $res = $this->fullYear($this->supplierId);

        self::assertSame(1, $this->countRows($res, 'bank'), 'Sloučená úhrada = JEDEN řádek deníku.');
        self::assertEqualsWithDelta(30000.0, $res['totals']['prijem_danovy'], 0.01, 'Základ 3×10 000 = 30 000, ne 90 000.');
        self::assertEqualsWithDelta(6300.0, $res['totals']['prijem_nedanovy'], 0.01, 'DPH složka 3×2 100 = 6 300.');
    }

    // ── H1: leg B converts foreign currency to CZK ──────────────────────────

    /**
     * H1/G4: cizoměnové inkaso 1 000 (base 826,45, kurz 24,50) → daňový příjem
     * ≈ 20 248 CZK, ne 826. XTS (bez reálných dat v exchange_rates, G4 subselect
     * by jinak u EUR sáhl po posledním reálném kurzu) — fallback na kurz faktury.
     */
    public function testForeignBankIncomeConvertedToCzk(): void
    {
        $xts = $this->noHistoryCurrency($this->supplierId);
        $inv = $this->invoiceFx($this->supplierId, $xts, 24.50, 826.45, 1000.0);
        $st  = $this->statement($this->supplierId, $this->accountA);
        $tx  = $this->bankTx($st, 24500.0, ['currency' => 'CZK']);
        $this->paymentCcy($this->supplierId, $inv, 1000.0, 'XTS', 'bank', $tx);

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(20248.03, $res['totals']['prijem_danovy'], 0.05, 'Základ 826,45 × 24,50 ≈ 20 248 CZK.');
        self::assertGreaterThan(20000.0, $res['totals']['prijem_danovy'], 'NESMÍ být počítán nominálně (826).');
    }

    // ── G4: kasová báze — kurz ke dni ÚHRADY, ne ke dni vystavení faktury ────

    /**
     * G4 (noha B — bankovní inkaso): faktura zafixovaná kurzem 30,0 při vystavení,
     * ale exchange_rates má pro DEN ÚHRADY jiný kurz (24,50) → musí se použít kurz
     * úhrady, ne vystavení. Špatně by dal 826,45 × 30 ≈ 24 793,50.
     */
    public function testBankIncomeUsesPaymentDateRateNotInvoiceIssueRate(): void
    {
        $eur = $this->eurCurrency($this->supplierId);
        $inv = $this->invoiceFx($this->supplierId, $eur, 30.0, 826.45, 1000.0);
        $this->exchangeRate('EUR', self::YEAR . '-06-15', 24.50); // den úhrady (paymentCcy hardcoduje 06-15)
        $st = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($st, 24500.0, ['currency' => 'CZK']);
        $this->paymentCcy($this->supplierId, $inv, 1000.0, 'EUR', 'bank', $tx);

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(20248.03, $res['totals']['prijem_danovy'], 0.05,
            'G4: kurz ke dni ÚHRADY (24,50), ne k datu vystavení faktury (30,0).');
        self::assertLessThan(23000.0, $res['totals']['prijem_danovy'],
            'NESMÍ použít kurz vystavení (30,0) — dal by ≈24 793.');
    }

    /**
     * G4 (noha C1 — virtuální inkaso bez fyzického dokladu, mark_paid): stejný
     * princip jako noha B — kurz ke dni ip.paid_on, ne kurz faktury.
     */
    public function testVirtualInvoicePaymentUsesPaymentDateRateNotInvoiceIssueRate(): void
    {
        $eur = $this->eurCurrency($this->supplierId);
        $inv = $this->invoiceFx($this->supplierId, $eur, 30.0, 826.45, 1000.0);
        $this->exchangeRate('EUR', self::YEAR . '-06-15', 24.50);
        $this->paymentCcy($this->supplierId, $inv, 1000.0, 'EUR', 'mark_paid', null);

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(20248.03, $res['totals']['prijem_danovy'], 0.05,
            'G4 (C1): virtuální platba používá kurz ke dni úhrady, ne kurz faktury.');
    }

    /**
     * G4 fallback: pro měnu/den úhrady NENÍ v exchange_rates žádný záznam →
     * NESMÍ tiše spadnout na nominál 1:1 (826), ale na kurz faktury (i.exchange_rate
     * = 24,50), stejně jako před fixem. XTS bez reálných dat (viz noHistoryCurrency).
     */
    public function testBankIncomeFallsBackToInvoiceRateWhenNoExchangeRateRowExists(): void
    {
        $xts = $this->noHistoryCurrency($this->supplierId);
        $inv = $this->invoiceFx($this->supplierId, $xts, 24.50, 826.45, 1000.0);
        // Žádný exchange_rates řádek pro XTS.
        $st = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($st, 24500.0, ['currency' => 'CZK']);
        $this->paymentCcy($this->supplierId, $inv, 1000.0, 'XTS', 'bank', $tx);

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(20248.03, $res['totals']['prijem_danovy'], 0.05,
            'Bez záznamu v exchange_rates padá na kurz faktury (24,50).');
        self::assertGreaterThan(20000.0, $res['totals']['prijem_danovy'],
            'NESMÍ tiše spadnout na nominál 826 (1:1).');
    }

    // ── #28: chybějící kurz nesmí shodit CELÝ deník ─────────────────────────

    /**
     * #28: cizoměnová úhrada ke dni, ke kterému není kurz ani zpětně, a doklad nemá
     * ani vlastní `exchange_rate` → dřív z toho letěla RuntimeException a `GET
     * /api/tax-evidence/cash-journal` vracel 500 pro CELÝ rok. Účetní neviděl ani
     * zbytek deníku a z hlášky nepoznal, který doklad je vadný.
     *
     * Nově deník projde, vadný řádek je oceněn nulou (NE tichým 1:1, to by pohyb
     * podhodnotilo ~25×), nese příznak `fx_rate_missing` a ve `warnings[]` je
     * blokující položka s identifikací dokladu.
     */
    public function testMissingRateFlagsTheRowInsteadOfKillingTheWholeJournal(): void
    {
        $xts = $this->noHistoryCurrency($this->supplierId);
        $broken = $this->invoiceFx($this->supplierId, $xts, 24.50, 826.45, 1000.0);
        // Ani kurz na faktuře — jinak by G4 fallback pohyb ocenit uměl.
        $this->db->pdo()->prepare('UPDATE invoices SET exchange_rate = NULL WHERE id = ?')->execute([$broken]);
        $this->paymentCcy($this->supplierId, $broken, 1000.0, 'XTS', 'mark_paid', null);

        // Zdravý korunový příjem ve stejném období — MUSÍ být vidět i s vadným sousedem.
        $healthy = $this->cashDoc('in', 'sale', 5000.0);

        $res = $this->fullYear($this->supplierId);

        self::assertSame(1, $this->countRows($res, 'cash', $healthy),
            '#28: jeden neoceněný doklad nesmí schovat zbytek deníku.');
        self::assertEqualsWithDelta(5000.0, $res['totals']['prijem_danovy'], 0.01,
            'Neoceněný pohyb do daňového základu nevstupuje (nula, ne tichý nominál 1 000).');

        $flagged = array_values(array_filter(
            $res['rows'],
            static fn (array $r): bool => $r['source_type'] === 'invoice_payment' && $r['fx_rate_missing'] === true,
        ));
        self::assertCount(1, $flagged, 'Řádek bez kurzu musí být označený příznakem fx_rate_missing.');
        self::assertSame(0.0, $flagged[0]['income'], 'Neoceněný řádek se vykazuje nulou.');

        $fxWarnings = array_values(array_filter(
            $res['warnings'],
            static fn (array $w): bool => ($w['type'] ?? '') === 'fx_rate_missing',
        ));
        self::assertCount(1, $fxWarnings, 'Chybějící kurz musí být ve warnings[], ať účetní ví, co opravit.');
        self::assertTrue($fxWarnings[0]['blocking'], 'Varování je blokující — daňový základ je podhodnocený.');
        self::assertStringContainsString(self::YEAR . '-06-15', $fxWarnings[0]['message'],
            'Zpráva musí identifikovat doklad datem pohybu.');
    }

    /**
     * Tatáž pojistka pro nohu B: nespárovaný cizoměnový bankovní pohyb se ocení
     * kurzem měny pohybu a ten ŽÁDNÝ fallback na doklad nemá. Bez kurzu k datu
     * (ani staršího) tedy vzniká NULL — a i ten musí skončit varováním, ne 500.
     */
    public function testMissingRateOnUnmatchedBankMovementIsAlsoOnlyAWarning(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($st, 1000.0);
        // bankTx() měnu hardcoduje na CZK — pro FX scénář ji přepíšeme.
        $this->db->pdo()->prepare('UPDATE bank_transactions SET currency = ? WHERE id = ?')->execute(['XTS', $tx]);

        $res = $this->fullYear($this->supplierId);

        self::assertSame(1, $this->countRows($res, 'bank', $tx), 'Pohyb musí v deníku zůstat vidět.');
        $fxWarnings = array_values(array_filter(
            $res['warnings'],
            static fn (array $w): bool => ($w['type'] ?? '') === 'fx_rate_missing',
        ));
        self::assertCount(1, $fxWarnings, 'I bankovní noha hlásí chybějící kurz varováním.');
    }

    /**
     * #28: pohyb STARŠÍ, než kam sahá kurzová historie instalace (čerstvý tenant má
     * v `exchange_rates` jen pár posledních dnů, ale zaeviduje loňskou úhradu).
     * Zpětně není co najít → ocení se nejbližším POZDĚJŠÍM známým kurzem, ne nulou:
     * kurz sousedního dne se od vyhlášeného liší v desetinách procenta, kdežto
     * vypuštění pohybu je chyba o celou jeho hodnotu.
     *
     * Kurz na faktuře má přednost před cizím dnem — tady ho proto nemá, ať je vidět
     * čistě větev „nejbližší pozdější".
     */
    public function testMovementOlderThanRateHistoryIsPricedByNearestKnownRate(): void
    {
        $xts = $this->noHistoryCurrency($this->supplierId);
        $inv = $this->invoiceFx($this->supplierId, $xts, 24.50, 826.45, 1000.0);
        $this->db->pdo()->prepare('UPDATE invoices SET exchange_rate = NULL WHERE id = ?')->execute([$inv]);
        $this->paymentCcy($this->supplierId, $inv, 1000.0, 'XTS', 'mark_paid', null);
        // Jediný známý kurz XTS je AŽ PO dni úhrady (2099-06-15).
        $this->exchangeRate('XTS', self::YEAR . '-09-01', 25.00);

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(20661.25, $res['totals']['prijem_danovy'], 0.05,
            '#28: ocenit nejbližším pozdějším kurzem (25,00), ne nulou.');

        $flagged = array_filter($res['rows'], static fn (array $r): bool => $r['fx_rate_missing'] === true);
        self::assertSame([], $flagged, 'Oceněný řádek už není „bez kurzu".');

        $approx = array_values(array_filter(
            $res['warnings'],
            static fn (array $w): bool => ($w['type'] ?? '') === 'fx_rate_approximate',
        ));
        self::assertCount(1, $approx, 'Nouzové ocenění nesmí být tiché — patří do warnings[].');
        self::assertFalse($approx[0]['blocking'],
            'Přiblížení kurzu není blokující: pohyb v základu daně JE, jen s kurzem sousedního dne.');
        self::assertStringContainsString(self::YEAR . '-09-01', $approx[0]['message'],
            'Zpráva musí říct, ze kterého dne je použitý kurz.');
    }

    /**
     * Kurz na dokladu má přednost před kurzem cizího dne — je to reálný kurz TOHO
     * dokladu, kdežto pozdější den je jen nejbližší dostupné přiblížení.
     */
    public function testDocumentRateWinsOverNearestLaterDay(): void
    {
        $xts = $this->noHistoryCurrency($this->supplierId);
        $inv = $this->invoiceFx($this->supplierId, $xts, 24.50, 826.45, 1000.0);
        $this->paymentCcy($this->supplierId, $inv, 1000.0, 'XTS', 'mark_paid', null);
        $this->exchangeRate('XTS', self::YEAR . '-09-01', 25.00);

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(20248.03, $res['totals']['prijem_danovy'], 0.05,
            'Kurz faktury (24,50) má přednost před pozdějším dnem (25,00).');
    }

    // ── H2: bank-paid income vanishes if statement stops matching → warning ──

    /** H2: source='bank' platba, jejíž výpis neodpovídá účtu supplieru → blokující varování. */
    public function testOrphanedBankPaymentEmitsBlockingWarning(): void
    {
        $inv = $this->saleInvoice($this->supplierId, ['without' => 10000.0, 'with' => 12100.0, 'status' => 'paid', 'paid_at' => self::YEAR . '-06-15']);
        // Výpis na CIZÍ účet (nespáruje se s currencies supplieru A).
        $foreign = $this->statement($this->supplierId, '770000999', '0800');
        $tx = $this->bankTx($foreign, 12100.0);
        $this->invoicePayment($this->supplierId, $inv, 12100.0, 'bank', $tx);

        $res = $this->fullYear($this->supplierId);

        $orphan = array_values(array_filter($res['warnings'], static fn ($w) => ($w['type'] ?? '') === 'orphan_bank_payments'));
        self::assertNotEmpty($orphan, 'Musí vzniknout varování o bankovních úhradách mimo spárované výpisy.');
        self::assertTrue($orphan[0]['blocking'], 'Varování je blokující (riziko podhodnocení základu).');
        self::assertSame(1, (int) $orphan[0]['count'], 'Přesně 1 osiřelá bankovní úhrada.');
        self::assertSame(0, $this->countRows($res, 'bank'), 'Nespárovaný výpis se do nohy B nedostane (proto varování).');
    }

    // ── H3: multi-PF bank tx splits proportionally by payment_matches.amount ──

    /** H3: odchozí 24 200 settluje PF#10 (12 100, deductible) + PF#11 (12 100, advance) → daňový výdaj 10 000. */
    public function testMultiPurchaseInvoiceExpenseSplitsByPaymentMatch(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($st, -24200.0);
        $pf10 = $this->purchaseInvoice($this->supplierId, ['without' => 10000.0, 'with' => 12100.0, 'tax_deductible' => 1, 'status' => 'paid', 'paid_at' => self::YEAR . '-06-20']);
        $pf11 = $this->purchaseInvoice($this->supplierId, ['without' => 10000.0, 'with' => 12100.0, 'document_kind' => 'advance', 'status' => 'paid', 'paid_at' => self::YEAR . '-06-20']);
        $this->paymentMatch($this->supplierId, $tx, $pf10, 12100.0);
        $this->paymentMatch($this->supplierId, $tx, $pf11, 12100.0);

        $res = $this->fullYear($this->supplierId);

        self::assertSame(1, $this->countRows($res, 'bank'), 'Odchozí sloučená úhrada = JEDEN řádek.');
        self::assertEqualsWithDelta(20000.0, $res['totals']['vydaj_danovy'], 0.01, 'Běžná PF i zaplacená záloha jsou v kasové bázi daňovým výdajem.');
        self::assertEqualsWithDelta(4200.0, $res['totals']['vydaj_nedanovy'], 0.01, 'Nedaňová zůstává pouze DPH složka u plátce.');
        self::assertSame(0, $this->countRows($res, 'purchase_invoice'), 'PF s payment_matches nesmí být i ve virtuální noze C2.');
    }

    // ── M1: cancelled paid PF drops out of taxable expense ──────────────────

    /** M1: zaplacená PF později stornovaná (status='cancelled') → vypadne z daňového výdaje. */
    public function testCancelledPaidPurchaseInvoiceDropsOut(): void
    {
        $this->purchaseInvoice($this->supplierId, ['without' => 5000.0, 'with' => 5000.0, 'status' => 'cancelled', 'paid_at' => self::YEAR . '-06-20']);

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(0.0, $res['totals']['vydaj_danovy'], 0.01, 'Stornovaná PF není daňový výdaj (M1).');
        self::assertSame(0, $this->countRows($res, 'purchase_invoice'), 'Stornovaná PF se v deníku neobjeví.');
    }

    // ── M2/C-1: cash amounts are already CZK in DB — no second FX conversion ─

    /**
     * M2 + C-1 (audit pokladny 2026-08): EUR hotovostní prodej 1 000 EUR, kurz 24,50.
     * V DB je doklad uložen UŽ V CZK — `total_amount` = CZK ekvivalent a řádky DPH
     * převedl `CashDocumentService::convertVatLinesToCzk()` (migrace 1114). Deník
     * proto NESMÍ kurzem násobit podruhé (dřív dával 24,5× nadhodnocený příjem).
     */
    public function testForeignCashSaleVatBaseInCzk(): void
    {
        $pdo = $this->db->pdo();
        // Tvar řádku přesně tak, jak ho ukládá CashDocumentService: CZK základ 20 248,03
        // + CZK daň 4 251,98 = total_amount 24 500,01 CZK, amount_foreign 1 000 EUR.
        $pdo->prepare(
            "INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, partner_name,
                 description, vat_mode, total_amount, currency_code, fx_rate, amount_foreign, status, created_by)
             VALUES (?, ?, 'in', 'sale', ?, ?, 'Zákazník', 'EUR prodej', 'vat', 24500.01, 'EUR', 24.50, 1000.00, 'posted', ?)"
        )->execute([$this->supplierId, $this->registerId, self::nextDocNumber('PPD-EUR'), self::YEAR . '-06-15', $this->userId]);
        $cd = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO cash_document_vat_lines (cash_document_id, vat_rate, base_amount, vat_amount)
             VALUES (?, 21.00, 20248.03, 4251.98)'
        )->execute([$cd]);

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(20248.03, $res['totals']['prijem_danovy'], 0.05,
            'C-1: základ se bere z DB v CZK 1:1, NESMÍ se znovu násobit kurzem (dalo by ≈ 496 077 Kč).');
        // Nic nezmizí: základ + nedaňová (DPH) složka = celé brutto v CZK.
        $sum = $res['totals']['prijem_danovy'] + $res['totals']['prijem_nedanovy'];
        self::assertEqualsWithDelta(24500.01, $sum, 0.02, 'Základ + zbytek = celé CZK brutto dokladu (M2).');
    }

    /**
     * C-1: valutový hotovostní VÝDAJ bez DPH rozpadu (neplátcovská větev i větev
     * `TaxExpenseAllocationCalculator::forCashDocument`) — daňový výdaj = `total_amount`
     * v CZK, bez druhého přepočtu kurzem.
     */
    public function testForeignCashPurchaseExpenseNotConvertedTwice(): void
    {
        $pdo = $this->db->pdo();
        // 400 EUR × 24,50 = 9 800 CZK; režim 'none' → žádné řádky DPH.
        $pdo->prepare(
            "INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, partner_name,
                 description, vat_mode, total_amount, currency_code, fx_rate, amount_foreign, status, created_by)
             VALUES (?, ?, 'out', 'purchase', ?, ?, 'Dodavatel', 'EUR nákup', 'none', 9800.00, 'EUR', 24.50, 400.00, 'posted', ?)"
        )->execute([$this->supplierId, $this->registerId, self::nextDocNumber('VPD-EUR'), self::YEAR . '-06-16', $this->userId]);

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(9800.0, $res['totals']['vydaj_danovy'], 0.02,
            'C-1: výdaj v CZK 1:1 z total_amount (dvojí přepočet by dal 240 100 Kč).');
    }

    // ── M3: bank-fee heuristic restricted to description ────────────────────

    /** M3: odchozí platba partnerovi 'Feeder s.r.o.' bez fee klíčového slova → nezarazeno, ne daňový výdaj. */
    public function testFeePartnerNameDoesNotTriggerExpense(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $this->bankTx($st, -3000.0, ['counterparty_name' => 'Feeder s.r.o.', 'description' => 'Platba dodavateli']);

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(0.0, $res['totals']['vydaj_danovy'], 0.01, "Partner 'Feeder' nesmí planě spustit poplatek (M3).");
        self::assertEqualsWithDelta(3000.0, $res['totals']['nezarazeno'], 0.01, 'Nezařazený odchozí pohyb.');
    }

    /** M3 kontrola: skutečný poplatek v description → daňový výdaj (heuristika stále funguje). */
    public function testRealBankFeeInDescriptionIsTaxExpense(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $this->bankTx($st, -150.0, ['counterparty_name' => 'Banka', 'description' => 'Poplatek za vedení účtu']);

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(150.0, $res['totals']['vydaj_danovy'], 0.01, 'Poplatek v popisu → daňový výdaj.');
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.01);
    }

    // ── MED-1: reconciled non-'bank' payment on an orphaned statement warns ──

    /**
     * MED-1: mark_paid platba REKONCILIOVANÁ na bankovní pohyb (source zůstává mark_paid, bt_id
     * doplněn), jehož výpis přestal odpovídat účtu supplieru (změna currencies.account_number).
     * C1 ji vypouští (bt_id != NULL), noha B ji míjí (výpis nespárován) → bez varování by tiše
     * zmizela ze základu. orphanedBankPaymentCount musí počítat JAKÝKOLI source, ne jen 'bank'.
     */
    public function testReconciledNonBankPaymentOnOrphanStatementWarns(): void
    {
        $inv = $this->saleInvoice($this->supplierId, ['without' => 10000.0, 'with' => 12100.0, 'status' => 'paid', 'paid_at' => self::YEAR . '-06-15']);
        // Výpis na CIZÍ účet (nespáruje se s currencies supplieru A).
        $foreign = $this->statement($this->supplierId, '770000999', '0800');
        $tx = $this->bankTx($foreign, 12100.0, ['matched_invoice_id' => $inv]);
        // mark_paid platba rekonciliovaná na bankovní pohyb (source zůstává mark_paid, bt_id doplněn).
        $this->invoicePayment($this->supplierId, $inv, 12100.0, 'mark_paid', $tx);

        $res = $this->fullYear($this->supplierId);

        $orphan = array_values(array_filter($res['warnings'], static fn ($w) => ($w['type'] ?? '') === 'orphan_bank_payments'));
        self::assertNotEmpty($orphan, 'Rekonciliovaná mark_paid platba na osiřelém výpisu musí vyvolat varování (MED-1).');
        self::assertTrue($orphan[0]['blocking'], 'Varování je blokující.');
        self::assertGreaterThanOrEqual(1, (int) $orphan[0]['count'], 'Alespoň 1 osiřelá bankovní úhrada (jakýkoli source).');
        self::assertSame(0, $this->countRows($res, 'bank'), 'Osiřelý výpis se do nohy B nedostane.');
        self::assertSame(0, $this->countRows($res, 'invoice_payment'), 'C1 platbu vypouští (bt_id doplněn) — bez varování by tiše zmizela.');
    }

    // ── MED-2: cancelled invoice + alreadyPaid bank match must not vanish ────

    /**
     * MED-2: mark_paid faktura, banka na ni navázala (alreadyPaid — matched_invoice_id, žádná
     * source='bank' platba), pak je faktura STORNOVÁNA. C1 ji dropne (cancelled), skip-guard nohy B
     * ale dřív stále fungoval (ip řádek existoval) → fyzický příjem nebyl v žádné noze. Po fixu
     * skip-guard vyžaduje nestornovanou fakturu → pohyb propadne do klasifikace jako nezařazený
     * (R10 blokující varování), nezmizí tiše.
     */
    public function testCancelledAlreadyPaidBankTxSurfacesAsUnclassified(): void
    {
        $inv = $this->saleInvoice($this->supplierId, ['without' => 10000.0, 'with' => 12100.0, 'status' => 'paid', 'paid_at' => self::YEAR . '-06-15']);
        $this->invoicePayment($this->supplierId, $inv, 12100.0, 'mark_paid'); // bt_id NULL (virtuální C1)
        // alreadyPaid: banka jen navázala matched_invoice_id, žádná source='bank' platba.
        $st = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($st, 12100.0, ['matched_invoice_id' => $inv, 'match_status' => 'auto_exact']);
        // Faktura později stornována.
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")->execute([$inv]);

        $res = $this->fullYear($this->supplierId);

        self::assertSame(1, $this->countRows($res, 'bank'), 'Stornovaná alreadyPaid banka propadne do klasifikace (MED-2), nezmizí.');
        self::assertEqualsWithDelta(12100.0, $res['totals']['nezarazeno'], 0.01, 'Objeví se jako nezařazený pohyb.');
        self::assertEqualsWithDelta(0.0, $res['totals']['prijem_danovy'], 0.01, 'Stornovaná faktura NENÍ daňový příjem.');
        $blocking = array_values(array_filter(
            $res['warnings'],
            static fn ($w) => ($w['blocking'] ?? false) && ($w['source_type'] ?? '') === 'bank' && (int) ($w['source_id'] ?? 0) === $tx
        ));
        self::assertNotEmpty($blocking, 'Musí vzniknout blokující R10 varování pro nezařazený příchozí pohyb.');
    }

    // ── 8a: CZK bank tx paying EUR PF converts by BT currency, not PF ───────

    /**
     * 8a: CZK bankovní úhrada EUR přijaté faktury. pm.amount je v měně bankovního pohybu (CZK),
     * NE v měně PF — převod na CZK proto musí jít kurzem měny bt (zde CZK = 1), ne pi.exchange_rate
     * (24,50), jinak by se daňový výdaj nadhodnotil ~24×. Daňový poměr (bez/s DPH) je bezrozměrný.
     */
    public function testCzkBankExpensePayingEurPurchaseConvertsByBtCurrency(): void
    {
        $eur = $this->eurCurrency($this->supplierId);
        $pdo = $this->db->pdo();
        // EUR PF: bez DPH 826,45 / s DPH 1 000 EUR, kurz PF 24,50 (poměr základu je bezrozměrný).
        $pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, tax_deductible, status, paid_at, created_by)
             VALUES (?, ?, ?, ?, 'invoice', 'full', ?, ?, ?, ?, ?, 24.50, 0, 0, 826.45, 173.55, 1000.00, 1, 'paid', ?, ?)"
        )->execute([
            $this->supplierId, $this->vendorId, 'PF-EUR-' . random_int(100000, 999999),
            json_encode(['company_name' => 'Dodavatel A s.r.o.'], JSON_UNESCAPED_UNICODE),
            self::YEAR . '-06-10', self::YEAR . '-06-10', self::YEAR . '-06-10', self::YEAR . '-06-10',
            $eur, self::YEAR . '-06-20', $this->userId,
        ]);
        $pf = (int) $pdo->lastInsertId();
        // CZK bankovní úhrada 24 200 CZK (pm.amount = $absAmount v měně bt = CZK).
        $st = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($st, -24200.0, ['posted_at' => self::YEAR . '-06-20']);
        $this->paymentMatch($this->supplierId, $tx, $pf, 24200.0);

        $res = $this->fullYear($this->supplierId);

        self::assertSame(1, $this->countRows($res, 'bank'), 'Odchozí úhrada = jeden řádek.');
        // Daňový výdaj = pm.amount (CZK) × poměr 826,45/1000 = 24 200 × 0,82645 = 20 000,09 CZK.
        self::assertEqualsWithDelta(20000.09, $res['totals']['vydaj_danovy'], 0.05, 'Základ v CZK přes kurz měny bt, ne PF (8a).');
        self::assertLessThan(100000.0, $res['totals']['vydaj_danovy'], 'Nesmí být přepočteno kurzem PF (nadhodnocení ~24×).');
        // Nic nezmizí: základ + nedaňový zbytek = celá CZK úhrada 24 200.
        self::assertEqualsWithDelta(4199.91, $res['totals']['vydaj_nedanovy'], 0.05, 'Zbytek (DPH složka) 24 200 − 20 000,09.');
        self::assertSame(0, $this->countRows($res, 'purchase_invoice'), 'PF s payment_matches není i ve virtuální noze C2.');
    }
}
