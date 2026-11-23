<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

/**
 * Obsahový otisk podkladů odložené daně 504 (doména DT504SOURCE2).
 *
 * Podklady jsou zdrojové řádky období ze snímku neziskovky nebo ze stránek J6. Otisk bere
 * jejich obsah (účet, strana a částka, datum, osy dimenzí, původ zápisu, alokace, zjištění),
 * ne id řádků a zápisů, a řádky i zjištění porovnává jako multimnožinu nezávisle na pořadí.
 * Nové spuštění kroku Zásoby přepíše skladový slot se stejnými částkami pod novými id;
 * otisk nad id pak zneplatnil potvrzené rozhodnutí a blokoval uzavření knih, ačkoli se
 * podklady nezměnily.
 */
final class DeferredTax504SourceContent
{
    /** Klíče identity řádku deníku; obsah podkladu nenesou a při přepisu zápisu se mění. */
    private const IDENTITY_KEYS = ['line_id', 'entry_id', 'journal_entry_id', 'source_line_id', 'mirrors_line_id', 'line_no'];

    /** @var list<string> */
    private array $rows = [];
    /** @var list<string> */
    private array $findings = [];

    /**
     * Řádky a zjištění jedné stránky (nebo celého snímku). Zjištění se váže na obsah svého
     * řádku; čtečky je vydávají ve stejné dávce jako řádek, ke kterému patří.
     */
    public function add(array $rows, array $findings): void
    {
        $byLine = [];
        foreach ($rows as $row) {
            $hash = NonprofitTaxRules::hash(self::content($row, true));
            $this->rows[] = $hash;
            if (isset($row['line_id'])) $byLine[(int) $row['line_id']] = $hash;
        }
        foreach ($findings as $finding) {
            $line = isset($finding['line_id']) ? ($byLine[(int) $finding['line_id']] ?? null) : null;
            $this->findings[] = NonprofitTaxRules::hash(['row' => $line] + self::content($finding));
        }
    }

    public function finish(array $header): string
    {
        $rows = $this->rows;
        $findings = $this->findings;
        sort($rows, SORT_STRING);
        sort($findings, SORT_STRING);
        return NonprofitTaxRules::hash(['domain' => 'DT504SOURCE2', 'header' => self::content($header),
            'rows' => hash('sha256', implode("\n", $rows)), 'row_count' => count($rows),
            'findings' => hash('sha256', implode("\n", $findings)), 'finding_count' => count($findings)]);
    }

    /** Hlavička legacy snímku bez částí, které nesou id řádků (obsah řádků je v {@see add()}). */
    public static function snapshotHeader(array $snapshot): array
    {
        return array_diff_key($snapshot, array_flip(['sources', 'findings', 'ledger', 'dimensions', 'joint_projections',
            'source_references', 'neutral_line_ids', 'input_hash']));
    }

    private static function content(array $value, bool $row = false): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && (in_array($key, self::IDENTITY_KEYS, true) || ($row && $key === 'id'))) continue;
            $out[$key] = is_array($item) ? self::content($item) : $item;
        }
        return $out;
    }
}
