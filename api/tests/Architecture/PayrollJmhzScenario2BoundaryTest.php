<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Blocker;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario2DocumentResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario2NormalizedDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario2Resolution;
use PHPUnit\Framework\TestCase;

final class PayrollJmhzScenario2BoundaryTest extends TestCase
{
    public function testResolverDependsOnlyOnFrozenSnapshotAndPinnedCatalog(): void
    {
        $constructor = (new \ReflectionClass(JmhzScenario2DocumentResolver::class))->getConstructor();
        self::assertNull($constructor);

        $source = file_get_contents((new \ReflectionClass(
            JmhzScenario2DocumentResolver::class,
        ))->getFileName());
        self::assertIsString($source);
        foreach (['Repository\\', 'Infrastructure\\Database', 'DateTimeImmutable', 'Uuid'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    public function testInternalDtosCannotBeSerializedImplicitly(): void
    {
        foreach ([
            JmhzScenario1Blocker::class,
            JmhzScenario2NormalizedDocument::class,
            JmhzScenario2Resolution::class,
        ] as $class) {
            self::assertFalse((new \ReflectionClass($class))->implementsInterface(\JsonSerializable::class));
        }
    }
}
