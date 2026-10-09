<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\Reports\DocumentCompletenessService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kontrola úplnosti dokladů v daňové evidenci (§ 7b odst. 1 ZDP): bez deníku a saldokonta.
 * Pohyb doklad má, když je spárovaný s fakturou, přiřazený k ostatní položce nebo ručně
 * zařazený jako soukromý. Doklady po splatnosti jsou faktury a ostatní položky (po splátkách).
 */
#[Group('integration')]
final class DocumentCompletenessTaxEvidenceTest extends CashJournalTestCase
{
    public function testBankMovementsAndOverdueDocumentsWithoutLedger(): void
    {
        $now = new \DateTimeImmutable(self::YEAR . '-10-01');
        $statement = $this->statement($this->supplierId, $this->accountA);
        $this->db->pdo()->prepare('UPDATE bank_statements SET supplier_id = ? WHERE id = ?')->execute([$this->supplierId, $statement]);

        $missing = $this->bankTx($statement, -1000.0, ['posted_at' => self::YEAR . '-05-01', 'description' => 'Nákup bez dokladu']);
        $private = $this->bankTx($statement, 3000.0, ['posted_at' => self::YEAR . '-05-02', 'description' => 'Vklad']);
        $this->classifyOverride($this->supplierId, 'bank', $private, 'private');
        $loanPayment = $this->bankTx($statement, -50000.0, ['posted_at' => self::YEAR . '-03-31']);

        $items = $this->container->get(OtherItemService::class);
        $loan = $items->create($this->supplierId, [
            'side' => 'payable', 'kind' => 'loan', 'title' => 'Přijatá půjčka',
            'issued_on' => self::YEAR . '-01-10', 'due_on' => self::YEAR . '-09-30', 'amount' => 100000,
        ], $this->userId);
        $this->container->get(OtherItemScheduleService::class)->setInstallments($this->supplierId, (int) $loan['id'], [
            ['due_on' => self::YEAR . '-03-31', 'amount' => 50000],
            ['due_on' => self::YEAR . '-09-30', 'amount' => 50000],
        ]);
        $items->post($this->supplierId, (int) $loan['id'], $this->userId);
        $items->allocate($this->supplierId, (int) $loan['id'], ['bank_transaction_id' => $loanPayment, 'amount' => 50000], $this->userId);

        $overdueInvoice = $this->saleInvoice($this->supplierId, ['without' => 2500.0, 'issue_date' => self::YEAR . '-08-01']);
        $this->db->pdo()->prepare('UPDATE invoices SET due_date = ? WHERE id = ?')->execute([self::YEAR . '-08-15', $overdueInvoice]);
        $notDue = $this->purchaseInvoice($this->supplierId, ['without' => 900.0, 'issue_date' => self::YEAR . '-09-25']);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET due_date = ? WHERE id = ?')->execute([self::YEAR . '-10-10', $notDue]);

        $result = $this->container->get(DocumentCompletenessService::class)->build($this->supplierId, 30, 'all', $now);

        self::assertSame('tax_evidence', $result['accounting_mode']);
        $bankIds = array_column($result['bank_without_document']['items'], 'bank_transaction_id');
        self::assertSame([$missing], $bankIds, 'Soukromý vklad a splátka půjčky doklad nepotřebují.');

        $overdue = array_map(
            static fn (array $i): array => [$i['doc_type'], $i['doc_id'], $i['due_date'], $i['remaining_czk']],
            $result['documents_overdue_unpaid']['items'],
        );
        self::assertSame([
            ['invoice', $overdueInvoice, self::YEAR . '-08-15', 2500.0],
            ['other_item', (int) $loan['id'], self::YEAR . '-09-30', 50000.0],
        ], $overdue);
        self::assertSame(52500.0, $result['documents_overdue_unpaid']['summary']['total_czk']);
    }
}
