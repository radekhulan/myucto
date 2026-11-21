<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\OtherItemBook;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\OtherItemRepository;
use MyInvoice\Service\Accounting\OtherItemInstallmentCoverage;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\OtherItemSort;
use PDO;

/**
 * Sestaví knihu ostatních pohledávek a závazků nad filtrem výpisu
 * ({@see \MyInvoice\Http\OtherItemListFilters}). Výběr dělá WHERE výpisu
 * (`OtherItemRepository::listWhere()`), tady se doplní hlavička, popisky a stav řádků.
 *
 * Kniha obsahuje jen ručně zadané položky. Převzaté předpisy z mezd a daní výpis ukazuje
 * zvlášť (podle jiných práv a s odhady do budoucna) a do jeho součtů nevstupují; kniha
 * to uvádí v hlavičce.
 */
final class OtherItemBookService
{
    public const TITLE = 'Kniha ostatních pohledávek a závazků';
    public const SCOPE_NOTE = 'Kniha obsahuje jen ručně zadané ostatní pohledávky a závazky. '
        . 'Převzaté předpisy z mezd a daní, které výpis ukazuje zvlášť, v ní nejsou.';

    public const SIDE_LABELS = ['receivable' => 'Pohledávka', 'payable' => 'Závazek'];
    public const SIDE_TOTAL_LABELS = ['receivable' => 'Pohledávky', 'payable' => 'Závazky'];

    /** Popisky stavu jako odznak výpisu (cs.json other_items.status). */
    public const STATUS_LABELS = [
        'draft' => 'Rozpracováno', 'confirmed' => 'Potvrzeno', 'posted' => 'Zaúčtováno',
        'reversed' => 'Stornováno', 'cancelled' => 'Zrušeno', 'paid' => 'Uhrazeno',
        'partial' => 'Částečně uhrazeno', 'overpaid' => 'Přeplaceno',
    ];

    /** Vestavěné druhy podle směru (cs.json other_items.kind_by_side). */
    public const KIND_BY_SIDE = [
        'receivable' => [
            'other' => 'Jiná pohledávka', 'rent' => 'Nájemné k inkasu', 'loan' => 'Poskytnutá půjčka nebo splátka',
            'deposit' => 'Vratná kauce', 'insurance' => 'Pojistné plnění', 'fee' => 'Poplatek k inkasu',
            'claim' => 'Nárok na náhradu',
        ],
        'payable' => [
            'other' => 'Jiný závazek', 'rent' => 'Nájemné k úhradě', 'loan' => 'Přijatá půjčka nebo splátka',
            'deposit' => 'Kauce k vrácení', 'insurance' => 'Pojistné k úhradě', 'fee' => 'Poplatek k úhradě',
            'claim' => 'Náhrada k úhradě',
        ],
    ];

    /** Vestavěný druh bez směru (cs.json other_items.kind) pro výběr bez filtru směru. */
    private const KIND_LABELS = [
        'other' => 'Ostatní', 'rent' => 'Nájem', 'loan' => 'Půjčka nebo splátka', 'deposit' => 'Kauce',
        'insurance' => 'Pojištění', 'fee' => 'Poplatek', 'claim' => 'Nárok na náhradu',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly OtherItemRepository $items,
    ) {}

