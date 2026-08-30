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
     * Události, které do evidence zapisuje UŽIVATEL aplikace o tom, co udělal
     * mimo systém. `recorded_by` u nich vždy je.
     */
    public const ACTOR_EVENTS = ['handover', 'downloaded', 'external_notification'];

    /**
     * Události zabezpečeného odkazu. Aktérem je fronta nebo sám zaměstnanec,
     * který uživatelem aplikace není — `recorded_by` proto zůstává NULL a je to
     * záměr, ne chybějící údaj. Zapisuje je výhradně
     * {@see \MyInvoice\Service\Payroll\Document\Delivery\PayrollSecureDeliveryService}.
     */
    public const CHANNEL_EVENTS = [
        'secure_link_sent',
        'secure_link_failed',
        'secure_link_revoked',
        'self_downloaded',
    ];

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
        if (!in_array($eventType, self::ACTOR_EVENTS, true)) {
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

    /**
     * Zápis události zabezpečeného kanálu. Bez aktéra-uživatele.
     *
     * Vědomě NEPŘIJÍMÁ typy z {@see self::ACTOR_EVENTS}: „účetní si pásku stáhla"
     * a „zaměstnanec si ji převzal" musí v evidenci zůstat rozlišitelné, jinak
     * ztrácí smysl. Proto je i `self_downloaded` samostatný typ, ne `downloaded`.
     *
     * @param 'secure_link_sent'|'secure_link_failed'|'secure_link_revoked'|'self_downloaded' $eventType
     * @return array<string,mixed>
     */
    public function recordChannelEvent(
        int $supplierId,
        int $documentId,
        string $eventType,
    ): array {
        if (!in_array($eventType, self::CHANNEL_EVENTS, true)) {
            throw new \InvalidArgumentException('Typ události zabezpečeného kanálu není platný.');
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
            null,
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
