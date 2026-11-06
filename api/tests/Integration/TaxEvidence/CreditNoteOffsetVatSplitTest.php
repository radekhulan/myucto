<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Repository\VatCoefficientRepository;
use MyInvoice\Service\Invoice\CreditNoteOffsetService;
use MyInvoice\Service\Report\VatLedgerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Úhrada zbytku faktury po zápočtu dobropisu u plátce DPH: daňová část a DPH úhrady
 * musí odpovídat faktuře minus dobropis, ne poměru DPH celé faktury.
 *
 * Zápočet dobropisu není peněžní tok (migrace 1988): do peněžního deníku vstupuje jen
 * skutečně zaplacený zbytek faktury. Má-li faktura položky s různými sazbami DPH a dobropis
 * vrací jen některé (celé nebo část kusů), zbytek nemá složení celé faktury. Rozdělení
 * zbytku poměrem DPH celé faktury pak nadsadí nebo podhodnotí daňový příjem a výdaj.
 * U faktury s jednou sazbou má dobropis týž poměr a rozdělení sedí.
 *
 * Peněžní deník sazby nerozlišuje (zná jen daňovou část a DPH). Po sazbách se proto
 * kontroluje evidence DPH, ze které se skládá přiznání i kontrolní hlášení: dobropis smí
 * snížit jen sazby svých položek, ostatní sazby zůstanou přesně jako na faktuře, a zápočet
 * ani úhrada na evidenci DPH nic nemění.
 *
 * Stejné rozdělení žije na každé cestě úhrady do peněžního deníku, proto se ověřují
 * všechny: banka, pokladna a ruční úhrada, na vydané i přijaté straně.
 */
