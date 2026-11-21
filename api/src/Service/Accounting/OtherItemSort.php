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

    /** Popisky řazení pro hlavičku knihy (cs.json other_items.*). */
    public const LABELS = [
        'document_no' => 'Číslo dokladu', 'title' => 'Položka', 'partner_name' => 'Protistrana',
        'due_on' => 'Splatnost', 'amount' => 'Částka', 'remaining_amount' => 'Zbývá uhradit', 'status' => 'Stav',
    ];

    public static function orderBy(array $filters): string
    {
        $sort = self::selected($filters);
        if ($sort === null) return 'oi.due_on DESC, oi.id DESC';
        return self::COLUMNS[$sort['key']] . ' ' . $sort['dir'] . ', oi.id ' . $sort['dir'];
    }

    /**
     * Řazení zvolené ve výpisu (klíč sloupce a směr), nebo null pro výchozí. Sdílí ho výpis
     * a kniha, která zvolené řazení převezme ({@see \MyInvoice\Repository\OtherItemRepository::bookBatch()}).
     *
     * @return array{key:string, dir:'ASC'|'DESC'}|null
     */
    public static function selected(array $filters): ?array
    {
        $key = is_scalar($filters['sort_by'] ?? null) ? (string) $filters['sort_by'] : '';
        if (!isset(self::COLUMNS[$key])) return null;
        return ['key' => $key, 'dir' => ($filters['sort_dir'] ?? '') === 'asc' ? 'ASC' : 'DESC'];
    }
}
