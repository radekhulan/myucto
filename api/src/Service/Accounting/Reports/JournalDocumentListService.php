<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionFilter;

/**
 * Soupiska dokladů z filtru Deníku (#356): doklady, jejichž řádky v rozsahu účtů soupisky
 * (a v dimenzi filtru) tvoří částku k doložení, například k vyúčtování dotace. Výběr dělá
 * {@see JournalEntryRepository::documentSelection()}; tahle služba k němu přidá hlavičku
 * (firma, filtr Deníku, rozsah soupisky, datum vytvoření) a u dokladu důvody k ověření.
 *
 * Soupiska je pracovní podklad ke dni vytvoření: zaúčtování a dimenze se později mohou
 * změnit, proto nese datum a stav v hlavičce.
 */
final class JournalDocumentListService
{
    public const MAX_DOCUMENTS = 5000;

    /** Druhy skupin, které nejsou typem zdroje deníku. */
    private const KIND_LABELS = [
        'internal_document' => 'Interní doklad',
        'entry'             => 'Účetní zápis bez dokladu',
        // Z uzávěrky zbývají jen skladové sloty (uzavření knih soupiska vynechává).
        'closing'           => 'Uzávěrka zásob',
    ];

    private const REVERSAL_LABELS = [
        'reversed' => 'jen stornované zápisy',
        'reversal' => 'jen protizápisy storna',
        'any'      => 'stornované zápisy i protizápisy',
        'none'     => 'bez storen',
    ];

    private const AUTOMATION_LABELS = [
        'auto'     => 'automaticky zaúčtované',
        'approved' => 'potvrzené návrhy',
        'manual'   => 'ručně zaúčtované',
    ];

    private const PAIR_STATUS_LABELS = [
        'composite_mn' => 'zápisy M : N k ruční úpravě',
        'ineligible'   => 'zápisy, které nejde rozložit na dvojice',
    ];

    public function __construct(
        private readonly JournalEntryRepository $journal,
        private readonly JournalExportService $journalExport,
    ) {}

    /**
     * @param array<string,mixed> $filters filtr seznamu Deníku (JournalAction::parseFilters)
     * @return array{entity:array<string,mixed>, filters:array<string,mixed>, documents:list<array<string,mixed>>, totals:array{amount:float, count:int, to_check:int}}
     * @throws ReportException 422 too_many_rows
     */
    public function build(
        int $supplierId,
        array $filters,
        string $lineAccountFrom,
        string $lineAccountTo,
        bool $dimensionDescendants = true,
        ?string $preparedBy = null,
        int $maxDocuments = self::MAX_DOCUMENTS,
    ): array {
        $rows = $this->journal->documentSelection($supplierId, $filters, $lineAccountFrom, $lineAccountTo, $maxDocuments);
        if (count($rows) > $maxDocuments) {
            throw new ReportException(
                'too_many_rows',
                'Filtru odpovídá příliš mnoho dokladů (nad ' . $maxDocuments . ') pro jednu soupisku — zúžte rozsah dat (období/datum).',
                422,
            );
        }

        $rows = $this->withSelfAssessedVat($supplierId, $rows);
        $documents = [];
        $amount = 0.0;
        $toCheck = 0;
        foreach ($rows as $row) {
            $row['kind_label'] = self::KIND_LABELS[$row['doc_kind']] ?? JournalSourceLabel::label((string) $row['doc_kind']);
            $row['date'] = $row['document_date'] ?? $row['first_entry_date'];
            $row['checks'] = self::checks($row);
            $documents[] = $row;
            $amount += (float) $row['amount'];
            if ($row['checks'] !== []) {
                $toCheck++;
            }
        }

        [$dateFrom, $dateTo] = $this->journalExport->periodBounds($supplierId, $filters);
        $dimension = $filters['dimension'] ?? null;
        return [
            'entity'    => $this->journalExport->loadEntity($supplierId) + ['prepared_by' => $preparedBy],
            'filters'   => [
                'date_from'             => $dateFrom,
                'date_to'               => $dateTo,
                'line_account_from'     => $lineAccountFrom,
                'line_account_to'       => $lineAccountTo,
                'dimension'             => $dimension instanceof DimensionFilter ? $dimension->toArray()['label'] : null,
                'dimension_descendants' => $dimensionDescendants,
                'journal'               => self::journalFilterLabels($filters),
                'drafts_excluded'       => $this->journal->documentSelectionDraftCount($supplierId, $filters, $lineAccountFrom, $lineAccountTo),
            ],
            'documents' => $documents,
            'totals'    => [
                'amount'   => round($amount, 2),
                'count'    => count($documents),
                'to_check' => $toCheck,
            ],
        ];
    }

