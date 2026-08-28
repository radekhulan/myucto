<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Repository\Payroll\PayrollDocumentRepository;

/**
 * Neměnný důkaz o předání konkrétního osobního mzdového dokumentu.
 *
 * Úmyslně sem nepatří obsah dokumentu, příjemcův kontakt ani download grant.
 */
final class PayrollDocumentDeliveryLedgerService
{
    public function __construct(private readonly PayrollDocumentRepository $documents) {}

    /**
     * @param 'handover'|'downloaded'|'external_notification' $eventType
     * @return array<string,mixed>
     */
    public function record(
        int $supplierId,
        int $documentId,
        int $actorUserId,
        string $eventType,
    ): array {
        if (!in_array($eventType, ['handover', 'downloaded', 'external_notification'], true)) {
            throw new \InvalidArgumentException('Typ doručovací události není platný.');
        }
        $document = $this->documents->find($supplierId, $documentId);
        if ($document === null) {
            throw new \DomainException('Mzdový dokument pro osobní doručení nebyl nalezen.');
        }
        $employeeId = $document['employee_id'];
        if (!is_int($employeeId) || $employeeId <= 0) {
            throw new \DomainException('Mzdový dokument pro osobní doručení nebyl nalezen.');
        }

        return $this->documents->appendDeliveryEvent(
            $supplierId,
            $documentId,
            $employeeId,
            $eventType,
            $actorUserId,
        );
    }

    /** @return array<string,mixed>|null */
    public function recordViewedIfPersonalDocument(
        int $supplierId,
        int $documentId,
        int $actorUserId,
    ): ?array {
        $document = $this->documents->find($supplierId, $documentId);
        if ($document === null) {
            throw new \DomainException('Mzdový dokument nebyl nalezen.');
        }
        $employeeId = $document['employee_id'];
        if (!is_int($employeeId) || $employeeId <= 0) {
            return null;
        }

        return $this->documents->appendDeliveryEvent(
            $supplierId,
            $documentId,
            $employeeId,
            'downloaded',
            $actorUserId,
        );
    }

    /** @return list<array<string,mixed>> */
    public function forDocument(int $supplierId, int $documentId): array
    {
        $document = $this->documents->find($supplierId, $documentId);
        if ($document === null || !is_int($document['employee_id'] ?? null)) {
            throw new \DomainException('Mzdový dokument pro osobní doručení nebyl nalezen.');
        }
        return $this->documents->deliveryEventsForDocument($supplierId, $documentId);
    }

    /**
     * @param list<int> $documentIds
     * @return array<int,array{handed_over_at:?string,downloaded_at:?string,external_notification_at:?string}>
     */
    public function summaries(int $supplierId, array $documentIds): array
    {
        return $this->documents->deliverySummaries($supplierId, $documentIds);
    }
}
