<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\SourceFacts;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final class NonprofitTaxSourceFactRegistry
{
    /** @var array<string,NonprofitTaxSourceFactProvider> */
    private array $providers = [];

    /** @param iterable<NonprofitTaxSourceFactProvider> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $schema = $provider->schema();
            if (preg_match('/^[a-z0-9][a-z0-9._-]{2,79}$/D', $schema) !== 1 || isset($this->providers[$schema])) {
                throw new \InvalidArgumentException('Snapshot source fact schema is invalid or duplicated.');
            }
            $this->providers[$schema] = $provider;
        }
        ksort($this->providers, SORT_STRING);
    }

    /** @return list<string> */
    public function schemas(): array
    {
        return array_keys($this->providers);
    }

    /** @param list<array<string,mixed>> $rows @return array<int,array<string,array<string,mixed>>> */
    public function capture(int $supplierId, array $rows, bool $currentRead): array
    {
        if (count($rows) > 500) throw new \InvalidArgumentException('Snapshot source facts exceed the page bound.');
        $allowed = [];
        foreach ($rows as $row) {
            $lineId = $row['line_id'] ?? null;
            if (!is_int($lineId) || $lineId <= 0 || isset($allowed[$lineId])) throw new \InvalidArgumentException('Snapshot page line identity is invalid.');
            $allowed[$lineId] = true;
        }
        $out = [];
        foreach ($this->providers as $schema => $provider) {
            foreach ($provider->capture($supplierId, $rows, $currentRead) as $lineId => $fact) {
                $lineId = (int) $lineId;
                if (!isset($allowed[$lineId]) || !is_array($fact) || isset($out[$lineId][$schema])) {
                    throw new \UnexpectedValueException('Snapshot source fact does not bind one exact page line.');
                }
                $fact['schema'] = $schema;
                NonprofitTaxRules::canonicalJson($fact);
                $out[$lineId][$schema] = $fact;
            }
        }
        foreach ($out as &$facts) ksort($facts, SORT_STRING);
        unset($facts);
        ksort($out, SORT_NUMERIC);
        return $out;
    }
}
