<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use PDO;

/**
 * Vydaná faktura navázaná na ostatní pohledávku (migrace 2246).
 *
 * Prodej se zaúčtoval už ostatní pohledávkou (311.x / 641.x) a úhrada se spárovala na ni.
 * Faktura vystavená později na tutéž částku proto vlastní předpis nemá: účtuje ji zápis
 * pohledávky ({@see DocumentPostingGroup::activePostingExistsSql()}), automatika ani
 * doúčtování ji neúčtují ({@see PostingService::coveredByOtherItemException()}) a její
 * platby zrcadlí úhrady pohledávky ({@see InvoiceOtherItemPayments}).
 *
 * Vazba se neopírá o skupinu zaúčtování (journal_document_postings): zápis by tím
 * vlastnila faktura a přeúčtování, storno i párování pohledávky by narazily na cizího
 * vlastníka. Zápis zůstává pohledávce, faktura na něj jen odkazuje.
 *
 * Evidence DPH se nemění: {@see \MyInvoice\Service\Report\VatLedgerService} čte řádky
 * dokladu, ne deník. Navázat lze jen fakturu bez DPH, aby daň nikde nechyběla.
 *
 * Pořadí zámků: období firmy, pohledávka, faktura. Stejně zamyká přiřazení úhrady
 * (pohledávka, faktura) i zaúčtování dokladu (období, faktura).
 */
