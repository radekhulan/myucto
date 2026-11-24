<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Co má MyÚčto uložené jako doklad o podání (doručenka, potvrzení EPO) a proč nic,
 * když nic. Jedno místo pro seznam podání i pro PDF podání, aby UI i tisk říkaly
 * totéž.
 *
 * Vstupem je snapshot obohacený přes {@see \MyInvoice\Repository\TaxSubmissionEpoRepository::enrich()}
 * (`artifacts`, `isds_dispatch`).
 */
final class TaxSubmissionReceiptEvidence
{
    /** Druhy artefaktů, které dokládají doručení podání finanční správě. */
    public const EVIDENCE_KINDS = [
        'confirmation_p7s'  => 'Potvrzení o podání z EPO (podepsaná dodejka)',
        'confirmation_xml'  => 'Potvrzení o podání z EPO (čitelný přepis)',
        'receipt_pdf'       => 'Potvrzení o podání z portálu EPO (PDF)',
        'isds_delivery_zfo' => 'Doručenka datové zprávy (ZFO)',
    ];

    public const REASON_LABELS = [
        'not_submitted'        => 'Podání ještě není označené jako podané.',
        'isds_receipt_pending' => 'Datová zpráva odešla, doručenka ze schránky ale zatím není přiložená.',
        'submitted_outside'    => 'Podání bylo označené jako podané ručně, bez doručenky. Typicky se podávalo '
            . 'mimo MyÚčto, například přímo v portálu EPO. Potvrzení z EPO lze doplnit přes „Nahrát výstupy z EPO".',
    ];

    /**
     * @param array<string,mixed> $row
     * @return array{available:bool, reason:?string, reason_label:?string, reference:?string,
     *               submitted_at:?string, items:list<array{artifact_id:int, kind:string, label:string, name:string}>}
     */
    public static function summarize(array $row): array
    {
        $items = [];
        $receipt = null;
        foreach ((array) ($row['artifacts'] ?? []) as $artifact) {
            $kind = (string) ($artifact['artifact_kind'] ?? '');
            if (!isset(self::EVIDENCE_KINDS[$kind])) {
                continue;
            }
            $items[] = [
                'artifact_id' => (int) ($artifact['id'] ?? 0),
                'kind'        => $kind,
                'label'       => self::EVIDENCE_KINDS[$kind],
                'name'        => (string) ($artifact['original_name'] ?? ''),
            ];
            $summary = $artifact['verification']['receipt'] ?? null;
            if ($receipt === null && is_array($summary) && $summary !== []
                && in_array($kind, ['confirmation_xml', 'confirmation_p7s'], true)) {
                $receipt = $summary;
            }
        }

        $dispatch = is_array($row['isds_dispatch'] ?? null) ? $row['isds_dispatch'] : null;
        $submitted = in_array((string) ($row['status'] ?? ''), ['submitted', 'accepted'], true);

        $reason = null;
        if ($items === []) {
            $reason = match (true) {
                !$submitted => 'not_submitted',
                $dispatch !== null && in_array((string) ($dispatch['dispatch_state'] ?? ''), ['sent', 'delivered'], true)
                    => 'isds_receipt_pending',
                default => 'submitted_outside',
            };
        }

        $reference = self::text($receipt['reference'] ?? null)
            ?? self::text($row['submission_ref'] ?? null)
            ?? self::text($dispatch['external_message_id'] ?? null);

        return [
            'available'    => $items !== [],
            'reason'       => $reason,
            'reason_label' => $reason !== null ? self::REASON_LABELS[$reason] : null,
            'reference'    => $reference,
            'submitted_at' => self::text($receipt['submitted_at'] ?? null) ?? self::text($row['submitted_at'] ?? null),
            'items'        => $items,
        ];
    }

    private static function text(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        return $value !== '' ? $value : null;
    }
}
