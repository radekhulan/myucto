<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Repository\Payroll\PayrollSubmissionQueueRepository;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchCapability;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchCapabilityCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchGate;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionDeliveryProof;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use PHPUnit\Framework\TestCase;

/**
 * Seznam stavů, které fronta odchozích podání ukazuje, žije na dvou místech:
 * jako PHP pole a jako řetězec vlepený do `IN (...)`. Konstantní výraz v PHP
 * neumí zavolat `implode()`, takže se ta kopie udělat musela — a kopie se
 * rozejdou. Rozejít se tyhle dvě nesmí: v poli navíc znamená stav, který
 * dotaz nevrátí, v SQL navíc znamená řádek, kterému brána nezná důvod.
 */
final class PayrollSubmissionQueueStatusSqlTest extends TestCase
{
    public function testSqlStatusListMirrorsThePhpList(): void
    {
        $fromSql = array_map(
            static fn (string $item): string => trim($item, '"'),
            explode(',', PayrollSubmissionQueueRepository::LISTED_STATUSES_SQL),
        );

        self::assertSame(
            PayrollSubmissionQueueRepository::LISTED_STATUSES,
            $fromSql,
        );
    }

    public function testQueuedSqlStatusListMirrorsThePhpList(): void
    {
        $fromSql = array_map(
            static fn (string $item): string => trim($item, '"'),
            explode(',', PayrollSubmissionQueueRepository::QUEUED_STATUSES_SQL),
        );

        self::assertSame(
            PayrollSubmissionQueueRepository::QUEUED_STATUSES,
            $fromSql,
        );
    }

    /**
     * Podmínka „doručení je doložené" žije taky dvakrát: jako SQL ve frontě
     * (která takový řádek přestane ukazovat) a jako PHP v
     * {@see PayrollSubmissionDeliveryProof} (které podle ní zakazuje zahodit
     * a podat znovu). Kdyby se rozešly, dostal by se řádek do fronty
     * s tlačítkem, které server odmítne — nebo naopak zmizel, aniž by k němu
     * vedla jakákoliv akce.
     */
    public function testDeliveryProofSqlMirrorsThePhpRule(): void
    {
        $sql = (string) (new \ReflectionClass(
            PayrollSubmissionQueueRepository::class,
        ))->getConstant('DELIVERY_PROVEN_SQL');

        // Každý důvod, který PHP uznává, musí mít protějšek ve sloupci dotazu.
        $columnsByReason = [
            'delivered' => 'outbox.dispatch_state = "delivered"',
            'receipt' => 'outbox.receipt_document_id IS NOT NULL',
            'accepted' => 'outbox.acceptance_state = "accepted"',
        ];
        $rowsByReason = [
            'delivered' => ['dispatch_state' => 'delivered'],
            'receipt' => [
                'dispatch_state' => 'sent',
                'receipt_document_id' => 7,
            ],
            'accepted' => [
                'dispatch_state' => 'sent',
                'acceptance_state' => 'accepted',
            ],
        ];
        foreach ($columnsByReason as $reason => $condition) {
            self::assertStringContainsString($condition, $sql, sprintf(
                'Fronta neumí odfiltrovat důkaz doručení „%s".',
                $reason,
            ));
            self::assertSame(
                $reason,
                PayrollSubmissionDeliveryProof::reason($rowsByReason[$reason]),
            );
        }

        // A naopak: co PHP za důkaz nepovažuje, nesmí fronta schovat.
        self::assertNull(PayrollSubmissionDeliveryProof::reason(null));
        self::assertNull(PayrollSubmissionDeliveryProof::reason([
            'dispatch_state' => 'sent',
            'acceptance_state' => 'unknown',
            'receipt_document_id' => null,
        ]));
        self::assertSame(
            count($columnsByReason),
            substr_count($sql, 'outbox.'),
            'V SQL přibyla podmínka, kterou PayrollSubmissionDeliveryProof nezná.',
        );
    }

    public function testListedStatusesCoverEveryReopenableStatus(): void
    {
        foreach (PayrollSubmissionStateMachine::REOPENABLE_STATUSES as $status) {
            self::assertContains(
                $status,
                PayrollSubmissionQueueRepository::LISTED_STATUSES,
                sprintf(
                    'Ze stavu „%s" se smí podání vrátit k odeslání, ale fronta'
                        . ' ho neukáže — tlačítko by nebylo kde stisknout.',
                    $status,
                ),
            );
        }
    }

    /**
     * Uvízlé podání nesmí spadnout do věty o nezmrazeném podání: ta radí
     * dokončit přípravu v agendě, což u odeslaného podání nikam nevede.
     */
    public function testStuckSubmissionGetsItsOwnReason(): void
    {
        $gate = new PayrollDispatchGate();
        $capability = new PayrollDispatchCapability(
            'JMHZ25',
            PayrollDispatchCapabilityCatalog::MODE_VREP_JMHZ,
            null,
        );

        foreach (PayrollSubmissionStateMachine::REOPENABLE_STATUSES as $status) {
            $reason = $gate->blockedReason(
                ['submission_status' => $status],
                $capability,
                'production',
                0,
            );
            self::assertIsString($reason);
            self::assertStringNotContainsString('zmrazené k odeslání', $reason);
            self::assertStringContainsString('zahodit a podat znovu', $reason);
        }
    }
}
