<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Automatický měsíční export do Pohody je volba instalace a ve výchozím stavu je VYPNUTÝ.
 * Zapíná ho správce instalace přes `export.scheduled_pohoda` v cfg.php
 * (ENV MYINVOICE_SCHEDULED_POHODA_EXPORT). Export odesílá účetní data e-mailem mimo
 * aplikaci, proto o něm rozhoduje provozovatel, ne jednotlivá firma.
 *
 * Vypnutá volba vypíná celou funkci naráz: panel na stránce Export, API nastavení
 * i ručního spuštění (404) a cron, který skončí bez práce. Uložená nastavení firem
 * a historie běhů zůstávají a po zapnutí platí dál.
 */
final class ScheduledPohodaExportFeature
{
    public const CONFIG_KEY = 'export.scheduled_pohoda';

    public function __construct(private readonly Config $config) {}

    public function isEnabled(): bool
    {
        return filter_var($this->config->get(self::CONFIG_KEY, false), FILTER_VALIDATE_BOOL);
    }
}
