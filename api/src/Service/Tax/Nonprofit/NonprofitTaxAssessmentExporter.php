<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Repository\NonprofitTaxAssessmentRepository;

final class NonprofitTaxAssessmentExporter
{
    public function __construct(private readonly NonprofitTaxAssessmentRepository $assessments, private readonly NonprofitTaxAssessmentGuard $guard) {}

    public function export(int $supplierId, int $id): array
    {
        $revision = $this->assessments->find($supplierId, $id);
        try {
            $check = $this->guard->closingCheck($supplierId, $revision['period_id']);
        } catch (NonprofitTaxAssessmentException $e) {
            if (!in_array($e->errorCode, ['nonprofit_tax_joint_changed', 'nonprofit_tax_framework_unsupported', 'nonprofit_tax_facts_incomplete'], true)) throw $e;
            $check = ['applicable' => true, 'complete' => false, 'assessment_id' => null,
                'findings' => [['code' => $e->errorCode, 'message' => $e->getMessage()]], 'dppo_supported' => false];
        }
        return ['format' => 'myucto-nonprofit-tax-assessment-v1', 'scope' => 'classification_bridge_before_general_adjustments',
            'dppo_supported' => false, 'current_confirmed' => $check['complete'] && $check['assessment_id'] === $id,
            'current_check' => $check, 'revision' => $revision];
    }
}
