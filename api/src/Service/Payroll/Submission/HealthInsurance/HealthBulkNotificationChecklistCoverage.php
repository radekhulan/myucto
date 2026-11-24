<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Položky checklistu „Oznámení pojišťovně o nástupu / skončení", které splnilo
 * odeslané hromadné oznámení.
 *
 * Doklad položky ({@see \MyInvoice\Repository\Payroll\PayrollChecklistEvidenceSql})
 * je individuální povinnost oznámení ve stavu odesláno nebo splněno. Ta ale
 * vlastní podání nemá nikdy: událost se podává hromadným oznámením za měsíc
 * a pojišťovnu. Položka tak po odeslání HOZ zůstávala na kartě nesplněná,
 * zatímco Termíny i Měsíční přehled už o povinnosti mlčely. Pokrytí určuje
 * jediné pravidlo {@see HealthInsuranceSubmissionService::bulkNotifiedAmong()}
 * (věty odeslaného XML); v SQL ho vyjádřit nejde, protože artefakt je
 * šifrovaný, proto se položky dorovnávají tady, za dotazem.
 *
 * Výkon: individuální povinnosti všech položek se načtou JEDNÍM dotazem
 * (po indexu firmy a předmětu `employment:{id}`) a pokrytí se zjišťuje jen
 * za měsíce, ve kterých nějaká nevyřízená položka je. Bez takové položky se
 * nesahá do databáze vůbec.
 *
 * Jako doklad checklistu platí jen ostré prostředí, stejně jako u SQL dokladu.
 */
final readonly class HealthBulkNotificationChecklistCoverage
{
    /** @var array<string,string> klíč položky → druh povinnosti v `source_event_reference` */
    public const ITEM_DUTY_KINDS = [
        'health_insurance_registration' => 'employment_start',
        'health_insurance_deregistration' => 'employment_end',
    ];

    public function __construct(
        private Connection $db,
        private HealthInsuranceSubmissionService $notifications,
    ) {}

    /**
     * @param iterable<array{item_id:int,employment_id:int,item_key:string}> $items nevyřízené položky
     * @return array<int,true> klíčem je id pokryté položky
     */
    public function coveredItems(int $supplierId, iterable $items): array
    {
        $wanted = [];
        foreach ($items as $item) {
            $kind = self::ITEM_DUTY_KINDS[(string) $item['item_key']] ?? null;
            if ($kind !== null) {
                $wanted[(int) $item['employment_id']][$kind][] = (int) $item['item_id'];
            }
        }
        if ($wanted === []) {
            return [];
        }

        $subjects = array_map(
            static fn (int $employmentId): string => 'employment:' . $employmentId,
            array_keys($wanted),
        );
        $statement = $this->db->pdo()->prepare(
            'SELECT obligation.source_event_type, obligation.source_event_reference, obligation.period_start
               FROM payroll_obligations obligation
              WHERE obligation.supplier_id = ?
                AND obligation.subject_type = "employment"
                AND obligation.subject_reference IN (' . implode(',', array_fill(0, count($subjects), '?')) . ')
                AND obligation.environment = "production"
                AND obligation.source_event_type = ?
                AND obligation.status <> "cancelled"',
        );
        $statement->execute([
            $supplierId,
            ...$subjects,
            HealthInsuranceSubmissionService::SOURCE_EVENT_NOTIFICATION,
        ]);
        $obligations = $statement->fetchAll(PDO::FETCH_ASSOC);
        $notified = $this->notifications->bulkNotifiedAmong($supplierId, 'production', $obligations);

        $covered = [];
        foreach (array_keys($notified) as $reference) {
            // payroll_health_notification:{vztah}:{druh}:{den}[:outgoing]
            $parts = explode(':', (string) $reference);
            $employmentId = (int) ($parts[1] ?? 0);
            $kind = (string) ($parts[2] ?? '');
            foreach ($wanted[$employmentId][$kind] ?? [] as $itemId) {
                $covered[$itemId] = true;
            }
        }

        return $covered;
    }
}
