<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Snapshot;

final readonly class SnapshotCursor
{
    public function __construct(public string $entryDate, public int $entryId, public int $lineId)
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $entryDate);
        if ($date === false || $date->format('Y-m-d') !== $entryDate || $entryId <= 0 || $lineId <= 0) {
            throw new \InvalidArgumentException('Snapshot cursor is invalid.');
        }
    }

    public static function fromArray(?array $value): ?self
    {
        if ($value === null) return null;
        if (array_keys($value) !== ['entry_date', 'entry_id', 'line_id'] || !is_string($value['entry_date'])
            || !is_int($value['entry_id']) || !is_int($value['line_id'])) {
            throw new \InvalidArgumentException('Snapshot cursor shape is invalid.');
        }
        return new self($value['entry_date'], $value['entry_id'], $value['line_id']);
    }

    public function toArray(): array
    {
        return ['entry_date' => $this->entryDate, 'entry_id' => $this->entryId, 'line_id' => $this->lineId];
    }
}
