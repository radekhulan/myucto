<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingPayments;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxMovementJournalLinks;
use MyInvoice\Service\Migration\StereoNx\StereoNxSourcePlan;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../Fixtures/StereoNx/SyntheticNx1Archive.php';
require_once __DIR__ . '/../../../../Fixtures/StereoNx/SyntheticStereoNxTables.php';

final class StereoNxAccountingPaymentsTest extends TestCase
{
    public function testPlansOnlyVerifiedDomesticPaymentsAndDraftsUnknownCash(): void
    {
        $path = $this->archive(self::tables());
        try {
            $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
            $plan = $module->prepare(StereoNxBackup::open($path, 0));
        } finally {
            @unlink($path);
        }
        self::assertSame(1, $plan['counts']['bank_accounts']);
        self::assertSame(1, $plan['counts']['bank_statements']);
        self::assertSame(5, $plan['counts']['bank_transactions']);
        self::assertSame(1, $plan['counts']['cash_transactions']);
        self::assertSame(3, $plan['counts']['payments']);
        self::assertSame(1, $plan['counts']['requires_review']);
        self::assertTrue($plan['records']['cash_transactions'][0]['requires_draft']);
    }

    public function testBankExpenseReversalKeepsSignedPaymentAndReconcilesNetPaidAmount(): void
    {
        $tables = self::tables();
        $reversal = array_replace($tables['CBankap'][1], ['Klic' => 6, 'Castka' => -21.0, 'CastkaVlastni' => -21.0]);
        $tables['CBankap'][] = $reversal;
        $tables['Cpz'][1]['Uhrazeno'] = 100.0;
        $tables['Cpz'][1]['UhrazenoVse'] = false;
        $path = $this->archive($tables);
        try {
            $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
            $plan = $module->prepare(StereoNxBackup::open($path, 0));
        } finally {
            @unlink($path);
        }
        self::assertEquals(21.0, $plan['records']['bank_transactions'][5]['amount']);
        $payments = array_values(array_filter($plan['records']['payments'],
            static fn (array $payment): bool => $payment['document_key'] === '["2025","PF","1"]'));
        self::assertEquals([121.0, -21.0], array_column($payments, 'amount'));
        self::assertSame('["2025","B","1","6"]', $payments[1]['movement_key']);
        $reconciliation = \MyInvoice\Service\Migration\StereoNx\StereoNxPaymentReconciliation::check(
            $tables['Cpz'], $tables['CBankap'], $tables['CPokl']);
        self::assertTrue($reconciliation['ok']);
    }

    public function testAbsolutePaymentTotalCannotVerifyExpenseReversal(): void
    {
        $tables = self::tables();
        $tables['CBankap'][] = array_replace($tables['CBankap'][1],
            ['Klic' => 6, 'Castka' => -21.0, 'CastkaVlastni' => -21.0]);
        $tables['Cpz'][1]['Uhrazeno'] = 142.0;
        $path = $this->archive($tables);
        try {
            $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
            $plan = $module->prepare(StereoNxBackup::open($path, 0));
        } finally {
            @unlink($path);
        }
        self::assertSame([], array_values(array_filter($plan['records']['payments'],
            static fn (array $payment): bool => $payment['document_key'] === '["2025","PF","1"]')));
        self::assertSame(3, $plan['counts']['requires_review']);
    }

    public function testNegativePurchaseSettlementPaysNegativeDocumentWithoutLosingBankSign(): void
    {
        $tables = self::tables();
        foreach (['ZaklDPHz', 'DPHz', 'Celkem'] as $field) $tables['SPFH'][0][$field] *= -1;
        $tables['CBankap'][1]['Castka'] = $tables['CBankap'][1]['CastkaVlastni'] = -121.0;
        $tables['Cpz'][1]['Celkem'] = $tables['Cpz'][1]['Uhrazeno'] = -121.0;
        $path = $this->archive($tables);
        try {
            $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
            $plan = $module->prepare(StereoNxBackup::open($path, 0));
        } finally {
            @unlink($path);
        }
        self::assertEquals(121.0, $plan['records']['bank_transactions'][1]['amount']);
        self::assertEquals(121.0, $plan['records']['payments'][1]['amount']);
        self::assertSame('purchase', $plan['records']['payments'][1]['document_kind']);
    }