    /**
     * Přijatá faktura v režimu přenesené daňové povinnosti: celkem dokladu je bez DPH, kterou si
     * odběratel přiznal sám. Pokud ji zápisy nesou v nákladu (neplátce, identifikovaná osoba,
     * neodpočtená část), řádek dostane `self_assessed_vat` = díl částky k doložení připadající
     * na přiznanou DPH (poměr základu a daně, platí i pro fakturu jen dílem v dimenzi). Faktura
     * tu DPH nedokládá, proto ji soupiska uvádí zvlášť a vyzve k jejímu doložení.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function withSelfAssessedVat(int $supplierId, array $rows): array
    {
        $entryIds = [];
        foreach ($rows as $row) {
            if ($row['reverse_charge'] ?? false) {
                array_push($entryIds, ...$row['entry_ids']);
            }
        }
        $vatByEntry = $this->journal->selfAssessedVatByEntry($supplierId, array_values(array_unique($entryIds)));
        $out = [];
        foreach ($rows as $row) {
            // DPH je načtená jen pro zápisy faktur v režimu přenesené daňové povinnosti.
            $vat = round(array_sum(array_map(static fn (int $id): float => $vatByEntry[$id] ?? 0.0, $row['entry_ids'])), 2);
            $base = (float) ($row['document_total'] ?? 0);
            $row['self_assessed_vat'] = $vat > 0 && $base + $vat > 0
                ? round((float) $row['amount'] * $vat / ($base + $vat), 2)
                : null;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Další zúžení výběru filtrem Deníku do hlavičky soupisky (období, rozsah soupisky
     * a dimenzi hlavička uvádí zvlášť). Bez nich by soupiska vypadala jako úplná.
     *
     * @param array<string,mixed> $filters
     * @return list<string>
     */
    public static function journalFilterLabels(array $filters): array
    {
        $labels = [];
        if (!empty($filters['source_type'])) {
            $labels[] = 'zdroj ' . JournalSourceLabel::label((string) $filters['source_type'])
                . (!empty($filters['source_id']) ? ' #' . (int) $filters['source_id'] : '');
        } elseif (!empty($filters['source_id'])) {
            $labels[] = 'zdroj #' . (int) $filters['source_id'];
        }
        if (!empty($filters['entry_id'])) {
            $labels[] = 'zápis #' . (int) $filters['entry_id'];
        }
        if (isset($filters['entry_ids'])) {
            $labels[] = 'jen nálezy kontroly integrity';
        }
        if (!empty($filters['document_no'])) {
            $labels[] = 'číslo dokladu „' . (string) $filters['document_no'] . '“';
        }
        if (!empty($filters['q'])) {
            $labels[] = 'hledání „' . (string) $filters['q'] . '“';
        }
        if ((string) ($filters['account_from'] ?? '') !== '' || (string) ($filters['account_to'] ?? '') !== '') {
            $labels[] = 'zápisy s účtem ' . ((string) ($filters['account_from'] ?? '') ?: '…') . ' až ' . ((string) ($filters['account_to'] ?? '') ?: '…');
        }
        if (array_key_exists('posted', $filters)) {
            $labels[] = $filters['posted'] ? 'jen zaúčtované' : 'jen koncepty';
        }
        if (isset($filters['reversal'])) {
            $labels[] = self::REVERSAL_LABELS[$filters['reversal']] ?? (string) $filters['reversal'];
        }
        if (isset($filters['automation'])) {
            $labels[] = self::AUTOMATION_LABELS[$filters['automation']] ?? (string) $filters['automation'];
        }
        if (isset($filters['pair_status'])) {
            $labels[] = self::PAIR_STATUS_LABELS[$filters['pair_status']] ?? (string) $filters['pair_status'];
        }
        if (isset($filters['amount_from']) || isset($filters['amount_to'])) {
            $labels[] = 'částka zápisu ' . (isset($filters['amount_from']) ? 'od ' . self::number((float) $filters['amount_from']) : '')
                . (isset($filters['amount_from'], $filters['amount_to']) ? ' ' : '')
                . (isset($filters['amount_to']) ? 'do ' . self::number((float) $filters['amount_to']) : '');
        }
        return $labels;
    }

    /**
     * Důvody, proč doklad v soupisce ověřit ručně (prázdné = bez výhrad).
     *
     * @param array<string,mixed> $row
     * @return list<string>
     */
    public static function checks(array $row): array
    {
        $checks = [];
        $selfAssessedVat = (float) ($row['self_assessed_vat'] ?? 0);
        if ($selfAssessedVat > 0) {
            $checks[] = 'Reverse charge: k doložení zahrnuje DPH přiznanou odběratelem, která na faktuře není; doložte ji výpočtem k faktuře a rekapitulací přiznání a úhrady za období';
        }
        if ($row['doc_kind'] === 'entry') {
            $checks[] = 'Zápis bez dokladu v evidenci';
        } elseif ($row['document_total'] === null) {
            $checks[] = 'Celková částka dokladu není v evidenci';
        }
        if ($row['mixed_sides'] ?? false) {
            $checks[] = 'V rozsahu jsou účty různých druhů (náklady, výnosy, rozvaha), částka sčítá obě strany';
        }
        if ($row['corrected_outside'] ?? false) {
            $checks[] = 'Storno nebo oprava dokladu leží mimo filtr (jiné období, koncept nebo jiný filtr Deníku)';
        }
        $currency = $row['document_currency'] ?? null;
        if ($currency !== null && $currency !== 'CZK') {
            $checks[] = 'Doklad v cizí měně, celkem v ' . $currency;
        }
        if ((float) $row['amount'] < 0) {
            $checks[] = 'Záporná částka (vratka nebo storno)';
        } elseif ($row['document_total'] !== null && $currency === 'CZK'
            && round(abs((float) $row['amount'] - $selfAssessedVat) - abs((float) $row['document_total']), 2) > 0) {
            $checks[] = 'K doložení převyšuje celkovou částku dokladu';
        }
        return $checks;
    }

    private static function number(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}
