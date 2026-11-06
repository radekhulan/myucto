<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Funds;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Property\Funds\FundingRepository;
use MyInvoice\Service\Property\Funds\{FundingException,FundingRead};
use MyInvoice\Tests\Support\FundingFixture;
use PHPUnit\Framework\TestCase;

/**
 * Chronologie fondů se hlídá jen v rámci stejného zdroje (účet jednotky), ne celého domu.
 */
final class FundingChronologyTest extends TestCase
{
    use FundingFixture;

    private function income2027(): array
    {
        (new AccountingPeriodRepository($this->db))->create($this->f['supplier'], 2027, '2027-01-01', '2027-12-31');
        $request = $this->fundingRequest('income', '2027-01-15', '6000000') + [
            'bank_id' => $this->fundingBank('2027-01-15', '60000.00'),
            'key' => $this->fundingKey('2027-01-15'),
        ];
        return $this->fundingApply($request, 'income-2027');
    }

    private function sameContextCost(int $template, string $date, string $amount): int
    {
        $q = $this->db->pdo()->prepare('SELECT dimension_type_id,dimension_value_id FROM journal_entry_line_dimensions WHERE supplier_id=? AND line_id=?');
        $q->execute([$this->f['supplier'], $template]);
        $dims = array_map('intval', array_column($q->fetchAll(\PDO::FETCH_ASSOC), 'dimension_value_id', 'dimension_type_id'));
        $origins = array_fill_keys(array_keys($dims), ['origin' => 'explicit', 'ref' => null]);
        $entry = $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->f['supplier'], 'manual', null, [
            ['account_code' => '549.900', 'side' => 'debit', 'amount' => $amount, 'dimensions' => $dims, 'dimension_origins' => $origins],
            ['account_code' => '321', 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => $date, 'posted_by' => $this->f['actor']]);
        $q = $this->db->pdo()->prepare("SELECT id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? AND side='debit'");
        $q->execute([$this->f['supplier'], $entry]);
        return (int)$q->fetchColumn();
    }

    public function testDecemberRepairCoveredAfterPostedJanuaryIncomeOfOtherSource(): void
    {
        $this->fundingOpening();
        $income = $this->income2027();
        self::assertSame('379.920', $income['receipt']['effects'][0]['account_code']);

        $cost = $this->fundingCost('2026-12-15', '549.900', '90000.00');
        $draw = $this->fundingDraw('2026-12-15', 'repair', '9000000', $cost);
        $operation = $this->fundingApply($draw, 'december-repair');

        self::assertSame('2026-12-15', $operation['accounting_date']);
        $read = (new FundingRead($this->db))->at($this->f['supplier'], null, '2026-12-31');
        self::assertTrue($read['matches']);
        self::assertSame('21000000', $read['totals']['955.100']);
    }

    public function testBackdatedDrawOfSameSourceStaysRejected(): void
    {
        $this->fundingOpening();
        $this->income2027();
        $december = $this->fundingCost('2026-12-15', '549.900', '90000.00');
        $this->fundingApply($this->fundingDraw('2026-12-15', 'repair', '9000000', $december), 'december-repair');

        $november = $this->sameContextCost($december, '2026-11-02', '30000.00');
        $request = $this->fundingDraw('2026-11-02', 'repair', '3000000', $november);
        try {
            (new FundingRepository($this->db))->preview($this->f['supplier'], $request, $this->f['actor']);
            self::fail('Backdated draw of the same 955 source accepted');
        } catch (FundingException $e) {
            self::assertSame('property_funding_chronology', $e->errorCode);
        }
    }
}
