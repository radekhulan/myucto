<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Service\Tax\Return\TaxReturnException;

final class NonprofitDppoJ6Receipt
{
    public static function capture(array $period, array $currentSnapshot, array $assessment): array
    {
        if (($assessment['status'] ?? null) !== 'confirmed'
            || ($assessment['ruleset'] ?? null) !== NonprofitTaxRules::VERSION
            || ($assessment['period_id'] ?? null) !== ($period['id'] ?? null)
            || !is_array($assessment['snapshot'] ?? null)
            || !is_array($assessment['assessment'] ?? null)
            || !is_array($assessment['result'] ?? null)
            || ($assessment['result']['ruleset'] ?? null) !== NonprofitTaxRules::VERSION
            || !is_array($assessment['result']['rows'] ?? null)
            || !is_int($assessment['id'] ?? null)
            || !is_string($assessment['input_hash'] ?? null)
            || !is_string($assessment['request_hash'] ?? null)
            || !hash_equals($assessment['input_hash'], (string) ($currentSnapshot['input_hash'] ?? ''))
            || !hash_equals($assessment['input_hash'], (string) ($assessment['snapshot']['input_hash'] ?? ''))) {
            self::invalid();
        }
        $receipt = [
            'version' => 1,
            'ruleset' => NonprofitTaxRules::VERSION,
            'assessment_id' => $assessment['id'],
            'period' => [
                'id' => $period['id'],
                'fiscal_year' => $period['fiscal_year'],
                'starts_on' => $period['starts_on'],
                'ends_on' => $period['ends_on'],
            ],
            'input_hash' => $assessment['input_hash'],
            'request_hash' => $assessment['request_hash'],
            'snapshot_hash' => NonprofitTaxRules::hash($assessment['snapshot']),
            'assessment_hash' => NonprofitTaxRules::hash($assessment['assessment']),
            'result_hash' => NonprofitTaxRules::hash($assessment['result']),
        ];
        return $receipt + ['receipt_hash' => NonprofitTaxRules::hash($receipt)];
    }

    /**
     * Receipt stránkovaného posouzení J6 (paged_v2): váže DPPO na ověřený neměnný graf
     * (otisk grafu a zmrazeného zdroje) a na výsledek sestavený z jeho stránek.
     */
    public static function capturePaged(array $period, array $assessment, string $graphReceiptHash): array
    {
        if (($assessment['status'] ?? null) !== 'confirmed'
            || ($assessment['storage_version'] ?? null) !== 'paged_v2'
            || ($assessment['ruleset'] ?? null) !== NonprofitTaxRules::VERSION
            || ($assessment['period_id'] ?? null) !== ($period['id'] ?? null)
            || !is_int($assessment['id'] ?? null)
            || !is_array($assessment['result'] ?? null)
            || ($assessment['result']['ruleset'] ?? null) !== NonprofitTaxRules::VERSION
            || !is_array($assessment['result']['rows'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($assessment['input_hash'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($assessment['request_hash'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $graphReceiptHash) !== 1) {
            self::invalid();
        }
        $receipt = [
            'version' => 2,
            'ruleset' => NonprofitTaxRules::VERSION,
            'assessment_id' => $assessment['id'],
            'period' => [
                'id' => $period['id'],
                'fiscal_year' => $period['fiscal_year'],
                'starts_on' => $period['starts_on'],
                'ends_on' => $period['ends_on'],
            ],
            'input_hash' => $assessment['input_hash'],
            'request_hash' => $assessment['request_hash'],
            'graph_receipt_hash' => $graphReceiptHash,
            'result_hash' => NonprofitTaxRules::hash($assessment['result']),
        ];
        return $receipt + ['receipt_hash' => NonprofitTaxRules::hash($receipt)];
    }

    /**
     * Receipt zmrazeného archivu převázaný na totéž posouzení po jeho přepisu v anonymizované
     * kopii. Číslo posouzení i období zůstávají, mění se jen otisky. Stránkovaný receipt
     * (verze 2) se takto přenést nedá, jeho graf by se musel přestavět celý.
     */
    public static function rebase(array $receipt, array $assessment): array
    {
        $period = $receipt['period'] ?? null;
        if (($receipt['version'] ?? null) !== 1
            || !is_array($period)
            || ($receipt['assessment_id'] ?? null) !== ($assessment['id'] ?? null)
            || ($period['id'] ?? null) !== ($assessment['period_id'] ?? null)
            || !is_array($assessment['snapshot'] ?? null)
            || !is_array($assessment['assessment'] ?? null)
            || !is_array($assessment['result'] ?? null)
            || !is_string($assessment['input_hash'] ?? null)
            || !is_string($assessment['request_hash'] ?? null)) {
            self::invalid();
        }
        $hash = $receipt['receipt_hash'] ?? null;
        $unsigned = $receipt;
        unset($unsigned['receipt_hash']);
        if (!is_string($hash) || !hash_equals($hash, NonprofitTaxRules::hash($unsigned))) {
            self::invalid();
        }
        $rebased = [
            'version' => 1,
            'ruleset' => $receipt['ruleset'],
            'assessment_id' => $receipt['assessment_id'],
            'period' => $period,
            'input_hash' => $assessment['input_hash'],
            'request_hash' => $assessment['request_hash'],
            'snapshot_hash' => NonprofitTaxRules::hash($assessment['snapshot']),
            'assessment_hash' => NonprofitTaxRules::hash($assessment['assessment']),
            'result_hash' => NonprofitTaxRules::hash($assessment['result']),
        ];
        return $rebased + ['receipt_hash' => NonprofitTaxRules::hash($rebased)];
    }

    public static function verify(array $receipt, array $period, array $result): void
    {
        $hash = $receipt['receipt_hash'] ?? null;
        unset($receipt['receipt_hash']);
        $version = $receipt['version'] ?? null;
        $proofFields = $version === 2 ? ['graph_receipt_hash'] : ['snapshot_hash', 'assessment_hash'];
        foreach ($proofFields as $field) {
            if (!is_string($receipt[$field] ?? null)) self::invalid();
        }
        if (!in_array($version, [1, 2], true)
            || ($receipt['ruleset'] ?? null) !== NonprofitTaxRules::VERSION
            || !is_string($hash)
            || !hash_equals($hash, NonprofitTaxRules::hash($receipt))
            || ($receipt['period'] ?? null) !== [
                'id' => $period['id'] ?? null,
                'fiscal_year' => $period['fiscal_year'] ?? null,
                'starts_on' => $period['starts_on'] ?? null,
                'ends_on' => $period['ends_on'] ?? null,
            ]
            || !is_int($receipt['assessment_id'] ?? null)
            || !is_string($receipt['request_hash'] ?? null)
            || !is_string($receipt['input_hash'] ?? null)
            || !is_string($receipt['result_hash'] ?? null)
            || ($result['ruleset'] ?? null) !== NonprofitTaxRules::VERSION
            || !is_array($result['rows'] ?? null)
            || !hash_equals($receipt['result_hash'], NonprofitTaxRules::hash($result))) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new TaxReturnException('nonprofit_dppo_j6_receipt_invalid', 'DPPO 504 vyžaduje úplný aktuální a neměnný receipt potvrzeného posouzení J6.', 409);
    }
}
