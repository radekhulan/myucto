<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class JmhzPreparationMappingLookupTest extends TestCase
{
    public function testRepeatedInputsResolveEachIncludedComponentOncePerPreparation(): void
    {
        $container = Bootstrap::buildContainer();
        $pdo = $container->get(Connection::class)->pdo();
        $service = $container->get(JmhzPreparationSnapshotService::class);
        $method = new \ReflectionMethod($service, 'supplements');
        $input = ['people' => array_fill(0, 30, [
            'employee' => ['id' => 0],
            'employments' => [[
                'employment' => ['id' => 0],
                'inputs' => [
                    ['component' => ['component_id' => 700001, 'jmhz_treatment' => 'included']],
                    ['component' => ['component_id' => 700002, 'jmhz_treatment' => 'included']],
                    ['component' => ['component_id' => 700003, 'jmhz_treatment' => 'excluded']],
                ],
            ]],
        ])];
        for ($preparation = 0; $preparation < 2; $preparation++) {
            $before = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];
            $result = $method->invoke($service, 0, 'test', 0, '2026-08-31', $input, null);
            $after = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];
            self::assertSame([[], [], [], []], $result);
            self::assertSame(2, $after - $before);
        }
    }
}
