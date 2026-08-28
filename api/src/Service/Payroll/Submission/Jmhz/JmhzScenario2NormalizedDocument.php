<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final readonly class JmhzScenario2NormalizedDocument
{
    public const SCHEMA_REFERENCE = 'payroll-jmhz-scenario2-document.v1';

    /** @param array<string,mixed> $payload */
    public function __construct(public array $payload) {}

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->payload);
    }

    public function sha256(): string
    {
        return hash('sha256', $this->canonicalJson());
    }
}