    public function testPaymentNormalizationRequiresMatchingImportedDocument(): void
    {
        foreach (['sign', 'total', 'direction', 'missing'] as $case) {
            $tables = self::tables();
            foreach ($tables['SPFH'] as &$header) $header['SmerPlatby'] = 'V';
            unset($header);
            $tables['CBankap'][1]['Castka'] = $tables['CBankap'][1]['CastkaVlastni'] = -121.0;
            $tables['CBankap'][] = array_replace($tables['CBankap'][1],
                ['Klic' => 6, 'Castka' => 21.0, 'CastkaVlastni' => 21.0]);
            $tables['Cpz'][1]['Celkem'] = -121.0;
            $tables['Cpz'][1]['Uhrazeno'] = -100.0;
            if ($case !== 'sign') $tables['SPFH'][0]['Celkem'] = -121.0;
            if ($case === 'total') $tables['SPFH'][0]['Celkem'] = -120.0;
            if ($case === 'direction') $tables['SPFH'][0]['SmerPlatby'] = 'P';
            if ($case === 'missing') unset($tables['SPFH'][0]);
            $path = $this->archive($tables);
            try {
                $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
                $plan = $module->prepare(StereoNxBackup::open($path, 0));
            } finally {
                @unlink($path);
            }
            self::assertSame([], array_values(array_filter($plan['records']['payments'],
                static fn (array $payment): bool => $payment['document_key'] === '["2025","PF","1"]')), $case);
            self::assertSame(3, $plan['counts']['requires_review'], $case);
        }
    }

    public function testDirectionMismatchKeepsEntirePaymentGroupForReview(): void
    {
        $tables = self::tables();
        $tables['CBankap'][] = array_replace($tables['CBankap'][1],
            ['Klic' => 6, 'Castka' => -21.0, 'CastkaVlastni' => -21.0, 'SmerPlatby' => 'P']);
        $tables['Cpz'][1]['Uhrazeno'] = 100.0;
        $path = $this->archive($tables);
        try {
            $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
            $plan = $module->prepare(StereoNxBackup::open($path, 0));
        } finally {
            @unlink($path);
        }
        self::assertSame([], array_values(array_filter($plan['records']['payments'],
            static fn (array $payment): bool => $payment['document_key'] === '["2025","PF","1"]')));
        self::assertSame(3, $plan['counts']['requires_review']);
    }

    public function testSkippedForeignMovementPreventsPartialPaymentGroup(): void
    {
        $tables = self::tables();
        $tables['CBankap'][] = array_replace($tables['CBankap'][1],
            ['Klic' => 6, 'Castka' => -21.0, 'CastkaVlastni' => -525.0, 'Kurz' => 25.0]);
        $path = $this->archive($tables);
        try {
            $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
            $plan = $module->prepare(StereoNxBackup::open($path, 0));
        } finally {
            @unlink($path);
        }
        self::assertSame([], array_values(array_filter($plan['records']['payments'],
            static fn (array $payment): bool => $payment['document_key'] === '["2025","PF","1"]')));
        self::assertSame(1, $plan['counts']['skipped_bank_transactions']);
        self::assertSame(2, $plan['counts']['requires_review']);
    }

    public function testInvalidReceivableTotalKeepsGroupForReview(): void
    {
        foreach (['domestic', 'foreign', 'unsupported_agenda'] as $case) {
            $tables = self::tables();
            $tables['Cpz'][1]['Celkem'] = null;
            if ($case === 'foreign') $tables['Cpz'][1]['Mena'] = 'EUR';
            if ($case === 'unsupported_agenda') $tables['Cpz'][1]['Agenda'] = 'PZ';
            $path = $this->archive($tables);
            try {
                $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
                $plan = $module->prepare(StereoNxBackup::open($path, 0));
            } finally {
                @unlink($path);
            }
            self::assertSame([], array_values(array_filter($plan['records']['payments'],
                static fn (array $payment): bool => $payment['document_key'] === '["2025","PF","1"]')), $case);
            self::assertSame(2, $plan['counts']['requires_review'], $case);
        }
    }

