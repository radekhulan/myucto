<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\TaxEvidence\ReceivablesPayablesService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Ostatní pohledávky a závazky v daňové evidenci: potvrzení bez deníku, splátky v knize
 * pohledávek a závazků, úhrada přiřazená z banky i pokladny a její zařazení v peněžním
 * deníku. Jistina úvěru a jistota (kauce) nejsou příjmem ani výdajem, ostatní druhy si
 * zařazení nechávají na rozhodnutí uživatele jako každý bankovní pohyb bez dokladu.
 */
#[Group('integration')]
final class OtherItemTaxEvidenceTest extends CashJournalTestCase
{
    private OtherItemService $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->items = $this->container->get(OtherItemService::class);
    }

    public function testLoanWithInstallmentsFlowsToBookAndCashJournal(): void
    {
        $loan = $this->items->create($this->supplierId, [
            'side' => 'payable', 'kind' => 'loan', 'title' => 'Úvěr od společníka',
            'issued_on' => self::YEAR . '-01-10', 'due_on' => self::YEAR . '-09-30', 'amount' => 100000,
        ], $this->userId);
        $id = (int) $loan['id'];
        $this->container->get(OtherItemScheduleService::class)->setInstallments($this->supplierId, $id, [
            ['due_on' => self::YEAR . '-03-31', 'amount' => 50000],
            ['due_on' => self::YEAR . '-09-30', 'amount' => 50000],
        ]);
        $posted = $this->items->post($this->supplierId, $id, $this->userId);
        self::assertSame('confirmed', $posted['status']);
        self::assertNull($posted['journal_entry_id'], 'Daňová evidence nemá deník.');
        self::assertNotSame('', (string) $posted['document_no']);

        self::assertSame([2, 100000.0], $this->payables());

        $statement = $this->statement($this->supplierId, $this->accountA);
        $this->db->pdo()->prepare('UPDATE bank_statements SET supplier_id = ? WHERE id = ?')->execute([$this->supplierId, $statement]);
        $tx = $this->bankTx($statement, -50000.0, ['posted_at' => self::YEAR . '-03-31', 'description' => 'Splátka úvěru']);
        $this->items->allocate($this->supplierId, $id, ['bank_transaction_id' => $tx, 'amount' => 50000], $this->userId);

        self::assertSame([1, 50000.0], $this->payables(), 'Uhrazená splátka z knihy zmizí, druhá zůstane.');

        $journal = $this->fullYear($this->supplierId, false);
        $row = $this->row($journal, 'bank', $tx);
        self::assertSame('expense_nontax', $row['bucket'], 'Splátka jistiny úvěru není daňovým výdajem.');
        self::assertFalse($row['unclassified']);
        self::assertSame(0.0, (float) ($journal['totals']['vydaj_danovy'] ?? 0));
    }

    public function testDepositReceivedInCashIsNotIncome(): void
    {
        $deposit = $this->items->create($this->supplierId, [
            'side' => 'payable', 'kind' => 'deposit', 'title' => 'Přijatá kauce',
            'issued_on' => self::YEAR . '-02-01', 'due_on' => self::YEAR . '-12-31', 'amount' => 20000,
        ], $this->userId);
        $this->items->post($this->supplierId, (int) $deposit['id'], $this->userId);
        // Kauce se vrací (závazek), peníze ale přišly: pokladní příjem s účelem „jiné".
        $refund = $this->items->create($this->supplierId, [
            'side' => 'receivable', 'kind' => 'deposit', 'title' => 'Složená kauce',
            'issued_on' => self::YEAR . '-02-01', 'due_on' => self::YEAR . '-12-31', 'amount' => 15000,
        ], $this->userId);
        $this->items->post($this->supplierId, (int) $refund['id'], $this->userId);
        $cash = $this->cashDoc('in', 'other', 15000.0, [], null, self::YEAR . '-05-05');
        $this->items->allocate($this->supplierId, (int) $refund['id'], ['cash_document_id' => $cash, 'amount' => 15000], $this->userId);

        $journal = $this->fullYear($this->supplierId, false);
        self::assertSame('income_nontax', $this->row($journal, 'cash', $cash)['bucket']);
        self::assertSame([1, 20000.0], $this->payables());
    }

    /** Splátka s úrokem: přiřazená jen jistina, úrok může být daňovým výdajem → rozhoduje uživatel. */
    public function testPartlyAllocatedLoanRepaymentStaysUnclassified(): void
    {
        $loan = $this->loan('payable', 30000);
        $tx = $this->bankTx($this->ownStatement(), -31000.0, ['posted_at' => self::YEAR . '-06-30']);
        $this->items->allocate($this->supplierId, $loan, ['bank_transaction_id' => $tx, 'amount' => 30000], $this->userId);

        $row = $this->row($this->fullYear($this->supplierId, false), 'bank', $tx);
        self::assertTrue($row['unclassified']);
    }

    /**
     * Druh se jmenuje „Poskytnutá půjčka nebo splátka" a splátka prodeje je daňovým příjmem:
     * příchozí platba se tiše mezi nedaňové zařadit nesmí, odchozí poskytnutí půjčky ano.
     */
    public function testIncomingRepaymentOfProvidedLoanNeedsUserDecision(): void
    {
        $loan = $this->loan('receivable', 30000);
        $in = $this->bankTx($this->ownStatement(), 30000.0, ['posted_at' => self::YEAR . '-06-30']);
        $this->items->allocate($this->supplierId, $loan, ['bank_transaction_id' => $in, 'amount' => 30000], $this->userId);

        $row = $this->row($this->fullYear($this->supplierId, false), 'bank', $in);
        self::assertTrue($row['unclassified']);
        self::assertTrue($row['blocking']);
    }

    private function loan(string $side, float $amount): int
    {
        $item = $this->items->create($this->supplierId, [
            'side' => $side, 'kind' => 'loan', 'title' => $side === 'payable' ? 'Přijatá půjčka' : 'Poskytnutá půjčka',
            'issued_on' => self::YEAR . '-01-10', 'due_on' => self::YEAR . '-06-30', 'amount' => $amount,
        ], $this->userId);
        $this->items->post($this->supplierId, (int) $item['id'], $this->userId);
        return (int) $item['id'];
    }

    private function ownStatement(): int
    {
        $statement = $this->statement($this->supplierId, $this->accountA);
        $this->db->pdo()->prepare('UPDATE bank_statements SET supplier_id = ? WHERE id = ?')->execute([$this->supplierId, $statement]);
        return $statement;
    }

    public function testReturnedDepositOnBankIsNotIncome(): void
    {
        $deposit = $this->items->create($this->supplierId, [
            'side' => 'receivable', 'kind' => 'deposit', 'title' => 'Složená kauce za nájem',
            'issued_on' => self::YEAR . '-02-01', 'due_on' => self::YEAR . '-12-31', 'amount' => 25000,
        ], $this->userId);
        $this->items->post($this->supplierId, (int) $deposit['id'], $this->userId);
        $tx = $this->bankTx($this->ownStatement(), 25000.0, ['posted_at' => self::YEAR . '-11-30']);
        $this->items->allocate($this->supplierId, (int) $deposit['id'], ['bank_transaction_id' => $tx, 'amount' => 25000], $this->userId);

        $journal = $this->fullYear($this->supplierId, false);
        $row = $this->row($journal, 'bank', $tx);
        self::assertSame('income_nontax', $row['bucket']);
        self::assertFalse($row['blocking']);
        self::assertSame(0.0, (float) ($journal['totals']['prijem_danovy'] ?? 0));
    }

    public function testRentReceivedStaysForUserDecision(): void
    {
        $rent = $this->items->create($this->supplierId, [
            'side' => 'receivable', 'kind' => 'rent', 'title' => 'Nájem skladu',
            'issued_on' => self::YEAR . '-04-01', 'due_on' => self::YEAR . '-04-15', 'amount' => 12000,
        ], $this->userId);
        $this->items->post($this->supplierId, (int) $rent['id'], $this->userId);
        $statement = $this->statement($this->supplierId, $this->accountA);
        $this->db->pdo()->prepare('UPDATE bank_statements SET supplier_id = ? WHERE id = ?')->execute([$this->supplierId, $statement]);
        $tx = $this->bankTx($statement, 12000.0, ['posted_at' => self::YEAR . '-04-14']);
        $this->items->allocate($this->supplierId, (int) $rent['id'], ['bank_transaction_id' => $tx, 'amount' => 12000], $this->userId);

        $row = $this->row($this->fullYear($this->supplierId, false), 'bank', $tx);
        self::assertTrue($row['unclassified'], 'Nájem může být příjmem § 7 i § 9; rozhoduje uživatel.');
        self::assertSame([], $this->receivablesPayables()['receivables']);
    }

    /** @return array{0:int,1:float} počet a součet otevřených závazků v CZK */
    private function payables(): array
    {
        $count = 0;
        $total = 0.0;
        foreach ($this->receivablesPayables()['payables'] as $bucket) {
            if ($bucket['currency'] === 'CZK') {
                $count += $bucket['count'];
                $total = round($total + $bucket['total'], 2);
            }
        }
        return [$count, $total];
    }

    /** @return array<string,mixed> */
    private function receivablesPayables(): array
    {
        return $this->container->get(ReceivablesPayablesService::class)->build($this->supplierId);
    }

    /**
     * @param array<string,mixed> $journal
     * @return array<string,mixed>
     */
    private function row(array $journal, string $sourceType, int $sourceId): array
    {
        foreach ($journal['rows'] as $row) {
            if ($row['source_type'] === $sourceType && (int) $row['source_id'] === $sourceId) {
                return $row;
            }
        }
        self::fail("Pohyb {$sourceType} #{$sourceId} v peněžním deníku chybí.");
    }
}