final class InvoiceOtherItemLinkService
{
    private const LINKABLE_STATUSES = ['draft', 'issued', 'sent', 'reminded', 'paid'];

    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly InvoicePdfRenderer $pdf,
        private readonly StatsRecomputer $stats,
    ) {}

    /**
     * Navázaná pohledávka pro detail faktury, null bez vazby.
     *
     * @return array<string,mixed>|null
     */
    public function describe(int $supplierId, int $invoiceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT oi.id, oi.document_no, oi.title, oi.partner_name, oi.variable_symbol, oi.issued_on,
                    oi.amount, oi.currency, oi.status, oi.journal_entry_id,
                    (SELECT COALESCE(SUM(a.amount), 0) FROM other_item_allocations a
                      WHERE a.supplier_id = oi.supplier_id AND a.other_item_id = oi.id AND a.reversed_on IS NULL)
                      + oi.settled_outside_amount AS paid_amount
               FROM invoices i
               JOIN other_items oi ON oi.supplier_id = i.supplier_id AND oi.id = i.other_item_id
              WHERE i.id = ? AND i.supplier_id = ?'
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castItem($row);
    }

    /**
     * Payload detailu faktury doplněný o navázanou pohledávku (`other_item_link`)
     * a u nenavázané faktury o jednoznačný návrh vazby (`other_item_suggestion`).
     *
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    public function withLink(int $supplierId, array $invoice): array
    {
        $invoice['other_item_id'] = !empty($invoice['other_item_id']) ? (int) $invoice['other_item_id'] : null;
        $invoice['other_item_link'] = $invoice['other_item_id'] !== null ? $this->describe($supplierId, (int) $invoice['id']) : null;
        $invoice['other_item_suggestion'] = $invoice['other_item_id'] === null ? $this->suggestion($supplierId, $invoice) : null;
        return $invoice;
    }

    /**
     * Pohledávky, na které lze fakturu navázat: zaúčtované, nenavázané, ve stejné měně.
     * Řazení: shoda VS, pak stejný odběratel a částka, pak od nejnovější.
     *
     * @return list<array<string,mixed>>
     */
    public function candidates(int $supplierId, int $invoiceId, string $search = '', int $limit = 50): array
    {
        $invoice = $this->invoice($supplierId, $invoiceId, false);
        if ($invoice === null) {
            throw new InvoiceOtherItemLinkException('not_found', 'Faktura nenalezena.', 404);
        }
        $limit = max(1, min(100, $limit));
        $symbols = self::symbols($invoice) + [null, null];
        $params = [$symbols[0], $symbols[1], (int) $invoice['client_id'], (float) $invoice['total_with_vat'],
            $supplierId, $supplierId, (string) $invoice['currency']];
        $where = '';
        $search = trim($search);
        if ($search !== '') {
            $term = '%' . addcslashes(mb_substr($search, 0, 100), '%_\\') . '%';
            $where = ' AND (oi.document_no LIKE ? OR oi.partner_name LIKE ? OR oi.variable_symbol LIKE ? OR oi.title LIKE ?)';
            array_push($params, $term, $term, $term, $term);
        }
        // Pohledávky firmy jdou po idx_other_item_open (supplier_id, side, status).
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.* FROM (
                SELECT oi.id, oi.document_no, oi.title, oi.partner_id, oi.partner_name, oi.variable_symbol, oi.issued_on,
                       oi.due_on, oi.amount, oi.currency, oi.status, oi.journal_entry_id,
                       COALESCE((SELECT SUM(a.amount) FROM other_item_allocations a
                                  WHERE a.supplier_id = oi.supplier_id AND a.other_item_id = oi.id AND a.reversed_on IS NULL), 0)
                         + oi.settled_outside_amount AS paid_amount,
                       COALESCE(TRIM(oi.variable_symbol) IN (?, ?), 0) AS vs_match,
                       COALESCE(oi.partner_id = ?, 0) AS partner_match,
                       ROUND(oi.amount * 100) = ROUND(? * 100) AS amount_match
                  FROM other_items oi
                 WHERE oi.supplier_id = ? AND oi.side = 'receivable' AND oi.status = 'posted'
                   AND oi.journal_entry_id IS NOT NULL AND oi.deleted_at IS NULL
                   AND NOT EXISTS (SELECT 1 FROM invoices li WHERE li.supplier_id = ? AND li.other_item_id = oi.id)
                   AND oi.currency = ?{$where}
             ) c
             ORDER BY c.vs_match DESC, (c.partner_match AND c.amount_match) DESC, c.amount_match DESC, c.partner_match DESC,
                      c.issued_on DESC, c.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute($params);
        return array_map(static function (array $row): array {
            $item = self::castItem($row);
            $item['partner_id'] = $row['partner_id'] !== null ? (int) $row['partner_id'] : null;
            $item['due_on'] = (string) $row['due_on'];
            $item['vs_match'] = (bool) $row['vs_match'];
            $item['partner_match'] = (bool) $row['partner_match'];
            $item['amount_match'] = (bool) $row['amount_match'];
            return $item;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Návrh vazby pro vystavenou nezaplacenou fakturu bez DPH: právě jedna pohledávka
     * se shodným VS i částkou. Jinak null (nejednoznačnost řeší účetní výběrem).
     *
     * @param array<string,mixed> $invoice řádek z InvoiceRepository::find
     * @return array<string,mixed>|null
     */
    public function suggestion(int $supplierId, array $invoice): ?array
    {
        if (!empty($invoice['other_item_id']) || ($invoice['invoice_type'] ?? '') !== 'invoice'
            || !in_array((string) ($invoice['status'] ?? ''), ['issued', 'sent', 'reminded'], true)
            || (float) ($invoice['paid_total'] ?? 0) > 0 || abs((float) ($invoice['total_vat'] ?? 0)) >= 0.005
            || self::symbols($invoice) === [] || !$this->isDoubleEntry($supplierId)) {
            return null;
        }
        $matches = array_values(array_filter(
            $this->candidates($supplierId, (int) $invoice['id'], '', 100),
            static fn (array $row): bool => $row['vs_match'] && $row['amount_match'],
        ));
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Naváže fakturu na pohledávku. Vlastní aktivní zaúčtování faktury se stornuje
     * protizápisem (v zamčeném datu k dnešku, v uzavřeném období se vazba odmítne).
     *
     * @return array{reversed_entries:array<int,int>, payments_synced:bool}
     */
    public function link(int $supplierId, int $invoiceId, int $otherItemId, ?int $userId): array
    {
        $result = TransactionFailure::retrying($this->db->pdo(), fn (): array =>
            (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $invoiceId, $otherItemId, $userId): array {
                $pdo = $this->db->pdo();
                (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
                if (!$this->isDoubleEntry($supplierId)) {
                    throw new InvoiceOtherItemLinkException('not_double_entry',
                        'Fakturu lze navázat na ostatní pohledávku jen v podvojném účetnictví.');
                }
                $item = $this->item($supplierId, $otherItemId, true)
                    ?? throw new InvoiceOtherItemLinkException('other_item_not_found', 'Ostatní pohledávka nebyla nalezena.', 404);
                $invoice = $this->invoice($supplierId, $invoiceId, true)
                    ?? throw new InvoiceOtherItemLinkException('not_found', 'Faktura nenalezena.', 404);
                if ($invoice['other_item_id'] !== null && (int) $invoice['other_item_id'] === $otherItemId) {
                    return ['reversed_entries' => [], 'payments_synced' => false];
                }
                if ($invoice['other_item_id'] !== null) {
                    throw new InvoiceOtherItemLinkException('already_linked',
                        'Faktura je už navázaná na jinou ostatní pohledávku. Nejprve ji odpojte.');
                }
                $this->assertLinkable($invoice, $item);
                $other = $pdo->prepare('SELECT varsymbol FROM invoices WHERE supplier_id = ? AND other_item_id = ? AND id <> ? FOR UPDATE');
                $other->execute([$supplierId, $otherItemId, $invoiceId]);
                $taken = $other->fetch(PDO::FETCH_ASSOC);
                if ($taken !== false) {
                    throw new InvoiceOtherItemLinkException('other_item_taken', sprintf(
                        'Ostatní pohledávka %s je už navázaná na fakturu %s.', $item['document_no'], $taken['varsymbol'] ?? '#'));
                }
                $payments = $pdo->prepare('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ? AND supplier_id = ? AND source <> ?');
                $payments->execute([$invoiceId, $supplierId, InvoiceOtherItemPayments::SOURCE]);
                if ((int) $payments->fetchColumn() > 0) {
                    throw new InvoiceOtherItemLinkException('invoice_has_payments',
                        'Faktura už má evidované úhrady. Úhrada patří ostatní pohledávce, platby faktury nejprve odeberte.');
                }

                $reversed = [];
                if ((new DocumentPostingGroup($this->db))->active($supplierId, 'invoice', $invoiceId, true) !== []) {
                    try {
                        $reversed = $this->posting->reverseDocument($supplierId, 'invoice', $invoiceId, [
                            'description' => mb_substr(sprintf('Storno zaúčtování faktury %s: účtuje ji ostatní pohledávka %s',
                                $invoice['varsymbol'] ?? ('#' . $invoiceId), $item['document_no']), 0, 255),
                            'user_id' => $userId,
                            'posted_by' => $userId,
                        ]);
                    } catch (PostingException $e) {
                        if (TransactionFailure::aborted($e)) throw $e;
                        throw new InvoiceOtherItemLinkException('own_posting_not_reversible',
                            'Vlastní zaúčtování faktury nelze stornovat (' . $e->getMessage() . '). Fakturu nelze navázat.');
                    }
                }

                $pdo->prepare(
                    "UPDATE invoices
                        SET other_item_id = ?,
                            booked_at = IF(status = 'draft', booked_at, COALESCE(booked_at, NOW())),
                            booked_by = IF(status = 'draft', booked_by, COALESCE(booked_by, ?))
                      WHERE id = ? AND supplier_id = ?"
                )->execute([$otherItemId, $userId, $invoiceId, $supplierId]);
                $synced = InvoiceOtherItemPayments::syncInvoice($pdo, $supplierId, $invoiceId);
                return ['reversed_entries' => $reversed, 'payments_synced' => $synced];
            }),
        );
        $this->afterChange($invoiceId);
        return $result;
    }

    /**
     * Odpojí fakturu od pohledávky. Faktura je pak nezaúčtovaná a bez úhrad pohledávky,
     * zaúčtuje se běžným „Zaúčtovat" (automatika se znovu nespouští).
     *
     * @return int|null odpojená pohledávka, null když faktura vazbu neměla
     */
    public function unlink(int $supplierId, int $invoiceId): ?int
    {
        $previous = TransactionFailure::retrying($this->db->pdo(), fn (): ?int =>
            (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $invoiceId): ?int {
                (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
                $invoice = $this->invoice($supplierId, $invoiceId, true)
                    ?? throw new InvoiceOtherItemLinkException('not_found', 'Faktura nenalezena.', 404);
                if ($invoice['other_item_id'] === null) return null;
                $this->detachLocked($supplierId, $invoiceId, true);
                return (int) $invoice['other_item_id'];
            }),
        );
        if ($previous !== null) $this->afterChange($invoiceId);
        return $previous;
    }

    /**
     * Po vystavení navázaného konceptu: faktura se znovu ověří (koncept se mohl změnit),
     * dostane zámek zaúčtovaného dokladu a převezme úhrady pohledávky. Faktura, která
     * podmínky už nesplňuje, se odpojí a vrátí se důvod.
     *
     * @return array{status:'none'|'linked'|'unlinked', reason?:string}
     */
    public function afterIssue(int $supplierId, int $invoiceId, ?int $userId): array
    {
        $result = TransactionFailure::retrying($this->db->pdo(), fn (): array =>
            (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $invoiceId, $userId): array {
                $pdo = $this->db->pdo();
                $linked = $pdo->prepare('SELECT other_item_id FROM invoices WHERE id = ? AND supplier_id = ?');
                $linked->execute([$invoiceId, $supplierId]);
                $otherItemId = $linked->fetchColumn();
                if ($otherItemId === false || $otherItemId === null) return ['status' => 'none'];
                (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
                $item = $this->item($supplierId, (int) $otherItemId, true);
                $invoice = $this->invoice($supplierId, $invoiceId, true);
                if ($invoice === null || $invoice['other_item_id'] === null) return ['status' => 'none'];
                try {
                    if ($item === null) {
                        throw new InvoiceOtherItemLinkException('other_item_not_found', 'Ostatní pohledávka nebyla nalezena.');
                    }
                    if (!$this->isDoubleEntry($supplierId)) {
                        throw new InvoiceOtherItemLinkException('not_double_entry',
                            'Fakturu lze navázat na ostatní pohledávku jen v podvojném účetnictví.');
                    }
                    $this->assertLinkable($invoice, $item);
                } catch (InvoiceOtherItemLinkException $e) {
                    $this->detachLocked($supplierId, $invoiceId, true);
                    return ['status' => 'unlinked', 'reason' => $e->getMessage()];
                }
                $pdo->prepare('UPDATE invoices SET booked_at = COALESCE(booked_at, NOW()), booked_by = COALESCE(booked_by, ?)
                    WHERE id = ? AND supplier_id = ?')->execute([$userId, $invoiceId, $supplierId]);
                InvoiceOtherItemPayments::syncInvoice($pdo, $supplierId, $invoiceId);
                return ['status' => 'linked'];
            }),
        );
        if ($result['status'] !== 'none') $this->afterChange($invoiceId);
        return $result;
    }

    /**
     * Storno faktury vazbu ruší a odebere zrcadlené úhrady. Volá se v transakci storna;
     * obnovení storna fakturu znovu nenaváže.
     *
     * @return int|null odpojená pohledávka
     */
    public static function detachForCancel(PDO $pdo, int $supplierId, int $invoiceId): ?int
    {
        $stmt = $pdo->prepare('SELECT other_item_id FROM invoices WHERE id = ? AND supplier_id = ? FOR UPDATE');
        $stmt->execute([$invoiceId, $supplierId]);
        $otherItemId = $stmt->fetchColumn();
        if ($otherItemId === false || $otherItemId === null) return null;
        $pdo->prepare('UPDATE invoices SET other_item_id = NULL WHERE id = ? AND supplier_id = ?')->execute([$invoiceId, $supplierId]);
        InvoiceOtherItemPayments::syncInvoice($pdo, $supplierId, $invoiceId);
        return (int) $otherItemId;
    }

    /** Zruší vazbu zamčené faktury; s $unbook faktura přestane být označená jako zaúčtovaná. */
    private function detachLocked(int $supplierId, int $invoiceId, bool $unbook): void
    {
        $pdo = $this->db->pdo();
        $ownPosting = (new DocumentPostingGroup($this->db))->active($supplierId, 'invoice', $invoiceId, true) !== [];
        $pdo->prepare('UPDATE invoices SET other_item_id = NULL'
            . ($unbook && !$ownPosting ? ', booked_at = NULL, booked_by = NULL' : '')
            . ' WHERE id = ? AND supplier_id = ?')->execute([$invoiceId, $supplierId]);
        InvoiceOtherItemPayments::syncInvoice($pdo, $supplierId, $invoiceId);
    }

    /**
     * Podmínky vazby: běžná faktura bez DPH ve stejné měně a částce jako zaúčtovaná
     * pohledávka. Částka se porovnává na haléře.
     *
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $item
     */
    private function assertLinkable(array $invoice, array $item): void
    {
        if ((string) $invoice['invoice_type'] !== 'invoice') {
            throw new InvoiceOtherItemLinkException('invalid_invoice_type',
                'Na ostatní pohledávku lze navázat jen běžnou fakturu (ne zálohu, dobropis ani jiný doklad).');
        }
        if (!in_array((string) $invoice['status'], self::LINKABLE_STATUSES, true)) {
            throw new InvoiceOtherItemLinkException('invalid_state', 'Stornovanou fakturu nelze navázat na ostatní pohledávku.');
        }
        if (abs((float) $invoice['total_vat']) >= 0.005) {
            throw new InvoiceOtherItemLinkException('invoice_has_vat',
                'Faktura obsahuje DPH. Navázat na ostatní pohledávku lze jen fakturu bez DPH (např. osvobozený prodej pozemku), jinak by daň v účetnictví chyběla.');
        }
        if ((string) $item['side'] !== 'receivable') {
            throw new InvoiceOtherItemLinkException('other_item_not_receivable', 'Fakturu lze navázat jen na ostatní pohledávku, ne na závazek.');
        }
        if ((string) $item['status'] !== 'posted' || $item['journal_entry_id'] === null) {
            throw new InvoiceOtherItemLinkException('other_item_not_posted', 'Ostatní pohledávka není zaúčtovaná.');
        }
        if ((string) $item['currency'] !== (string) $invoice['currency']) {
            throw new InvoiceOtherItemLinkException('currency_mismatch', sprintf(
                'Měna faktury (%s) se liší od měny ostatní pohledávky (%s).', $invoice['currency'], $item['currency']));
        }
        if (!self::sameAmount((float) $item['amount'], (float) $invoice['total_with_vat'])) {
            throw new InvoiceOtherItemLinkException('amount_mismatch', sprintf(
                'Částka faktury (%s %s) se liší od částky ostatní pohledávky (%s %s).',
                number_format((float) $invoice['total_with_vat'], 2, ',', ' '), $invoice['currency'],
                number_format((float) $item['amount'], 2, ',', ' '), $item['currency']));
        }
    }

    private function afterChange(int $invoiceId): void
    {
        try {
            $this->pdf->invalidate($invoiceId, 'invalidate_payment_change');
            $this->stats->recomputeAfterCommit($invoiceId);
        } catch (\Throwable $e) {
            error_log('Obnova výstupů faktury #' . $invoiceId . ' po změně vazby na ostatní pohledávku selhala: ' . $e->getMessage());
        }
    }

    /** @return array<string,mixed>|null */
    private function invoice(int $supplierId, int $invoiceId, bool $lock): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id, i.client_id, i.invoice_type, i.status, i.varsymbol, i.payment_variable_symbol,
                    i.total_with_vat, i.total_vat, i.other_item_id, cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ? AND i.supplier_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    private function item(int $supplierId, int $otherItemId, bool $lock): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, side, status, document_no, currency, amount, journal_entry_id
               FROM other_items WHERE id = ? AND supplier_id = ? AND deleted_at IS NULL' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$otherItemId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function isDoubleEntry(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() === 'double_entry';
    }

    /**
     * Variabilní symboly faktury, podle kterých odběratel platí.
     *
     * @param array<string,mixed> $invoice
     * @return list<string>
     */
    private static function symbols(array $invoice): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $vs): string => trim((string) $vs),
            [$invoice['payment_variable_symbol'] ?? null, $invoice['varsymbol'] ?? null],
        ), static fn (string $vs): bool => $vs !== '')));
    }

    private static function sameAmount(float $a, float $b): bool
    {
        return (int) round($a * 100) === (int) round($b * 100);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function castItem(array $row): array
    {
        $amount = round((float) $row['amount'], 2);
        $paid = round((float) $row['paid_amount'], 2);
        return [
            'id' => (int) $row['id'],
            'document_no' => $row['document_no'] !== null ? (string) $row['document_no'] : null,
            'title' => (string) $row['title'],
            'partner_name' => $row['partner_name'] !== null ? (string) $row['partner_name'] : null,
            'variable_symbol' => $row['variable_symbol'] !== null && trim((string) $row['variable_symbol']) !== ''
                ? trim((string) $row['variable_symbol']) : null,
            'issued_on' => (string) $row['issued_on'],
            'amount' => $amount,
            'currency' => (string) $row['currency'],
            'status' => (string) $row['status'],
            'journal_entry_id' => $row['journal_entry_id'] !== null ? (int) $row['journal_entry_id'] : null,
            'paid_amount' => $paid,
            'remaining_amount' => round($amount - $paid, 2),
        ];
    }
}