    public function testUnsupportedReversalKeepsWholePaymentGroupForReview(): void
    {
        foreach (['issued_bank', 'purchase_cash'] as $case) {
            $tables = self::tables();
            $index = $case === 'issued_bank' ? 0 : 1;
            $reversal = array_replace($tables['CBankap'][$index],
                ['Klic' => 6, 'Castka' => -21.0, 'CastkaVlastni' => -21.0]);
            if ($case === 'issued_bank') $tables['CBankap'][] = $reversal;
            else $tables['CPokl'][] = array_replace($reversal,
                ['DoklRada' => 'P', 'DoklCislo' => 2, 'Doklad' => 'P-2']);
            $tables['Cpz'][$index]['Uhrazeno'] = 100.0;
            $path = $this->archive($tables);
            try {
                $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
                $plan = $module->prepare(StereoNxBackup::open($path, 0));
            } finally {
                @unlink($path);
            }
            $documentKey = $case === 'issued_bank' ? '["2025","VF","1"]' : '["2025","PF","1"]';
            self::assertSame([], array_values(array_filter($plan['records']['payments'],
                static fn (array $payment): bool => $payment['document_key'] === $documentKey)), $case);
            self::assertSame(3, $plan['counts']['requires_review'], $case);
            if ($case === 'issued_bank') self::assertEquals(-21.0, $plan['records']['bank_transactions'][5]['amount']);
            else self::assertTrue($plan['records']['cash_transactions'][1]['requires_draft']);
        }
    }

    public function testSkipsForeignMovementWhenSourceOmitsCurrencyCode(): void
    {
        $tables = self::tables();
        $tables['CBankap'][0]['Kurz'] = 25.1;
        $tables['CBankap'][0]['CastkaVlastni'] = 3037.1;
        $path = $this->archive($tables);
        try {
            $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
            $plan = $module->prepare(StereoNxBackup::open($path, 0));
        } finally {
            @unlink($path);
        }
        self::assertSame(4, $plan['counts']['bank_transactions']);
        self::assertSame(2, $plan['counts']['payments']);
        self::assertSame(1, $plan['counts']['skipped_bank_transactions']);
        self::assertContains('bank_currency_unresolved', array_column($plan['warnings'], 'code'));
    }

    public function testInvalidBankVariableSymbolIsPreservedAsReference(): void
    {
        $tables = self::tables();
        $tables['CBankap'][0]['VarSym'] = 'CARD-1234567890123';
        $path = $this->archive($tables);
        try {
            $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
            $plan = $module->prepare(StereoNxBackup::open($path, 0));
        } finally {
            @unlink($path);
        }
        self::assertNull($plan['records']['bank_transactions'][0]['variable_symbol']);
        self::assertStringContainsString('CARD-1234567890123', $plan['records']['bank_transactions'][0]['description']);
    }

    public function testPostsOnlyExplicitNonVatCashWithMatchingCashSideAndAmount(): void
    {
        $tables = self::tables();
        $tables['CPokl'][0]['DoklSCislo'] = '';
        $tables['CPokl'][0]['ZpracovatDPH'] = false;
        $tables['CPokl'][0]['TypDPH'] = '';
        foreach (['ZaklDPHz', 'DPHz', 'ZaklDPHs', 'DPHs', 'ZaklDPHt', 'DPHt', 'BezDane'] as $field) {
            $tables['CPokl'][0][$field] = 0.0;
        }
        $path = $this->archive($tables);
        try {
            $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
            $plan = $module->prepare(StereoNxBackup::open($path, 0));
        } finally {
            @unlink($path);
        }
        $entry = [
            'source_key' => json_encode(['P', 'P', '1', '0', '0'], JSON_THROW_ON_ERROR),
            'date' => $plan['records']['cash_transactions'][0]['date'], 'is_opening' => false,
            'debit' => '21110', 'credit' => '379', 'amount_cents' => 2000, 'is_red_storno' => false,
        ];
        $journal = ['accounting_plan' => ['entries' => [$entry]]];
        $resolved = $module->resolveNonVatCash($plan, $journal);
        self::assertFalse($resolved['records']['cash_transactions'][0]['requires_draft']);
        self::assertSame([], $resolved['records']['reviews']);
        self::assertSame(0, $resolved['counts']['requires_review']);

        foreach ([
            [['debit' => '379', 'credit' => '21110']],
            [['amount_cents' => 1900]],
            [['is_red_storno' => true]],
            [['debit' => '21110', 'credit' => '21120']],
        ] as $changes) {
            $unverified = $module->resolveNonVatCash($plan, ['accounting_plan' => ['entries' => [array_replace($entry, $changes[0])]]]);
            self::assertTrue($unverified['records']['cash_transactions'][0]['requires_draft']);
            self::assertSame(1, $unverified['counts']['requires_review']);
        }
    }

