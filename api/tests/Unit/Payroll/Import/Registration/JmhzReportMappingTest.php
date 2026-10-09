<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Registration;

use MyInvoice\Service\Payroll\Import\Jmhz\JmhzAveragePlanner;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzBatch;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzBatchItem;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzOpeningBalancePlanner;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportFile;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportReader;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzStatutoryChanges;
use PHPUnit\Framework\TestCase;

/**
 * Mapování hlášení na doménu bez databáze: počáteční stavy, slevy,
 * prohlášení, platnost formulářů v dávce a vstupy průměru.
 */
final class JmhzReportMappingTest extends TestCase
{
    /**
     * Zrcadlo `PayrollRunStatutoryAccumulatorApprover`: z hlášení musí vyjít
     * přesně ta čísla, která by do kumulace zapsalo schválení vlastního běhu
     * nad týmž výsledkem (taxable 40 000, záloha po slevách 2 163, uplatněné
     * slevy 2 570, sleva na dítě 1 267, sociální základ 40 000).
     */
    public function testOpeningMonthIsTheMirrorOfTheAccumulatorApproval(): void
    {
        $items = $this->items(JmhzReportFixtures::report([JmhzReportFixtures::person()], 2026, 2));

        $result = JmhzOpeningBalancePlanner::monthRow(2, $items);

        self::assertNull($result['reason']);
        self::assertSame([
            'month' => 2,
            'social_assessment_base_minor_units' => 4_000_000,
            'advance_base_minor_units' => 4_000_000,
            'advance_tax_minor_units' => 216_300,
            'withholding_base_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
            'applied_non_refundable_credits_minor_units' => 257_000,
            'applied_child_credit_minor_units' => 126_700,
            'tax_bonus_minor_units' => 0,
            'bonus_qualifying_income_minor_units' => 4_000_000,
        ], $result['row']);
    }

    /**
     * Záloha nižší než nárok: § 35ba se uplatní jen do výše zálohy (2 000)
     * a na slevu na dítě nezbude nic — celý nárok na dítě jde do bonusu.
     */
    public function testOpeningMonthDerivesPartiallyAppliedCredits(): void
    {
        $person = JmhzReportFixtures::person([
            'wage' => 13_400,
            'taxable' => 13_400,
            'base' => 13_400,
            'computed' => 2_010,
            'after_credits' => 0,
            'child_applied' => 0,
            'bonus' => 1_267,
            'social_base' => 13_400,
        ]);

        $row = JmhzOpeningBalancePlanner::monthRow(2, $this->items(JmhzReportFixtures::report([$person], 2026, 2)))['row'];

        self::assertSame(201_000, $row['applied_non_refundable_credits_minor_units']);
        self::assertSame(0, $row['applied_child_credit_minor_units']);
        self::assertSame(126_700, $row['tax_bonus_minor_units']);
        self::assertSame(0, $row['advance_tax_minor_units']);
    }

    public function testInconsistentAdvanceBlocksTheMonth(): void
    {
        $person = JmhzReportFixtures::person(['computed' => 3_000, 'after_credits' => 2_163]);

        $result = JmhzOpeningBalancePlanner::monthRow(2, $this->items(JmhzReportFixtures::report([$person], 2026, 2)));

        self::assertNull($result['row']);
        self::assertStringContainsString('nesedí záloha', (string) $result['reason']);
    }

    public function testCreditsAboveTheDeclaredClaimBlockTheMonth(): void
    {
        $person = JmhzReportFixtures::person(['basic_credit' => 1_000]);

        $result = JmhzOpeningBalancePlanner::monthRow(2, $this->items(JmhzReportFixtures::report([$person], 2026, 2)));

        self::assertNull($result['row']);
        self::assertStringContainsString('vyšší než slevy v prohlášení', (string) $result['reason']);
    }

    /** Souběh: pojistné i základ daně se sčítají přes formuláře osoby, daň je jen na primárním. */
    public function testConcurrentEmploymentsAreSummedPerPerson(): void
    {
        $primary = JmhzReportFixtures::person();
        $secondary = JmhzReportFixtures::person([
            'employment_id' => 102,
            'id_ppv' => '200000000000000000202',
            'primary' => false,
            'wage' => 5_000,
            'taxable' => 5_000,
            'social_base' => 5_000,
        ]);
        $items = $this->items(JmhzReportFixtures::report([$primary, $secondary], 2026, 2));
        self::assertCount(2, $items);

        $row = JmhzOpeningBalancePlanner::monthRow(2, $items)['row'];

        self::assertSame(4_500_000, $row['social_assessment_base_minor_units']);
        self::assertSame(4_500_000, $row['advance_base_minor_units']);
        self::assertSame(216_300, $row['advance_tax_minor_units']);
    }

