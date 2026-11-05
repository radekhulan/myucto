<?php

declare(strict_types=1);

namespace MyInvoice\Service\Demo;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\System\ManagedModeGuard;

/**
 * Jediná odpověď na otázku „běží tahle instalace jako veřejná ukázka?".
 *
 * Middleware {@see \MyInvoice\Middleware\DemoReadOnlyMiddleware} blokuje zápisové
 * METODY, jenže část aplikace zapisuje i při čtení: archiv stažených daňových XML,
 * vestavěné vzory importu nebo výchozí číselník mzdových složek se doplňují při GET.
 * Služby, které takové doplnění dělají, se ptají sem, ne na atribut požadavku — ten
 * by se musel protahovat přes repozitáře, které o HTTP nic nevědí.
 *
 * Zámek spravované instalace platí stejně jako u middleware: spravovaná zákaznická
 * instalace demo nikdy není, ať je v konfiguraci cokoli.
 */
final class DemoMode
{
    public function __construct(private readonly Config $config) {}

    public function enabled(): bool
    {
        return (new ManagedModeGuard($this->config))->effectiveFlag(
            ManagedModeGuard::KEY_DEMO_ENABLED,
            (bool) $this->config->get('demo.enabled', false),
        );
    }
}