    public function testAmbiguousCashVatOrDocumentReferenceStaysDraft(): void
    {
        foreach (['unknown_flag', 'missing_tax_amount', 'nonzero_tax_amount', 'linked_document'] as $case) {
            $tables = self::tables();
            $row = &$tables['CPokl'][0];
            $row['DoklSCislo'] = '';
            $row['ZpracovatDPH'] = false;
            $row['TypDPH'] = '';
            foreach (['ZaklDPHz', 'DPHz', 'ZaklDPHs', 'DPHs', 'ZaklDPHt', 'DPHt', 'BezDane'] as $field) $row[$field] = 0.0;
            if ($case === 'unknown_flag') $row['ZpracovatDPH'] = null;
            if ($case === 'missing_tax_amount') unset($row['DPHz']);
            if ($case === 'nonzero_tax_amount') $row['DPHz'] = 1.0;
            if ($case === 'linked_document') { $row['DoklSRada'] = 'ZZ'; $row['DoklSCislo'] = '1'; }
            unset($row);
            $path = $this->archive($tables);
            try {
                $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
                $plan = $module->prepare(StereoNxBackup::open($path, 0));
            } finally {
                @unlink($path);
            }
            $entry = ['source_key' => json_encode(['P', 'P', '1', '0', '0'], JSON_THROW_ON_ERROR),
                'date' => $plan['records']['cash_transactions'][0]['date'], 'is_opening' => false,
                'debit' => '21110', 'credit' => '379', 'amount_cents' => 2000, 'is_red_storno' => false];
            $resolved = $module->resolveNonVatCash($plan, ['accounting_plan' => ['entries' => [$entry]]]);
            self::assertTrue($resolved['records']['cash_transactions'][0]['requires_draft'], $case);
            self::assertSame(1, $resolved['counts']['requires_review'], $case);
            if ($case === 'nonzero_tax_amount') {
                self::assertSame(['movement_vat_unverified'], $resolved['records']['cash_transactions'][0]['review_codes']);
            }
        }
    }

    public function testSplitPostingClearsOnlyTheVerifiedCashReview(): void
    {
        $tables = self::tables();
        $first = &$tables['CPokl'][0];
        $first['DoklSCislo'] = '';
        $first['ZpracovatDPH'] = false;
        $first['TypDPH'] = '';
        foreach (['ZaklDPHz', 'DPHz', 'ZaklDPHs', 'DPHs', 'ZaklDPHt', 'DPHt', 'BezDane'] as $field) $first[$field] = 0.0;
        $second = $first;
        unset($first);
        $second['DoklCislo'] = 2;
        $second['Doklad'] = 'P-2';
        $second['ZpracovatDPH'] = null;
        $tables['CPokl'][] = $second;
        $path = $this->archive($tables);
        try {
            $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
            $plan = $module->prepare(StereoNxBackup::open($path, 0));
        } finally {
            @unlink($path);
        }
        $date = $plan['records']['cash_transactions'][0]['date'];
        $entry = ['source_key' => json_encode(['P', 'P', '1', '0', '0'], JSON_THROW_ON_ERROR),
            'date' => $date, 'is_opening' => false, 'debit' => '21110', 'credit' => '379',
            'amount_cents' => 1000, 'is_red_storno' => false];
        $split = array_replace($entry, ['source_key' => json_encode(['P', 'P', '1', '0', '1'], JSON_THROW_ON_ERROR),
            'credit' => '321']);
        $resolved = $module->resolveNonVatCash($plan, ['accounting_plan' => ['entries' => [$entry, $split]]]);
        self::assertFalse($resolved['records']['cash_transactions'][0]['requires_draft']);
        self::assertTrue($resolved['records']['cash_transactions'][1]['requires_draft']);
        self::assertSame(1, $resolved['counts']['requires_review']);
        self::assertCount(1, $resolved['records']['reviews']);
        $pending = array_values(array_filter($resolved['warnings'],
            static fn (array $warning): bool => ($warning['code'] ?? '') === 'pending_reconciliation'));
        self::assertCount(1, $pending);
        self::assertSame(1, $pending[0]['count']);
    }

