<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\Bank\BankAnalyticAssigner;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\Product\IssuedDiscountAllocation;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard;
use MyInvoice\Service\Invoice\InvoiceItemKind;
use PDO;

/**
 * Dimenze zdrojového dokladu → řádky účetního zápisu (Firma → Dimenze).
 *
 * Volá ho {@see \MyInvoice\Service\Accounting\PostingService::postDocument()} pro
 * každý zápis, stejně jako razítko zakázky: zápis vzniká mnoha cestami a dimenze,
 * kterou by razítkovala jen část z nich, by sestavy po dimenzích tiše zkreslila.
 *
 * Pravidla:
 *   • Hlavička dokladu platí pro všechny řádky zápisu. Typ, který hlavička nemá,
 *     doplní výchozí dimenze zakázky, jinak klienta ({@see DimensionDefaults}) —
 *     i u dokladů z importu, vytěžení a automatizací, které editor neviděly.
 *   • Dimenze položky přebíjí hlavičku (typ po typu) na výsledkových řádcích
 *     (náklad, výnos). Nesou-li položky jednoho výsledkového řádku různé dimenze,
 *     řádek se při zaúčtování rozdělí v poměru základu položek — účet, strana
 *     a součet zůstávají, zápis zůstává vyvážený na haléř.
 *   • Položka bez vlastní hodnoty typu dostane výchozí dimenzi svého produktu
 *     (produkt > kategorie, {@see DimensionDefaults::forProducts()}); pořadí je tedy
 *     položka > produkt > kategorie > hlavička > zakázka > klient > pravidlo.
 *   • Dimenze, kterou řádek přinesl sám (ruční zápis), má přednost.
 *   • Hodnota Střediska navázaná na číselník středisek doplní textový
 *     `cost_center`, hodnota Projektu navázaná na zakázku doplní `project_id` —
 *     jen tam, kde řádek žádný nemá (mzdové kódy středisek zůstávají).
 *
 * Přerazítkování už zaúčtovaného dokladu ({@see restamp()}) řádky nedělí: změnilo by
 * to částky řádků, a to smí jen přeúčtování. Když by dělení bylo potřeba, vrátí
 * `needs_repost` a řádek dostane jen hlavičkové dimenze.
 */
final class DimensionStamper
{
    /**
     * Zdroj zápisu v deníku => [typ dokladu v document_dimensions, tabulka položek, sloupec dokladu].
     * Zápisy majetku nesou dimenze karty (odpis má za zdroj řádek depreciation_entries,
     * kartu dohledá {@see DimensionDefaults::assetId()}). Zápočty vlastní dimenze nemají:
     * vzájemný zápočet je přebírá ze započtených dokladů po stranách, zápočet proti účtu
     * z vyrovnávané faktury ({@see DimensionDefaults::forSource()}).
     */
    public const SOURCES = [
        'purchase_invoice' => ['purchase_invoice', 'purchase_invoice_items', 'purchase_invoice_id'],
        'invoice' => ['invoice', 'invoice_items', 'invoice_id'],
        'cash' => ['cash_document', null, null],
        'bank' => ['bank_transaction', null, null],
        'other_item' => ['other_item', null, null],
        'asset' => ['asset', null, null],
        'asset_disposal' => ['asset', null, null],
        'depreciation' => ['asset', null, null],
        'offset' => [null, null, null],
        'settlement' => [null, null, null],
    ];

    /** @var array<int,bool> */
    private array $enabledCache = [];

    private array $serviceCostCandidates = [];

    private ?DimensionRuleService $rules = null;

    private ?DimensionDefaults $defaults = null;

    /**
     * Zdroje z Účtování podle dimenzí F4. Přerazítkování jejich zápisů přepisuje jen typy,
     * které určuje doklad (nebo které doklad dřív určoval), ruční dimenze řádků ostatních
     * typů zůstávají ({@see restamp()}).
     */
    public const KEEP_LINE_TYPES = ['other_item', 'asset', 'asset_disposal', 'depreciation', 'offset', 'settlement'];

    public function __construct(private readonly Connection $db) {}

    /** Sdílená instance — v rámci běhu drží cache vazeb (karta majetku odpisu). */
    private function defaults(): DimensionDefaults
    {
        return $this->defaults ??= new DimensionDefaults($this->db);
    }

    public function enabled(int $supplierId): bool
    {
        return $this->enabledCache[$supplierId] ??= (new DimensionRepository($this->db))->enabled($supplierId);
    }

    public function rules(): DimensionRuleService
    {
        return $this->rules ??= new DimensionRuleService($this->db);
    }

    /**
     * @param list<array<string,mixed>> $resolved řádky po překladu účtů (account_id, side, amount, …)
     * @param array<int,int>|null $itemAccounts id položky => id účtu, na který se položka účtuje (je-li známé)
     * @param string|null $entryDate datum účetního případu — podle něj platí pravidla dimenzí (null = pravidla se neuplatní)
     * @return list<array<string,mixed>>
     */
    public function stamp(int $supplierId, string $sourceType, ?int $sourceId, array $resolved, ?array $itemAccounts = null, ?string $entryDate = null): array
    {
        if ($sourceType === 'bank' && $sourceId !== null && $this->enabled($supplierId)) {
            $resolved = $this->allocateBankDocuments($supplierId, $sourceId, $resolved);
        }
        if ($sourceType === 'offset' && $sourceId !== null && $this->enabled($supplierId)) {
            $resolved = $this->allocateOffsetDocuments($supplierId, $sourceId, $resolved);
        }
        if ($sourceType === 'cash' && $sourceId !== null && $this->enabled($supplierId)) {
            $resolved = $this->allocateCashDocuments($supplierId, $sourceId, $resolved);
        }
        $hasExplicit = false;
        foreach ($resolved as $line) {
            if (!empty($line['dimensions']) || !empty($line['dimension_splits'])) {
                $hasExplicit = true;
                break;
            }
        }
        $enabled = $this->enabled($supplierId);
        if (!$hasExplicit && !$enabled) {
            return $resolved;
        }
        [$header, $items, $splits, , $headerOrigins, $splitOrigins, , $bankAccount] = $sourceId !== null && isset(self::SOURCES[$sourceType]) && $enabled
            ? $this->documentContext($supplierId, $sourceType, $sourceId, $itemAccounts)
            : [[], [], [], [], [], [], [], null];
        if ($header !== [] || $items !== [] || $hasExplicit) {
            $resolved = self::expandSplits(
                self::assign($resolved, $header, $items, $this->accountTypes($supplierId), true, $headerOrigins,$this->serviceCostAccounts($supplierId, $sourceType, $entryDate, $resolved))['lines'],
                $splits,
                $splitOrigins,
            );
        }
        if ($enabled && $entryDate !== null) {
            // Výchozí hodnoty pravidel dimenzí až po dokladu — doklad má vždy přednost.
            $resolved = $this->rules()->applyDefaults($supplierId, $sourceType, $sourceId, $resolved, $entryDate);
        }
        if ($bankAccount !== null) {
            $resolved = self::fillBankAccount($resolved, $bankAccount, $this->lineAccountCodes($supplierId, $resolved), $this->transferCodes($supplierId));
        }
        return $this->syncLinkedColumns($supplierId, $resolved);
    }

