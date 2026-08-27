<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final readonly class JmhzPreparationSnapshot
{
    public const LEGACY_SCHEMA_REFERENCE = 'payroll-jmhz-preparation-source.v1';
    public const PREVIOUS_V2_SCHEMA_REFERENCE = 'payroll-jmhz-preparation-source.v2';
    public const PREVIOUS_SCHEMA_REFERENCE = 'payroll-jmhz-preparation-source.v3';
    public const PREVIOUS_V4_SCHEMA_REFERENCE = 'payroll-jmhz-preparation-source.v4';
    public const PREVIOUS_V5_SCHEMA_REFERENCE = 'payroll-jmhz-preparation-source.v5';
    public const PREVIOUS_V6_SCHEMA_REFERENCE = 'payroll-jmhz-preparation-source.v6';
    public const PREVIOUS_V7_SCHEMA_REFERENCE = 'payroll-jmhz-preparation-source.v7';
    public const PREVIOUS_V8_SCHEMA_REFERENCE = 'payroll-jmhz-preparation-source.v8';
    public const PREVIOUS_V9_SCHEMA_REFERENCE = 'payroll-jmhz-preparation-source.v9';
    public const PREVIOUS_V10_SCHEMA_REFERENCE = 'payroll-jmhz-preparation-source.v10';
    public const CURRENT_SCHEMA_REFERENCE = 'payroll-jmhz-preparation-source.v11';

    /**
     * @param array<string,mixed> $payload
     * @param list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     */
    public function __construct(
        public array $payload,
        public array $issues,
    ) {}

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->payload);
    }

    /** @return array<string,mixed> */
    public function readiness(): array
    {
        $publicIssues = [];
        foreach ($this->issues as $issue) {
            $key = $issue['code'] . '|' . $issue['entity_type'] . '|'
                . implode(',', $issue['attribute_ids']);
            if (!isset($publicIssues[$key])) {
                $publicIssues[$key] = [
                    'code' => $issue['code'],
                    'entity_type' => $issue['entity_type'],
                    'count' => 0,
                    'attribute_ids' => $issue['attribute_ids'],
                ];
            }
            $publicIssues[$key]['count']++;
        }
        return [
            'schema_reference' => 'payroll-jmhz-preparation-readiness.v1',
            'status' => $this->issues === [] ? 'source_ready' : 'blocked',
            'issue_count' => count($this->issues),
            'issues' => array_values($publicIssues),
            'official_submission_supported' => false,
        ];
    }
}
