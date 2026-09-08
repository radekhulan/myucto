<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final readonly class JmhzPvpojPreview
{
    public const SCHEMA_REFERENCE = 'payroll-jmhz-pvpoj-preview.v1';

    /**
     * @param array{
     *   office_id:int,
     *   code:string,
     *   name:string,
     *   variable_symbol:string
     * } $office registrace u OSSZ, za kterou se přehled podává
     * @param list<array<string,mixed>> $allocation rozpad kořenových částek na
     *        všechny účtárny běhu — přehled je jeho podílem, takže součet přes
     *        účtárny musí dát kořenový sociální výsledek
     * @param array<string,mixed> $source
     * @param array<string,mixed> $pvpoj
     * @param list<array<string,mixed>> $reconciliation osoby CELÉHO běhu; přehled
     *        vzniká rozdělením jejich kořenového úhrnu, ne přepočtem za účtárnu
     */
    public function __construct(
        public int $supplierId,
        public int $runId,
        public int $revisionId,
        public int $revisionNo,
        public string $period,
        public array $office,
        public array $allocation,
        public array $source,
        public array $pvpoj,
        public array $reconciliation,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_reference' => self::SCHEMA_REFERENCE,
            'document_kind' => 'internal_jmhz_pvpoj_preview',
            'workflow_status' => 'preview_only',
            'official_submission' => [
                'supported' => false,
                'reason_code' => 'pvpoj_only_identity_snapshot_incomplete',
            ],
            'xsd' => [
                'bundle_version' => '1.4.3.6',
                'schema_version' => '1.4.3',
                'entry_point' => 'jmhz-1.4.3.6/PVPOJ.xsd',
                'namespace' => 'http://schemas.cssz.cz/JMHZ/PVPOJ/1.0',
            ],
            'supplier_id' => $this->supplierId,
            'run_id' => $this->runId,
            'revision_id' => $this->revisionId,
            'revision_no' => $this->revisionNo,
            'period' => $this->period,
            'currency_code' => 'CZK',
            'office' => $this->office,
            'office_allocation' => [
                'method' => 'largest_remainder_by_capped_assessment_base',
                'root_result_is_single_source_of_truth' => true,
                'offices' => $this->allocation,
            ],
            'source' => $this->source,
            'pvpoj' => $this->pvpoj,
            'reconciliation' => $this->reconciliation,
        ];
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }

    public function sha256(): string
    {
        return hash('sha256', $this->canonicalJson());
    }

    public function downloadBytes(): string
    {
        return $this->canonicalJson();
    }

    public function filename(): string
    {
        return sprintf(
            'jmhz-pvpoj-preview-%s-revize-%d-uctarna-%d.json',
            $this->period,
            $this->revisionId,
            $this->office['office_id'],
        );
    }
}
