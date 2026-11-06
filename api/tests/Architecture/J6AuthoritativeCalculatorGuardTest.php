<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Tax\Nonprofit\J6AuthoritativeCalculator;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxCalculator;
use PHPUnit\Framework\TestCase;

final class J6AuthoritativeCalculatorGuardTest extends TestCase
{
    public function testLegacyArrayCalculatorContainsOnlyDelegationToCallableAuthoritativeSsot(): void
    {
        $legacy = new \ReflectionClass(NonprofitTaxCalculator::class);
        $authoritative = new \ReflectionClass(J6AuthoritativeCalculator::class);
        self::assertTrue($this->calls($legacy->getMethod('calculate'), 'calculateLegacyArray'));
        self::assertTrue($this->calls($authoritative->getMethod('calculateLegacyArray'), 'calculate'));
        foreach (['resolveActivities', 'activityFundingOrder', 'activityGroups', 'resolveConditionalCycle', 'funding'] as $duplicate) {
            self::assertFalse($authoritative->hasMethod($duplicate), $duplicate . ' must not reintroduce a second legacy tax algorithm.');
        }
        self::assertTrue($authoritative->hasMethod('classifySource'));
    }

    private function calls(\ReflectionMethod $method, string $target): bool
    {
        $lines = file($method->getFileName());
        $source = implode('', array_slice($lines, $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1));
        $tokens = token_get_all("<?php\n" . $source);
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== $target) continue;
            $previous = $tokens[$index - 1] ?? null;
            if (is_array($previous) && $previous[0] === T_OBJECT_OPERATOR) return true;
        }
        return false;
    }
}
