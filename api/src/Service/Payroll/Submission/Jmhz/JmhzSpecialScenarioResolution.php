<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzSpecialScenarioResolution
{
    /** @param list<JmhzScenario1Blocker> $blockers */
    public function __construct(
        public ?JmhzSpecialScenarioNormalizedDocument $candidate,
        public array $blockers,
    ) {}

    public function status(): string
    {
        return $this->blockers === [] ? 'resolved' : 'blocked';
    }

    public function requireResolvedDocument(): JmhzSpecialScenarioNormalizedDocument
    {
        if ($this->candidate === null || $this->blockers !== []) {
            throw new JmhzPreparationSnapshotException(
                'jmhz_special_scenarios_resolution_blocked',
                'Normalizovaný dokument zvláštních scénářů JMHZ není úplný.',
            );
        }

        return $this->candidate;
    }
}