    /**
     * @param array{filters: array<string,mixed>, default_window: bool} $parsed výstup OtherItemListFilters::fromRequest()
     * @param bool $canReadAccounting právo číst účetnictví; sloupec Zaúčtováno má kniha jen s ním
     *        a v podvojném účetnictví, stejně jako sloupec Zápis ve výpisu
     * @param array{name?:string, email?:string} $user
     */
    public function build(int $supplierId, array $parsed, bool $canReadAccounting, array $user, ?\DateTimeImmutable $now = null,
        int $batchSize = OtherItemBook::BATCH_SIZE): OtherItemBook
    {
        $filters = $parsed['filters'];
        $stmt = $this->db->pdo()->prepare('SELECT company_name, ic, dic, accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $showPosting = $canReadAccounting && ($supplier['accounting_mode'] ?? '') === 'double_entry';
        $items = $this->items;
        $fetch = static function (?array $after, int $limit) use ($items, $supplierId, $filters, $showPosting): array {
            $batch = $items->bookBatch($supplierId, $filters, $after, $limit);
            // Stav a zbývá uhradit u rozdělených splátek jedním dávkovým čtením za dávku (jako výpis).
            $coverage = $items->installmentCoverage($supplierId, array_map(static fn (array $row): int => (int) $row['id'], $batch));
            return array_map(
                static fn (array $row): array => self::normalize($row, $coverage[(int) $row['id']] ?? [], $showPosting),
                $batch,
            );
        };

        return new OtherItemBook(
            $this->meta($supplierId, $supplier, $parsed, $user, $now),
            $fetch,
            $showPosting,
            $this->domesticCurrency($supplierId),
            $batchSize,
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $coverage
     * @return array<string,mixed>
     */
    public static function normalize(array $row, array $coverage, bool $showPosting): array
    {
        $side = (string) $row['side'];
        $status = (string) $row['status'];
        $row['amount'] = round((float) $row['amount'], 2);
        $row['paid_amount'] = round((float) $row['paid_amount'], 2);
        $row['remaining_amount'] = round((float) $row['remaining_amount'], 2);
        $row['remaining_payment'] = OtherItemService::payableOf($row, $coverage);
        $row['has_split_installments'] = OtherItemInstallmentCoverage::hasSplit($coverage);
        $row['side_label'] = self::SIDE_LABELS[$side] ?? $side;
        $row['kind_label'] = trim((string) ($row['kind_name'] ?? '')) !== ''
            ? (string) $row['kind_name']
            : (self::KIND_BY_SIDE[$side][(string) $row['kind']] ?? (string) $row['kind']);
        $row['display_status'] = self::displayStatus($status, (float) $row['remaining_payment'], (float) $row['paid_amount']);
        $row['status_label'] = self::STATUS_LABELS[$row['display_status']] ?? $row['display_status'];
        $row['counted'] = in_array($status, ['confirmed', 'posted'], true);
        // Datum zaúčtování jako štítek Zápis ve výpisu (posted_at, jinak datum účetního případu).
        $posted = $showPosting && $status === 'posted' && $row['journal_entry_id'] !== null;
        $row['posted_on'] = $posted ? substr((string) ($row['posted_at'] ?? $row['accounting_on'] ?? ''), 0, 10) : null;
        return $row;
    }

    /**
     * Stav pro odznak jako `otherItemDisplayStatus` ve výpisu: u živé položky (potvrzené,
     * zaúčtované) přebije platební stav, jinak uložený stav.
     */
    public static function displayStatus(string $status, float $remainingPayment, float $paidAmount): string
    {
        if (!in_array($status, ['posted', 'confirmed'], true)) return $status;
        if ($remainingPayment < -0.005) return 'overpaid';
        if ($remainingPayment <= 0) return 'paid';
        if ($paidAmount > 0) return 'partial';
        return $status;
    }

    /**
     * @param array<string,mixed> $supplier
     * @param array{filters: array<string,mixed>, default_window: bool} $parsed
     * @param array{name?:string, email?:string} $user
     * @return array<string,mixed>
     */
    private function meta(int $supplierId, array $supplier, array $parsed, array $user, ?\DateTimeImmutable $now): array
    {
        $name = trim((string) ($user['name'] ?? ''));
        $filters = $parsed['filters'];

        return [
            'title' => self::TITLE,
            'supplier' => [
                'company_name' => (string) ($supplier['company_name'] ?? ''),
                'ic' => (string) ($supplier['ic'] ?? ''),
                'dic' => (string) ($supplier['dic'] ?? ''),
            ],
            'period_label' => self::periodLabel($filters, $parsed['default_window']),
            'selection' => $this->selection($supplierId, $filters),
            'scope_note' => self::SCOPE_NOTE,
            'printed_by' => $name !== '' ? $name : (string) ($user['email'] ?? ''),
            'printed_at' => ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Splatnost, kterou výběr skutečně použil (po výchozím okně a jeho výjimkách).
     *
     * @param array<string,mixed> $filters
     */
    public static function periodLabel(array $filters, bool $defaultWindow): string
    {
        // Zadané jen „od“ nebo jen „do“ doplní OtherItemListFilters zarážkou; ta mez není.
        $from = ($filters['from'] ?? null) === '1000-01-01' ? '' : self::date($filters['from'] ?? null);
        $to = ($filters['to'] ?? null) === '9999-12-31' ? '' : self::date($filters['to'] ?? null);
        $label = match (true) {
            $from !== '' && $to !== '' => 'splatnost ' . $from . ' – ' . $to,
            $from !== '' => 'splatnost od ' . $from,
            $to !== '' => 'splatnost do ' . $to,
            default => 'bez omezení splatnosti',
        };
        return $defaultWindow && ($from !== '' || $to !== '') ? $label . ' (výchozí okno výpisu)' : $label;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array{label:string, value:string}>
     */
    private function selection(int $supplierId, array $filters): array
    {
        $out = [];
        $side = is_scalar($filters['side'] ?? null) ? (string) $filters['side'] : '';
        self::add($out, 'Směr', $side === '' ? '' : (self::SIDE_LABELS[$side] ?? $side));
        $status = is_scalar($filters['status'] ?? null) ? (string) $filters['status'] : '';
        self::add($out, 'Stav', match ($status) {
            '', 'all' => '',
            'open' => 'Otevřené (zbývá uhradit)',
            default => self::STATUS_LABELS[$status] ?? $status,
        });
        self::add($out, 'Druh', $this->kindLabel($supplierId, $filters, $side));
        self::add($out, 'Hledání', is_scalar($filters['q'] ?? null) ? trim((string) $filters['q']) : '');
        $sort = OtherItemSort::selected($filters);
        $sort ??= ['key' => 'document_no', 'dir' => 'ASC'];
        self::add($out, 'Řazení', OtherItemSort::LABELS[$sort['key']] . ($sort['dir'] === 'ASC' ? ', vzestupně' : ', sestupně'));
        return $out;
    }

    /** @param array<string,mixed> $filters */
    private function kindLabel(int $supplierId, array $filters, string $side): string
    {
        if (!empty($filters['kind_id'])) {
            $stmt = $this->db->pdo()->prepare('SELECT name FROM other_item_kinds WHERE id = ? AND supplier_id = ?');
            $stmt->execute([(int) $filters['kind_id'], $supplierId]);
            $name = $stmt->fetchColumn();
            return $name === false ? '#' . (int) $filters['kind_id'] : (string) $name;
        }
        $kind = is_scalar($filters['kind'] ?? null) ? (string) $filters['kind'] : '';
        if ($kind === '') return '';
        return self::KIND_BY_SIDE[$side][$kind] ?? self::KIND_LABELS[$kind] ?? $kind;
    }

    private function domesticCurrency(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT cur.code FROM supplier s JOIN currencies cur ON cur.id = s.default_currency_id WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        $code = $stmt->fetchColumn();
        return $code === false || $code === null ? 'CZK' : (string) $code;
    }

    private static function date(mixed $value): string
    {
        if (!is_scalar($value) || (string) $value === '') {
            return '';
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
        return $d === false ? (string) $value : $d->format('j. n. Y');
    }

    /** @param list<array{label:string, value:string}> $out */
    private static function add(array &$out, string $label, string $value): void
    {
        if ($value !== '') {
            $out[] = ['label' => $label, 'value' => $value];
        }
    }
}
