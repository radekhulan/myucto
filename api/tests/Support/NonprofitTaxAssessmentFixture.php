<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;

final class NonprofitTaxAssessmentFixture
{
    public static function confirm(ContainerInterface $container, int $supplier, int $period, int $actor): array
    {
        $service = $container->get(NonprofitTaxAssessmentService::class);
        $snapshot = $service->read($supplier, $period)['snapshot'];
        Assert::assertSame([], $snapshot['findings']);
        $assessment = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Synthetic statutes and taxpayer assessment'],
            'activities' => [], 'decisions' => [], 'funding' => []];
        $entityKind = $snapshot['legal_profile']['profile']['kind'] ?? (($snapshot['svj_module_proof'] ?? []) !== [] ? 'svj' : 'narrow_vpp');
        $assessment['profile']['kind'] = $entityKind;
        $manual = !$snapshot['assessment_support']['automatic'];
        if ($manual) {
            $assessment['profile']['kind'] = 'documented';
            $assessment['profile']['entity_kind'] = $entityKind;
            $assessment['method'] = ['mode' => 'documented_manual', 'valid_from' => $snapshot['period']['starts_on'], 'valid_to' => $snapshot['period']['ends_on'],
                'legal_basis' => 'Synthetic legislation applicable to this fiscal period', 'evidence' => 'Synthetic complete classification working paper'];
        }
        foreach ($snapshot['sources'] as $source) {
            $assessment['activities'][$source['activity_kind_id']] = ['evidence' => 'Synthetic documented activity'];
            $assessment['decisions'][$source['id']] = ['title' => $source['kind'] === 'revenue' ? 'rent' : 'cost',
                'evidence' => 'Synthetic source decision', 'legal_basis' => 'zdp_24_1',
                'deductible_cents' => $source['mandatory_non_deductible'] ? 0 : $source['amount_cents']];
            if (isset($source['svj_owner_facts'])) {
                $assessment['decisions'][$source['id']]['title'] = $source['kind'] === 'revenue' ? match ($source['svj_owner_facts']['legal_basis']) {'zdp_18_2f_2' => 'svj_management_payment', 'zdp_18_2f_3' => 'svj_service_payment'} : 'excluded_cost';
                $assessment['decisions'][$source['id']]['deductible_cents'] = 0;
            }
            if ($manual) {
                $assessment['decisions'][$source['id']]['final_regime'] = isset($source['svj_owner_facts']) && $source['kind'] === 'revenue' ? 'out_of_scope' : 'subject';
                if ($source['kind'] === 'revenue') $assessment['decisions'][$source['id']]['final_method'] = isset($source['svj_owner_facts']) ? 'none' : 'return';
            }
        }
        return $service->confirm($supplier, $period, $assessment, $snapshot['input_hash'],
            'Synthetic reviewed accounting evidence', 'synthetic-closing-' . $period, $actor);
    }
}
