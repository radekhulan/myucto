<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Dimension\DimensionValueLabel;
use MyInvoice\Service\Accounting\ManualJournalEntryException;
use MyInvoice\Service\Accounting\ManualJournalEntryService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\UnbalancedEntryException;

/**
 * Kontrola návrhu interního dokladu od AI proti stavu firmy, stejnými pravidly, jakými
 * zaúčtování projde ruční interní doklad ({@see \MyInvoice\Service\Accounting\InternalDocumentService::post()}):
 * řádky jdou přes {@see ManualJournalEntryService::normalizeLines()} (tvar, dimenze)
 * a nanečisto přes {@see PostingService::prepareDocumentLines()} se zdrojem `manual`
 * (účet v osnově k datu, aktivní účet, závěrkové účty, podrozvaha, vyrovnanost).
 * Období a zámek k datu čte stejně jako zaúčtování: období musí být otevřené
 * (chybějící období zaúčtování založí otevřené), datum nesmí ležet v zamčeném úseku.
 *
 * Nic nezapisuje. Výsledek je jen náhled pro uživatele; skutečné zaúčtování kontroly
 * provede znovu pod zámky.
 */
final class AiInternalDocumentValidator
{
    public const MAX_PROMPT_DIMENSION_VALUES = 300;

    public function __construct(
        private readonly ManualJournalEntryService $manualEntries,
        private readonly PostingService $posting,
        private readonly AccountingPeriodRepository $periods,
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly DimensionService $dimensions,
        private readonly DimensionRepository $dimensionRepo,
    ) {}

    /**
     * Aktivní hodnoty dimenzí pro zadání modelu. Prázdné, když firma dimenze nepoužívá.
     *
     * @return array<int,string> id hodnoty => „Typ: kód název", systémová hodnota „Typ: název"
     */
    public function dimensionChoices(int $supplierId): array
    {
        if (!$this->dimensions->enabled($supplierId)) {
            return [];
        }
        $types = [];
        foreach ($this->dimensionRepo->listTypes($supplierId, false) as $t) {
            $types[(int) $t['id']] = (string) $t['name'];
        }
        $chosen = [];
        $byType = [];
        foreach ($this->dimensionRepo->listValues($supplierId, null, false) as $v) {
            if (!isset($types[(int) $v['type_id']])) {
                continue;
            }
            $chosen[(int) $v['id']] = (int) $v['type_id'];
            $byType[(int) $v['type_id']][(int) $v['id']] = $v;
            if (count($chosen) >= self::MAX_PROMPT_DIMENSION_VALUES) {
                break;
            }
        }
        $labels = [];
        foreach ($byType as $values) {
            $labels += DimensionValueLabel::fullAll($values);
        }
        $out = [];
        foreach ($chosen as $id => $typeId) {
            $out[$id] = $types[$typeId] . ': ' . $labels[$id];
        }
        return $out;
    }

    /**
     * Doplní ke každému návrhu z {@see AiInternalDocumentNormalizer} nálezy (`problems`),
     * příznak `postable` a u řádků dimenze ve tvaru agendy (typ => hodnota) s popisky.
     *
     * @param list<array<string,mixed>> $documents
     * @return list<array<string,mixed>>
     */
    public function validate(int $supplierId, array $documents): array
    {
        if ($documents === []) {
            return [];
        }
        $lockedUntil = $this->settings->getLockedUntil($supplierId);
        $labels = null;
        $out = [];
        foreach ($documents as $doc) {
            $problems = (array) $doc['problems'];
            $raw = [];
            foreach ($doc['lines'] as $line) {
                $raw[] = array_filter([
                    'account_code' => $line['account_code'],
                    'side' => $line['side'],
                    'amount' => $line['amount'],
                    'dimensions' => $line['dimension_value_ids'] ?? [],
                ], static fn ($v): bool => $v !== []);
            }
            $prepared = null;
            try {
                $prepared = $this->manualEntries->normalizeLines($supplierId, $raw);
            } catch (ManualJournalEntryException $e) {
                $problems[] = $e->getMessage();
            }
            $lines = [];
            foreach ($doc['lines'] as $i => $line) {
                unset($line['dimension_value_ids']);
                $dims = $prepared[$i]['dimensions'] ?? [];
                if ($dims !== []) {
                    $labels ??= $this->dimensionChoices($supplierId);
                }
                $line['dimensions'] = $dims;
                $line['dimension_labels'] = array_values(array_map(
                    static fn (int $valueId): string => $labels[$valueId] ?? ('#' . $valueId),
                    $dims,
                ));
                $lines[] = $line;
            }
            $doc['lines'] = $lines;

            $date = (string) $doc['entry_date'];
            $period = $this->periods->findForDate($supplierId, $date);
            if ($period !== null && $period['status'] !== 'open') {
                $problems[] = 'Účetní období ' . $period['fiscal_year'] . ' je ve stavu „' . $period['status']
                    . '", do uzavřeného období nelze účtovat (§ 35 ZoÚ).';
            }
            if ($lockedUntil !== null && $date <= $lockedUntil) {
                $problems[] = 'Datum účetního případu ' . $date . ' je uzamčené k ' . $lockedUntil . '.';
            }
            if ($problems === [] && $prepared !== null) {
                try {
                    $this->posting->prepareDocumentLines($supplierId, 'manual', null, $prepared, $date);
                } catch (PostingException|UnbalancedEntryException|DimensionException $e) {
                    $problems[] = $e->getMessage();
                }
            }
            $doc['problems'] = array_values(array_unique($problems));
            $doc['postable'] = $doc['problems'] === [];
            $out[] = $doc;
        }
        return $out;
    }
}
