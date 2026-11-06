<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice\Book;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PDO;

/**
 * Sestaví knihu vystavených / přijatých faktur nad filtrem seznamu
 * ({@see \MyInvoice\Http\InvoiceListFilters}). Výběr dokladů dělá WHERE seznamu
 * (`listWhere()` repozitářů), tady se jen doplní hlavička a shrnutí výběru.
 */
final class InvoiceBookService
{
    public const ISSUED_STATUS_LABELS = [
        'draft' => 'Koncept', 'issued' => 'Vystavená', 'sent' => 'Odeslaná', 'reminded' => 'Upomínaná',
        'paid' => 'Uhrazená', 'cancelled' => 'Stornovaná',
    ];
    public const RECEIVED_STATUS_LABELS = [
        'draft' => 'Koncept', 'received' => 'Přijatá', 'booked' => 'Zaúčtovaná', 'paid' => 'Uhrazená',
        'cancelled' => 'Stornovaná',
    ];
    public const ISSUED_TYPE_LABELS = [
        'invoice' => 'Faktura', 'proforma' => 'Zálohová faktura', 'credit_note' => 'Dobropis',
        'tax_document' => 'Daňový doklad k přijaté platbě', 'cancellation' => 'Storno',
        'rent_calendar' => 'Předpis nájemného', 'advance_calendar' => 'Předpis záloh',
    ];
    public const RECEIVED_KIND_LABELS = [
        'invoice' => 'Faktura', 'receipt' => 'Účtenka', 'credit_note' => 'Dobropis',
        'advance' => 'Zálohová faktura', 'tax_document' => 'Daňový doklad k přijaté platbě',
    ];
    private const APPROVAL_LABELS = [
        'none' => 'Bez schvalování', 'pending' => 'Čeká na schválení', 'approved' => 'Schváleno', 'rejected' => 'Zamítnuto',
    ];
    private const OSS_REVIEW_LABELS = [
        'any' => 'Vše k posouzení', 'oss' => 'V OSS k posouzení', 'domestic' => 'Mimo OSS k posouzení',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly PurchaseInvoiceRepository $purchases,
    ) {}

    /**
     * @param array<string,mixed> $filters výstup InvoiceListFilters::issued()
     * @param array{name?:string, email?:string} $user
     */
    public function issued(array $filters, array $user, ?\DateTimeImmutable $now = null): InvoiceBook
    {
        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        $repo = $this->invoices;
        return new InvoiceBook(
            InvoiceBook::ISSUED,
            $this->meta('Kniha vystavených faktur', 'DUZP', $supplierId, $this->issuedSelection($filters, $supplierId), $filters, $user, $now),
            static fn (?array $after, int $limit): array => $repo->listBookBatch($filters, $after, $limit),
            $this->domesticCurrency($supplierId),
        );
    }

    /**
     * @param array<string,mixed> $filters výstup InvoiceListFilters::purchase()
     * @param array{name?:string, email?:string} $user
     */
    public function received(array $filters, array $user, ?DocumentViewerContext $documentViewer = null, ?\DateTimeImmutable $now = null): InvoiceBook
    {
        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        $repo = $this->purchases;
        return new InvoiceBook(
            InvoiceBook::RECEIVED,
            $this->meta('Kniha přijatých faktur', 'data vystavení', $supplierId, $this->receivedSelection($filters, $supplierId), $filters, $user, $now),
            static fn (?array $after, int $limit): array => $repo->listBookBatch($filters, $documentViewer, $after, $limit),
            $this->domesticCurrency($supplierId),
        );
    }

    /**
     * @param list<array{label:string, value:string}> $selection
     * @param array<string,mixed> $filters
     * @param array{name?:string, email?:string} $user
     * @return array<string,mixed>
     */
    private function meta(string $title, string $dateBasis, int $supplierId, array $selection, array $filters, array $user, ?\DateTimeImmutable $now): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, ic, dic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $name = trim((string) ($user['name'] ?? ''));