    public function testFiscalYearCompanyKeysMovementsAndJournalLinksByCompanyPeriod(): void
    {
        $tables = self::tables();
        $first = &$tables['CPokl'][0];
        $first['DoklSCislo'] = '';
        $first['ZpracovatDPH'] = false;
        $first['TypDPH'] = '';
        foreach (['ZaklDPHz', 'DPHz', 'ZaklDPHs', 'DPHs', 'ZaklDPHt', 'DPHt', 'BezDane'] as $field) $first[$field] = 0.0;
        $next = $first;
        unset($first);
        // Stereo čísluje pokladnu v rámci účetního roku: P-1 hospodářského roku 2025/26
        // padne do stejného kalendářního roku jako P-1 roku 2024/25.
        $next['KdyUcPripad'] = '2025-09-10';
        $next['KdyVystaveno'] = '2025-09-10';
        $next['Castka'] = 30.0;
        $next['CastkaVlastni'] = 30.0;
        $tables['CPokl'][] = $next;
        $path = $this->archive($tables);
        try {
            $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
            $plan = $module->prepare(StereoNxBackup::open($path, 0), new FiscalCalendar(8));
        } finally {
            @unlink($path);
        }
        self::assertSame(2, $plan['counts']['cash_transactions']);
        self::assertSame(['["2024","P","1"]', '["2025","P","1"]'],
            array_column($plan['records']['cash_transactions'], 'source_key'));
        self::assertSame('["2024","B","1"]', $plan['records']['bank_statements'][0]['source_key']);
        self::assertSame(5, $plan['counts']['bank_transactions']);

        $entry = static fn (string $klic, string $date, int $year, int $cents): array => [
            'source_key' => json_encode(['P', 'P', '1', $klic, '0'], JSON_THROW_ON_ERROR),
            'date' => $date, 'posting_year' => $year, 'is_opening' => false,
            'debit' => '21110', 'credit' => '379', 'amount_cents' => $cents, 'is_red_storno' => false];
        $journal = ['accounting_plan' => ['entries' => [
            $entry('1', '2025-03-15', 2024, 2000), $entry('2', '2025-09-10', 2025, 3000)]]];
        $index = StereoNxMovementJournalLinks::indexMovementEntries($journal);
        self::assertSame(['["2024","P","1"]', '["2025","P","1"]'], array_keys($index['cash']));

        $resolved = $module->resolveNonVatCash($plan, $journal);
        self::assertFalse($resolved['records']['cash_transactions'][0]['requires_draft']);
        self::assertFalse($resolved['records']['cash_transactions'][1]['requires_draft']);
        self::assertSame(0, $resolved['counts']['requires_review']);
    }