    /**
     * Dimenze, které zaúčtování dá výsledkovému řádku každé položky dokladu: položka >
     * produkt > hlavička > zakázka > klient (rozpad jako `dimension_splits`). Výchozí
     * hodnoty pravidel se tu neuplatní — závisí na účtu, doplní je volající přes
     * {@see DimensionRuleService::applyDefaults()}.
     *
     * Potřebuje ji časové rozlišení (381/384): odkládá náklad položky a musí ho vzít
     * z téže analytiky, na kterou ho zaúčtovala účtotvorná dimenze.
     *
     * @return array<int,array{dimensions?:array<int,int>, dimension_splits?:array<int,array<int,float>>}> id položky => dimenze
     */
    public function itemDimensions(int $supplierId, string $sourceType, int $sourceId): array
    {
        if (!isset(self::SOURCES[$sourceType]) || self::SOURCES[$sourceType][1] === null || !$this->enabled($supplierId)) {
            return [];
        }
        [$header, , $splits, $byItem, $headerOrigins, $splitOrigins, $byItemOrigins] = $this->documentContext($supplierId, $sourceType, $sourceId, null);
        $out = [];
        foreach ($byItem as $itemId => $dims) {
            $line = self::expandSplits([['dimensions' => self::merge($header, $dims), 'dimension_origins' => array_replace($headerOrigins, $byItemOrigins[$itemId] ?? [])]], $splits, $splitOrigins)[0];
            $out[$itemId] = array_intersect_key($line, ['dimensions' => 1, 'dimension_splits' => 1, 'dimension_origins' => 1, 'dimension_split_origins' => 1]);
        }
        return $out;
    }

    /**
     * Doplní textové středisko a zakázku z dimenzí řádků (viz stamp()) — pro řádky,
     * které po razítkování ještě změnily dimenze (dělení účtotvornou dimenzí).
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    public function linkColumns(int $supplierId, array $lines): array
    {
        return $this->syncLinkedColumns($supplierId, $lines);
    }

    /**
     * Rozpad dokladu (hlavička nebo položka) jede jádrem {@see assign()} jako zástupná
     * záporná „hodnota" (-1 = první rozpad dokladu): položky se stejným rozpadem se tak
     * seskupí stejně jako položky se stejnou hodnotou. Tady se zástupná hodnota nahradí
     * rozpadem řádku (`dimension_splits`). Rozpad zadaný přímo na řádku (ruční zápis)
     * přebíjí jedinou hodnotu téhož typu.
     *
     * @param list<array<string,mixed>> $lines
     * @param list<array<int,float>> $splits zástupný index => hodnota => podíl
     * @return list<array<string,mixed>>
     */
    public static function expandSplits(array $lines, array $splits, array $splitOrigins = []): array
    {
        foreach ($lines as $i => $line) {
            $dims = (array) ($line['dimensions'] ?? []);
            $lineSplits = (array) ($line['dimension_splits'] ?? []);
            foreach ($dims as $typeId => $valueId) {
                if ((int) $valueId < 0) {
                    unset($dims[$typeId]);
                    if (!isset($lineSplits[$typeId]) && isset($splits[-(int) $valueId - 1])) {
                        $lineSplits[$typeId] = $splits[-(int) $valueId - 1];
                        if ($splitOrigins !== []) {
                            foreach ($lineSplits[$typeId] as $splitValueId => $_) $lines[$i]['dimension_split_origins'][$typeId][$splitValueId] = DimensionAssignmentOrigin::normalize($splitOrigins[-(int) $valueId - 1][$splitValueId] ?? []);
                        }
                    }
                } elseif (isset($lineSplits[$typeId])) {
                    unset($dims[$typeId]);
                }
            }
            unset($lines[$i]['dimensions'], $lines[$i]['dimension_splits']);
            if (isset($lines[$i]['dimension_origins'])) $lines[$i]['dimension_origins'] = array_intersect_key($lines[$i]['dimension_origins'], $dims);
            if (isset($lines[$i]['dimension_split_origins'])) $lines[$i]['dimension_split_origins'] = array_intersect_key($lines[$i]['dimension_split_origins'], $lineSplits);
            if ($dims !== []) {
                $lines[$i]['dimensions'] = $dims;
            }
            if ($lineSplits !== []) {
                ksort($lineSplits);
                $lines[$i]['dimension_splits'] = $lineSplits;
            }
        }
        return array_values($lines);
    }

    /**
     * Dimenze už zaúčtovaného dokladu se promítnou do jeho (nestornovaných) zápisů.
     *
     * `$keepLineTypes` (vždy u {@see KEEP_LINE_TYPES}): řádek si ponechá dimenze typů,
     * které doklad neurčuje — ruční středisko na řádku odpisu tak uložení karty nesmaže.
     * Typ z `$released` (typ => hodnota, null = rozpad), který doklad určoval dřív a teď
     * už ne, se z řádku odebere, jen když tam pořád je tatáž hodnota (resp. rozpad).
     *
     * `account_change` = dimenze by změnila analytický účet (účtotvorná dimenze) —
     * dotčené řádky se nepřerazítkovaly a doklad potřebuje přeúčtovat. Posuzují se
     * výsledné dimenze řádku (po zachování ručních typů), takže ruční středisko
     * řádku, které doklad neurčuje, konflikt nevyvolá.
     *
     * @param array<int,?int> $released
     * @param array<string,string> $singleAnalytics syntetika => jediná analytika (přesměr PostingService)
     * @return array{lines:int, needs_repost:bool, account_change:bool}
     */
    public function restamp(int $supplierId, string $sourceType, int $sourceId, bool $keepLineTypes = false, array $released = [], array $singleAnalytics = []): array
    {
        $keepLineTypes = $keepLineTypes || in_array($sourceType, self::KEEP_LINE_TYPES, true);
        if (!isset(self::SOURCES[$sourceType]) || !$this->enabled($supplierId)) {
            return ['lines' => 0, 'needs_repost' => false, 'account_change' => false];
        }
        return (new DimensionPeriodGuard($this->db))->transaction(
            fn (): array => $this->restampLocked($supplierId, $sourceType, $sourceId, $keepLineTypes, $released, $singleAnalytics),
        );
    }