        return [
            'title' => $title,
            'supplier' => [
                'company_name' => (string) ($supplier['company_name'] ?? ''),
                'ic' => (string) ($supplier['ic'] ?? ''),
                'dic' => (string) ($supplier['dic'] ?? ''),
            ],
            'period_label' => self::periodLabel($filters),
            'date_basis' => $dateBasis,
            'selection' => $selection,
            'printed_by' => $name !== '' ? $name : (string) ($user['email'] ?? ''),
            'printed_at' => ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string,mixed> $filters */
    public static function periodLabel(array $filters): string
    {
        $year = (int) ($filters['year'] ?? 0);
        $month = (int) ($filters['month'] ?? 0);
        $from = self::date($filters['date_from'] ?? null);
        $to = self::date($filters['date_to'] ?? null);
        $parts = [];
        if ($year > 0 && $month >= 1 && $month <= 12) {
            $parts[] = sprintf('%02d/%04d', $month, $year);
        } elseif ($year > 0) {
            $parts[] = 'rok ' . $year;
        } elseif ($month >= 1 && $month <= 12) {
            $parts[] = sprintf('měsíc %d (všechny roky)', $month);
        }
        if ($from !== '' && $to !== '') {
            $parts[] = $from . ' - ' . $to;
        } elseif ($from !== '') {
            $parts[] = 'od ' . $from;
        } elseif ($to !== '') {
            $parts[] = 'do ' . $to;
        }
        return $parts === [] ? 'všechna období' : implode(', ', $parts);
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array{label:string, value:string}>
     */
    private function issuedSelection(array $filters, int $supplierId): array
    {
        $out = $this->periodSelection($filters, 'DUZP');
        self::add($out, 'Stav', self::labels($filters['status'] ?? null, self::ISSUED_STATUS_LABELS));
        self::add($out, 'Typ dokladu', self::labels($filters['type'] ?? null, self::ISSUED_TYPE_LABELS));
        self::add($out, 'Odběratel', $this->clientName($filters['client_id'] ?? null, $supplierId));
        self::add($out, 'Zakázka', $this->projectName($filters['project_id'] ?? null, $supplierId));
        $this->commonSelection($out, $filters);
        self::add($out, 'Kategorie tržby', $this->revenueCategories($filters['revenue_category_id'] ?? null, $supplierId));
        self::add($out, 'Skryté kategorie tržby', $this->revenueCategories($filters['revenue_category_exclude'] ?? null, $supplierId));
        $oss = is_scalar($filters['oss_review'] ?? null) ? strtolower((string) $filters['oss_review']) : '';
        if ($oss !== '') {
            self::add($out, 'Místo plnění', self::OSS_REVIEW_LABELS[$oss] ?? self::OSS_REVIEW_LABELS['any']);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array{label:string, value:string}>
     */
    private function receivedSelection(array $filters, int $supplierId): array
    {
        $out = $this->periodSelection($filters, 'data vystavení');
        self::add($out, 'Stav', self::labels($filters['status'] ?? null, self::RECEIVED_STATUS_LABELS));
        self::add($out, 'Druh dokladu', self::labels($filters['document_kind'] ?? null, self::RECEIVED_KIND_LABELS));
        self::add($out, 'Dodavatel', $this->clientName($filters['vendor_id'] ?? null, $supplierId));
        self::add($out, 'Zakázka', (string) ($filters['project_id'] ?? '') === 'none'
            ? 'Bez zakázky'
            : $this->projectName($filters['project_id'] ?? null, $supplierId));
        $this->commonSelection($out, $filters);
        self::flag($out, 'Bez párování úhrady', $filters['unmatched'] ?? false);
        self::flag($out, 'Ke kontrole vytěžení', $filters['needs_review'] ?? false);
        self::flag($out, 'Uhrazeno s rozdílem', $filters['paid_shortfall'] ?? false);
        $approval = is_scalar($filters['approval_status'] ?? null) ? (string) $filters['approval_status'] : '';
        self::add($out, 'Schvalování', self::APPROVAL_LABELS[$approval] ?? '');
        self::add($out, 'Předáno k úhradě', self::yesNo($filters['payment_ordered'] ?? null, 'Předané', 'Nepředané'));
        self::add($out, 'Obrazový podklad', self::yesNo($filters['has_visual_document'] ?? null, 'Má', 'Nemá'));
        self::add($out, 'Strukturovaný zdroj', self::yesNo($filters['has_structured_source'] ?? null, 'Má', 'Nemá'));
        self::add($out, 'Dávka importu', is_scalar($filters['import_batch_id'] ?? null) ? (string) $filters['import_batch_id'] : '');
        return $out;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array{label:string, value:string}>
     */
    private function periodSelection(array $filters, string $basis): array
    {
        $out = [];
        $label = self::periodLabel($filters);
        if ($label !== 'všechna období') {
            self::add($out, 'Období', $label . ' (podle ' . $basis . ')');
        }
        return $out;
    }

    /**
     * @param list<array{label:string, value:string}> $out
     * @param array<string,mixed> $filters
     */
    private function commonSelection(array &$out, array $filters): void
    {
        self::add($out, 'Měna', is_scalar($filters['currency'] ?? null) ? strtoupper((string) $filters['currency']) : '');
        self::add($out, 'Hledání', is_scalar($filters['q'] ?? null) ? trim((string) $filters['q']) : '');
        self::flag($out, 'Jen neuhrazené', $filters['unpaid_only'] ?? false);
        self::flag($out, 'Jen po splatnosti', $filters['overdue'] ?? false);
        self::add($out, 'Neuhrazené k datu', self::date($filters['unpaid_as_of'] ?? null));
        self::add($out, 'Zaúčtování', self::yesNo($filters['booked'] ?? null, 'Zaúčtované', 'Nezaúčtované'));
    }

    private function clientName(mixed $id, int $supplierId): string
    {
        if (!is_scalar($id) || (int) $id <= 0) {
            return '';
        }
        $stmt = $this->db->pdo()->prepare('SELECT company_name FROM clients WHERE id = ? AND supplier_id = ?');
        $stmt->execute([(int) $id, $supplierId]);
        $name = $stmt->fetchColumn();
        return $name === false ? '#' . (int) $id : (string) $name;
    }

    private function projectName(mixed $id, int $supplierId): string
    {
        if (!is_scalar($id) || (int) $id <= 0) {
            return '';
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.name FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ? AND c.supplier_id = ?'
        );
        $stmt->execute([(int) $id, $supplierId]);
        $name = $stmt->fetchColumn();
        return $name === false ? '#' . (int) $id : (string) $name;
    }

    private function revenueCategories(mixed $raw, int $supplierId): string
    {
        $tokens = is_array($raw) ? $raw : (is_scalar($raw) ? explode(',', (string) $raw) : []);
        $ids = [];
        $labels = [];
        foreach ($tokens as $token) {
            $token = is_scalar($token) ? trim((string) $token) : '';
            if (strcasecmp($token, InvoiceRepository::REVENUE_CATEGORY_NONE) === 0) {
                $labels[] = 'bez kategorie';
            } elseif (ctype_digit($token) && (int) $token > 0) {
                $ids[] = (int) $token;
            }
        }
        if ($ids !== []) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT label FROM revenue_categories WHERE supplier_id = ? AND id IN ($place) ORDER BY display_order, label"
            );
            $stmt->execute([$supplierId, ...$ids]);
            array_unshift($labels, ...array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
        }
        return implode(', ', $labels);
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

    /**
     * @param array<string,string> $map
     */
    private static function labels(mixed $value, array $map): string
    {
        $values = is_array($value) ? $value : (is_scalar($value) && (string) $value !== '' ? [$value] : []);
        $out = [];
        foreach ($values as $v) {
            $v = is_scalar($v) ? trim((string) $v) : '';
            if ($v !== '') {
                $out[] = $map[$v] ?? $v;
            }
        }
        return implode(', ', $out);
    }

    private static function yesNo(mixed $value, string $yes, string $no): string
    {
        if (!is_scalar($value) || (string) $value === '') {
            return '';
        }
        return (string) $value === '1' ? $yes : $no;
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

    /** @param list<array{label:string, value:string}> $out */
    private static function flag(array &$out, string $label, mixed $value): void
    {
        if (!empty($value)) {
            $out[] = ['label' => $label, 'value' => 'ano'];
        }
    }
}