    /**
     * Souběh HPP se zálohou a DPP se srážkovou daní: 10535 u dohody nese základ
     * srážky (DS 1.4.1.6, Pokyny MH 1.4.14 kap. 3.4), do základu zálohy ale
     * nepatří. Základ zálohy je 10297 (40 000), ne Σ 10535 (51 999); hlášení,
     * které srážku v 10535 nevykazuje, dá týž výsledek.
     */
    public function testWithholdingBaseIn10535IsNotCountedIntoTheAdvanceBase(): void
    {
        foreach ([11_999, 0] as $secondaryTaxable) {
            $primary = JmhzReportFixtures::person(['withholding' => ['base' => 11_999, 'tax' => 1_799]]);
            $secondary = JmhzReportFixtures::person([
                'employment_id' => 102,
                'id_ppv' => '200000000000000000202',
                'primary' => false,
                'wage' => 11_999,
                'taxable' => $secondaryTaxable,
                'social_base' => 0,
            ]);

            $result = JmhzOpeningBalancePlanner::monthRow(
                2,
                $this->items(JmhzReportFixtures::report([$primary, $secondary], 2026, 2)),
            );

            self::assertNull($result['reason'], (string) $result['reason']);
            self::assertSame(4_000_000, $result['row']['advance_base_minor_units']);
            self::assertSame(1_199_900, $result['row']['withholding_base_minor_units']);
        }
    }

    public function testClaimedCreditIsTakenFromTheMonthAndImportedClaimEndsWhenAbsent(): void
    {
        $reference = 'jmhz-import:' . str_repeat('a', 64) . ':' . JmhzReportFixtures::guid(1, 101);
        $claimed = JmhzStatutoryChanges::credits(['tax_credit_claims' => []], null, '2026-02-01', ['taxpayer' => 2_570, 'ztp-p' => 0], $reference);

        self::assertSame([['kind' => 'taxpayer', 'action' => 'claim', 'current' => null]], $claimed['changes']);
        self::assertSame([[
            'id' => null,
            'effective_from' => '2026-02-01',
            'effective_to' => null,
            'evidence_note' => null,
            'credit_kind' => 'taxpayer',
            'evidence_status' => 'verified',
            'evidence_reference' => $reference,
        ]], $claimed['sections']['tax_credit_claims']);

        $stored = [
            ['id' => 5, 'row_version' => 1, 'credit_kind' => 'taxpayer', 'evidence_status' => 'verified',
                'evidence_reference' => $reference, 'effective_from' => '2026-02-01', 'effective_to' => null],
            ['id' => 6, 'row_version' => 1, 'credit_kind' => 'ztp-p', 'evidence_status' => 'verified',
                'evidence_reference' => 'declaration:manual', 'effective_from' => '2026-01-01', 'effective_to' => null],
        ];
        $ended = JmhzStatutoryChanges::credits(['tax_credit_claims' => $stored], null, '2026-04-01', [], $reference);

        self::assertSame([['kind' => 'taxpayer', 'action' => 'end', 'current' => 'verified']], $ended['changes']);
        $byId = array_column($ended['sections']['tax_credit_claims'], null, 'id');
        self::assertSame('2026-03-31', $byId[5]['effective_to']);
        self::assertNull($byId[6]['effective_to'], 'Ručně zapsanou slevu import neukončuje.');
        self::assertCount(1, $ended['warnings']);
    }

    public function testDeclarationChangeSplitsTheCoveringVersionAtTheMonth(): void
    {
        $stored = [['id' => 9, 'row_version' => 2, 'status' => 'signed', 'evidence_reference' => 'declaration:x',
            'effective_from' => '2026-01-01', 'effective_to' => null, 'evidence_note' => null]];

        $result = JmhzStatutoryChanges::declaration(['tax_declarations' => $stored], null, '2026-03-01', false, 'jmhz-import:ref');

        self::assertTrue($result['changed']);
        self::assertSame('signed', $result['current']);
        self::assertSame('not-signed', $result['imported']);
        self::assertSame('2026-02-28', $result['sections']['tax_declarations'][0]['effective_to']);
        self::assertSame(9, $result['sections']['tax_declarations'][0]['id']);
        self::assertSame(['id' => null, 'effective_from' => '2026-03-01', 'effective_to' => null, 'evidence_note' => null,
            'status' => 'not-signed', 'evidence_reference' => 'jmhz-import:ref'], $result['sections']['tax_declarations'][1]);

        $same = JmhzStatutoryChanges::declaration(['tax_declarations' => $stored], null, '2026-03-01', true, 'jmhz-import:ref');
        self::assertFalse($same['changed']);

        $frozen = JmhzStatutoryChanges::declaration(['tax_declarations' => $stored], '2026-03-31', '2026-03-01', false, 'jmhz-import:ref');
        self::assertFalse($frozen['changed']);
        self::assertStringContainsString('uzavřený schválenou mzdou', (string) $frozen['warning']);
    }

