<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Snapshot;

final readonly class SnapshotHeaderV2
{
    public const SCHEMA = 'nonprofit-j6-source.v2';

    public function __construct(public array $data)
    {
        foreach (['supplier_id', 'period_id'] as $key) if (!is_int($data[$key] ?? null) || $data[$key] <= 0) throw new \InvalidArgumentException('Snapshot header identity is invalid.');
        if (($data['schema'] ?? null) !== self::SCHEMA || ($data['scope'] ?? null) !== 'current-period-source'
            || !is_string($data['ruleset'] ?? null) || !is_array($data['period'] ?? null)
            || !is_array($data['frameworks'] ?? null) || !is_array($data['source_fact_schemas'] ?? null)) {
            throw new \InvalidArgumentException('Snapshot header is invalid.');
        }
    }
}
