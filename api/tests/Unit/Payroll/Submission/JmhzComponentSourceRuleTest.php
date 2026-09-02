<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzComponentSourceRule;
use PHPUnit\Framework\TestCase;

/**
 * Pravidlo o zařazení mzdové složky má JEDNU podobu pro zmrazení hlášení
 * i pro kontrolu před zahájením běhu. Dvě kopie by se rozešly a účetní by
 * dostala jinou odpověď podle toho, odkud se ptá.
 */
final class JmhzComponentSourceRuleTest extends TestCase
{
    public function testIncludedComponentWithoutMappingIsAFinding(): void
    {
        self::assertSame(
            'component_jmhz_mapping_missing',
            JmhzComponentSourceRule::issueCode('included', null),
        );
    }

    public function testIncludedComponentWithMappingIsFine(): void
    {
        self::assertNull(
            JmhzComponentSourceRule::issueCode('included', ['target_attribute_id' => '10329']),
        );
    }

    /**
     * Vyřazená složka nemá co mapovat — chybějící zařazení u ní není nález,
     * je to správný stav.
     */
    public function testExcludedComponentIsNeverAFinding(): void
    {
        self::assertNull(JmhzComponentSourceRule::issueCode('excluded', null));
    }

    public function testManualReviewAsksForConfirmation(): void
    {
        self::assertSame(
            'component_jmhz_manual_review',
            JmhzComponentSourceRule::issueCode('manual_review', null),
        );
    }

    public function testUnknownTreatmentIsInvalidSetup(): void
    {
        foreach ([null, '', 'nesmysl', 42] as $treatment) {
            self::assertSame(
                'component_jmhz_treatment_invalid',
                JmhzComponentSourceRule::issueCode($treatment, null),
            );
        }
    }
}
