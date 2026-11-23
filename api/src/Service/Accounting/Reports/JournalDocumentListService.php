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
            'totals'    => ['amount' => round($amount, 2), 'count' => count($documents), 'to_check' => $toCheck],
        ];
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
            && round(abs((float) $row['amount']) - abs((float) $row['document_total']), 2) > 0) {
            $checks[] = 'K doložení převyšuje celkovou částku dokladu';
        }
        return $checks;
    }

    private static function number(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}
