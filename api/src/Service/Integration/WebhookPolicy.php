<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\License\LicenseConsistency;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseState;

final class WebhookPolicy
{
    public function __construct(
        private readonly Config $config,
        private readonly LicenseService $license,
        private readonly LicenseConsistency $consistency,
    ) {}

    public function licensed(): bool
    {
        try {
            $state = $this->license->current();
            return self::isPaid($state) && $this->consistency->allowsCommercial();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isPaid(LicenseState $state): bool
    {
        return in_array($state->state, [LicenseState::ACTIVE, LicenseState::OVERAGE], true)
            && $state->hasCommercialFeatures();
    }

    public function mode(): string
    {
        if ($this->config->get('demo.enabled', false)) {
            return 'disabled';
        }
        $mode = $this->config->get('webhooks.mode', 'public');
        if ($mode === 'allowlist' && $this->allowedHosts() === []) {
            return 'disabled';
        }
        return in_array($mode, ['public', 'allowlist'], true) ? $mode : 'disabled';
    }

    /** @return list<string> */
    public function allowedHosts(): array
    {
        $hosts = $this->config->get('webhooks.allowed_hosts', []);
        if (!is_array($hosts)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn (mixed $host): string => is_string($host) ? strtolower(trim($host)) : '',
            $hosts,
        ), static fn (string $host): bool => $host !== ''));
    }
}