    private function restampLocked(int $supplierId, string $sourceType, int $sourceId, bool $keepLineTypes, array $released, array $singleAnalytics): array
    {
        $pdo = $this->db->pdo();
        $guard = new DimensionPeriodGuard($this->db);
        (new SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        (new IssuanceUseGuard($this->db))->lockClaims($supplierId);
        (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->acquire($supplierId);
        $lockedEntries = $guard->lockSources($supplierId, [[$sourceType, $sourceId]]);
        $entryDates = [];
        foreach ($lockedEntries as $entryId => $entry) {
            if ($entry['reversed_by'] === null) {
                $entryDates[$entryId] = (string) $entry['entry_date'];
            }
        }
        if ($entryDates === []) {
            return ['lines' => 0, 'needs_repost' => false, 'account_change' => false];
        }
        // Přerazítkování bere i deaktivovaný bankovní účet: pohyb na něm už je zaúčtovaný.
        [$header, $items, $splits, , $headerOrigins, $splitOrigins, , $bankAccount] = $this->documentContext($supplierId, $sourceType, $sourceId, null, true, true);
        $types = $this->accountTypes($supplierId);
        $assignments = new DimensionAssignmentRepository($this->db);
        $router = new DimensionAccountRouter($this->db);
        $changed = 0;
        $needsRepost = false;
        $accountChange = false;
        foreach ($entryDates as $entryId => $entryDate) {
            $stmt = $pdo->prepare(
                'SELECT id, account_id, side, amount, currency_code FROM journal_entry_lines
                  WHERE supplier_id = ? AND entry_id = ? ORDER BY line_no, id FOR UPDATE'
            );
            $stmt->execute([$supplierId, $entryId]);
            $current = $assignments->entryLineDimensions($supplierId, $entryId, true);
            $originalDims = $current;
            $originalOrigins = $assignments->entryLineOrigins($supplierId, $entryId, true);
            $originalSplitOrigins = $assignments->entryLineSplitOrigins($supplierId, $entryId, true);
            $originalSplits = $assignments->entryLineSplits($supplierId, $entryId, true);
            // Rozpad, který řádek už nese, se pro porovnání převede na zástupnou hodnotu
            // téhož rozpadu dokladu — řádek rozdělený při zaúčtování si ho ponechá.
            foreach ($originalSplits as $lineId => $byType) {
                foreach ($byType as $typeId => $shares) {
                    foreach ($splits as $k => $docShares) {
                        if (DimensionAssignmentRepository::sameSplits([$typeId => $shares], [$typeId => $docShares])) {
                            $current[$lineId][$typeId] = -($k + 1);
                            break;
                        }
                    }
                }
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Řádek rozdělený při zaúčtování mohla účtotvorná dimenze rozeslat na různé
            // analytiky téže syntetiky — sourozence proto počítá syntetika, ne analytika.
            $siblingAccount = $router->siblingAccounts($supplierId, $entryDate, $singleAnalytics, true);
            $siblingKey = static fn (array $l): string
                => ($siblingAccount[(int) $l['account_id']] ?? (int) $l['account_id']) . '|' . $l['side'];
            $siblings = [];
            foreach ($rows as $l) {
                $key = $siblingKey($l);
                $siblings[$key] = ($siblings[$key] ?? 0) + 1;
            }
            $lines = array_map(static function (array $l) use ($current, $originalOrigins, $siblings, $siblingKey): array {
                $l['current_dimensions'] = $current[(int) $l['id']] ?? [];
                $l['current_dimension_origins'] = $originalOrigins[(int) $l['id']] ?? [];
                $l['split_siblings'] = $siblings[$siblingKey($l)];
                return $l;
            }, $rows);
            if ($sourceType === 'bank') {
                $lines = $this->allocateBankDocuments($supplierId, $sourceId, $lines);
            }
            if ($sourceType === 'offset') {
                $lines = $this->allocateOffsetDocuments($supplierId, $sourceId, $lines);
            }
            if ($sourceType === 'cash') {
                $lines = $this->allocateCashDocuments($supplierId, $sourceId, $lines);
            }
            $result = self::assign($lines, $header, $items, $types, false, $headerOrigins,$this->serviceCostAccounts($supplierId, $sourceType, $entryDate, $lines));
            $needsRepost = $needsRepost || $result['needs_split'];
            $restamped = $this->rules()->applyDefaults(
                $supplierId,
                $sourceType,
                $sourceId,
                self::expandSplits($result['lines'], $splits, $splitOrigins),
                $entryDate,
            );
            if ($bankAccount !== null) {
                $restamped = self::fillBankAccount($restamped, $bankAccount, $this->lineAccountCodes($supplierId, $restamped), $this->transferCodes($supplierId));
            }
            // Výsledné dimenze řádku (u zachovaných ručních typů po sloučení s řádkem).
            foreach ($restamped as $i => $line) {
                if (!$keepLineTypes) {
                    continue;
                }
                $lineId = (int) $line['id'];
                [$dims, $lineSplits] = self::keepLineTypes(
                    $originalDims[$lineId] ?? [],
                    $originalSplits[$lineId] ?? [],
                    array_map('intval', (array) ($line['dimensions'] ?? [])),
                    (array) ($line['dimension_splits'] ?? []),
                    $released,
                );
                unset($restamped[$i]['dimensions'], $restamped[$i]['dimension_splits']);
                if ($dims !== []) {
                    $restamped[$i]['dimensions'] = $dims;
                    $restamped[$i]['dimension_origins'] = array_intersect_key(($line['dimension_origins'] ?? []) + ($originalOrigins[$lineId] ?? []), $dims);
                }
                if ($lineSplits !== []) {
                    $restamped[$i]['dimension_splits'] = $lineSplits;
                    $restamped[$i]['dimension_split_origins'] = array_intersect_key(($line['dimension_split_origins'] ?? []) + ($originalSplitOrigins[$lineId] ?? []), $lineSplits);
                }
            }
            // Účtotvorná dimenze: nová hodnota, která by změnila analytický účet nebo
            // rozdělení řádků mezi analytiky, se tiše nepřerazítkuje — řádek si ponechá
            // dosavadní dimenze a doklad potřebuje přeúčtovat (needs_repost).
            $old = array_fill_keys(array_map(static fn (array $r): int => (int) $r['id'], $rows), []);
            foreach ($originalDims as $lineId => $dims) {
                $old[$lineId]['dimensions'] = $dims;
            }
            foreach ($originalSplits as $lineId => $byType) {
                $old[$lineId]['dimension_splits'] = $byType;
            }
            $projected = $router->projectForEntry($supplierId, $entryDate, array_values($restamped), $singleAnalytics, $old, true);
            $restamped = $projected['lines'];
            if ($projected['conflicts'] !== []) {
                $needsRepost = true;
                $accountChange = true;
                $restamped = array_values(array_filter(
                    $restamped,
                    static fn (array $l): bool => !in_array((int) $l['id'], $projected['conflicts'], true),
                ));
            }
            $prospective = array_column($restamped, null, 'id');
            foreach ($rows as $row) {
                $lineId = (int) $row['id'];
                if (!isset($prospective[$lineId])) {
                    $prospective[$lineId] = $row + [
                        'dimensions' => $originalDims[$lineId] ?? [],
                        'dimension_splits' => $originalSplits[$lineId] ?? [],
                    ];
                }
            }
            $hasChanges = false;
            foreach ($prospective as $lineId => $line) {
                $before = $originalDims[$lineId] ?? [];
                $after = $line['dimensions'] ?? [];
                ksort($before);
                ksort($after);
                if ($before !== $after || !DimensionAssignmentRepository::sameSplits($originalSplits[$lineId] ?? [], $line['dimension_splits'] ?? [])) {
                    $hasChanges = true;
                    break;
                }
            }
            if ($hasChanges) {
                (new SystemDimensionWritePolicy($this->db))->assertJournal(
                    $lockedEntries[$entryId] + ['supplier_id' => $supplierId],
                    array_values($prospective),
                    array_map(static fn (array $row): array => $row + [
                        'dimensions' => $originalDims[(int) $row['id']] ?? [],
                        'dimension_splits' => $originalSplits[(int) $row['id']] ?? [],
                    ], $rows),
                );
            }
            foreach ($restamped as $line) {
                $lineId = (int) $line['id'];
                $dims = $line['dimensions'] ?? [];
                $lineSplits = $line['dimension_splits'] ?? [];
                $before = $originalDims[$lineId] ?? [];
                ksort($before);
                ksort($dims);
                if ($before === $dims && DimensionAssignmentRepository::sameSplits($originalSplits[$lineId] ?? [], $lineSplits)) {
                    continue;
                }
                $guard->assertMutable($lockedEntries[$entryId]);
                $dimsChanged = $assignments->replaceLineDimensions($supplierId, $lineId, $dims, $line['dimension_origins'] ?? []);
                $splitsChanged = $assignments->replaceLineSplits($supplierId, $lineId, $lineSplits, $line['dimension_split_origins'] ?? []);
                if ($dimsChanged || $splitsChanged) {
                    $changed++;
                }
            }
        }
        return ['lines' => $changed, 'needs_repost' => $needsRepost, 'account_change' => $accountChange];
    }

    /**
     * Sloučí dimenze řádku s tím, co určuje doklad: typy dokladu (hodnota i rozpad)
     * přepíšou řádek, ostatní typy řádku zůstanou. Uvolněný typ (doklad ho dřív
     * určoval, teď ne) se odebere, jen když řádek pořád nese uvolněnou hodnotu.
     *
     * @param array<int,int> $lineDims
     * @param array<int,array<int,float>> $lineSplits
     * @param array<int,int> $docDims
     * @param array<int,array<int,float>> $docSplits
     * @param array<int,?int> $released typ => hodnota, null = rozpad
     * @return array{0:array<int,int>, 1:array<int,array<int,float>>}
     */
    public static function keepLineTypes(array $lineDims, array $lineSplits, array $docDims, array $docSplits, array $released): array
    {
        foreach ($released as $typeId => $valueId) {
            if (isset($docDims[$typeId]) || isset($docSplits[$typeId])) {
                continue;
            }
            if ($valueId === null) {
                unset($lineSplits[$typeId]);
            } elseif (($lineDims[$typeId] ?? null) === (int) $valueId) {
                unset($lineDims[$typeId]);
            }
        }
        foreach ($docDims as $typeId => $valueId) {
            $lineDims[$typeId] = (int) $valueId;
            unset($lineSplits[$typeId]);
        }
        foreach ($docSplits as $typeId => $shares) {
            $lineSplits[$typeId] = $shares;
            unset($lineDims[$typeId]);
        }
        ksort($lineDims);
        ksort($lineSplits);
        return [$lineDims, $lineSplits];
    }

    /**
     * Čisté jádro rozpadu (jednotkově testovatelné).
     *
     * @param list<array<string,mixed>> $lines
     * @param array<int,int> $header typ => hodnota
     * @param list<array{dims:array<int,int>, weight:float, account_id:?int}> $items
     * @param array<int,string> $accountTypes id účtu => account_type
     * @return array{lines:list<array<string,mixed>>, needs_split:bool}
     */
    public static function assign(array $lines, array $header, array $items, array $accountTypes, bool $allowSplit, array $headerOrigins = [], array $serviceCostAccounts = []): array
    {
        $out = [];
        $needsSplit = false;
        foreach ($lines as $line) {
            $explicit = array_map('intval', (array) ($line['dimensions'] ?? []));
            $type = $accountTypes[(int) $line['account_id']] ?? null;
            $combos = [];
            if ($items !== [] && (in_array($type, ['revenue', 'expense'], true)||isset($serviceCostAccounts[(int)$line['account_id']]))) {
                $relevant = array_values(array_filter(
                    $items,
                    static fn (array $it): bool => $it['account_id'] === null || $it['account_id'] === (int) $line['account_id'],
                ));
                if ($relevant === []) {
                    $relevant = $items;
                }
                foreach ($relevant as $it) {
                    $dims = self::merge($header, $it['dims']);
                    $key = self::key($dims);
                    $origins = array_replace($headerOrigins, $it['origins'] ?? []);
                    if (isset($combos[$key]['origins'])) {
                        foreach ($dims as $typeId => $_) {
                            if (DimensionAssignmentOrigin::normalize($combos[$key]['origins'][$typeId] ?? []) !== DimensionAssignmentOrigin::normalize($origins[$typeId] ?? [])) $origins[$typeId] = DimensionAssignmentOrigin::normalize();
                        }
                    }
                    $combos[$key]['origins'] = $origins;
                    $combos[$key]['dims'] = $dims;
                    $combos[$key]['weight'] = round(($combos[$key]['weight'] ?? 0.0) + $it['weight'], 2);
                }
            }

            if (count($combos) <= 1) {
                $dims = $combos === [] ? $header : reset($combos)['dims'];
                $out[] = self::withDims($line, self::merge($dims, $explicit), $combos === [] ? $headerOrigins : reset($combos)['origins']);
                continue;
            }

            $weights = array_column($combos, 'weight');
            $splittable = min($weights) > 0.0 && ($line['currency_code'] ?? null) === null;
            // Řádek rozdělený už při zaúčtování nese jednu z kombinací — tu si ponechá.
            // Rozdělený je jen tehdy, když má zápis na tomtéž účtu a straně aspoň tolik
            // řádků, kolik je kombinací; jediný nerozdělený řádek by si jinak ponechal
            // dimenzi jedné položky pro celou částku a zbytek by v sestavách chyběl.
            $current = isset($line['current_dimensions']) ? self::merge([], (array) $line['current_dimensions']) : null;
            $wasSplit = ($line['split_siblings'] ?? PHP_INT_MAX) >= count($combos);
            if (!$allowSplit && $current !== null && $wasSplit && isset($combos[self::key(self::merge($current, $explicit))])) {
                $out[] = self::withDims($line, self::merge($current, $explicit), $line['current_dimension_origins'] ?? []);
                continue;
            }
            if (!$allowSplit || !$splittable) {
                $needsSplit = true;
                $out[] = self::withDims($line, self::merge($header, $explicit), $headerOrigins);
                continue;
            }
            $cents = self::distributeCents(JournalLineAmount::cents($line['amount']), $weights);
            foreach (array_values($combos) as $i => $combo) {
                if ($cents[$i] === 0) {
                    continue;
                }
                $part = $line;
                $part['amount'] = $cents[$i] / 100;
                $out[] = self::withDims($part, self::merge($combo['dims'], $explicit), $combo['origins']);
            }
        }
        return ['lines' => $out, 'needs_split' => $needsSplit];
    }

    /**
     * Pohyb hradící víc dokladů: řádek saldokonta, jehož částka odpovídá alokaci dokladu,
     * nese dimenze toho dokladu. Ostatní řádky (banka, zaokrouhlení, kurzový rozdíl)
     * dostanou typ, ve kterém se doklady liší, jako rozpad v poměru alokací — jen když
     * hodnotu toho typu mají všechny doklady. Dimenze zadaná na pohybu přebíjí obojí.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function allocateBankDocuments(int $supplierId, int $txId, array $lines): array
    {
        $bank = $this->defaults()->bankDocuments($supplierId, $txId, true);
        $documents = $bank['documents'];
        if (count($documents) < 2) {
            return $lines;
        }
        $assignments = new DimensionAssignmentRepository($this->db);
        $ownTypes = $assignments->documentDimensions($supplierId, 'bank_transaction', $txId)['header'];
        foreach ($assignments->documentSplits($supplierId, 'bank_transaction', $txId)[0] ?? [] as $typeId => $_) {
            $ownTypes[$typeId] = 0;
        }
        return self::allocateDocuments($lines, $documents, $bank['incoming'] ? 'credit' : 'debit', $ownTypes, 'bank:' . $txId);
    }

    /**
     * Pokladní doklad hradící víc ostatních pohledávek nebo závazků: stejně jako
     * {@see allocateBankDocuments()}.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function allocateCashDocuments(int $supplierId, int $cashId, array $lines): array
    {
        $cash = $this->defaults()->cashDocuments($supplierId, $cashId, true);
        if (count($cash['documents']) < 2) {
            return $lines;
        }
        $assignments = new DimensionAssignmentRepository($this->db);
        $ownTypes = $assignments->documentDimensions($supplierId, 'cash_document', $cashId)['header'];
        foreach ($assignments->documentSplits($supplierId, 'cash_document', $cashId)[0] ?? [] as $typeId => $_) {
            $ownTypes[$typeId] = 0;
        }
        return self::allocateDocuments($lines, $cash['documents'], $cash['incoming'] ? 'credit' : 'debit', $ownTypes, 'cash:' . $cashId);
    }

    /**
     * Vzájemný zápočet: řádek na straně Dal (pohledávka) nese dimenze započtených vydaných
     * faktur, řádek na straně Má dáti (závazek) dimenze přijatých faktur. Typ, ve kterém
     * se doklady jedné strany liší, dostane řádek jako rozpad v poměru započtených částek.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function allocateOffsetDocuments(int $supplierId, int $agreementId, array $lines): array
    {
        $bySide = ['credit' => [], 'debit' => []];
        foreach ($this->defaults()->offsetDocuments($supplierId, $agreementId) as $doc) {
            $bySide[$doc['doc_type'] === 'invoice' ? 'credit' : 'debit'][] = $doc;
        }
        foreach ($lines as $i => $line) {
            $side = (string) ($line['side'] ?? '');
            if (!isset($bySide[$side]) || $bySide[$side] === []) {
                continue;
            }
            [$dims, $splits] = self::commonDocumentDimensions($bySide[$side]);
            $module = ['origin' => 'module', 'ref' => 'offset:' . $agreementId];
            foreach (array_diff_key($dims, $line['dimensions'] ?? []) as $typeId => $_) {
                $lines[$i]['dimension_origins'][$typeId] = $module;
            }
            foreach (array_diff_key($splits, ($line['dimension_splits'] ?? []) + ($line['dimensions'] ?? [])) as $typeId => $shares) {
                foreach ($shares as $valueId => $_) {
                    $lines[$i]['dimension_split_origins'][$typeId][$valueId] = $module;
                }
            }
            $dims = self::merge($dims, array_map('intval', (array) ($line['dimensions'] ?? [])));
            $splits = array_diff_key($splits, $dims);
            if ($dims !== []) {
                $lines[$i]['dimensions'] = $dims;
            }
            if ($splits !== []) {
                $lines[$i]['dimension_splits'] = (array) ($line['dimension_splits'] ?? []) + $splits;
            }
        }
        return $lines;
    }

    /**
     * Dimenze společné dokladům: typ se stejnou hodnotou u všech dokladů jako hodnota,
     * typ, který mají všechny doklady (hodnotou nebo rozpadem), ale s různou hodnotou,
     * jako rozpad v poměru částek (rozpad dokladu se rozpočítá jeho podíly).
     * Typ, který některému dokladu chybí, se vynechá.
     *
     * @param list<array{amount:float, header:array<int,int>, splits?:array<int,array<int,float>>}> $documents
     * @return array{0:array<int,int>, 1:array<int,array<int,float>>}
     */
    public static function commonDocumentDimensions(array $documents): array
    {
        $types = [];
        foreach ($documents as $doc) {
            $types += $doc['header'] + ($doc['splits'] ?? []);
        }
        $total = array_sum(array_column($documents, 'amount'));
        $dims = [];
        $splits = [];
        foreach (array_keys($types) as $typeId) {
            $weights = [];
            foreach ($documents as $doc) {
                $docWeight = $total > 0.0 ? $doc['amount'] : 1.0;
                if (isset($doc['header'][$typeId])) {
                    $valueId = $doc['header'][$typeId];
                    $weights[$valueId] = ($weights[$valueId] ?? 0.0) + $docWeight;
                } elseif (isset($doc['splits'][$typeId])) {
                    foreach ($doc['splits'][$typeId] as $valueId => $share) {
                        $weights[$valueId] = ($weights[$valueId] ?? 0.0) + $docWeight * (float) $share;
                    }
                } else {
                    continue 2;
                }
            }
            if (count($weights) === 1) {
                $dims[$typeId] = (int) array_key_first($weights);
                continue;
            }
            $sum = array_sum($weights);
            if ($sum <= 0.0) {
                continue;
            }
            $shares = [];
            $assigned = 0.0;
            $last = array_key_last($weights);
            foreach ($weights as $valueId => $weight) {
                $share = $valueId === $last ? round(1.0 - $assigned, 10) : round($weight / $sum, 10);
                $assigned += $share;
                if ($share > 0.0) {
                    $shares[$valueId] = $share;
                }
            }
            $splits[$typeId] = $shares;
        }
        ksort($dims);
        ksort($splits);
        return [$dims, $splits];
    }

    /**
     * Čisté jádro {@see allocateBankDocuments()} (jednotkově testovatelné).
     *
     * @param list<array<string,mixed>> $lines
     * @param list<array{amount:float, header:array<int,int>}> $documents
     * @param array<int,int> $ownTypes typy zadané přímo na pohybu (klíče)
     * @return list<array<string,mixed>>
     */
    public static function allocateDocuments(array $lines, array $documents, string $counterSide, array $ownTypes, ?string $moduleRef = null): array
    {
        $remaining = [];
        foreach ($documents as $i => $doc) {
            $remaining[$i] = (int) round($doc['amount'] * 100);
        }
        $tagged = [];
        foreach ($lines as $li => $line) {
            if (($line['side'] ?? null) !== $counterSide) {
                continue;
            }
            $cents = (int) round(((float) $line['amount']) * 100);
            $docIndex = array_search($cents, $remaining, true);
            if ($docIndex !== false) {
                $tagged[$li] = $docIndex;
                unset($remaining[$docIndex]);
            }
        }

        $total = array_sum(array_column($documents, 'amount'));
        $splits = [];
        $types = [];
        foreach ($documents as $doc) {
            $types += $doc['header'];
        }
        foreach (array_keys($types) as $typeId) {
            if (isset($ownTypes[$typeId]) || $total <= 0.0) {
                continue;
            }
            $weights = [];
            foreach ($documents as $doc) {
                if (!isset($doc['header'][$typeId])) {
                    continue 2;
                }
                $valueId = $doc['header'][$typeId];
                $weights[$valueId] = ($weights[$valueId] ?? 0.0) + $doc['amount'];
            }
            if (count($weights) < 2) {
                continue;
            }
            $shares = [];
            $assigned = 0.0;
            $last = array_key_last($weights);
            foreach ($weights as $valueId => $weight) {
                $share = $valueId === $last ? round(1.0 - $assigned, 10) : round($weight / $total, 10);
                $assigned += $share;
                if ($share > 0.0) {
                    $shares[$valueId] = $share;
                }
            }
            $splits[$typeId] = $shares;
        }

        foreach ($lines as $li => $line) {
            if (isset($tagged[$li])) {
                $dims = self::merge(
                    array_diff_key($documents[$tagged[$li]]['header'], $ownTypes),
                    array_map('intval', (array) ($line['dimensions'] ?? [])),
                );
                if ($dims !== []) {
                    $lines[$li]['dimensions'] = $dims;
                    if ($moduleRef !== null) {
                        $inherited = array_diff_key($documents[$tagged[$li]]['origins'] ?? [], $ownTypes, $line['dimensions'] ?? []);
                        $explicitOrigins = $line['dimension_origins'] ?? [];
                        foreach ($line['dimensions'] ?? [] as $typeId => $_) {
                            $explicitOrigins[$typeId] = DimensionAssignmentOrigin::normalize($explicitOrigins[$typeId] ?? []);
                        }
                        $lines[$li]['dimension_origins'] = array_intersect_key($explicitOrigins + $inherited, $dims);
                    }
                }
                continue;
            }
            if ($splits !== []) {
                $lines[$li]['dimension_splits'] = (array) ($line['dimension_splits'] ?? []) + $splits;
                if ($moduleRef !== null) {
                    foreach (array_diff_key($splits, $line['dimension_splits'] ?? []) as $typeId => $shares) {
                        foreach ($shares as $valueId => $_) {
                            $lines[$li]['dimension_split_origins'][$typeId][$valueId] = ['origin' => 'module', 'ref' => $moduleRef];
                        }
                    }
                }
            }
        }
        return $lines;
    }

    /**
     * Výchozí dimenze vlastního bankovního účtu po řádcích zápisu pohybu. Doplní jen typ,
     * který řádek nemá hodnotou ani rozpadem — dimenze pohybu, hrazeného dokladu
     * i výchozí hodnota pravidla dimenzí podle účtu ({@see DimensionRuleService::applyDefaults()},
     * běží dřív) mají přednost.
     *
     * Dostane ji jen řádek banky (strana pohybu na syntetice účtu výpisu: 221, u kreditní
     * karty 231) a u pohybu, který nehradí doklad, i protiúčet. Řádky saldokonta hrazených
     * dokladů ji nedostanou, jinak by se pohledávka nebo závazek po dimenzi nevyrovnaly
     * s předpisem. Protiúčet na peněžním účtu a penězích na cestě (21x, 22x, 26x a účty
     * pravidel převodu mezi vlastními účty) ji nedostane taky: nohy převodu leží na různých
     * bankovních účtech a 261 se musí po dimenzi vyrovnat, i když mají účty různé výchozí
     * dimenze.
     *
     * @param list<array<string,mixed>> $lines
     * @param array{header:array<int,int>, origins:array<int,array<string,mixed>>, synthetics:list<string>, side:string, pays_documents:bool} $account
     * @param array<int,string> $codes id účtu => kód
     * @param list<string> $transferCodes účty pravidel převodu mezi vlastními účty
     * @return list<array<string,mixed>>
     */
    public static function fillBankAccount(array $lines, array $account, array $codes, array $transferCodes = []): array
    {
        foreach ($lines as $i => $line) {
            $code = $codes[(int) ($line['account_id'] ?? 0)] ?? '';
            $isBank = ($line['side'] ?? null) === $account['side']
                && array_filter($account['synthetics'], static fn (string $synthetic): bool => str_starts_with($code, $synthetic)) !== [];
            if (!$isBank && ($account['pays_documents'] || self::isMoneyAccount($code, $transferCodes))) {
                continue;
            }
            $dims = array_map('intval', (array) ($line['dimensions'] ?? []));
            $lineSplits = (array) ($line['dimension_splits'] ?? []);
            $added = false;
            foreach ($account['header'] as $typeId => $valueId) {
                if (isset($dims[$typeId]) || isset($lineSplits[$typeId])) {
                    continue;
                }
                $dims[$typeId] = $valueId;
                $lines[$i]['dimension_origins'][$typeId] = DimensionAssignmentOrigin::normalize($account['origins'][$typeId] ?? []);
                $added = true;
            }
            if ($added) {
                ksort($dims);
                $lines[$i]['dimensions'] = $dims;
            }
        }
        return array_values($lines);
    }

    /** @param list<string> $transferCodes */
    private static function isMoneyAccount(string $code, array $transferCodes): bool
    {
        if (preg_match('/^2[126]/', $code) === 1) {
            return true;
        }
        foreach ($transferCodes as $transfer) {
            if (str_starts_with($code, $transfer)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<int,string> id účtu => kód
     */
    private function lineAccountCodes(int $supplierId, array $lines): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $l): int => (int) ($l['account_id'] ?? 0), $lines))));
        if ($ids === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute([$supplierId, ...$ids]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = (string) $r['account_code'];
        }
        return $out;
    }

    /** @var array<int,list<string>> */
    private array $transferCodes = [];

    /**
     * Účty pravidel převodu mezi vlastními účty mimo banku (výchozí 261, viz
     * {@see \MyInvoice\Service\Accounting\Bank\TransferPairService}).
     *
     * @return list<string>
     */
    private function transferCodes(int $supplierId): array
    {
        if (!isset($this->transferCodes[$supplierId])) {
            $rules = new PostingRuleRepository($this->db);
            $codes = [];
            foreach (['bank.transfer.own.out', 'bank.transfer.own.in'] as $key) {
                $rule = $rules->resolve($supplierId, $key);
                foreach (['debit_account_code', 'credit_account_code'] as $column) {
                    $code = trim((string) ($rule[$column] ?? ''));
                    if ($code !== '' && !str_starts_with($code, BankAnalyticAssigner::BANK_SYNTHETIC)) {
                        $codes[] = $code;
                    }
                }
            }
            $this->transferCodes[$supplierId] = array_values(array_unique($codes));
        }
        return $this->transferCodes[$supplierId];
    }

    /**
     * Rozdělí haléře podle vah. Kladný zbytek dostane největší váha, záporný
     * se od největších vah postupně odečte jen do výše jejich zaokrouhlených dílů.
     * Součet tak sedí přesně a kladný celek nikdy nevytvoří záporný díl.
     *
     * @param list<float> $weights
     * @return list<int>
     */
    public static function distributeCents(int $total, array $weights): array
    {
        $sign = $total < 0 ? -1 : 1;
        $total = abs($total);
        $sum = array_sum($weights);
        $out = [];
        $assigned = 0;
        foreach ($weights as $w) {
            $c = (int) round($total * ($w / $sum));
            $out[] = $c;
            $assigned += $c;
        }
        $residual = $total - $assigned;
        if ($residual > 0) {
            $biggest = array_keys($weights, max($weights), true)[0];
            $out[$biggest] += $residual;
        } elseif ($residual < 0) {
            $remaining = -$residual;
            $priority = array_keys($weights);
            usort($priority, static fn (int $a, int $b): int => ($weights[$b] <=> $weights[$a]) ?: ($a <=> $b));
            foreach ($priority as $i) {
                $deduction = min($out[$i], $remaining);
                $out[$i] -= $deduction;
                $remaining -= $deduction;
                if ($remaining === 0) {
                    break;
                }
            }
        }
        return $sign === 1 ? $out : array_map(static fn (int $part): int => -$part, $out);
    }

    /**
     * Hlavička, položky a rozpady dokladu. Rozpad se do map typ => hodnota vkládá jako
     * zástupná záporná hodnota, kterou po rozdělení řádků nahradí {@see expandSplits()}.
     *
     * @param array<int,int>|null $itemAccounts
     * Čtvrtý prvek = id položky => její vlastní dimenze (položka > produkt), bez hlavičky.
     *
     * Osmý prvek = výchozí dimenze vlastního bankovního účtu pohybu ({@see fillBankAccount()}).
     *
     * @return array{0:array<int,int>, 1:list<array{dims:array<int,int>, weight:float, account_id:?int}>, 2:list<array<int,float>>, 3:array<int,array<int,int>>}
     */
    private function documentContext(int $supplierId, string $sourceType, int $sourceId, ?array $itemAccounts, bool $forUpdate = false, bool $includeInactiveBankAccount = false): array
    {
        [$docType, $itemTable, $itemColumn] = self::SOURCES[$sourceType];
        $defaults = $this->defaults();
        $docId = $docType === 'asset' ? $defaults->assetId($supplierId, $sourceType, $sourceId) : $sourceId;
        $assignments = new DimensionAssignmentRepository($this->db);
        $dims = $docType !== null && $docId !== null
            ? $assignments->documentDimensions($supplierId, $docType, $docId, $forUpdate)
            : ['header' => [], 'items' => []];
        $docOrigins = $docType !== null && $docId !== null ? $assignments->documentOrigins($supplierId, $docType, $docId, $forUpdate) : ['header' => [], 'items' => []];
        $docSplitOrigins = $docType !== null && $docId !== null ? $assignments->documentSplitOrigins($supplierId, $docType, $docId, $forUpdate) : [];
        $splits = [];
        $splitOrigins = [];
        $docSplits = $docType !== null && $docId !== null ? $assignments->documentSplits($supplierId, $docType, $docId, $forUpdate) : [];
        foreach ($docSplits as $itemNo => $byType) {
            foreach ($byType as $typeId => $shares) {
                $splits[] = $shares;
                $splitOrigins[] = $docSplitOrigins[$itemNo][$typeId] ?? [];
                if ($itemNo === 0) {
                    $dims['header'][$typeId] = -count($splits);
                } else {
                    $dims['items'][$itemNo][$typeId] = -count($splits);
                }
            }
        }
        ksort($dims['header']);
        $items = [];
        $byItem = [];
        $byItemOrigins = [];
        if ($itemTable !== null) {
            // Pořadí položky = pořadí v editoru (order_index), číslováno od 1 — stejně
            // jako ho ukládá DimensionService::saveDocument().
            $issued = $itemTable === 'invoice_items';
            $stmt = $this->db->pdo()->prepare(
                'SELECT id, total_without_vat, stock_item_id'
                . ($issued ? ', item_kind, vat_rate_id, vat_classification_code, revenue_account_code' : '')
                . " FROM {$itemTable} WHERE {$itemColumn} = ? ORDER BY order_index, id" . ($forUpdate ? ' FOR UPDATE' : '')
            );
            $stmt->execute([$sourceId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Položka bez vlastní hodnoty typu dostane výchozí dimenzi svého produktu
            // (produkt > kategorie): položka > produkt > hlavička > zakázka > klient.
            $productDims = $defaults->forProducts(
                $supplierId,
                array_map(static fn (array $r): int => (int) ($r['stock_item_id'] ?? 0), $rows),
                true,
            );
            foreach ($rows as $i => $row) {
                $byItemOrigins[(int) $row['id']] = ($docOrigins['items'][$i + 1] ?? []) + ($productDims[(int) ($row['stock_item_id'] ?? 0)]['origins'] ?? []);
                $byItem[(int) $row['id']] = DimensionDefaults::fill(
                    $dims['items'][$i + 1] ?? [],
                    $productDims[(int) ($row['stock_item_id'] ?? 0)]['header'] ?? [],
                );
            }
            if ($dims['items'] !== [] || $productDims !== []) {
                foreach ($rows as $i => $row) {
                    // Textový řádek vydaného dokladu (InvoiceItemKind::TEXT) nemá částku. Jako
                    // nulová kombinace dimenzí by rozpad výnosu zablokoval (nulová váha) a celý
                    // řádek by spadl na hlavičku. Číslování položek ($i + 1) ho ale počítá,
                    // stejně jako editor při ukládání dimenzí položek.
                    if ($issued && InvoiceItemKind::isText($row)) {
                        continue;
                    }
                    $items[$i] = [
                        'dims' => DimensionDefaults::fill(
                            $dims['items'][$i + 1] ?? [],
                            $productDims[(int) ($row['stock_item_id'] ?? 0)]['header'] ?? [],
                        ),
                        'origins' => $byItemOrigins[(int) $row['id']],
                        'weight' => round((float) $row['total_without_vat'], 2),
                        'account_id' => $itemAccounts[(int) $row['id']] ?? null,
                    ];
                }
                // foldIssuedDiscounts adresuje položky pozicí řádku, proto klíč $i až do něj.
                $items = $issued ? self::foldIssuedDiscounts($rows, $items) : array_values($items);
            }
        }
        // Zděděné dimenze (zápočet z faktury, karta z přijaté faktury) včetně rozpadu;
        // typ, který doklad má sám (hodnotou nebo rozpadem), zůstává.
        $inherited = $defaults->sourceDimensions($supplierId, $sourceType, $sourceId, $includeInactiveBankAccount);
        foreach ($inherited['splits'] as $typeId => $shares) {
            if (!isset($dims['header'][$typeId])) {
                $splits[] = $shares;
                $splitOrigins[] = $inherited['split_origins'][$typeId] ?? [];
                $dims['header'][$typeId] = -count($splits);
            }
        }
        $header = DimensionDefaults::fill($dims['header'], $inherited['header']);
        $headerOrigins = ($docOrigins['header'] ?? []) + ($inherited['origins'] ?? []);
        return [$header, $items, $splits, $byItem, $headerOrigins, $splitOrigins, $byItemOrigins, $inherited['bank_account'] ?? null];
    }

    /**
     * Slevový řádek z hlavičky vydané faktury nemá vlastní dimenze — zlevňuje položky své
     * sazby. Jeho základ se proto přičte (záporně) k zlevněným položkám v poměru, jakým ho
     * zaúčtování rozpustí do jejich účtů ({@see IssuedDiscountAllocation}), a jako samostatná
     * položka zmizí. Jinak by nesl dimenze hlavičky se zápornou vahou a výnosový řádek by se
     * mezi položky s různými dimenzemi nedal rozdělit.
     *
     * @param list<array<string,mixed>> $rows řádky invoice_items v pořadí $items
     * @param array<int,array{dims:array<int,int>, weight:float, account_id:?int}> $items klíč = pozice řádku v $rows
     *        (textový řádek bez položky chybí)
     * @return list<array{dims:array<int,int>, weight:float, account_id:?int}>
     */
    public static function foldIssuedDiscounts(array $rows, array $items): array
    {
        $index = [];
        foreach ($rows as $i => $row) {
            $index[(int) $row['id']] = $i;
        }
        $allocation = IssuedDiscountAllocation::allocate(array_values(array_filter(
            $rows,
            static fn (array $r): bool => ($r['item_kind'] ?? 'standard') !== 'discount'
                || trim((string) ($r['revenue_account_code'] ?? '')) === '',
        )));
        foreach ($allocation as $discountId => $shares) {
            $d = $index[$discountId];
            foreach ($shares as $targetId => $share) {
                $t = $index[$targetId];
                $items[$t]['weight'] = round($items[$t]['weight'] + $items[$d]['weight'] * $share, 2);
            }
            unset($items[$d]);
        }
        return array_values($items);
    }

    private function serviceCostAccounts(int $supplierId, string $sourceType, ?string $date, ?array $lines = null): array
    {
        if ($sourceType !== 'purchase_invoice' || $date === null) return [];
        if (!isset($this->serviceCostCandidates[$supplierId])) $this->accountTypes($supplierId);
        $candidates = $this->serviceCostCandidates[$supplierId];
        if ($lines !== null) $candidates = array_intersect_key($candidates, array_fill_keys(array_column($lines, 'account_id'), true));
        if ($candidates === []) return [];
        return \MyInvoice\Service\Accounting\AccountRoleResolver::fromConnection($this->db)->forDate($supplierId, $date)->framework === 'cz_504' ? $candidates : [];
    }

    private function accountTypes(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, account_type, account_code FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $out = [];
        $this->serviceCostCandidates[$supplierId] = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = (string) $r['account_type'];
            if ($r['account_type'] === 'asset' && preg_match(\MyInvoice\Service\Property\Opening\OpeningRolePolicy::ROLES['service_cost'][2], $r['account_code']) === 1) {
                $this->serviceCostCandidates[$supplierId][(int) $r['id']] = true;
            }
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function syncLinkedColumns(int $supplierId, array $lines): array
    {
        $valueIds = [];
        foreach ($lines as $line) {
            foreach ((array) ($line['dimensions'] ?? []) as $valueId) {
                $valueIds[] = (int) $valueId;
            }
        }
        if ($valueIds === []) {
            return $lines;
        }
        $repo = new DimensionRepository($this->db);
        $values = $repo->valuesByIds($supplierId, $valueIds);
        $centers = $repo->costCenterCodes($supplierId, array_keys($values));
        foreach ($lines as $i => $line) {
            foreach ((array) ($line['dimensions'] ?? []) as $valueId) {
                $valueId = (int) $valueId;
                if (($line['cost_center'] ?? null) === null && isset($centers[$valueId])) {
                    $lines[$i]['cost_center'] = $centers[$valueId];
                }
                if (($line['project_id'] ?? null) === null && ($values[$valueId]['project_id'] ?? null) !== null) {
                    $lines[$i]['project_id'] = $values[$valueId]['project_id'];
                }
            }
        }
        return $lines;
    }

    /**
     * @param array<int,int> $base
     * @param array<int,int> $over
     * @return array<int,int>
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $typeId => $valueId) {
            $base[(int) $typeId] = (int) $valueId;
        }
        ksort($base);
        return $base;
    }

    /** @param array<int,int> $dims */
    private static function key(array $dims): string
    {
        ksort($dims);
        return json_encode($dims, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $line
     * @param array<int,int> $dims
     * @return array<string,mixed>
     */
    private static function withDims(array $line, array $dims, array $origins = []): array
    {
        if ($origins !== [] || isset($line['dimension_origins'])) {
            foreach ((array) ($line['dimensions'] ?? []) as $typeId => $_) $origins[$typeId] = DimensionAssignmentOrigin::normalize($line['dimension_origins'][$typeId] ?? []);
            $line['dimension_origins'] = array_intersect_key($origins, $dims);
        }
        unset($line['current_dimensions'], $line['current_dimension_origins'], $line['split_siblings']);
        if ($dims === []) {
            unset($line['dimensions']);
        } else {
            $line['dimensions'] = $dims;
        }
        return $line;
    }
}