    public function testCorrectionSupersedesAndCancellationRemovesTheForm(): void
    {
        $regular = $this->file(JmhzReportFixtures::report([JmhzReportFixtures::person()], 2026, 2));
        $correction = $this->file(JmhzReportFixtures::report(
            [JmhzReportFixtures::person(['work_place' => 'Ostrava'])],
            2026,
            2,
            ['type' => 'O', 'filled_at' => '2026-03-15T08:00:00Z'],
        ));
        $items = [
            new JmhzBatchItem('aaaaaaaaaaaaaaaa:1', $regular, $regular->forms[0], 'r.xml', str_repeat('a', 64), 0),
            new JmhzBatchItem('bbbbbbbbbbbbbbbb:1', $correction, $correction->forms[0], 'o.xml', str_repeat('b', 64), 1),
        ];

        $batch = JmhzBatch::build($items, []);
        self::assertSame(JmhzBatch::SUPERSEDED, $batch->state('aaaaaaaaaaaaaaaa:1'));
        self::assertSame(JmhzBatch::EFFECTIVE, $batch->state('bbbbbbbbbbbbbbbb:1'));

        $cancel = $this->file(JmhzReportFixtures::componentCancellation(2026, 2, 1, 101, '2026-03-20T08:00:00Z'));
        $withCancel = JmhzBatch::build([
            ...$items,
            new JmhzBatchItem('cccccccccccccccc:1', $cancel, $cancel->forms[0], 's.xml', str_repeat('c', 64), 2),
        ], []);
        self::assertSame(JmhzBatch::CANCELLED, $withCancel->state('bbbbbbbbbbbbbbbb:1'));
        self::assertSame(JmhzBatch::CANCELLATION, $withCancel->state('cccccccccccccccc:1'));
        self::assertSame([], $withCancel->effective());

        $storno = $this->file(JmhzReportFixtures::submissionCancellation(2026, 2, 1, '2026-03-25T08:00:00Z'));
        $withStorno = JmhzBatch::build($items, [['file' => $storno, 'name' => 'storno.xml']]);
        self::assertSame(JmhzBatch::CANCELLED, $withStorno->state('bbbbbbbbbbbbbbbb:1'));
    }

    public function testTwoDifferentFormsForTheSameEmploymentAreAConflict(): void
    {
        $first = $this->file(JmhzReportFixtures::report([JmhzReportFixtures::person()], 2026, 2));
        $second = $this->file(JmhzReportFixtures::report([JmhzReportFixtures::person()], 2026, 2, ['guid_seed' => 2]));

        $batch = JmhzBatch::build([
            new JmhzBatchItem('aaaaaaaaaaaaaaaa:1', $first, $first->forms[0], 'a.xml', str_repeat('a', 64), 0),
            new JmhzBatchItem('bbbbbbbbbbbbbbbb:1', $second, $second->forms[0], 'b.xml', str_repeat('b', 64), 1),
        ], []);

        self::assertSame(JmhzBatch::CONFLICT, $batch->state('aaaaaaaaaaaaaaaa:1'));
        self::assertSame(JmhzBatch::CONFLICT, $batch->state('bbbbbbbbbbbbbbbb:1'));
    }

    public function testQuarterInputsComeFromWageWorkedHoursAndDays(): void
    {
        $forms = [];
        foreach ([1, 2, 3] as $month) {
            $forms[$month] = $this->file(JmhzReportFixtures::report([JmhzReportFixtures::person()], 2026, $month))->forms[0];
        }

        self::assertSame(
            ['gross_minor' => 12_000_000, 'worked_minutes' => 30_240, 'worked_days' => 63, 'reason' => null],
            JmhzAveragePlanner::quarterInputs($forms),
        );

        $forms[2] = $this->file(JmhzReportFixtures::report([JmhzReportFixtures::person(['irregular' => 5_000])], 2026, 2))->forms[0];
        self::assertStringContainsString('§ 358', (string) JmhzAveragePlanner::quarterInputs($forms)['reason']);
    }

    /** @return list<JmhzBatchItem> */
    private function items(string $xml): array
    {
        $file = $this->file($xml);
        $items = [];
        foreach ($file->forms as $form) {
            $items[] = new JmhzBatchItem('aaaaaaaaaaaaaaaa:' . $form->position, $file, $form, 'h.xml', str_repeat('a', 64), 0);
        }

        return $items;
    }

    private function file(string $xml): JmhzReportFile
    {
        return (new JmhzReportReader())->read($xml);
    }
}