    public function testFiscalYearPaymentsPointToFiscalYearDocumentKeys(): void
    {
        $tables = self::tables();
        $path = $this->archive($tables);
        try {
            $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
            $plan = $module->prepare(StereoNxBackup::open($path, 0), new FiscalCalendar(8));
        } finally {
            @unlink($path);
        }
        $documents = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true, new FiscalCalendar(8));
        $keys = [...array_column($documents['issued'], 'source_key'), ...array_column($documents['purchases'], 'source_key')];
        self::assertCount(3, $plan['records']['payments']);
        foreach ($plan['records']['payments'] as $payment) {
            self::assertStringStartsWith('["2024",', $payment['document_key']);
            self::assertContains($payment['document_key'], $keys);
        }
    }

    public function testPaymentOutsideImportedInvoiceAgendasGetsSpecificReview(): void
    {
        $tables = self::tables();
        $tables['CPokl'][0]['DoklSRada'] = 'ZZ';
        $tables['CPokl'][0]['DoklSCislo'] = '1';
        $tables['Cpz'][] = ['DoklSRada' => 'ZZ', 'DoklSCislo' => '1', 'Agenda' => 'PZ',
            'SmerPlatby' => 'P', 'Mena' => 'Kč', 'Uhrazeno' => 20.0, 'KdyVystaveno' => '2025-03-15'];
        $path = $this->archive($tables);
        try {
            $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
            $plan = $module->prepare(StereoNxBackup::open($path, 0));
        } finally {
            @unlink($path);
        }
        self::assertSame(['payment_agenda_not_imported'], $plan['records']['cash_transactions'][0]['review_codes']);
        self::assertSame(['payment_agenda_not_imported'], $plan['records']['reviews'][0]['review_codes']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('openingRawCases')]
    public function testNullOpeningTaxFieldsCreateOnlyDedicatedOpeningCandidate(string $case): void
    {
        $tables = self::tables();
        $tables['LFirma'][0]['DruhPS'] = 'OPEN';
        $cash = &$tables['CPokl'][0];
        $cash['Druh'] = 'OPEN';
        $cash['ZpracovatDPH'] = false;
        $cash['TypDPH'] = '';
        $cash['DoklSRada'] = '';
        $cash['DoklSCislo'] = null;
        $cash['Castka'] = $cash['CastkaVlastni'] = 456.78;
        foreach (['ZaklDPHz', 'DPHz', 'ZaklDPHs', 'DPHs', 'ZaklDPHt', 'DPHt', 'BezDane'] as $field) $cash[$field] = null;
        if ($case === 'ordinary') $cash['Druh'] = 'OTHER';
        if ($case === 'tax_flag') $cash['ZpracovatDPH'] = true;
        if ($case === 'vat_type') $cash['TypDPH'] = 'TU';
        if ($case === 'payment') { $cash['DoklSRada'] = 'VF'; $cash['DoklSCislo'] = '77'; }
        if ($case === 'missing_kind') unset($tables['LFirma'][0]['DruhPS']);
        if ($case === 'missing_tax_field') unset($cash['DPHz']);
        if (str_starts_with($case, 'tax_') && $case !== 'tax_flag') $cash[substr($case, 4)] = 1.0;
        unset($cash);
        $path = $this->archive($tables);
        try {
            $module = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
            $plan = $module->prepare(StereoNxBackup::open($path, 0));
        } finally {
            @unlink($path);
        }
        $movement = $plan['records']['cash_transactions'][0];
        self::assertSame($case === 'nulls', $plan['cash_opening_candidates'][$movement['source_key']] ?? false);
        self::assertSame([], $plan['non_vat_cash_candidates']);
        self::assertTrue($movement['requires_draft']);
        self::assertSame(str_starts_with($case, 'tax_') && $case !== 'tax_flag' ? ['movement_vat_unverified'] : ['pending_reconciliation'], $movement['review_codes']);
    }

    public static function openingRawCases(): iterable
    {
        foreach (['nulls', 'ordinary', 'tax_flag', 'vat_type', 'payment', 'missing_kind', 'missing_tax_field',
            'tax_ZaklDPHz', 'tax_DPHz', 'tax_ZaklDPHs', 'tax_DPHs', 'tax_ZaklDPHt', 'tax_DPHt', 'tax_BezDane'] as $case) yield $case => [$case];
    }

    /** @return array<string,list<array<string,mixed>>> */
    public static function tables(): array
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['CBanka'][0]['Kurz'] = 1.0; $tables['CBanka'][0]['KurzMn'] = 1.0;
        foreach ($tables['CBankap'] as &$row) {
            $row['Kurz'] = 1.0; $row['KurzMn'] = 1.0; $row['CastkaVlastni'] = $row['Castka'];
            $row['DPHz'] = 0.0; $row['DPHs'] = 0.0; $row['DPHt'] = 0.0;
        }
        unset($row);
        foreach ($tables['CPokl'] as &$row) {
            $row['Kurz'] = 1.0; $row['KurzMn'] = 1.0; $row['CastkaVlastni'] = $row['Castka'];
            $row['DPHz'] = 0.0; $row['DPHs'] = 0.0; $row['DPHt'] = 0.0;
        }
        unset($row);
        foreach ($tables['CPZZ'] as &$row) $row['Mena'] = 'Kč';
        unset($row);
        foreach ($tables['Cpz'] as &$row) $row['Agenda'] = $row['DoklSRada'];
        unset($row);
        return $tables;
    }

    /** @param array<string,list<array<string,mixed>>> $tables */
    private function archive(array $tables): string
    {
        $path = sys_get_temp_dir() . '/stereo-payments-' . bin2hex(random_bytes(6)) . '.zip';
        SyntheticNx1Archive::write($path, $tables, SyntheticStereoNxTables::identity());
        return $path;
    }
}
