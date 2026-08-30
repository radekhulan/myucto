<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class PayrollStatutoryAgendaCatalog
{
    public const VERSION = 'mz24-p0.v2';
    public const CAPABILITIES = [
        'manual_review',
        'prepared_only',
        'not_supported',
    ];

    /**
     * @return array{
     *   version:string,sha256:string,period:string,
     *   agendas:list<array<string,mixed>>
     * }
     */
    public function forPeriod(string $period): array
    {
        $start = self::periodStart($period);
        $fromJmhz = $start >= new \DateTimeImmutable('2026-04-01');
        $legacyNempri = $start < new \DateTimeImmutable('2025-01-01');
        $historicEldp = $start < new \DateTimeImmutable('2026-01-01');

        $agendas = [
            [
                'agenda_code' => 'NEMPRI',
                'replacement_mode' => $fromJmhz
                    ? 'partially_replaced'
                    : 'standalone',
                // Datovou větu NEMPRI25 modul sestaví a zvaliduje proti
                // připnutému XSD; odeslat ji umí datovou schránkou. Kanál
                // VREP/APEP zůstává zavřený — identifikátor třídy podání pro
                // tuhle agendu není v připnutém Podávacím a dotazovacím
                // protokolu v1.47 uvedený.
                'capability' => $legacyNempri
                    ? 'not_supported'
                    : 'prepared_only',
                'transport_capability' => $legacyNempri
                    ? 'not_supported'
                    : 'isds',
                'evidence_supported' => !$legacyNempri,
                'reason_code' => $legacyNempri
                    ? 'nempri_legacy_variant_not_supported'
                    : ($fromJmhz
                        ? 'nempri_only_partially_in_jmhz'
                        : 'nempri_standalone_prepared'),
                'workflow_codes' => $legacyNempri ? [] : [
                    'record_sickness_case',
                    'prepare_nempri_submission',
                    'send_via_data_box',
                    'record_receipt_evidence',
                ],
            ],
            [
                'agenda_code' => 'HZUPN',
                'replacement_mode' => 'standalone',
                'capability' => 'prepared_only',
                'transport_capability' => 'isds',
                'evidence_supported' => true,
                'reason_code' => 'hzupn_remains_standalone',
                'workflow_codes' => [
                    'record_sickness_case',
                    'prepare_hzupn_submission',
                    'send_via_data_box',
                    'record_receipt_evidence',
                ],
            ],
            [
                'agenda_code' => 'ELDP',
                'replacement_mode' => $historicEldp
                    ? 'standalone'
                    : 'partially_replaced',
                'capability' => 'prepared_only',
                'transport_capability' => 'not_supported',
                'evidence_supported' => false,
                'reason_code' => $historicEldp
                    ? 'eldp_historic_preparation_available'
                    : 'eldp_jmhz_default_on_demand_exception',
                'workflow_codes' => ['use_eldp_workspace'],
            ],
            [
                'agenda_code' => 'STATUTORY_ACCIDENT_INSURANCE',
                'replacement_mode' => 'standalone',
                'capability' => 'manual_review',
                'transport_capability' => 'not_supported',
                'evidence_supported' => true,
                'reason_code' => 'accident_insurance_calculation_output_liability_not_supported',
                'workflow_codes' => [
                    'calculate_accident_insurance_externally',
                    'pay_accident_insurance_externally',
                    'store_payment_evidence_in_company_dms',
                    'record_payment_evidence',
                ],
            ],
        ];
        $fingerprint = [
            'version' => self::VERSION,
            'period' => $period,
            'agendas' => $agendas,
        ];

        return [
            ...$fingerprint,
            'sha256' => hash('sha256', CanonicalJson::encode($fingerprint)),
        ];
    }

    private static function periodStart(string $period): \DateTimeImmutable
    {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new \InvalidArgumentException('Období musí mít formát RRRR-MM.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
        if (!$date instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('Období není platné.');
        }

        return $date;
    }
}
