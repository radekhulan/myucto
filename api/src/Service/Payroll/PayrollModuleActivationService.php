<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Settings\PayrollSetupCheckService;
use MyInvoice\Service\Payroll\Settings\PayrollSetupFeaturesResolver;

final class PayrollModuleActivationService
{
    public const TRIGGER_SETUP_COMPLETE = 'setup_complete';
    public const TRIGGER_FIRST_APPROVED_RUN = 'first_approved_run';

    public function __construct(
        private readonly PayrollModuleStateRepository $state,
        private readonly ActivityLogger $logger,
        private readonly PayrollSetupFeaturesResolver $features,
        private readonly PayrollSetupCheckService $setupCheck,
    ) {}

    /** @return array<string,mixed>|null */
    public function activateWhenSetupComplete(
        int $supplierId,
        ?int $userId,
    ): ?array {
        $current = $this->state->get($supplierId);
        if ($current['status'] !== 'setup') {
            return null;
        }
        $effectiveOn = self::effectiveOn($current['start_period']);
        $check = $this->setupCheck->check(
            $supplierId,
            $effectiveOn,
            $this->features->resolve($supplierId, $effectiveOn),
        );
        if ($check['ready'] !== true || $check['blockers'] !== []) {
            return null;
        }

        return $this->promote(
            $supplierId,
            $userId,
            self::TRIGGER_SETUP_COMPLETE,
        );
    }

    /** @return array<string,mixed>|null */
    public function activateAfterApprovedRun(
        int $supplierId,
        ?int $userId,
    ): ?array {
        return $this->promote(
            $supplierId,
            $userId,
            self::TRIGGER_FIRST_APPROVED_RUN,
        );
    }

    /** @return array<string,mixed>|null */
    private function promote(
        int $supplierId,
        ?int $userId,
        string $trigger,
    ): ?array {
        if ($userId === null) {
            return null;
        }
        $state = $this->state->promoteToActive($supplierId, $userId);
        if ($state === null) {
            return null;
        }
        $this->logger->log(
            'payroll.activation.activated',
            $userId,
            'payroll_module_state',
            $supplierId,
            [
                'status' => $state['status'],
                'start_period' => $state['start_period'],
                'trigger' => $trigger,
            ],
            null,
            null,
            $supplierId,
        );

        return $state;
    }

    private static function effectiveOn(?string $startPeriod): string
    {
        $monthStart = date('Y-m-01');
        if ($startPeriod === null) {
            return $monthStart;
        }
        $start = substr($startPeriod, 0, 7) . '-01';

        return $start > $monthStart ? $start : $monthStart;
    }
}
