<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\Employment\PayrollRelationType;
use MyInvoice\Service\Payroll\Garnishment\ClaimCategory;
use MyInvoice\Service\Payroll\HealthInsurance\HealthRelationshipKindMapper;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKindMapper;
use MyInvoice\Service\Payroll\SocialInsurance\SocialRelationshipKindMapper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class PayrollClassificationContractTest extends TestCase
{
    public function testEveryRelationTypeIsHandledByEveryStatutoryMapper(): void
    {
        $health = new HealthRelationshipKindMapper();
        $social = new SocialRelationshipKindMapper();
        $tax = new EmploymentRelationshipKindMapper();

        foreach (PayrollRelationType::cases() as $relationType) {
            $health->fromDatabaseRelationType($relationType->value);
            $social->fromRelationType($relationType->value);
            $tax->fromDatabaseRelationType($relationType->value);
            $this->addToAssertionCount(3);
        }
    }

    public function testMaintenanceClassificationHasOneOrderedSourceOfTruth(): void
    {
        // Pořadí = § 280 odst. 2 o. s. ř. Postoupené výživné a úplata za
        // postupované pohledávky výživného jsou podle § 279 odst. 2 písm. a)
        // rovněž výživné a v druhé třetině mají místo PŘED náhradním výživným.
        self::assertSame([
            ClaimCategory::CurrentMaintenance,
            ClaimCategory::MaintenanceArrears,
            ClaimCategory::AssignedMaintenanceConsideration,
            ClaimCategory::AssignedMaintenance,
            ClaimCategory::SubstituteMaintenance,
        ], ClaimCategory::maintenanceCategories());

        foreach (ClaimCategory::cases() as $category) {
            self::assertSame(
                in_array($category, ClaimCategory::maintenanceCategories(), true),
                $category->requiresMaintenanceWeight(),
            );
        }

        $duplicates = [];
        foreach ([
            'src/Service/Payroll/Garnishment/GarnishmentCalculator.php',
            'src/Repository/Payroll/PayrollEnforcementRepository.php',
        ] as $relativePath) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
            if (preg_match(
                '/ClaimCategory::(?:CurrentMaintenance|MaintenanceArrears'
                    . '|AssignedMaintenanceConsideration|AssignedMaintenance'
                    . '|SubstituteMaintenance)/',
                $source,
            ) === 1) {
                $duplicates[] = $relativePath;
            }
        }

        self::assertSame([], $duplicates, sprintf(
            'Klasifikace výživného je znovu opsaná mimo ClaimCategory: %s',
            implode(', ', $duplicates),
        ));
    }

    public function testEnforcementPaymentPriorityHasOneSourceOfTruth(): void
    {
        self::assertSame([
            ClaimCategory::CurrentMaintenance->value,
            ClaimCategory::MaintenanceArrears->value,
            ClaimCategory::AssignedMaintenanceConsideration->value,
            ClaimCategory::AssignedMaintenance->value,
            ClaimCategory::SubstituteMaintenance->value,
            ClaimCategory::OtherPriority->value,
            ClaimCategory::NonPriority->value,
        ], array_map(
            static fn (ClaimCategory $category): string => $category->value,
            ClaimCategory::paymentPriorityOrder(),
        ));

        foreach (ClaimCategory::paymentPriorityOrder() as $rank => $category) {
            self::assertSame($rank, $category->paymentPriorityRank());
        }

        $materializer = (string) file_get_contents(
            dirname(__DIR__, 2)
                . '/src/Service/Payroll/Payment/'
                . 'PayrollEnforcementLiabilityMaterializer.php',
        );
        self::assertStringNotContainsString('CATEGORY_RANK', $materializer);
    }
}
