<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxPayrollPostingMap;
use PHPUnit\Framework\TestCase;

final class StereoNxPayrollPostingMapTest extends TestCase
{
    public function testOnlyVerifiedEmployeeJournalRowsBecomePostingEvidence(): void
    {
        $parameters = [
            ['TypPar' => 1, 'Text' => 'Hrubá mzda (z)', 'UcetMD' => '521', 'UcetD' => '331'],
            ['TypPar' => 1, 'Text' => 'Zdravotní pojištění zaměstnance', 'UcetMD' => '331', 'UcetD' => '336'],
            ['TypPar' => 1, 'Text' => 'Srážky zaměstnanců', 'UcetMD' => '331', 'UcetD' => '379'],
            ['TypPar' => 2, 'Text' => 'Hrubá mzda (s)', 'UcetMD' => '522', 'UcetD' => '366'],
        ];
        $transfer = [['DoklRadaU' => 'MZ', 'DoklRadaP' => 'MP', 'DoklRadaZ' => 'MM']];
        $journal = [
            $this->line('MZ', 'Hrubá mzda (z)', '521', '331', 28_000.0),
            $this->line('MZ', 'Zdravotní pojištění zaměstnance', '331', '336', 1_260.0),
            $this->line('MZ', 'Exekuce (srážky zaměstnanců)', '331', '379', 1_000.0),
            $this->line('MZ', 'Spoření (srážky zaměstnanců)', '331', '379', 500.0),
            $this->line('X', 'Hrubá mzda (z)', '521', '331', 99_000.0),
            $this->line('MZ', 'Hrubá mzda (z)', '522', '331', 99_000.0),
            $this->line('MZ', 'Hrubá mzda (s)', '522', '366', 99_000.0),
        ];

        $source = StereoNxPayrollPostingMap::fromTables($parameters, $transfer, $journal);
        self::assertSame('stereo_nx', $source->sourceKey());
        self::assertSame(2026, $source->year);
        self::assertSame(['enforcement_deductions', 'employment_gross', 'other_deductions', 'employee_health'], array_map(
            static fn ($row): ?string => $row->concept, $source->postingRows(),
        ));
        self::assertSame(2_800_000, $source->postingRows()[1]->amountMinor);
        self::assertSame('336', $source->postingRows()[3]->creditAccount);
    }

    public function testAmbiguousParameterAccountPairIsSkipped(): void
    {
        $parameters = [
            ['TypPar' => 1, 'Text' => 'Hrubá mzda (z)', 'UcetMD' => '521', 'UcetD' => '331'],
            ['TypPar' => 1, 'Text' => 'Hrubá mzda (z)', 'UcetMD' => '522', 'UcetD' => '331'],
        ];
        $source = StereoNxPayrollPostingMap::fromTables($parameters, [['DoklRadaU' => 'MZ']], [
            $this->line('MZ', 'Hrubá mzda (z)', '521', '331', 28_000.0),
        ]);
        self::assertSame([], $source->postingRows());
    }

    public function testUnverifiedAnalyticExpansionIsSkipped(): void
    {
        $source = StereoNxPayrollPostingMap::fromTables(
            [['TypPar' => 1, 'Text' => 'Zdravotní pojištění zaměstnance', 'UcetMD' => '331', 'UcetD' => '336P']],
            [['DoklRadaU' => 'MZ']],
            [$this->line('MZ', 'Zdravotní pojištění zaměstnance', '331', '336.111', 1_260.0)],
        );
        self::assertSame([], $source->postingRows());
    }

    /** @return array<string,mixed> */
    private function line(string $series, string $text, string $debit, string $credit, float $amount): array
    {
        return ['DoklRada' => $series, 'Text' => $text, 'UcetMD' => $debit,
            'UcetD' => $credit, 'KdyUcPripad' => '2026-01-31', 'Celkem' => $amount];
    }
}
