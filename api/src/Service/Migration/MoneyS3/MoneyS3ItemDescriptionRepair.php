<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\MoneyS3ImportRepository;
use PDO;

final class MoneyS3ItemDescriptionRepair
{
    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
        private readonly JournalImporter $journal,
    ) {}

    /**
     * @return \Generator<int,array{kind:string,year:int,document_no:string,document_id:int,old:string,items:list<array{id:int,rate:string,new:string}>}>
     */
    public function run(Ms3Backup $backup, int $supplierId, bool $commit = false): \Generator
    {
        $years = $this->yearsByDirectory($backup, $supplierId);
        foreach ([MoneyS3ImportRepository::KIND_INVOICE, MoneyS3ImportRepository::KIND_PURCHASE_INVOICE] as $kind) {
            $issued = $kind === MoneyS3ImportRepository::KIND_INVOICE;
            $existing = $this->map->all($supplierId, $kind);
            if ($existing === []) {
                continue;
            }
            $batch = [];
            foreach (InvoiceImporter::sourceInvoices($backup, $issued, $years, ['Cislo', 'Doklad', 'Popis']) as [$row, $items]) {
                $year = $years[$row['__dir']];
                $docNo = trim((string) ($row['Doklad'] ?? ''));
                $old = trim((string) ($row['Popis'] ?? ''));
                $id = $existing[InvoiceImporter::documentKey($year, $docNo)] ?? null;
                if ($id === null || $docNo === '' || !$old || $items === []) {
                    continue;
                }
                $descriptions = array_filter(InvoiceImporter::lineDescriptions($items), static fn (string $description): bool => $description !== $old);
                if ($descriptions === []) {
                    continue;
                }
                $batch[$id] = ['kind' => $kind, 'year' => $year, 'document_no' => $docNo, 'document_id' => $id, 'old' => $old, 'descriptions' => $descriptions];
                if (count($batch) >= 200) {
                    yield from $this->repairBatch($supplierId, $issued, $batch, $commit);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                yield from $this->repairBatch($supplierId, $issued, $batch, $commit);
            }
        }
    }

    /** @return array<string,int> */
    private function yearsByDirectory(Ms3Backup $backup, int $supplierId): array
    {
        $agenda = AgendaInfo::fromBackup($backup);
        if (!$agenda->taxRecordsOnly()) {
            return array_column($this->journal->plan($backup, new ImportOptions(), $this->journal->companyCalendar($supplierId)), 'year', 'dir');
        }
        $years = [];
        foreach ($agenda->years as $year) {
            if ($year['accounting_mode'] === 'tax_evidence' && $year['money_year'] !== null) {
                $years[$year['dir']] = $year['money_year'];
            }
        }
        return $years;
    }

    private function repairBatch(int $supplierId, bool $issued, array $batch, bool $commit): \Generator
    {
        $documents = $issued ? 'invoices' : 'purchase_invoices';
        $items = $issued ? 'invoice_items' : 'purchase_invoice_items';
        $fk = $issued ? 'invoice_id' : 'purchase_invoice_id';
        $pdo = $this->db->pdo();
        $placeholders = implode(', ', array_fill(0, count($batch), '?'));
        $select = $pdo->prepare(
            "SELECT d.id AS document_id, i.id, i.description, i.vat_rate_snapshot
               FROM {$documents} d JOIN {$items} i ON i.{$fk} = d.id
              WHERE d.supplier_id = ? AND d.id IN ({$placeholders})
              ORDER BY d.id, i.order_index, i.id"
        );
        $select->execute([$supplierId, ...array_keys($batch)]);
        $changes = [];
        while (($item = $select->fetch(PDO::FETCH_ASSOC)) !== false) {
            $id = (int) $item['document_id'];
            $source = $batch[$id];
            $rate = (string) $item['vat_rate_snapshot'];
            $new = $source['descriptions'][InvoiceImporter::rateKey((float) $rate)] ?? null;
            if ($item['description'] === $source['old'] && $new !== null) {
                $changes[$id][] = ['id' => (int) $item['id'], 'rate' => $rate, 'new' => $new];
            }
        }
        $select->closeCursor();

        foreach ($changes as $id => $rows) {
            $document = $batch[$id];
            if ($commit) {
                $this->writeDocument($pdo, $documents, $items, $fk, $supplierId, $document, $rows);
            }
            unset($document['descriptions']);
            yield $document + ['items' => $rows];
        }
    }

    private function writeDocument(PDO $pdo, string $documents, string $items, string $fk, int $supplierId, array $document, array $rows): void
    {
        $cases = [];
        $pairs = [];
        foreach ($rows as $row) {
            array_push($cases, $row['id'], $row['new']);
            array_push($pairs, $row['id'], $row['rate']);
        }
        $when = str_repeat(' WHEN ? THEN ?', count($rows));
        $matched = implode(', ', array_fill(0, count($rows), '(?, ?)'));
        $update = $pdo->prepare(
            "UPDATE {$documents} d JOIN {$items} i ON i.{$fk} = d.id
                SET i.description = CASE i.id{$when} ELSE i.description END
              WHERE d.supplier_id = ? AND d.id = ? AND BINARY i.description = BINARY ?
                AND (i.id, i.vat_rate_snapshot) IN ({$matched})"
        );
        $own = !$pdo->inTransaction();
        $own ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT money_s3_item_description_repair');
        try {
            $update->execute([...$cases, $supplierId, $document['document_id'], $document['old'], ...$pairs]);
            if ($update->rowCount() !== count($rows)) {
                throw new MoneyS3Exception('document_changed', sprintf(
                    'Doklad %s (%d, ID %d) se během opravy změnil. Spusťte opravu znovu.',
                    $document['document_no'], $document['year'], $document['document_id'],
                ));
            }
            $own ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT money_s3_item_description_repair');
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT money_s3_item_description_repair');
                $pdo->exec('RELEASE SAVEPOINT money_s3_item_description_repair');
            }
            throw $e;
        }
    }
}
