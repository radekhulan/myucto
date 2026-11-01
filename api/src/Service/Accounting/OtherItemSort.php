<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

final class OtherItemSort
{
    private const COLUMNS = [
        'document_no' => 'oi.document_no',
        'title' => 'oi.title',
        'partner_name' => 'oi.partner_name',
        'due_on' => 'oi.due_on',
        'amount' => 'oi.amount',
        'remaining_amount' => 'remaining_amount',
        'status' => 'oi.status',
    ];

    public static function orderBy(array $filters): string
    {
        $column = self::COLUMNS[$filters['sort_by'] ?? ''] ?? null;
        if ($column === null) return 'oi.due_on DESC, oi.id DESC';
        $direction = ($filters['sort_dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';
        return $column . ' ' . $direction . ', oi.id ' . $direction;
    }
}
