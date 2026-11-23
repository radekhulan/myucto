<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

/**
 * Pevný seznam sloupců řádku deníku pro čtenáře, kteří CELÝ řádek otiskují (hash
 * zdroje uzávěrkových kroků 504, převodu rámce, přecenění, sdruženého rozúčtování)
 * a otisk ukládají k pozdějšímu porovnání.
 *
 * `SELECT l.*` by do otisku vtáhl každý nově přidaný sloupec: po migraci, která
 * přidala `pair_no` a `line_text` (jednoduchý zápis), by uložené otisky hlásily
 * „zdroj se změnil", přestože se v účetních datech nic nezměnilo. Seznam proto
 * přesně odpovídá sloupcům řádku PŘED jednoduchým zápisem, ve stejném pořadí jako
 * dřívější `l.*` (json_encode řádku závisí na pořadí klíčů), takže otisky spočtené
 * před migrací a po ní jsou shodné.
 *
 * Nový sloupec řádku sem patří jen tehdy, když má otisk zdroje opravdu měnit;
 * pak je to vědomá změna otisků, ne vedlejší účinek migrace.
 */
final class JournalLineProofColumns
{
    /** @var list<string> */
    public const COLUMNS = [
        'id',
        'entry_id',
        'supplier_id',
        'account_id',
        'side',
        'amount',
        'is_red_storno',
        'signed_amount',
        'currency_code',
        'fx_rate',
        'amount_foreign',
        'signed_amount_foreign',
        'cost_center',
        'project_id',
        'line_no',
    ];

    /** Seznam pro SELECT s aliasem tabulky, např. `l.id, l.entry_id, …`. */
    public static function select(string $alias): string
    {
        return implode(', ', array_map(static fn (string $column): string => $alias . '.' . $column, self::COLUMNS));
    }
}