#[Group('integration')]
final class CreditNoteOffsetVatSplitTest extends CashJournalTestCase
{
    private CreditNoteOffsetService $offsets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->offsets = $this->container->get(CreditNoteOffsetService::class);
    }

    public static function reviewOverrideCases(): iterable
    {
        foreach (['issued', 'purchase'] as $side) {
            foreach (['bank', 'cash'] as $instrument) {
                foreach (['taxable', 'nontax', 'private', 'transfer', 'opposite', 'income_exempt'] as $override) {
                    yield "$side/$instrument/$override" => [$side, $instrument, $override, 'normal'];
                }
                foreach (['zero', 'overpaid', 'mixed', ...($side === 'issued' ? ['exempt'] : ['fixed_asset', 'nondeductible', 'reduced', 'proportional'])] as $profile) {
                    yield "$side/$instrument/$profile" => [$side, $instrument, 'taxable', $profile];
                }
            }
        }
    }

    #[DataProvider('reviewOverrideCases')]
    public function testReviewTaxableOverrideStillNeedsLaterOffsetCorrection(string $side, string $instrument, string $override, string $profile): void
    {
        $issued = $side === 'issued';
        $previous = self::YEAR - 1;
        $invoiceId = $this->document($issued, $profile === 'zero' ? [[21, 1.0, 1000.0]] : [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false, $previous . '-12-01');
        $creditNoteId = $this->document($issued, [[21, $profile === 'zero' || $profile === 'overpaid' ? -0.4 : -1.0, 1000.0]], true);
        $pdo = $this->db->pdo();
        if ($issued && in_array($profile, ['exempt', 'mixed'], true)) {
            $pdo->prepare('UPDATE invoices SET income_tax_exempt = 1 WHERE id IN (?, ?)')->execute([$invoiceId, $creditNoteId]);
        } elseif (!$issued) {
            if (in_array($profile, ['nondeductible', 'mixed'], true)) {
                $pdo->prepare('UPDATE purchase_invoices SET tax_deductible = 0 WHERE id IN (?, ?)')->execute([$invoiceId, $creditNoteId]);
            } elseif ($profile === 'fixed_asset') {
                $pdo->prepare('UPDATE purchase_invoices SET is_fixed_asset = 1 WHERE id IN (?, ?)')->execute([$invoiceId, $creditNoteId]);
            } elseif (in_array($profile, ['reduced', 'proportional'], true)) {
                $pdo->prepare('UPDATE purchase_invoices SET vat_deduction = ?, vat_deduction_percent = 60 WHERE id IN (?, ?)')->execute([$profile, $invoiceId, $creditNoteId]);
                $this->container->get(VatCoefficientRepository::class)->setProvisionalPercent($this->supplierId, $previous, 60);
            }
        }
        $amount = match ($profile) { 'zero', 'mixed' => 500.0, 'overpaid' => 2300.0, default => 1000.0 };
        $offsetId = $profile === 'overpaid' ? $this->offset($issued, $invoiceId, $creditNoteId) : null;
        $this->pay($issued, $instrument, $invoiceId, $amount, $previous . '-12-15', false);
        if ($instrument === 'bank') {
            $query = $issued
                ? 'SELECT bank_transaction_id FROM invoice_payments WHERE supplier_id=? AND invoice_id=? AND bank_transaction_id IS NOT NULL'
                : 'SELECT bank_transaction_id FROM payment_matches WHERE supplier_id=? AND purchase_invoice_id=?';
        } else {
            $query = 'SELECT id FROM cash_documents WHERE supplier_id=? AND ' . ($issued ? 'invoice_id' : 'purchase_invoice_id') . '=?';
        }
        $stmt = $pdo->prepare($query);
        $stmt->execute([$this->supplierId, $invoiceId]);
        $sourceId = (int)$stmt->fetchColumn();
        self::assertGreaterThan(0, $sourceId);
        $bucket = match ($override) {
            'transfer', 'private', 'income_exempt' => $override,
            'opposite' => $issued ? 'expense_taxable' : 'income_taxable',
            default => ($issued ? 'income_' : 'expense_') . $override,
        };
        $this->overrideMovement($instrument, $sourceId, $bucket);
        if ($profile === 'mixed') {
            $this->pay($issued, $instrument === 'bank' ? 'cash' : 'bank', $invoiceId, 500.0, $previous . '-12-16', false);
        }

        $before = $this->year($previous);
        $offsetId ??= $this->offset($issued, $invoiceId, $creditNoteId);
        $after = $this->year($previous);
        $current = $this->fullYear($this->supplierId, true);
        $taxableKey = $issued ? 'prijem_danovy' : 'vydaj_danovy';
        self::assertSame($before['totals'], $after['totals'], 'Predchozi rok se nesmi zmenit');
        self::assertSame($before['rows'], $after['rows'], 'Zůstanou i původní alokace, základ, DPH a peněžní obrat');
        if ($override === 'taxable') {
            $expectedBefore = match ($profile) {
                'zero' => 413.22, 'mixed' => 452.49,
                'overpaid' => $issued ? 2090.0 : 2081.45,
                default => 904.98,
            };
            $expectedDelta = match ($profile) {
                'zero' => 0.0, 'mixed' => 47.51,
                'overpaid' => $issued ? 84.0 : 50.65,
                default => 95.02,
            };
            self::assertEqualsWithDelta($expectedBefore, $before['totals'][$taxableKey], 0.005);
            self::assertEqualsWithDelta($expectedDelta, $current['totals'][$taxableKey], 0.005, 'Ruční daňová klasifikace potřebuje shodné rozdělení před a po zápočtu');
            self::assertSame($profile === 'zero' ? 0 : 1, $this->countRows($current, 'credit_note_offset'));
            if ($issued) {
                self::assertEqualsWithDelta($profile === 'mixed' ? 47.51 : 0.0, $current['totals']['prijem_osvobozeny'], 0.005);
            }
            $expectedNontax = $profile === 'mixed' ? ($issued ? -47.51 : 0.0) : 0.0;
            self::assertEqualsWithDelta($expectedNontax, $current['totals'][$issued ? 'prijem_nedanovy' : 'vydaj_nedanovy'], 0.005,
                'Korekce ruční klasifikace zachová její alokaci, automatická část vyrovná DPH');
            if ($expectedDelta != 0.0) {
                $row = $this->onlyRow($current, static fn (array $r): bool => $r['source_type'] === 'credit_note_offset');
                self::assertEqualsWithDelta(-($expectedDelta + ($profile === 'mixed' && $issued ? 47.51 : 0.0)), $row['vat'], 0.005);
                self::assertEqualsWithDelta(0.0, (float) $row['income'] + (float) $row['expense'], 0.005, 'Zápočet není peněžní pohyb');
                self::assertEqualsWithDelta($expectedDelta, $row['base'], 0.005);
            }
        } else {
            self::assertSame(0, $this->countRows($current, 'credit_note_offset'));
            self::assertEqualsWithDelta(0.0, $current['totals'][$taxableKey], 0.005);
            $controlKey = match ($override) {
                'transfer' => 'prevody', 'private' => 'private', 'income_exempt' => 'prijem_osvobozeny',
                'opposite' => $issued ? 'vydaj_danovy' : 'prijem_danovy',
                default => $issued ? 'prijem_nedanovy' : 'vydaj_nedanovy',
            };
            self::assertEqualsWithDelta(1000.0, $before['totals'][$controlKey], 0.005);
        }
    }

    public static function bankOverrideSplitCases(): iterable
    {
        foreach (['issued', 'purchase'] as $side) {
            foreach (['primary', 'secondary', 'opposite', 'primary_overpaid', 'secondary_overpaid'] as $target) {
                yield "$side/$target" => [$side, $target];
            }
        }
    }

    #[DataProvider('bankOverrideSplitCases')]
    public function testBankOverrideUsesWholeMovementAndActualPrimaryDocument(string $side, string $target): void
    {
        $issued = $side === 'issued';
        $previous = self::YEAR - 1;
        $first = $this->document($issued, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false, $previous . '-12-01');
        $second = $this->document($issued, [[12, 1.0, 1000.0], [0, 1.0, 1000.0]], false, $previous . '-12-01');
        $overpaid = str_ends_with($target, '_overpaid');
        $amount = $overpaid ? 3000.0 : 1000.0;
        $tx = $this->bankTx($this->statement($this->supplierId, $this->accountA), $issued ? $amount : -$amount, ['posted_at' => $previous . '-12-15']);
        foreach ([$first, $second] as $id) {
            if ($issued) $this->invoicePayment($this->supplierId, $id, $amount / 2, 'bank', $tx, $previous . '-12-15');
            else $this->paymentMatch($this->supplierId, $tx, $id, $amount / 2);
        }
        $bucket = ($issued xor $target === 'opposite') ? 'income_taxable' : 'expense_taxable';
        $this->overrideMovement('bank', $tx, $bucket);
        $raw = array_values(array_filter($this->repo->movements($this->supplierId, $previous . '-01-01', $previous . '-12-31', true),
            static fn (array $r): bool => $r['source_type'] === 'bank' && (int) $r['source_id'] === $tx));
        self::assertCount(1, $raw);
        self::assertSame($first, (int) $raw[0][$issued ? 'invoice_id' : 'purchase_invoice_id']);
        self::assertNull($raw[0][$issued ? 'purchase_invoice_id' : 'invoice_id']);
        $before = $this->year($previous);
        $secondary = str_starts_with($target, 'secondary');
        $invoiceId = $secondary ? $second : $first;
        $creditNoteId = $this->document($issued, [[$secondary ? 12 : 21, -1.0, 1000.0]], true);
        if ($overpaid && !$issued) {
            // Zpětně dopárovaná platba zachová dnešní pravidlo služby pro započtení.
            $this->db->pdo()->prepare('DELETE FROM payment_matches WHERE supplier_id=? AND bank_transaction_id=?')->execute([$this->supplierId, $tx]);
        }
        $this->offset($issued, $invoiceId, $creditNoteId);
        if ($overpaid && !$issued) foreach ([$first, $second] as $id) $this->paymentMatch($this->supplierId, $tx, $id, $amount / 2);
        self::assertSame($before['rows'], $this->year($previous)['rows']);
        $current = $this->fullYear($this->supplierId, true);
        $key = $bucket === 'income_taxable' ? 'prijem_danovy' : 'vydaj_danovy';
        self::assertEqualsWithDelta($target === 'opposite' ? 1000.0 : ($overpaid ? 2714.93 : 904.98), $before['totals'][$key], 0.005);
        $expected = match ($target) { 'primary' => 95.02, 'primary_overpaid' => 285.07, 'secondary_overpaid' => $issued ? 47.51 : 0.0, default => 0.0 };
        self::assertEqualsWithDelta($expected, $current['totals'][$key], 0.005,
            'Ruční klasifikace používá celou banku a poměr skutečně připojeného prvního dokladu');
        self::assertSame($expected == 0.0 ? 0 : 1, $this->countRows($current, 'credit_note_offset'));
    }

    public function testWholeBankOverrideKeepsVatOfFullyPaidInvoiceAfterZeroRateCreditNote(): void
    {
        $previous = self::YEAR - 1;
        $invoiceId = $this->document(true, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false, $previous . '-12-01');
        $this->pay(true, 'bank', $invoiceId, 2210.0, $previous . '-12-15', false);
        $tx = (int) $this->db->pdo()->query('SELECT bank_transaction_id FROM invoice_payments WHERE invoice_id=' . $invoiceId)->fetchColumn();
        $this->overrideMovement('bank', $tx, 'income_taxable');
        $before = $this->year($previous);
        self::assertEqualsWithDelta(2000.0, $before['totals']['prijem_danovy'], 0.005);
        self::assertSame(210.0, $before['rows'][0]['vat']);
        $this->offset(true, $invoiceId, $this->document(true, [[0, -1.0, 500.0]], true));
        self::assertSame($before['rows'], $this->year($previous)['rows']);
        $current = $this->fullYear($this->supplierId, true);
        self::assertSame(0, $this->countRows($current, 'credit_note_offset'));
        self::assertEqualsWithDelta(0.0, $current['totals']['prijem_danovy'], 0.005, 'Přeplatek 500 nepřidává další DPH');
    }

    public function testLegacyBankTaxableOverrideUsesItsActualInvoiceContext(): void
    {
        $previous = self::YEAR - 1;
        $invoiceId = $this->document(true, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false, $previous . '-12-01');
        $tx = $this->bankTx($this->statement($this->supplierId, $this->accountA), 1000.0,
            ['posted_at' => $previous . '-12-15', 'matched_invoice_id' => $invoiceId, 'match_status' => 'manual']);
        $this->overrideMovement('bank', $tx, 'income_taxable');
        $before = $this->year($previous);
        self::assertSame(1, $this->countRows($before, 'bank'));
        self::assertEqualsWithDelta(904.98, $before['totals']['prijem_danovy'], 0.005);
        $this->offset(true, $invoiceId, $this->document(true, [[21, -1.0, 1000.0]], true));
        $current = $this->fullYear($this->supplierId, true);
        self::assertSame($before['rows'], $this->year($previous)['rows']);
        self::assertSame(1, $this->countRows($current, 'credit_note_offset'));
        self::assertEqualsWithDelta(95.02, $current['totals']['prijem_danovy'], 0.005);
    }

    public function testWholeBankOverrideSuccessiveOffsetsAlsoAdjustSecondaryCoverage(): void
    {
        $previous = self::YEAR - 1;
        $first = $this->document(true, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false, $previous . '-12-01');
        $second = $this->document(true, [[12, 1.0, 1000.0], [0, 1.0, 1000.0]], false, $previous . '-12-01');
        $tx = $this->bankTx($this->statement($this->supplierId, $this->accountA), 3000.0, ['posted_at' => $previous . '-12-15']);
        foreach ([$first, $second] as $id) $this->invoicePayment($this->supplierId, $id, 1500.0, 'bank', $tx, $previous . '-12-15');
        $this->overrideMovement('bank', $tx, 'income_taxable');
        $before = $this->year($previous);
        $firstOffset = $this->offset(true, $first, $this->document(true, [[21, -1.0, 400.0]], true));
        $secondOffset = $this->offset(true, $second, $this->document(true, [[12, -1.0, 1000.0]], true, self::YEAR . '-06-06'));
        $current = $this->fullYear($this->supplierId, true);
        self::assertSame($before['rows'], $this->year($previous)['rows']);
        $one = $this->onlyRow($current, static fn (array $r): bool => $r['source_type'] === 'credit_note_offset' && $r['source_id'] === $firstOffset);
        $two = $this->onlyRow($current, static fn (array $r): bool => $r['source_type'] === 'credit_note_offset' && $r['source_id'] === $secondOffset);
        self::assertEqualsWithDelta(66.07, $one['base'], 0.005);
        self::assertEqualsWithDelta(36.50, $two['base'], 0.005);
        self::assertEqualsWithDelta(2817.50, $before['totals']['prijem_danovy'] + $current['totals']['prijem_danovy'], 0.005);
    }

    private function overrideMovement(string $instrument, int $sourceId, string $bucket): void
    {
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', '/api/tax-evidence/classification')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody(['source_type' => $instrument, 'source_id' => $sourceId, 'tax_bucket' => $bucket]);
        $response = $this->container->get(\MyInvoice\Action\TaxEvidence\MovementClassificationAction::class)
            ->create($request, new \Slim\Psr7\Response());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testCandidateStatementOwnershipRetainsLegacyAmbiguityAndExplicitOwners(): void
    {
        $other = $this->cloneSupplier('tax_evidence', true);
        $this->currencyRow($other, 'CZK', $this->accountA, '0300');
        $owned = $this->statement($this->supplierId, '771234567');
        $foreign = $this->statement($other, $this->accountA);
        $legacy = $this->statement($this->supplierId, $this->accountA);
        $ambiguous = $this->statement($this->supplierId, $this->accountA);
        $outside = $this->statement($this->supplierId, $this->accountA);
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE bank_statements SET supplier_id=? WHERE id=?')->execute([$this->supplierId, $owned]);
        $pdo->prepare('UPDATE bank_statements SET supplier_id=? WHERE id=?')->execute([$other, $foreign]);
        $pdo->prepare('UPDATE bank_statements SET bank_code=NULL WHERE id=?')->execute([$ambiguous]);
        $candidates = [$owned, $foreign, $legacy, $ambiguous];
        $expected = [$owned, $legacy];
        $actual = $this->repo->matchingStatementIds($this->supplierId, $candidates);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
        self::assertNotContains($outside, $actual);
        self::assertSame([], $this->repo->matchingStatementIds($this->supplierId, []));
        self::assertContains($outside, $this->repo->matchingStatementIds($this->supplierId));
        $this->currencyRow($other, 'CZK', $this->accountA, '2010');
        self::assertSame([$owned], $this->repo->matchingStatementIds($this->supplierId, $candidates));
    }

    public function testOffsetOverrideDoesNotReviveLegacyNoticeCoveredByAnotherOfficialStatement(): void
    {
        $previous = self::YEAR - 1;
        $invoiceId = $this->document(true, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false, $previous . '-12-01');
        $noticeStatement = $this->statement($this->supplierId, $this->accountA);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE bank_statements SET source='email_notice' WHERE id=?")->execute([$noticeStatement]);
        $notice = $this->bankTx($noticeStatement, 1000.0, ['posted_at' => $previous . '-12-15']);
        $pdo->prepare("UPDATE bank_transactions SET source='email_notice' WHERE id=?")->execute([$notice]);
        $this->invoicePayment($this->supplierId, $invoiceId, 1000.0, 'bank', $notice, $previous . '-12-15');
        $this->overrideMovement('bank', $notice, 'income_taxable');
        $official = $this->bankTx($this->statement($this->supplierId, $this->accountA), 1.0, ['posted_at' => $previous . '-12-15']);
        $before = $this->year($previous);
        self::assertSame(1, $this->countRows($before, 'bank'));
        self::assertSame($official, $before['rows'][0]['source_id']);
        self::assertEqualsWithDelta(1.0, $before['rows'][0]['income'], 0.005);
        self::assertEqualsWithDelta(0.0, $before['totals']['prijem_danovy'], 0.005);
        $this->offset(true, $invoiceId, $this->document(true, [[21, -1.0, 1000.0]], true));
        self::assertSame($before['rows'], $this->year($previous)['rows']);
        self::assertSame(0, $this->countRows($this->fullYear($this->supplierId, true), 'credit_note_offset'));
    }

    public static function missingFxOverrideCases(): iterable
    {
        foreach ([false, true] as $nextYear) {
            $year = $nextYear ? 'další rok' : 'stejný rok';
            yield $year . ', neoceněná platba' => [$nextYear, false, true];
            foreach ([false, true] as $knownOverride) {
                yield $year . ($knownOverride ? ', známá ruční klasifikace' : ', známá automatická úhrada') => [$nextYear, true, $knownOverride];
            }
        }
    }

    #[DataProvider('missingFxOverrideCases')]
    public function testMissingFxOverrideKeepsBlockingWarningAndKnownOffsetPart(bool $nextYear, bool $mixed, bool $knownOverride): void
    {
        $pdo = $this->db->pdo();
        $this->currencyId = $this->currencyRow($this->supplierId, 'XTS', null, null);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM exchange_rates WHERE currency_code='XTS'")->fetchColumn());
        $paymentYear = $nextYear ? self::YEAR - 1 : self::YEAR;
        $paymentDate = $paymentYear . ($nextYear ? '-12-15' : '-06-03');
        $invoiceId = $this->document(false, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false, $paymentYear . '-06-01');
        $creditNoteId = $this->document(false, [[21, -1.0, 1000.0]], true);
        $pdo->prepare('UPDATE purchase_invoices SET exchange_rate=NULL WHERE id IN (?, ?)')->execute([$invoiceId, $creditNoteId]);
        $tx = $this->bankTx($this->statement($this->supplierId, $this->accountA), -500.0, ['posted_at' => $paymentDate]);
        $pdo->prepare("UPDATE bank_transactions SET currency='XTS' WHERE id=?")->execute([$tx]);
        $this->paymentMatch($this->supplierId, $tx, $invoiceId, 500.0);
        $this->overrideMovement('bank', $tx, 'expense_taxable');
        if ($mixed) {
            $known = $this->cashDoc('out', 'purchase_payment', 500.0, ['purchase_invoice_id' => $invoiceId], null, $paymentDate);
            $pdo->prepare("UPDATE cash_documents SET currency_code='XTS', fx_rate=2, amount_foreign=250 WHERE id=?")->execute([$known]);
            if ($knownOverride) $this->overrideMovement('cash', $known, 'expense_taxable');
        }
        $before = $this->year($paymentYear);
        self::assertCount(1, array_filter($before['warnings'], static fn (array $w): bool => ($w['type'] ?? '') === 'fx_rate_missing'));
        $oldRow = $this->onlyRow($before, static fn (array $r): bool => $r['source_type'] === 'bank' && $r['source_id'] === $tx);
        self::assertTrue($oldRow['fx_rate_missing']);
        self::assertSame(0.0, $oldRow['expense']);
        $this->offset(false, $invoiceId, $creditNoteId);
        $after = $this->fullYear($this->supplierId, true);
        $warnings = array_values(array_filter($after['warnings'], static fn (array $w): bool => ($w['type'] ?? '') === 'fx_rate_missing'));
        self::assertCount(1, $warnings, 'Neoceněná starší banka nesmí ztratit blokující upozornění ani mimo zobrazené období');
        self::assertTrue($warnings[0]['blocking']);
        self::assertSame('bank', $warnings[0]['source_type']);
        self::assertSame($tx, $warnings[0]['source_id']);
        self::assertSame($paymentDate, $warnings[0]['date']);
        self::assertEqualsWithDelta($mixed ? ($nextYear ? 47.51 : 500.0) : 0.0, $after['totals']['vydaj_danovy'], 0.005);
        self::assertEqualsWithDelta($mixed && !$knownOverride && $nextYear ? -47.51 : 0.0, $after['totals']['vydaj_nedanovy'], 0.005);
        self::assertSame($mixed ? 1 : 0, $this->countRows($after, 'credit_note_offset'));
        if ($mixed) {
            $correction = $this->onlyRow($after, static fn (array $r): bool => $r['source_type'] === 'credit_note_offset');
            self::assertEqualsWithDelta(47.51, $correction['base'], 0.005);
            self::assertTrue($correction['fx_rate_missing']);
            self::assertTrue($correction['blocking']);
        }
        if ($nextYear) self::assertSame($before['rows'], $this->year($paymentYear)['rows']);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM exchange_rates WHERE currency_code='XTS'")->fetchColumn());
    }

    public static function previousYearMissingFxCases(): iterable
    {
        foreach (['purchase_bank', 'issued_bank', 'issued_manual'] as $path) {
            foreach ([false, true] as $mixed) yield $path . ($mixed ? ', známá pokladna' : ', neoceněná úhrada') => [$path, $mixed];
        }
    }

    #[DataProvider('previousYearMissingFxCases')]
    public function testPreviousYearMissingFxCorrectionKeepsWarningWithoutOverride(string $path, bool $mixed): void
    {
        $issued = str_starts_with($path, 'issued');
        $manual = $path === 'issued_manual';
        $previous = self::YEAR - 1;
        $date = $previous . '-06-03';
        $pdo = $this->db->pdo();
        $this->currencyId = $this->currencyRow($this->supplierId, 'XTS', null, null);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM exchange_rates WHERE currency_code='XTS'")->fetchColumn());
        $invoiceId = $this->document($issued, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false, $previous . '-06-01');
        $creditNoteId = $this->document($issued, [[21, -1.0, 1000.0]], true);
        $pdo->prepare('UPDATE ' . ($issued ? 'invoices' : 'purchase_invoices') . ' SET exchange_rate=NULL WHERE id IN (?, ?)')->execute([$invoiceId, $creditNoteId]);
        $sourceId = $manual ? null : $this->bankTx($this->statement($this->supplierId, $this->accountA), $issued ? 500.0 : -500.0, ['posted_at' => $date]);
        if ($sourceId !== null) $pdo->prepare("UPDATE bank_transactions SET currency='XTS' WHERE id=?")->execute([$sourceId]);
        if ($issued) {
            $paymentId = $this->invoicePayment($this->supplierId, $invoiceId, 500.0, $manual ? 'manual' : 'bank', $sourceId, $date);
            $pdo->prepare("UPDATE invoice_payments SET currency='XTS' WHERE id=?")->execute([$paymentId]);
            if ($manual) $sourceId = $paymentId;
        } else {
            $this->paymentMatch($this->supplierId, $sourceId, $invoiceId, 500.0);
        }
        if ($mixed) {
            $cash = $this->cashDoc($issued ? 'in' : 'out', $issued ? 'invoice_payment' : 'purchase_payment', 500.0,
                [$issued ? 'invoice_id' : 'purchase_invoice_id' => $invoiceId], null, $date);
            $pdo->prepare("UPDATE cash_documents SET currency_code='XTS', fx_rate=2, amount_foreign=250 WHERE id=?")->execute([$cash]);
            if ($issued) {
                $payment = $this->invoicePayment($this->supplierId, $invoiceId, 250.0, 'cash', null, $date);
                $pdo->prepare("UPDATE invoice_payments SET currency='XTS' WHERE id=?")->execute([$payment]);
                $pdo->prepare('UPDATE cash_documents SET invoice_payment_id=? WHERE id=?')->execute([$payment, $cash]);
            }
        }
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM de_movement_classification WHERE supplier_id=' . $this->supplierId)->fetchColumn());
        self::assertSame([], $this->fullYear($this->supplierId, true)['rows'], 'Před zápočtem jsou úhrady pouze v minulém roce');
        $this->offset($issued, $invoiceId, $creditNoteId);
        $after = $this->fullYear($this->supplierId, true);
        $warnings = array_values(array_filter($after['warnings'], static fn (array $w): bool => ($w['type'] ?? '') === 'fx_rate_missing'));
        self::assertCount(1, $warnings);
        self::assertTrue($warnings[0]['blocking']);
        self::assertSame($manual ? 'invoice_payment' : 'bank', $warnings[0]['source_type']);
        self::assertSame($sourceId, $warnings[0]['source_id']);
        self::assertSame($date, $warnings[0]['date']);
        self::assertEqualsWithDelta($mixed ? 47.51 : 0.0, $after['totals'][$issued ? 'prijem_danovy' : 'vydaj_danovy'], 0.005);
        self::assertSame($mixed ? 1 : 0, $this->countRows($after, 'credit_note_offset'));
        if ($mixed) {
            $row = $this->onlyRow($after, static fn (array $r): bool => $r['source_type'] === 'credit_note_offset');
            self::assertTrue($row['fx_rate_missing']);
            self::assertTrue($row['blocking']);
        }
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM exchange_rates WHERE currency_code='XTS'")->fetchColumn());
    }

    public static function roundedBankOverrideCases(): iterable
    {
        yield 'jediná faktura' => ['single', 0.20, 0.13, 0.14, 0.01];
        yield 'primární faktura splitu' => ['primary', 0.26, 0.17, 0.17, 0.0];
        yield 'sekundární faktura splitu' => ['secondary', 0.23, 0.15, 0.17, 0.02];
    }

    #[DataProvider('roundedBankOverrideCases')]
    public function testNativeReconciledBankOverrideRoundsEachPaymentOverpaymentBeforeSumming(string $shape, float $income, float $oldBase, float $targetBase, float $correction): void
    {
        $pdo = $this->db->pdo();
        $this->currencyId = $this->currencyRow($this->supplierId, 'CNY', null, null);
        $pdo->prepare('INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES (?, ?, ?), (?, ?, ?)')
            ->execute([self::YEAR . '-06-03', 'CNY', 3.25, self::YEAR . '-06-07', 'CNY', 3.25]);
        $payments = $this->container->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class);
        $scenarios = [];
        foreach ([self::YEAR . '-06-03', self::YEAR . '-06-07'] as $date) {
            $first = $this->document(true, [[21, 1.0, 0.10], [0, 1.0, -0.07]], false);
            $second = $shape === 'single' ? null : $this->document(true, [[21, 1.0, 0.10], [0, 1.0, -0.07]], false);
            $invoice = $shape === 'secondary' ? $second : $first;
            $credit = $this->document(true, [[0, 1.0, -0.01]], true);
            $pdo->prepare('UPDATE invoices SET exchange_rate=3.25 WHERE id IN (?, ?, ?)')->execute([$first, $second ?? $first, $credit]);
            $offset = $this->offset(true, $invoice, $credit);
            $shares = $shape === 'single' ? [$first => 0.06]
                : ($shape === 'primary' ? [$first => 0.06, $second => 0.02] : [$first => 0.01, $second => 0.06]);
            $bank = $this->bankTx($this->statement($this->supplierId, $this->accountA), array_sum($shares), ['posted_at' => $date]);
            $pdo->prepare("UPDATE bank_transactions SET currency='CNY' WHERE id=?")->execute([$bank]);
            foreach ($shares as $id => $amount) {
                $recorded = $payments->recordPayment($id, $amount, $date, ['source' => 'manual', 'created_by' => $this->userId]);
                self::assertSame($amount, $recorded['amount']);
                self::assertSame($amount, $payments->reconcileToBankTransaction($id, $bank)['amount']);
            }
            $this->overrideMovement('bank', $bank, 'income_taxable');
            $scenarios[] = [$bank, $offset];
        }
        $report = $this->fullYear($this->supplierId, true);
        $totals = [];
        foreach ($scenarios as $index => [$bank, $offset]) {
            $row = $this->onlyRow($report, static fn (array $r): bool => $r['source_type'] === 'bank' && $r['source_id'] === $bank);
            self::assertEqualsWithDelta($income, $row['income'], 0.0001);
            self::assertEqualsWithDelta($index === 0 ? $oldBase : $targetBase, $row['base'], 0.0001);
            $offsetRows = array_values(array_filter($report['rows'], static fn (array $r): bool => $r['source_type'] === 'credit_note_offset' && $r['source_id'] === $offset));
            self::assertCount($index === 0 && $correction != 0.0 ? 1 : 0, $offsetRows);
            $delta = $offsetRows[0]['base'] ?? 0.0;
            self::assertEqualsWithDelta($index === 0 ? $correction : 0.0, $delta, 0.0001);
            if ($offsetRows !== []) {
                self::assertEqualsWithDelta(-$delta, $offsetRows[0]['vat'], 0.0001);
                self::assertEqualsWithDelta(0.0, (float) $offsetRows[0]['income'] + (float) $offsetRows[0]['expense'], 0.0001);
            }
            $totals[] = round($row['base'] + $delta, 2);
        }
        self::assertSame([$targetBase, $targetBase], $totals, 'Zpětně evidovaná úhrada s korekcí se rovná stejné úhradě po zápočtu');
    }

    public static function roundedIssuedCorrectionCases(): iterable
    {
        foreach (['manual', 'bank', 'legacy_bank', 'legacy_override'] as $path) {
            yield $path . ', brutto 0.05' => [$path, -0.07, $path === 'manual' || $path === 'bank' ? 0.0 : 0.01, $path === 'bank' ? 0.13 : 0.14];
            yield $path . ', brutto 0.04' => [$path, -0.08, $path === 'bank' ? 0.0 : -0.01, 0.13];
        }
    }

    #[DataProvider('roundedIssuedCorrectionCases')]
    public function testNativeIssuedCorrectionPreservesItsSourceRoundingPolicy(string $path, float $rebate, float $correction, float $target): void
    {
        $pdo = $this->db->pdo();
        $this->currencyId = $this->currencyRow($this->supplierId, 'CNY', null, null);
        $pdo->prepare('INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES (?, ?, ?), (?, ?, ?)')
            ->execute([self::YEAR . '-06-03', 'CNY', 3.25, self::YEAR . '-06-07', 'CNY', 3.25]);
        $payments = $this->container->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class);
        $scenarios = [];
        foreach ([self::YEAR . '-06-03', self::YEAR . '-06-07'] as $date) {
            $invoice = $this->document(true, [[21, 1.0, 0.10], [0, 1.0, $rebate]], false);
            $credit = $this->document(true, [[0, 1.0, -0.01]], true);
            $pdo->prepare('UPDATE invoices SET exchange_rate=3.25 WHERE id IN (?, ?)')->execute([$invoice, $credit]);
            $offset = $this->offset(true, $invoice, $credit);
            $bank = null;
            if ($path !== 'manual') {
                $bank = $this->bankTx($this->statement($this->supplierId, $this->accountA), 0.06,
                    ['posted_at' => $date, 'matched_invoice_id' => $invoice, 'match_status' => 'manual']);
                $pdo->prepare("UPDATE bank_transactions SET currency='CNY' WHERE id=?")->execute([$bank]);
            }
            if (in_array($path, ['manual', 'bank'], true)) {
                $recorded = $payments->recordPayment($invoice, 0.06, $date, ['source' => 'manual', 'created_by' => $this->userId]);
                self::assertSame(0.06, $recorded['amount']);
                if ($bank !== null) self::assertSame(0.06, $payments->reconcileToBankTransaction($invoice, $bank)['amount']);
                $source = $bank === null ? $recorded['payment_id'] : $bank;
            } else {
                $source = $bank;
            }
            if ($path === 'legacy_override') $this->overrideMovement('bank', $bank, 'income_taxable');
            $scenarios[] = [$source, $offset];
        }
        $report = $this->fullYear($this->supplierId, true);
        $totals = [];
        foreach ($scenarios as $index => [$source, $offset]) {
            $type = $path === 'manual' ? 'invoice_payment' : 'bank';
            $row = $this->onlyRow($report, static fn (array $r): bool => $r['source_type'] === $type && $r['source_id'] === $source);
            self::assertEqualsWithDelta(0.20, $row['income'], 0.0001);
            $offsetRows = array_values(array_filter($report['rows'], static fn (array $r): bool => $r['source_type'] === 'credit_note_offset' && $r['source_id'] === $offset));
            $delta = $offsetRows[0]['base'] ?? 0.0;
            self::assertEqualsWithDelta($index === 0 ? $correction : 0.0, $delta, 0.0001);
            $totals[] = round($row['base'] + $delta, 2);
        }
        self::assertSame([$target, $target], $totals, 'C1, bankovní agregace a legacy banka zachovají vlastní zaokrouhlovací pravidlo');
    }

    /**
     * Položky faktury a dobropisu [sazba %, počet kusů, cena za kus bez DPH] (dobropis vrací
     * kusy záporně), očekávaná evidence DPH faktury a dobropisu dohromady po sazbách
     * [sazba => [základ, DPH]] a rozdělení zaplaceného zbytku [daňová část, DPH].
     *
     * @return array<string,array{invoice:list<array{0:int,1:float,2:float}>,credit_note:list<array{0:int,1:float,2:float}>,by_rate:array<int,array{0:float,1:float}>,payment:array{0:float,1:float}}>
     */
    private static function shapes(): array
    {
        return [
            // Faktura: služba 21 % 1 000 + 210 a položka 0 % 1 000 = 2 210. Dobropis stornuje
            // celou službu. Zaplacený zbytek 1 000 je jen položka bez DPH.
            'sazby 21 % a 0 %, dobropis stornuje položku 21 %' => [
                'invoice' => [[21, 1.0, 1000.0], [0, 1.0, 1000.0]],
                'credit_note' => [[21, -1.0, 1000.0]],
                'by_rate' => [0 => [1000.0, 0.0], 21 => [0.0, 0.0]],
                'payment' => [1000.0, 0.0],
            ],
            // Faktura: zboží 21 % 10 ks × 100 = 1 000 + 210 a položka 0 % 1 000 = 2 210.
            // Dobropis vrací 4 ks zboží: −400 − 84. Zaplacený zbytek 1 726 = 600 + 1 000 + DPH 126.
            'sazby 21 % a 0 %, dobropis vrací 4 z 10 ks zboží 21 %' => [
                'invoice' => [[21, 10.0, 100.0], [0, 1.0, 1000.0]],
                'credit_note' => [[21, -4.0, 100.0]],
                'by_rate' => [0 => [1000.0, 0.0], 21 => [600.0, 126.0]],
                'payment' => [1600.0, 126.0],
            ],
            // Faktura: dvě položky 12 % (500 + 60 a 3 ks × 100 = 300 + 36) a položka 21 %
            // 1 000 + 210 = 2 106. Dobropis stornuje druhou položku 12 %: −300 − 36. Sazba 21 %
            // zůstane nedotčená. Zaplacený zbytek 1 770 = 500 + 1 000 + DPH 60 + 210.
            'dvě položky 12 % a položka 21 %, dobropis stornuje jednu položku 12 %' => [
                'invoice' => [[12, 1.0, 500.0], [12, 3.0, 100.0], [21, 1.0, 1000.0]],
                'credit_note' => [[12, -3.0, 100.0]],
                'by_rate' => [12 => [500.0, 60.0], 21 => [1000.0, 210.0]],
                'payment' => [1500.0, 270.0],
            ],
            // Faktura: položka 0 % 1 000 a služba 21 % 1 000 + 210 = 2 210. Opravný dobropis
            // vrací položku 0 % (−1 000) a doúčtuje službu 21 % 500 + 105: brutto −395, DPH +105,
            // tedy DPH a brutto s opačným znaménkem. Zaplacený zbytek 1 815 = 1 500 + DPH 315.
            'sazby 0 % a 21 %, opravný dobropis s opačným znaménkem DPH a brutto' => [
                'invoice' => [[0, 1.0, 1000.0], [21, 1.0, 1000.0]],
                'credit_note' => [[0, -1.0, 1000.0], [21, 1.0, 500.0]],
                'by_rate' => [0 => [0.0, 0.0], 21 => [1500.0, 315.0]],
                'payment' => [1500.0, 315.0],
            ],
            // Kontrola: faktura jen se sazbou 21 %, 10 ks × 100 = 1 000 + 210. Dobropis vrací
            // 4 ks: −400 − 84, týž poměr DPH. Zaplacený zbytek 726 = 600 + DPH 126.
            'jen sazba 21 %, dobropis vrací 4 z 10 ks' => [
                'invoice' => [[21, 10.0, 100.0]],
                'credit_note' => [[21, -4.0, 100.0]],
                'by_rate' => [21 => [600.0, 126.0]],
                'payment' => [600.0, 126.0],
            ],
        ];
    }

    /** @return iterable<string,array{0:string,1:string}> strana dokladu a cesta úhrady do peněžního deníku */
    public static function paths(): iterable
    {
        foreach (['issued' => 'vydaná', 'purchase' => 'přijatá'] as $side => $sideLabel) {
            foreach (['bank' => 'banka', 'cash' => 'pokladna', 'manual' => 'ruční úhrada'] as $instrument => $instrumentLabel) {
                yield "{$sideLabel}, {$instrumentLabel}" => [$side, $instrument];
            }
        }
    }

    /** @return iterable<string,array{0:string,1:string,2:array<string,mixed>}> */
    public static function cases(): iterable
    {
        foreach (self::shapes() as $shape => $documents) {
            foreach (self::paths() as $path => [$side, $instrument]) {
                yield "{$path}, {$shape}" => [$side, $instrument, $documents];
            }
        }
    }

    /**
     * @param array{invoice:list<array{0:int,1:float,2:float}>,credit_note:list<array{0:int,1:float,2:float}>,by_rate:array<int,array{0:float,1:float}>,payment:array{0:float,1:float}} $shape
     */
    #[DataProvider('cases')]
    public function testRemainderAfterCreditNoteOffsetIsSplitAsInvoiceMinusCreditNote(string $side, string $instrument, array $shape): void
    {
        $issued = $side === 'issued';
        $invoiceId = $this->document($issued, $shape['invoice'], false);
        $creditNoteId = $this->document($issued, $shape['credit_note'], true);
        $this->db->pdo()->prepare($issued
            ? 'UPDATE invoices SET parent_invoice_id = ? WHERE id = ?'
            : 'UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')->execute([$invoiceId, $creditNoteId]);

        // Evidence DPH po sazbách: faktura minus dobropis, sazby mimo dobropis jako na faktuře.
        $vatByRate = $this->vatByRate($issued, [$invoiceId, $creditNoteId]);
        self::assertSame($shape['by_rate'], $vatByRate, 'evidence DPH po sazbách [základ, DPH] = faktura minus dobropis');
        $invoiceOnly = $this->vatByRate($issued, [$invoiceId]);
        $creditNoteRates = array_map(static fn (array $item): int => $item[0], $shape['credit_note']);
        foreach (array_diff(array_keys($invoiceOnly), $creditNoteRates) as $rate) {
            self::assertSame($invoiceOnly[$rate], $vatByRate[$rate], "sazba {$rate} %: dobropis na ni nesahá, zůstane jako na faktuře");
        }

        $result = $issued
            ? $this->offsets->applyForInvoice($this->supplierId, $creditNoteId, $this->userId)
            : $this->offsets->applyForPurchase($this->supplierId, $creditNoteId, $this->userId);
        self::assertNotNull($result['offset_id'], (string) $result['reason']);

        [$taxable, $vat] = $shape['payment'];
        $this->pay($issued, $instrument, $invoiceId, round($taxable + $vat, 2));

        $res = $this->fullYear($this->supplierId, true);
        $bucket = $issued ? 'prijem' : 'vydaj';
        self::assertSame(1, $this->countRows($res), 'v peněžním deníku je jen skutečná úhrada zbytku, zápočet ne');
        self::assertEqualsWithDelta($taxable, $res['totals'][$bucket . '_danovy'], 0.005,
            'daňová část úhrady = základ faktury minus základ dobropisu');
        self::assertEqualsWithDelta($vat, $res['totals'][$bucket . '_nedanovy'], 0.005,
            'DPH úhrady = DPH faktury minus DPH dobropisu');
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);

        self::assertSame($vatByRate, $this->vatByRate($issued, [$invoiceId, $creditNoteId]),
            'zápočet ani úhrada evidenci DPH po sazbách nemění');
    }

    /**
     * Zápočet se od brutto dobropisu liší, když má dobropis odečtenou zálohu: zápočet je
     * částka dobropisu k úhradě (CreditNoteOffsetService). Zbytek faktury je pak brutto minus
     * částka zápočtu, tedy táž veličina, kterou od faktury odečítá noha C2 peněžního deníku,
     * a DPH minus poměrná část DPH dobropisu podle částky zápočtu.
     */
    #[DataProvider('paths')]
    public function testRemainderSplitFollowsOffsetAmount(string $side, string $instrument): void
    {
        $issued = $side === 'issued';
        // Faktura: služba 21 % 1 000 + 210 a položka 0 % 1 000 = 2 210. Dobropis stornuje
        // službu (−1 210); jako převzatý z cizího systému má polovinu vyrovnanou odečtenou
        // zálohou (−605), k úhradě zbývá −605. Zápočet 605 = polovina služby, 500 + DPH 105.
        $invoiceId = $this->document($issued, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false);
        $creditNoteId = $this->document($issued, [[21, -1.0, 1000.0]], true);
        $this->db->pdo()->prepare($issued
            ? 'UPDATE invoices SET parent_invoice_id = ?, advance_paid_amount = -605 WHERE id = ?'
            : 'UPDATE purchase_invoices SET parent_purchase_invoice_id = ?, advance_paid_amount = -605 WHERE id = ?')
            ->execute([$invoiceId, $creditNoteId]);

        $result = $issued
            ? $this->offsets->applyForInvoice($this->supplierId, $creditNoteId, $this->userId)
            : $this->offsets->applyForPurchase($this->supplierId, $creditNoteId, $this->userId);
        self::assertNotNull($result['offset_id'], (string) $result['reason']);
        $offsets = $this->offsets->forDocument($this->supplierId, $issued ? 'invoice' : 'purchase_invoice', $invoiceId)['offsets'];
        self::assertSame([605.0], array_column($offsets, 'amount'), 'zápočet = částka dobropisu k úhradě, ne jeho brutto');

        // Zaplacený zbytek 1 605 = položka 0 % 1 000 + zbylá polovina služby 500 + DPH 105.
        $this->pay($issued, $instrument, $invoiceId, 1605.0);

        $res = $this->fullYear($this->supplierId, true);
        $bucket = $issued ? 'prijem' : 'vydaj';
        self::assertSame(1, $this->countRows($res), 'v peněžním deníku je jen skutečná úhrada zbytku, zápočet ne');
        self::assertEqualsWithDelta(1500.0, $res['totals'][$bucket . '_danovy'], 0.005,
            'daňová část úhrady = základ faktury minus poměrná část základu dobropisu podle zápočtu');
        self::assertEqualsWithDelta(105.0, $res['totals'][$bucket . '_nedanovy'], 0.005,
            'DPH úhrady = DPH faktury minus poměrná část DPH dobropisu podle zápočtu');
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);
    }

    /** @return iterable<string,array{0:string}> cesty úhrady přijaté faktury */
    public static function purchasePaths(): iterable
    {
        foreach (self::paths() as $path => [$side, $instrument]) {
            if ($side === 'purchase') {
                yield $path => [$instrument];
            }
        }
    }

    /**
     * Přijatý dobropis, který není daňovým výdajem, vrací nedaňovou část faktury a daňovou
     * část zbytku nesnižuje. Vydaná strana obdobu nemá: osvobození příjmu nese faktura celá.
     */
    #[DataProvider('purchasePaths')]
    public function testNonDeductibleCreditNoteKeepsTaxableRemainder(string $instrument): void
    {
        // Faktura s řádkovým rozpisem: služba 21 % 1 000 + 210 (daňový výdaj) a soukromá
        // položka 0 % 1 000 (není výdajem) = 2 210. Dobropis vrací soukromou položku (−1 000)
        // a jako ona není daňovým výdajem. Zaplacený zbytek 1 210 je celá služba.
        $invoiceId = $this->document(false, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false);
        $creditNoteId = $this->document(false, [[0, -1.0, 1000.0]], true);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET tax_deductible = 0 WHERE id = ?')->execute([$creditNoteId]);
        $this->allocate($invoiceId, [['Služba', 21.0, 1000.0, 210.0, 'deductible'], ['Soukromá položka', 0.0, 1000.0, 0.0, 'not_expense']]);
        $this->offset(false, $invoiceId, $creditNoteId);

        $this->pay(false, $instrument, $invoiceId, 1210.0);

        $res = $this->fullYear($this->supplierId, true);
        self::assertSame(1, $this->countRows($res), 'v peněžním deníku je jen skutečná úhrada zbytku, zápočet ne');
        self::assertEqualsWithDelta(1000.0, $res['totals']['vydaj_danovy'], 0.005,
            'nedaňový dobropis daňovou část zbytku nesnižuje: zbytek je celá služba');
        self::assertEqualsWithDelta(210.0, $res['totals']['vydaj_nedanovy'], 0.005, 'mimo daňový výdaj je jen DPH služby');
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);
    }

    /**
     * Faktura s řádkovým rozpisem a daňový dobropis: daňová část zbytku je daňová část
     * rozpisu minus daňová část dobropisu. Položka mimo daňové výdaje zůstane mimo celá.
     */
    #[DataProvider('purchasePaths')]
    public function testLineAllocationsRemainderIsAllocationsMinusCreditNote(string $instrument): void
    {
        // Faktura s rozpisem: služba 21 % 1 000 + 210 (daňový výdaj) a soukromá položka 0 %
        // 1 000 (není výdajem) = 2 210. Dobropis vrací polovinu služby: −500 − 105. Zaplacený
        // zbytek 1 605 = zbylá služba 500 + DPH 105 a soukromá položka 1 000.
        $invoiceId = $this->document(false, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false);
        $this->allocate($invoiceId, [['Služba', 21.0, 1000.0, 210.0, 'deductible'], ['Soukromá položka', 0.0, 1000.0, 0.0, 'not_expense']]);
        $this->offset(false, $invoiceId, $this->document(false, [[21, -1.0, 500.0]], true));

        $this->pay(false, $instrument, $invoiceId, 1605.0);

        $res = $this->fullYear($this->supplierId, true);
        self::assertSame(1, $this->countRows($res), 'v peněžním deníku je jen skutečná úhrada zbytku, zápočet ne');
        self::assertEqualsWithDelta(500.0, $res['totals']['vydaj_danovy'], 0.005,
            'daňová část zbytku = služba z rozpisu 1 000 minus dobropis 500');
        self::assertEqualsWithDelta(1105.0, $res['totals']['vydaj_nedanovy'], 0.005,
            'mimo daňové výdaje: DPH zbylé služby 105 a celá soukromá položka 1 000');
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);
    }

    /** @return iterable<string,array{0:string,1:string}> režim kráceného odpočtu a cesta úhrady */
    public static function reducedDeductionCases(): iterable
    {
        foreach (['koeficient § 76' => 'reduced', 'poměrný odpočet § 75' => 'proportional'] as $modeLabel => $mode) {
            foreach (self::purchasePaths() as $path => [$instrument]) {
                yield "{$modeLabel}, {$path}" => [$mode, $instrument];
            }
        }
    }

    /**
     * Krácený odpočet: neodečtená část DPH je daňovým výdajem. Faktura i dobropis se krátí
     * stejně, takže daňová část zbytku nese neodečtenou část jen z DPH, které na zbytku zůstalo.
     */
    #[DataProvider('reducedDeductionCases')]
    public function testReducedDeductionAppliesToInvoiceAndCreditNote(string $mode, string $instrument): void
    {
        // Odpočet 60 %: u kráceného zálohový koeficient roku, u poměrného procento dokladu.
        $this->container->get(VatCoefficientRepository::class)->setProvisionalPercent($this->supplierId, self::YEAR, 60);
        // Faktura: 10 ks zboží 21 % (1 000 + 210) a položka 0 % 1 000 = 2 210. Dobropis vrací
        // 4 ks: −400 − 84. Zbytek 1 726 = 1 600 + DPH 126; odečte se 60 % DPH, tedy 75,60, a 40 %,
        // 50,40, je daňovým výdajem. Daňová část 1 650,40, mimo daňové výdaje 75,60.
        $invoiceId = $this->document(false, [[21, 10.0, 100.0], [0, 1.0, 1000.0]], false);
        $creditNoteId = $this->document(false, [[21, -4.0, 100.0]], true);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET vat_deduction = ?, vat_deduction_percent = 60 WHERE id IN (?, ?)')
            ->execute([$mode, $invoiceId, $creditNoteId]);
        $this->offset(false, $invoiceId, $creditNoteId);

        $this->pay(false, $instrument, $invoiceId, 1726.0);

        $res = $this->fullYear($this->supplierId, true);
        self::assertSame(1, $this->countRows($res), 'v peněžním deníku je jen skutečná úhrada zbytku, zápočet ne');
        self::assertEqualsWithDelta(1650.40, $res['totals']['vydaj_danovy'], 0.005,
            'daňová část zbytku = základ 1 600 a neodečtených 40 % z DPH zbytku 126');
        self::assertEqualsWithDelta(75.60, $res['totals']['vydaj_nedanovy'], 0.005, 'mimo daňové výdaje je jen odečtená DPH');
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);
    }

    /**
     * Cesty, kterými lze fakturu platit po částech, před zápočtem dobropisu i po něm. Ruční
     * úhrada přijaté faktury je jen stav „zaplaceno" celé faktury: částečnou úhradu nezná
     * a zaplacenou fakturu už zápočet nepřipustí.
     *
     * @return iterable<string,array{0:string,1:string}>
     */
    public static function partialPaths(): iterable
    {
        foreach (self::paths() as $path => [$side, $instrument]) {
            if ($side === 'issued' || $instrument !== 'manual') {
                yield $path => [$side, $instrument];
            }
        }
    }

    /** @return iterable<string,array{0:string,1:string,2:array<string,mixed>}> */
    public static function partialCases(): iterable
    {
        foreach (self::shapes() as $shape => $documents) {
            foreach (self::partialPaths() as $path => [$side, $instrument]) {
                yield "{$path}, {$shape}" => [$side, $instrument, $documents];
            }
        }
    }

    /**
     * Úhrada zaplacená PŘED zápočtem se dělí podle faktury, jak byla v den úhrady, a pozdější
     * zápočet ji nepřepíše. Rozdíl, který zápočet do ní vnese, nese opravný řádek ke dni
     * zápočtu (částka 0, daňová část +Δ, DPH −Δ). Úhrada a oprava dohromady dají totéž
     * rozdělení jako úhrada po zápočtu: faktura minus dobropis.
     *
     * @param array{invoice:list<array{0:int,1:float,2:float}>,credit_note:list<array{0:int,1:float,2:float}>,by_rate:array<int,array{0:float,1:float}>,payment:array{0:float,1:float}} $shape
     */
    #[DataProvider('partialCases')]
    public function testPaymentBeforeOffsetKeepsItsSplitAndOffsetDayCarriesCorrection(string $side, string $instrument, array $shape): void
    {
        $issued = $side === 'issued';
        $invoiceId = $this->document($issued, $shape['invoice'], false);
        [$taxable, $vat] = $shape['payment'];
        $paid = round($taxable + $vat, 2);
        $this->pay($issued, $instrument, $invoiceId, $paid, self::YEAR . '-06-03', false);
        $creditNoteId = $this->document($issued, $shape['credit_note'], true);
        $this->offset($issued, $invoiceId, $creditNoteId);

        $res = $this->fullYear($this->supplierId, true);
        $bucket = $issued ? 'prijem' : 'vydaj';
        [$invoiceBase, $invoiceVat] = self::totals($shape['invoice']);
        $paymentBase = round($paid * $invoiceBase / ($invoiceBase + $invoiceVat), 2);
        $payment = $this->onlyRow($res, static fn (array $row): bool => $row['source_type'] !== 'credit_note_offset');
        self::assertEqualsWithDelta($paymentBase, $payment['base'], 0.005,
            'úhrada před zápočtem se dělí podle celé faktury, jak byla v den úhrady');

        $correction = round($taxable - $paymentBase, 2);
        if ($correction == 0.0) {
            self::assertSame(0, $this->countRows($res, 'credit_note_offset'), 'dobropis se složením faktury nic nepřerozdělí');
        } else {
            $row = $this->onlyRow($res, static fn (array $row): bool => $row['source_type'] === 'credit_note_offset');
            self::assertSame(self::YEAR . '-06-05', $row['date'], 'oprava je ke dni zápočtu');
            self::assertEqualsWithDelta(0.0, (float) ($issued ? $row['income'] : $row['expense']), 0.005, 'oprava nehýbe penězi');
            self::assertEqualsWithDelta($correction, $row['base'], 0.005, 'oprava = rozdělení podle zbytku minus rozdělení úhrady');
            self::assertEqualsWithDelta(-$correction, $row['vat'], 0.005, 'o tolik se sníží DPH');
        }
        self::assertEqualsWithDelta($taxable, $res['totals'][$bucket . '_danovy'], 0.005,
            'úhrada a oprava dohromady: daňová část = faktura minus dobropis');
        self::assertEqualsWithDelta($vat, $res['totals'][$bucket . '_nedanovy'], 0.005,
            'úhrada a oprava dohromady: DPH = faktura minus dobropis');
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);
    }

    /**
     * Zbytek po zápočtu zaplacený ve dvou částech: každá část se dělí poměrem zbytku (faktura
     * minus dobropis), ne celé faktury. Obě úhrady jsou po zápočtu, opravný řádek nevzniká.
     *
     * @param array{invoice:list<array{0:int,1:float,2:float}>,credit_note:list<array{0:int,1:float,2:float}>,by_rate:array<int,array{0:float,1:float}>,payment:array{0:float,1:float}} $shape
     */
    #[DataProvider('partialCases')]
    public function testRemainderPaidInPartsSplitsEachPartAsRemainder(string $side, string $instrument, array $shape): void
    {
        $issued = $side === 'issued';
        $invoiceId = $this->document($issued, $shape['invoice'], false);
        $this->offset($issued, $invoiceId, $this->document($issued, $shape['credit_note'], true));

        [$taxable, $vat] = $shape['payment'];
        $half = round(($taxable + $vat) / 2, 2);
        $this->pay($issued, $instrument, $invoiceId, $half, self::YEAR . '-06-15', false);
        $this->pay($issued, $instrument, $invoiceId, $half, self::YEAR . '-07-15');

        $res = $this->fullYear($this->supplierId, true);
        self::assertSame([self::YEAR . '-06-15', self::YEAR . '-07-15'], array_column($res['rows'], 'date'),
            'v deníku jsou jen obě úhrady, zápočet ani oprava ne');
        foreach ($res['rows'] as $row) {
            self::assertEqualsWithDelta($taxable / 2, $row['base'], 0.005, 'daňová část každé úhrady = polovina daňové části zbytku');
            self::assertEqualsWithDelta($vat / 2, $row['vat'], 0.005, 'DPH každé úhrady = polovina DPH zbytku');
        }
        $bucket = $issued ? 'prijem' : 'vydaj';
        self::assertEqualsWithDelta($taxable, $res['totals'][$bucket . '_danovy'], 0.005);
        self::assertEqualsWithDelta($vat, $res['totals'][$bucket . '_nedanovy'], 0.005);
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);
    }

    /**
     * Zápočet v dalším roce nesmí změnit deník roku úhrady (ten může mít dokončenou uzávěrku
     * a podané přiznání). Oprava padne do roku zápočtu a součet obou let sedí na fakturu minus
     * dobropis. Zrušením zápočtu oprava zmizí a rok úhrady se ani tehdy nepohne.
     */
    #[DataProvider('partialPaths')]
    public function testOffsetInLaterYearDoesNotChangeYearOfPayment(string $side, string $instrument): void
    {
        $issued = $side === 'issued';
        $previous = self::YEAR - 1;
        // Faktura: služba 21 % 1 000 + 210 a položka 0 % 1 000 = 2 210. V prosinci se zaplatí
        // 1 000, službu odběratel reklamuje; dobropis ji v červnu dalšího roku stornuje.
        $invoiceId = $this->document($issued, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false, $previous . '-12-01');
        $this->pay($issued, $instrument, $invoiceId, 1000.0, $previous . '-12-15', false);
        $before = $this->year($previous);
        self::assertEqualsWithDelta(904.98, $before['totals'][$issued ? 'prijem_danovy' : 'vydaj_danovy'], 0.005);

        $creditNoteId = $this->document($issued, [[21, -1.0, 1000.0]], true);
        $offsetId = $this->offset($issued, $invoiceId, $creditNoteId);

        $after = $this->year($previous);
        self::assertSame($before['totals'], $after['totals'], 'zápočet v dalším roce rok úhrady nezmění');
        self::assertSame($this->splits($before), $this->splits($after), 'ani jeho řádky');

        $res = $this->fullYear($this->supplierId, true);
        $bucket = $issued ? 'prijem' : 'vydaj';
        $row = $this->onlyRow($res, static fn (array $row): bool => true);
        self::assertSame(['credit_note_offset', $offsetId, self::YEAR . '-06-05'], [$row['source_type'], $row['source_id'], $row['date']]);
        self::assertEqualsWithDelta(95.02, $res['totals'][$bucket . '_danovy'], 0.005, 'oprava v roce zápočtu: 1 000 − 904,98');
        self::assertEqualsWithDelta(-95.02, $res['totals'][$bucket . '_nedanovy'], 0.005);
        self::assertEqualsWithDelta(1000.0, $before['totals'][$bucket . '_danovy'] + $res['totals'][$bucket . '_danovy'], 0.005,
            'za oba roky: zaplacená položka bez DPH je celá daňovou částí');

        $this->offsets->revertForDocument($this->supplierId, $issued ? 'invoice' : 'purchase_invoice', $invoiceId);
        self::assertSame(0, $this->countRows($this->fullYear($this->supplierId, true), 'credit_note_offset'), 'zrušením zápočtu oprava zmizí');
        self::assertSame($before['totals'], $this->year($previous)['totals'], 'a rok úhrady se nepohne');
    }

    /**
     * Dva dobropisy k jedné faktuře, oba započtené až po úhradě: každý zápočet nese jen svůj
     * díl rozdílu (zbytek před ním a po něm), dohromady faktura minus oba dobropisy.
     */
    #[DataProvider('partialPaths')]
    public function testEachOffsetCorrectsOnlyItsOwnShare(string $side, string $instrument): void
    {
        $issued = $side === 'issued';
        // Faktura 21 % 1 000 + 210, 12 % 1 000 + 120 a 0 % 1 000 = 3 330. Zaplatí se 1 000
        // (položka 0 %), 5. 6. dobropis stornuje položku 21 %, 10. 6. položku 12 %.
        $invoiceId = $this->document($issued, [[21, 1.0, 1000.0], [12, 1.0, 1000.0], [0, 1.0, 1000.0]], false);
        $this->pay($issued, $instrument, $invoiceId, 1000.0, self::YEAR . '-06-03', false);
        $first = $this->offset($issued, $invoiceId, $this->document($issued, [[21, -1.0, 1000.0]], true));
        $second = $this->offset($issued, $invoiceId, $this->document($issued, [[12, -1.0, 1000.0]], true, self::YEAR . '-06-10'));

        $res = $this->fullYear($this->supplierId, true);
        $bases = [];
        foreach ($res['rows'] as $row) {
            $bases[$row['source_type'] === 'credit_note_offset' ? $row['source_id'] : 'payment'] = $row['base'];
        }
        // Úhrada 1 000 × 3 000 / 3 330; po 1. dobropisu 1 000 × 2 000 / 2 120; po 2. celá 1 000.
        self::assertEqualsWithDelta(900.90, $bases['payment'], 0.005);
        self::assertEqualsWithDelta(943.40 - 900.90, $bases[$first], 0.005);
        self::assertEqualsWithDelta(1000.0 - 943.40, $bases[$second], 0.005);
        self::assertEqualsWithDelta(1000.0, $res['totals'][$issued ? 'prijem_danovy' : 'vydaj_danovy'], 0.005);
        self::assertEqualsWithDelta(0.0, $res['totals'][$issued ? 'prijem_nedanovy' : 'vydaj_nedanovy'], 0.005);
    }

    /** Neplátce DPH úhradu nedělí, zápočet proto nic neopravuje. */
    public function testNonVatPayerHasNoCorrection(): void
    {
        $invoiceId = $this->document(true, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false);
        $this->pay(true, 'bank', $invoiceId, 1000.0, self::YEAR . '-06-03', false);
        $this->offset(true, $invoiceId, $this->document(true, [[21, -1.0, 1000.0]], true));

        $res = $this->fullYear($this->supplierId, false);
        self::assertSame(0, $this->countRows($res, 'credit_note_offset'));
        self::assertEqualsWithDelta(1000.0, $res['totals']['prijem_danovy'], 0.005);
    }

    /** @return iterable<string,array{0:string}> cesty úhrady vydané faktury */
    public static function issuedPaths(): iterable
    {
        foreach (self::paths() as $path => [$side, $instrument]) {
            if ($side === 'issued') {
                yield $path => [$instrument];
            }
        }
    }

    /**
     * Faktura osvobozená od daně z příjmů (§ 4 ZDP) se dělí stejně jako zdanitelná, jen místo
     * daňového příjmu jde do osvobozeného: úhrada před zápočtem, opravný řádek i úhrada zbytku.
     * DPH z osvobozené faktury příjmem není.
     */
    #[DataProvider('issuedPaths')]
    public function testExemptInvoiceSplitsIntoExemptIncome(string $instrument): void
    {
        // Faktura: služba 21 % 1 000 + 210 a položka 0 % 2 000 = 3 210, osvobozená. Před
        // zápočtem se zaplatí 1 000, dobropis pak stornuje službu a zbytek 1 000 se zaplatí po
        // zápočtu. Zaplacené 2 000 je položka bez DPH, celá osvobozeným příjmem.
        $invoiceId = $this->document(true, [[21, 1.0, 1000.0], [0, 1.0, 2000.0]], false);
        $creditNoteId = $this->document(true, [[21, -1.0, 1000.0]], true);
        $this->db->pdo()->prepare('UPDATE invoices SET income_tax_exempt = 1 WHERE id IN (?, ?)')->execute([$invoiceId, $creditNoteId]);
        $this->pay(true, $instrument, $invoiceId, 1000.0, self::YEAR . '-06-03', false);
        $this->offset(true, $invoiceId, $creditNoteId);
        $this->pay(true, $instrument, $invoiceId, 1000.0);

        $res = $this->fullYear($this->supplierId, true);
        $before = $this->onlyRow($res, static fn (array $row): bool => $row['date'] === self::YEAR . '-06-03');
        $correction = $this->onlyRow($res, static fn (array $row): bool => $row['source_type'] === 'credit_note_offset');
        $after = $this->onlyRow($res, static fn (array $row): bool => $row['date'] === self::YEAR . '-06-15');
        foreach ([$before, $correction, $after] as $row) {
            self::assertSame('income_exempt', $row['bucket']);
            self::assertEqualsWithDelta(0.0, $row['base'], 0.005, 'osvobozený příjem nemá daňový základ');
        }
        self::assertEqualsWithDelta(65.42, $before['vat'], 0.005, 'úhrada před zápočtem: DPH podle celé faktury, 1 000 × 210 / 3 210');
        self::assertEqualsWithDelta(-65.42, $correction['vat'], 0.005, 'oprava převede DPH, které na zbytku nezůstalo, do osvobozeného příjmu');
        self::assertEqualsWithDelta(0.0, $after['vat'], 0.005, 'úhrada po zápočtu: zbytek je bez DPH');
        self::assertEqualsWithDelta(2000.0, $res['totals']['prijem_osvobozeny'], 0.005);
        self::assertEqualsWithDelta(0.0, $res['totals']['prijem_danovy'], 0.005);
        self::assertEqualsWithDelta(0.0, $res['totals']['prijem_nedanovy'], 0.005);
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);
    }

    public static function overpaymentPaths(): iterable
    {
        foreach (self::paths() as $name => [$side, $instrument]) {
            if ($side === 'purchase' && $instrument === 'manual') continue;
            yield $name => [$side, $instrument];
        }
    }

    #[DataProvider('partialCases')]
    public function testOverpaidRemainderCarriesOnlyRemainderVat(string $side, string $instrument, array $shape): void
    {
        $issued = $side === 'issued';
        $invoiceId = $this->document($issued, $shape['invoice'], false);
        $this->offset($issued, $invoiceId, $this->document($issued, $shape['credit_note'], true));

        [$taxable, $vat] = $shape['payment'];
        $this->pay($issued, $instrument, $invoiceId, round($taxable + $vat + 0.50, 2));

        $res = $this->fullYear($this->supplierId, true);
        $bucket = $issued ? 'prijem' : 'vydaj';
        self::assertSame(1, $this->countRows($res), 'v deníku je jen úhrada zbytku');
        self::assertEqualsWithDelta($taxable + 0.50, $res['totals'][$bucket . '_danovy'], 0.005,
            'daňová část = daňová část zbytku + celý přeplatek');
        self::assertEqualsWithDelta($vat, $res['totals'][$bucket . '_nedanovy'], 0.005,
            'DPH = DPH zbytku, přeplatek DPH nenese');
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);
    }

    #[DataProvider('overpaymentPaths')]
    public function testOffsetRemainderOverpaymentCarriesOnlyRemainingVat(string $side, string $instrument): void
    {
        $issued = $side === 'issued';
        $invoiceId = $this->document($issued, [[21, 10.0, 100.0], [0, 1.0, 1000.0]], false);
        $creditNoteId = $this->document($issued, [[21, -4.0, 100.0]], true);
        $this->offset($issued, $invoiceId, $creditNoteId);
        $this->pay($issued, $instrument, $invoiceId, 1826.0);

        $result = $this->fullYear($this->supplierId, true);
        $bucket = $issued ? 'prijem' : 'vydaj';
        self::assertSame(1, $this->countRows($result));
        self::assertEqualsWithDelta(1700.0, $result['totals'][$bucket . '_danovy'], 0.005);
        self::assertEqualsWithDelta(126.0, $result['totals'][$bucket . '_nedanovy'], 0.005);
        self::assertEqualsWithDelta(126.0, array_sum(array_column($result['rows'], 'vat')), 0.005);
    }

    #[DataProvider('overpaymentPaths')]
    public function testLaterOffsetAndOverpaymentKeepPreviousYearAndTotalVat(string $side, string $instrument): void
    {
        $issued = $side === 'issued';
        $previousYear = self::YEAR - 1;
        $invoiceId = $this->document($issued, [[21, 10.0, 100.0], [0, 1.0, 1000.0]], false, $previousYear . '-12-01');
        $this->pay($issued, $instrument, $invoiceId, 500.0, $previousYear . '-12-10', false);
        $before = $this->year($previousYear);
        $creditNoteId = $this->document($issued, [[21, -4.0, 100.0]], true);
        $this->offset($issued, $invoiceId, $creditNoteId);
        $this->pay($issued, $instrument, $invoiceId, 1326.0);

        $after = $this->year($previousYear);
        self::assertSame($before['totals'], $after['totals']);
        self::assertSame($this->splits($before), $this->splits($after));
        $current = $this->fullYear($this->supplierId, true);
        $bucket = $issued ? 'prijem' : 'vydaj';
        self::assertEqualsWithDelta(1700.0, $before['totals'][$bucket . '_danovy'] + $current['totals'][$bucket . '_danovy'], 0.005);
        self::assertEqualsWithDelta(126.0, $before['totals'][$bucket . '_nedanovy'] + $current['totals'][$bucket . '_nedanovy'], 0.005);
        self::assertSame(1, $this->countRows($current, 'credit_note_offset'));
    }

    public function testPurchaseCorrectionsLoadTheirInputsInBatches(): void
    {
        $this->setVatPayer($this->supplierId, true);
        $this->container->get(VatCoefficientRepository::class)->setProvisionalPercent($this->supplierId, self::YEAR, 60);
        $raw = [];
        for ($i = 0; $i < 8; $i++) {
            $invoiceId = $this->document(false, [[21, 10.0, 100.0], [0, 1.0, 1000.0]], false);
            $this->pay(false, 'bank', $invoiceId, 500.0, self::YEAR . '-06-03', false);
            $creditNoteId = $this->document(false, [[21, -4.0, 100.0]], true);
            $this->db->pdo()->prepare("UPDATE purchase_invoices SET vat_deduction = 'reduced' WHERE id IN (?, ?)")
                ->execute([$invoiceId, $creditNoteId]);
            $raw[] = ['source_type' => 'credit_note_offset', 'source_id' => $this->offset(false, $invoiceId, $creditNoteId)];
        }
        $batchMethod = new \ReflectionMethod($this->service, 'batch');
        $classifyMethod = new \ReflectionMethod($this->service, 'offsetCorrectionAlloc');
        $calculate = function (array $rows) use ($batchMethod, $classifyMethod): float {
            $batch = $batchMethod->invoke($this->service, $this->supplierId, $rows);
            $total = 0.0;
            foreach ($rows as $row) $total += $classifyMethod->invoke($this->service, $row, $this->supplierId, $batch)['base'];
            return $total;
        };
        $questions = fn (): int => (int) $this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetchColumn(1);
        $calculate($raw);
        $before = $questions();
        $single = $calculate([$raw[0]]);
        $singleQueries = $questions() - $before;
        $before = $questions();
        $all = $calculate($raw);
        $allQueries = $questions() - $before;
        self::assertGreaterThan(0.0, $single);
        self::assertEqualsWithDelta(8 * $single, $all, 0.005);
        self::assertLessThanOrEqual($singleQueries + 1, $allQueries, 'Další korekce nesmí přidávat dotazy na doklad, jeho alokace ani koeficient.');
    }

    public static function backdatedOverpaymentPaths(): iterable
    {
        foreach (self::overpaymentPaths() as $name => [$side, $instrument]) {
            foreach ([2000.0, 2300.0] as $amount) yield $name . ', ' . $amount => [$side, $instrument, $amount];
        }
    }

    #[DataProvider('backdatedOverpaymentPaths')]
    public function testBackdatedPaymentAboveLaterRemainderKeepsPreviousYear(string $side, string $instrument, float $amount): void
    {
        $issued = $side === 'issued';
        $previousYear = self::YEAR - 1;
        $invoiceId = $this->document($issued, [[21, 10.0, 100.0], [0, 1.0, 1000.0]], false, $previousYear . '-12-01');
        $creditNoteId = $this->document($issued, [[21, -4.0, 100.0]], true);
        $this->offset($issued, $invoiceId, $creditNoteId);
        $this->pay($issued, $instrument, $invoiceId, $amount, $previousYear . '-12-10');

        $previous = $this->year($previousYear);
        $bucket = $issued ? 'prijem' : 'vydaj';
        $previousVat = round(min($amount, 2210.0) * 210 / 2210, 2);
        self::assertEqualsWithDelta($amount - $previousVat, $previous['totals'][$bucket . '_danovy'], 0.005);
        self::assertEqualsWithDelta($previousVat, $previous['totals'][$bucket . '_nedanovy'], 0.005);
        $current = $this->fullYear($this->supplierId, true);
        self::assertEqualsWithDelta($amount - 126.0, $previous['totals'][$bucket . '_danovy'] + $current['totals'][$bucket . '_danovy'], 0.005);
        self::assertEqualsWithDelta(126.0, $previous['totals'][$bucket . '_nedanovy'] + $current['totals'][$bucket . '_nedanovy'], 0.005);
        self::assertSame($previous['totals'], $this->year($previousYear)['totals']);
        self::assertSame(1, $this->countRows($current, 'credit_note_offset'));
    }

    #[DataProvider('overpaymentPaths')]
    public function testBackdatedPaymentAfterFullOffsetBecomesWholeOverpayment(string $side, string $instrument): void
    {
        $issued = $side === 'issued';
        $previousYear = self::YEAR - 1;
        $invoiceId = $this->document($issued, [[21, 10.0, 100.0], [0, 1.0, 1000.0]], false, $previousYear . '-12-01');
        $creditNoteId = $this->document($issued, [[21, -10.0, 100.0], [0, -1.0, 1000.0]], true);
        $this->offset($issued, $invoiceId, $creditNoteId);
        $this->pay($issued, $instrument, $invoiceId, 500.0, $previousYear . '-12-10');
        $previous = $this->year($previousYear);
        $current = $this->fullYear($this->supplierId, true);
        $bucket = $issued ? 'prijem' : 'vydaj';
        self::assertEqualsWithDelta(500.0, $previous['totals'][$bucket . '_danovy'] + $current['totals'][$bucket . '_danovy'], 0.005);
        self::assertEqualsWithDelta(0.0, $previous['totals'][$bucket . '_nedanovy'] + $current['totals'][$bucket . '_nedanovy'], 0.005);
        self::assertSame($previous['totals'], $this->year($previousYear)['totals']);
    }

    public function testLegacyBankBackdatedOverpaymentIsCorrectedWithoutChangingPreviousYear(): void
    {
        $previousYear = self::YEAR - 1;
        $invoiceId = $this->document(true, [[21, 10.0, 100.0], [0, 1.0, 1000.0]], false, $previousYear . '-12-01');
        $creditNoteId = $this->document(true, [[21, -4.0, 100.0]], true);
        $this->offset(true, $invoiceId, $creditNoteId);
        $this->bankTx($this->statement($this->supplierId, $this->accountA), 2300.0,
            ['matched_invoice_id' => $invoiceId, 'match_status' => 'manual', 'posted_at' => $previousYear . '-12-10']);
        $previous = $this->year($previousYear);
        $current = $this->fullYear($this->supplierId, true);
        self::assertEqualsWithDelta(2090.0, $previous['totals']['prijem_danovy'], 0.005);
        self::assertEqualsWithDelta(2174.0, $previous['totals']['prijem_danovy'] + $current['totals']['prijem_danovy'], 0.005);
        self::assertEqualsWithDelta(126.0, $previous['totals']['prijem_nedanovy'] + $current['totals']['prijem_nedanovy'], 0.005);
        self::assertSame($previous['totals'], $this->year($previousYear)['totals']);
    }

    public static function sides(): iterable
    {
        yield 'vydaná' => ['issued'];
        yield 'přijatá' => ['purchase'];
    }

    #[DataProvider('sides')]
    public function testMixedBackdatedPaymentsShareRemainingCoverageOnlyOnce(string $side): void
    {
        $issued = $side === 'issued';
        $previousYear = self::YEAR - 1;
        $invoiceId = $this->document($issued, [[21, 10.0, 100.0], [0, 1.0, 1000.0]], false, $previousYear . '-12-01');
        $creditNoteId = $this->document($issued, [[21, -4.0, 100.0]], true);
        $this->offset($issued, $invoiceId, $creditNoteId);
        $this->pay($issued, 'bank', $invoiceId, 1400.0, $previousYear . '-12-10', false);
        $this->pay($issued, 'cash', $invoiceId, 900.0, $previousYear . '-12-11');
        $previous = $this->year($previousYear);
        $current = $this->fullYear($this->supplierId, true);
        $bucket = $issued ? 'prijem' : 'vydaj';
        self::assertEqualsWithDelta(2090.0, $previous['totals'][$bucket . '_danovy'], 0.005);
        self::assertEqualsWithDelta(2174.0, $previous['totals'][$bucket . '_danovy'] + $current['totals'][$bucket . '_danovy'], 0.005);
        self::assertEqualsWithDelta(126.0, $previous['totals'][$bucket . '_nedanovy'] + $current['totals'][$bucket . '_nedanovy'], 0.005);
        self::assertSame($previous['totals'], $this->year($previousYear)['totals']);
    }

    public function testBackdatedPurchaseRefundReturnsOverpaymentBeforeInvoiceCoverage(): void
    {
        $previousYear = self::YEAR - 1;
        $invoiceId = $this->document(false, [[21, 10.0, 100.0], [0, 1.0, 1000.0]], false, $previousYear . '-12-01');
        $creditNoteId = $this->document(false, [[21, -4.0, 100.0]], true);
        $this->offset(false, $invoiceId, $creditNoteId);
        $this->pay(false, 'bank', $invoiceId, 2300.0, $previousYear . '-12-10', false);
        $date = $previousYear . '-12-11';
        $tx = $this->bankTx($this->statement($this->supplierId, $this->accountA), 100.0, ['posted_at' => $date]);
        $this->paymentMatch($this->supplierId, $tx, $invoiceId, 100.0);
        $previous = $this->year($previousYear);
        $current = $this->fullYear($this->supplierId, true);
        self::assertEqualsWithDelta(1990.95, $previous['totals']['vydaj_danovy'], 0.005);
        self::assertEqualsWithDelta(2074.0, $previous['totals']['vydaj_danovy'] + $current['totals']['vydaj_danovy'], 0.005);
        self::assertEqualsWithDelta(126.0, $previous['totals']['vydaj_nedanovy'] + $current['totals']['vydaj_nedanovy'], 0.005);
        self::assertSame($previous['totals'], $this->year($previousYear)['totals']);
    }

    public function testLegacyUnlinkedCashOverpaymentKeepsPreviousYearAndCorrectsRemainingVat(): void
    {
        $previousYear = self::YEAR - 1;
        $invoiceId = $this->document(true, [[21, 10.0, 100.0], [0, 1.0, 1000.0]], false, $previousYear . '-12-01');
        $creditNoteId = $this->document(true, [[21, -4.0, 100.0]], true);
        $this->offset(true, $invoiceId, $creditNoteId);
        $this->pay(true, 'cash', $invoiceId, 2300.0, $previousYear . '-12-10');
        $this->db->pdo()->prepare('UPDATE cash_documents SET invoice_payment_id = NULL WHERE supplier_id = ? AND invoice_id = ?')
            ->execute([$this->supplierId, $invoiceId]);
        $previous = $this->year($previousYear);
        $current = $this->fullYear($this->supplierId, true);
        self::assertEqualsWithDelta(2081.45, $previous['totals']['prijem_danovy'], 0.005);
        self::assertEqualsWithDelta(2174.0, $previous['totals']['prijem_danovy'] + $current['totals']['prijem_danovy'], 0.005);
        self::assertEqualsWithDelta(126.0, $previous['totals']['prijem_nedanovy'] + $current['totals']['prijem_nedanovy'], 0.005);
        self::assertSame($previous['totals'], $this->year($previousYear)['totals']);
    }

    public function testOffsetCorrectionNoteCanBeCreatedAndReadOnlyInItsCompany(): void
    {
        $invoiceId = $this->document(true, [[21, 10.0, 100.0], [0, 1.0, 1000.0]], false);
        $this->pay(true, 'bank', $invoiceId, 500.0, self::YEAR . '-06-03', false);
        $offsetId = $this->offset(true, $invoiceId, $this->document(true, [[21, -4.0, 100.0]], true));
        $this->onlyRow($this->fullYear($this->supplierId, true), static fn (array $row): bool => $row['source_type'] === 'credit_note_offset');
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/tax-evidence/cash-journal/notes/credit_note_offset/' . $offsetId)
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody(['body' => 'Ověřená oprava rozdělení úhrady', 'pinned' => true]);
        $args = ['source' => 'credit_note_offset', 'id' => (string) $offsetId];
        $app = \Slim\Factory\AppFactory::createFromContainer($this->container);
        \MyInvoice\Routes::register($app);
        foreach (['POST' => 'create', 'GET' => 'list', 'PATCH' => 'update', 'DELETE' => 'delete'] as $method => $handler) {
            $path = $request->getUri()->getPath() . (in_array($method, ['PATCH', 'DELETE'], true) ? '/1' : '');
            $resolved = $app->getRouteResolver()->computeRoutingResults($path, $method);
            self::assertSame([\MyInvoice\Action\TaxEvidence\CashJournalNoteAction::class, $handler],
                $app->getRouteResolver()->resolveRoute((string) $resolved->getRouteIdentifier())->getCallable());
        }
        $response = $app->handle($request);
        self::assertSame(201, $response->getStatusCode());
        $created = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['notes'];
        self::assertSame(['Ověřená oprava rozdělení úhrady'], array_column($created, 'body'));
        $response = $app->handle($request->withMethod('GET'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($created, json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['notes']);
        $response = $app->handle($request->withMethod('GET')->withUri($request->getUri()->withPath('/api/tax-evidence/cash-journal'))
            ->withQueryParams(['year' => (string) self::YEAR]));
        self::assertSame(200, $response->getStatusCode());
        $row = $this->onlyRow(json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR),
            static fn (array $row): bool => $row['source_type'] === 'credit_note_offset');
        self::assertSame(['Ověřená oprava rozdělení úhrady'], array_column($row['notes'], 'body'));
        $other = $this->cloneSupplier('tax_evidence', true);
        $foreign = $request->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $other);
        self::assertSame(404, $app->handle($foreign->withMethod('GET'))->getStatusCode());
        self::assertSame(404, $app->handle($foreign)->getStatusCode());
        self::assertSame(404, $app->handle($request->withUri($request->getUri()->withPath('/api/tax-evidence/cash-journal/notes/unknown/' . $offsetId)))->getStatusCode());
        self::assertCount(1, $created);
    }

    public function testLegacyUnlinkedCashAfterOffsetCarriesOnlyRemainingVat(): void
    {
        $invoiceId = $this->document(true, [[21, 10.0, 100.0], [0, 1.0, 1000.0]], false);
        $creditNoteId = $this->document(true, [[21, -4.0, 100.0]], true);
        $this->offset(true, $invoiceId, $creditNoteId);
        $this->pay(true, 'cash', $invoiceId, 2300.0);
        $this->db->pdo()->prepare('UPDATE cash_documents SET invoice_payment_id = NULL WHERE supplier_id = ? AND invoice_id = ?')
            ->execute([$this->supplierId, $invoiceId]);
        $result = $this->fullYear($this->supplierId, true);
        self::assertEqualsWithDelta(2174.0, $result['totals']['prijem_danovy'], 0.005);
        self::assertEqualsWithDelta(126.0, $result['totals']['prijem_nedanovy'], 0.005);
        self::assertSame(0, $this->countRows($result, 'credit_note_offset'));
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * Řádkový daňový rozpis přijaté faktury.
     *
     * @param list<array{0:string,1:float,2:float,3:float,4:string}> $lines [popis, sazba %, základ, DPH, daňové zacházení]
     */
    private function allocate(int $invoiceId, array $lines): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, is_active)
             VALUES (?, '518', 'Ostatní služby', 'expense', 'debit', 1, 1)"
        )->execute([$this->supplierId]);
        $stmt = $pdo->prepare(
            "INSERT INTO purchase_invoice_vat_allocations
                (supplier_id, purchase_invoice_id, description, vat_rate, base_amount, vat_amount, total_amount,
                 tax_treatment, account_code, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, '518', ?)"
        );
        foreach ($lines as $index => [$description, $rate, $base, $vat, $treatment]) {
            $stmt->execute([$this->supplierId, $invoiceId, $description, $rate, $base, $vat, round($base + $vat, 2), $treatment, $index + 1]);
        }
    }

    /** Zápočet dobropisu proti faktuře; vrací id zápočtu. */
    private function offset(bool $issued, int $invoiceId, int $creditNoteId): int
    {
        $this->db->pdo()->prepare($issued
            ? 'UPDATE invoices SET parent_invoice_id = ? WHERE id = ?'
            : 'UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')->execute([$invoiceId, $creditNoteId]);
        $result = $issued
            ? $this->offsets->applyForInvoice($this->supplierId, $creditNoteId, $this->userId)
            : $this->offsets->applyForPurchase($this->supplierId, $creditNoteId, $this->userId);
        self::assertNotNull($result['offset_id'], (string) $result['reason']);
        return (int) $result['offset_id'];
    }

    /** Peněžní deník plátce DPH za jiný rok než self::YEAR. @return array<string,mixed> */
    private function year(int $year): array
    {
        $this->setVatPayer($this->supplierId, true);
        return $this->service->build($this->supplierId, $year . '-01-01', $year . '-12-31', ['year' => $year]);
    }

    /**
     * Rozdělení řádků deníku [zdroj, id, datum, daňová část, DPH].
     *
     * @param array<string,mixed> $res
     * @return list<array{0:string,1:int,2:string,3:float,4:float}>
     */
    private function splits(array $res): array
    {
        return array_map(static fn (array $row): array => [$row['source_type'], $row['source_id'], $row['date'], $row['base'], $row['vat']], $res['rows']);
    }

    /**
     * Jediný řádek deníku splňující podmínku.
     *
     * @param array<string,mixed> $res
     * @return array<string,mixed>
     */
    private function onlyRow(array $res, callable $match): array
    {
        $rows = array_values(array_filter($res['rows'], $match));
        self::assertCount(1, $rows, 'čekám právě jeden takový řádek deníku');
        return $rows[0];
    }

    /**
     * Základ a DPH dokladu z položek, zaokrouhlené stejně jako {@see document()}.
     *
     * @param list<array{0:int,1:float,2:float}> $items
     * @return array{0:float,1:float}
     */
    private static function totals(array $items): array
    {
        $base = 0.0;
        $vat = 0.0;
        foreach ($items as [$rate, $quantity, $unitPrice]) {
            $line = round($quantity * $unitPrice, 2);
            $base += $line;
            $vat += round($line * $rate / 100, 2);
        }
        return [round($base, 2), round($vat, 2)];
    }

    /**
     * Faktura nebo dobropis s položkami; součty hlavičky jsou součty položek.
     *
     * @param list<array{0:int,1:float,2:float}> $items [sazba %, počet kusů, cena za kus bez DPH]
     * @param string|null $date datum vystavení; bez něj faktura 1. 6. a dobropis 5. 6. roku self::YEAR
     */
    private function document(bool $issued, array $items, bool $creditNote, ?string $date = null): int
    {
        $lines = array_map(static function (array $item): array {
            [$rate, $quantity, $unitPrice] = $item;
            $base = round($quantity * $unitPrice, 2);
            return ['rate' => $rate, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'base' => $base, 'vat' => round($base * $rate / 100, 2)];
        }, $items);
        $base = round(array_sum(array_column($lines, 'base')), 2);
        $vat = round(array_sum(array_column($lines, 'vat')), 2);
        $opts = ['without' => $base, 'with' => round($base + $vat, 2), 'issue_date' => $date ?? self::YEAR . ($creditNote ? '-06-05' : '-06-01')];
        $id = $issued
            ? $this->saleInvoice($this->supplierId, $opts + ['type' => $creditNote ? 'credit_note' : 'invoice'])
            : $this->purchaseInvoice($this->supplierId, $opts + ['document_kind' => $creditNote ? 'credit_note' : 'invoice']);

        $stmt = $this->db->pdo()->prepare(sprintf(
            'INSERT INTO %s
                (%s, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, ?, "ks", ?, ?, ?, ?, ?, ?, ?)',
            $issued ? 'invoice_items' : 'purchase_invoice_items',
            $issued ? 'invoice_id' : 'purchase_invoice_id',
        ));
        foreach ($lines as $index => $line) {
            $stmt->execute([$id, "Položka {$line['rate']} %", $line['quantity'], $line['unit_price'], $this->vatRateId($line['rate']),
                $line['rate'], $line['base'], $line['vat'], round($line['base'] + $line['vat'], 2), $index]);
        }
        return $id;
    }

    /** Česká sazba DPH z číselníku (položka nese sazbu i ve snapshotu). */
    private function vatRateId(int $rate): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM vat_rates WHERE country = 'CZ' ORDER BY rate_percent = ? DESC, valid_to IS NULL DESC, id LIMIT 1"
        );
        $stmt->execute([$rate]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Evidence DPH dokladů za rok po sazbách [sazba => [základ, DPH]] v Kč se znaménkem
     * dokladu (dobropis záporně) — řádky, ze kterých se skládá přiznání i kontrolní hlášení.
     *
     * @param list<int> $documentIds
     * @return array<int,array{0:float,1:float}>
     */
    private function vatByRate(bool $issued, array $documentIds): array
    {
        $out = [];
        foreach ($this->container->get(VatLedgerService::class)->rows($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31') as $row) {
            if ($row['source'] !== ($issued ? 'sale' : 'purchase') || !in_array($row['invoice_id'], $documentIds, true)) {
                continue;
            }
            $rate = (int) round((float) $row['vat_rate']);
            $out[$rate] = [round(($out[$rate][0] ?? 0.0) + (float) $row['base_czk'], 2), round(($out[$rate][1] ?? 0.0) + (float) $row['vat_czk'], 2)];
        }
        ksort($out);
        return $out;
    }

    /**
     * Úhrada faktury zvolenou cestou do peněžního deníku. Úhrada zbytku ($settles) fakturu
     * označí jako zaplacenou; částečná úhrada před zápočtem ji nechá otevřenou, jinak by
     * dobropis nešel započíst.
     */
    private function pay(bool $issued, string $instrument, int $invoiceId, float $amount, ?string $date = null, bool $settles = true): void
    {
        $date ??= self::YEAR . '-06-15';
        if ($issued) {
            if ($instrument === 'bank') {
                $tx = $this->bankTx($this->statement($this->supplierId, $this->accountA), $amount, ['posted_at' => $date]);
                $this->invoicePayment($this->supplierId, $invoiceId, $amount, 'bank', $tx, $date);
            } elseif ($instrument === 'cash') {
                // Pokladní úhrada zapíše pokladní doklad i platbu faktury (source = cash).
                $cashId = $this->cashDoc('in', 'invoice_payment', $amount, ['invoice_id' => $invoiceId], null, $date);
                $paymentId = $this->invoicePayment($this->supplierId, $invoiceId, $amount, 'cash', null, $date);
                $this->db->pdo()->prepare('UPDATE cash_documents SET invoice_payment_id = ? WHERE id = ?')->execute([$paymentId, $cashId]);
            } else {
                $this->invoicePayment($this->supplierId, $invoiceId, $amount, 'manual', null, $date);
            }
            if ($settles) {
                $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_at = ? WHERE id = ?")->execute([$date, $invoiceId]);
            }
            return;
        }
        if ($instrument === 'bank') {
            $tx = $this->bankTx($this->statement($this->supplierId, $this->accountA), -$amount, ['posted_at' => $date]);
            $this->paymentMatch($this->supplierId, $tx, $invoiceId, $amount);
        } elseif ($instrument === 'cash') {
            $this->cashDoc('out', 'purchase_payment', $amount, ['purchase_invoice_id' => $invoiceId], null, $date);
        }
        // Ruční úhrada přijaté faktury je jen stav „zaplaceno" s datem úhrady (virtuální noha).
        if ($settles) {
            $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?")->execute([$date, $invoiceId]);
        }
    }
}
