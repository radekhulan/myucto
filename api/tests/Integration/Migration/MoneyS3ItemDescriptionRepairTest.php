<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3ItemDescriptionRepair;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;

final class MoneyS3ItemDescriptionRepairTest extends MoneyS3ImportTestCase
{
    public function testRepairsOriginalDescriptionsAndPreservesManualEdits(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithInvoiceItemDescriptions());
        $backup = $this->backup();
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $issuedId = $this->map->get($supplierId, MoneyS3ImportRepository::KIND_INVOICE, '2025|FV25001');
        $purchaseId = $this->map->get($supplierId, MoneyS3ImportRepository::KIND_PURCHASE_INVOICE, '2025|FP24001');
        $previousYearId = $this->map->get($supplierId, MoneyS3ImportRepository::KIND_PURCHASE_INVOICE, '2024|FP24001');
        self::assertNotNull($issuedId);
        self::assertNotNull($purchaseId);
        self::assertNotNull($previousYearId);
        $issued = $this->items($supplierId, $issuedId, true);
        $purchase = $this->items($supplierId, $purchaseId, false);
        $previousYear = $this->items($supplierId, $previousYearId, false);
        self::assertSame(['Pronájem', 'Dohled; Servis'], array_column($issued, 'description'));
        self::assertSame(['3', null], array_column($issued, 'vat_classification_code'));
        self::assertSame([400.0, 1000.0], array_map('floatval', array_column($issued, 'total_without_vat')));
        self::assertSame([0.0, 210.0], array_map('floatval', array_column($issued, 'total_vat')));
        self::assertSame(['Revize zařízení'], array_column($purchase, 'description'));
        self::assertSame(['Účetní služby'], array_column($previousYear, 'description'));

        $this->setDescription($supplierId, (int) $issued[0]['id'], true, 'Poradenství');
        $this->setDescription($supplierId, (int) $issued[1]['id'], true, 'poradenství');
        $this->setDescription($supplierId, (int) $purchase[0]['id'], false, 'Drobné služby');

        $backup = Ms3Backup::open($backup->dir());
        $repair = $this->container(MoneyS3ItemDescriptionRepair::class);
        $otherSupplier = $this->supplier();
        $this->map->put($otherSupplier, MoneyS3ImportRepository::KIND_INVOICE, '2025|FV25001', $issuedId, null);
        self::assertSame([], iterator_to_array($repair->run($backup, $otherSupplier), false));

        $preview = iterator_to_array($repair->run($backup, $supplierId), false);
        self::assertSame(['FV25001', 'FP24001'], array_column($preview, 'document_no'));
        self::assertSame(['Poradenství', 'Drobné služby'], array_column($preview, 'old'));
        self::assertSame([['Pronájem'], ['Revize zařízení']], array_map(static fn (array $doc): array => array_column($doc['items'], 'new'), $preview));
        self::assertSame(['Poradenství', 'poradenství'], array_column($this->items($supplierId, $issuedId, true), 'description'));
        self::assertSame(['Drobné služby'], array_column($this->items($supplierId, $purchaseId, false), 'description'));

        self::assertSame($preview, iterator_to_array($repair->run($backup, $supplierId, true), false));
        $issued[1]['description'] = 'poradenství';
        self::assertSame($issued, $this->items($supplierId, $issuedId, true));
        self::assertSame($purchase, $this->items($supplierId, $purchaseId, false));
        self::assertSame($previousYear, $this->items($supplierId, $previousYearId, false));
        self::assertSame([], iterator_to_array($repair->run($backup, $supplierId, true), false));
    }

    private function items(int $supplierId, int $documentId, bool $issued): array
    {
        $documents = $issued ? 'invoices' : 'purchase_invoices';
        $items = $issued ? 'invoice_items' : 'purchase_invoice_items';
        $fk = $issued ? 'invoice_id' : 'purchase_invoice_id';
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.* FROM {$documents} d JOIN {$items} i ON i.{$fk} = d.id
              WHERE d.supplier_id = ? AND d.id = ? ORDER BY i.order_index"
        );
        $stmt->execute([$supplierId, $documentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function setDescription(int $supplierId, int $itemId, bool $issued, string $description): void
    {
        $documents = $issued ? 'invoices' : 'purchase_invoices';
        $items = $issued ? 'invoice_items' : 'purchase_invoice_items';
        $fk = $issued ? 'invoice_id' : 'purchase_invoice_id';
        $this->db->pdo()->prepare(
            "UPDATE {$documents} d JOIN {$items} i ON i.{$fk} = d.id SET i.description = ? WHERE d.supplier_id = ? AND i.id = ?"
        )->execute([$description, $supplierId, $itemId]);
    }
}
