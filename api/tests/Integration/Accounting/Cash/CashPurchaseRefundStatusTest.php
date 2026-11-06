<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Cash;

use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** Storno a smazání pokladní vratky k přijaté faktuře: stejné pravidlo jako odpárování bankovní vratky. */
#[Group('integration')]
final class CashPurchaseRefundStatusTest extends BankPostingTestCase
{
    public static function refundRemovals(): iterable
    {
        yield 'storno' => ['reverse'];
        yield 'smazání' => ['delete'];
    }

    #[DataProvider('refundRemovals')]
    public function testRefundRemovalRestoresPaidStatus(string $removal): void
    {
        [$cash, $invoice, $refund] = $this->paidAndRefunded('SYN-CASH-REFUND');
        self::assertNotSame('paid', $this->state($invoice)[0]);

        if ($removal === 'reverse') {
            $cash->reverse($this->supplierId, $refund, ['reason' => 'Chybná vratka'], $this->userId);
        } else {
            $cash->deleteDocument($this->supplierId, $refund);
        }

        self::assertSame(['paid', self::YEAR . '-06-12'], $this->state($invoice));
    }

    /** Faktura ručně uhrazená po vratce: storno vratky stav ani ruční datum nemění. */
    public function testRefundReversalKeepsManuallyPaidInvoice(): void
    {
        [$cash, $invoice, $refund] = $this->paidAndRefunded('SYN-CASH-REFUND-PAID');
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?")
            ->execute([self::YEAR . '-06-20', $invoice]);

        $cash->reverse($this->supplierId, $refund, ['reason' => 'Chybná vratka'], $this->userId);

        self::assertSame(['paid', self::YEAR . '-06-20'], $this->state($invoice));
    }

    /** Výdej 121 Kč 12. 6. a vratka 21 Kč 15. 6. @return array{0:CashDocumentService, 1:int, 2:int} */
    private function paidAndRefunded(string $number): array
    {
        $register = $this->container->get(CashRegisterService::class)->create(
            $this->supplierId,
            ['name' => 'Pokladna ' . $number, 'account_code' => '211', 'is_default' => false],
        );
        $invoice = $this->purchaseInvoice($number, $this->client('Synthetic cash refund vendor'), 121.0);
        $cash = $this->container->get(CashDocumentService::class);
        $cash->create($this->supplierId, [
            'register_id' => $register, 'issue_date' => self::YEAR . '-06-12', 'description' => 'Úhrada',
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 121.0,
            'purchase_invoice_id' => $invoice, 'post' => true,
        ], $this->userId);
        $refund = $cash->create($this->supplierId, [
            'register_id' => $register, 'issue_date' => self::YEAR . '-06-15', 'description' => 'Vratka',
            'purpose' => 'purchase_payment', 'doc_type' => 'in', 'total_amount' => 21.0,
            'purchase_invoice_id' => $invoice, 'post' => true,
        ], $this->userId);
        return [$cash, $invoice, (int) $refund['id']];
    }

    /** @return array{0:string, 1:?string} */
    private function state(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, paid_at FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $this->supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return [(string) $row['status'], $row['paid_at'] !== null ? substr((string) $row['paid_at'], 0, 10) : null];
    }
}
