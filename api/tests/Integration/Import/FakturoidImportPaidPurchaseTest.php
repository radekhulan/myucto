<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Service\Bank\BankTransactionReleaseService;
use MyInvoice\Service\Bank\PurchasePaymentMatchWriter;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PHPUnit\Framework\Attributes\Group;

/**
 * Výdaj, který Fakturoid vede jako zaplacený, se převezme jako uhrazený jen s krytím
 * v evidenci úhrad (`settled_outside_amount`). Bez něj byl doklad uhrazený bez jediné
 * úhrady a párování banky i oprava stavu úhrad ho braly jako neuhrazený.
 */
#[Group('integration')]
final class FakturoidImportPaidPurchaseTest extends FakturoidImportTestCase
{
    public function testPaidExpenseIsCoveredBySettlementRecord(): void
    {
        $this->useVatPayer(true);
        $this->api['subjects.json'] = [$this->subject(760002, 'supplier')];
        $line = [['name' => 'Služba', 'quantity' => 1, 'unit_price' => '1000.0', 'vat_rate' => 21]];
        $mode = ['vat_price_mode' => 'without_vat', 'total' => '1210.0'];
        $this->api['expenses.json'] = [
            $this->document(781001, 760002, $line, $mode + ['status' => 'paid', 'paid_on' => '2094-03-20']),
            $this->document(781002, 760002, $line, $mode + ['status' => 'open']),
        ];

        $job = $this->runImport();
        self::assertSame('completed', $job['status'], (string) $job['log_text']);

        $paid = $this->state(781001);
        self::assertSame('paid', $paid['status']);
        self::assertSame('2094-03-20', substr((string) $paid['paid_at'], 0, 10));
        self::assertSame('1210.00', $paid['settled_outside_amount'], json_encode($paid));
        self::assertEqualsWithDelta((float) $paid['amount_to_pay'], (float) $paid['settled'], 0.001);

        $open = $this->state(781002);
        self::assertNotSame('paid', $open['status']);
        self::assertSame('0.00', $open['settled_outside_amount']);
    }

    /** Skutečná úhrada z banky krytí z importu nahradí, doklad není uhrazený dvakrát. */
    public function testMatchedBankPaymentReplacesImportCoverage(): void
    {
        $this->useVatPayer(true);
        $this->api['subjects.json'] = [$this->subject(760002, 'supplier')];
        $line = [['name' => 'Služba', 'quantity' => 1, 'unit_price' => '1000.0', 'vat_rate' => 21]];
        $this->api['expenses.json'] = [$this->document(781003, 760002, $line, ['vat_price_mode' => 'without_vat', 'total' => '1210.0', 'status' => 'paid', 'paid_on' => '2094-03-20'])];
        $job = $this->runImport();
        self::assertSame('completed', $job['status'], (string) $job['log_text']);
        $id = (int) $this->db->pdo()->query("SELECT id FROM purchase_invoices WHERE supplier_id = {$this->sid} AND fakturoid_id = 781003")->fetchColumn();
        self::assertSame('1210.00', $this->state(781003)['settled_outside_amount']);

        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, currency, statement_number,
                statement_date, prev_balance, curr_balance, credit_total, debit_total, transaction_count)
            VALUES (?, 'import', 'paid.xml', ?, '1000000005', '0100', 'CZK', '1', '2094-03-20', 0, -1210, 0, 1210, 1)")
            ->execute([$this->sid, hash('sha256', 'paid-purchase-' . $this->sid)]);
        $statement = (int) $pdo->lastInsertId();
        // Pohyb o den později než úhrada podle Fakturoidu (zrušené párování pak stav nevrací).
        $pdo->prepare("INSERT INTO bank_transactions (source, statement_id, posted_at, amount, currency, import_fingerprint)
            VALUES ('statement', ?, '2094-03-21', -1210, 'CZK', ?)")->execute([$statement, hash('sha256', 'paid-purchase-tx-' . $this->sid)]);
        $tx = (int) $pdo->lastInsertId();

        PurchasePaymentMatchWriter::record($pdo, $this->sid, $tx, $id, 1210.0, 'manual');

        $state = $this->state(781003);
        self::assertSame('0.00', $state['settled_outside_amount']);
        self::assertEqualsWithDelta(1210.0, (float) $state['settled'], 0.001, 'Doklad je uhrazený jednou, bankou.');

        // Zrušené párování krytí ze zdroje vrátí: doklad zůstává uhrazený podle Fakturoidu
        // a nesmí zůstat uhrazený bez krytí.
        $this->container->get(BankTransactionReleaseService::class)->release($this->sid, $tx, BankTransactionReleaseService::MODE_UNMATCH);
        $state = $this->state(781003);
        self::assertSame('paid', $state['status']);
        self::assertSame('1210.00', $state['settled_outside_amount']);
        self::assertEqualsWithDelta(1210.0, (float) $state['settled'], 0.001);
    }

    /** @return array<string,mixed> */
    private function state(int $fakturoidId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pi.status, pi.paid_at, pi.amount_to_pay, pi.settled_outside_amount, (' . PurchaseSettledExpr::settled('pi') . ') AS settled
               FROM purchase_invoices pi WHERE pi.supplier_id = ? AND pi.fakturoid_id = ?'
        );
        $stmt->execute([$this->sid, $fakturoidId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Přijatý doklad Fakturoid #{$fakturoidId} nevznikl.");
        return $row;
    }
}
