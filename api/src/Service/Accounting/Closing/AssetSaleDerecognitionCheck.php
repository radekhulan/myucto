<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use PDO;

/**
 * Prodej dlouhodobého majetku bez odúčtování zůstatkové ceny (měsíční kontrola i precheck uzávěrky).
 *
 * Tržba z prodeje (641, u rámce 504 účet 652) se zaúčtuje sama s fakturou, ostatní položkou
 * nebo pokladním dokladem. Odúčtování prodaného majetku (MD 541 / D 0xx, u 504 MD 552) je ale
 * samostatný interní doklad, na který se zapomíná. Typicky u pozemků: nejsou v evidenci
 * majetku, takže je nepohlídá ani vyřazení karty.
 *
 * Kontrola čte deník, ne evidenci dokladů. Pokryje tak fakturu, ostatní položku, pokladnu
 * i ruční zápis stejně a faktura vystavená jen kvůli DPH (tržba je na jiném dokladu) se
 * nepočítá dvakrát. Párování je počítací a záměrně shovívavé, aby nehlásilo zbytečně:
 *  - prodej = zaúčtovaný nestornovaný zápis od začátku období do data Do s čistou tržbou
 *    na účtu tržeb z prodeje majetku,
 *  - odúčtování = zápis se řádkem MD zůstatkové ceny nebo s řádkem Dal na majetkovém účtu
 *    01x až 03x (doprodej plně odepsaného majetku 541 nemá); jeden zápis pokryje tolik
 *    prodejů, kolik má řádků MD zůstatkové ceny, nebo řádků Dal majetku, je-li jich víc,
 *  - odúčtování se přiřazují k prodejům postupně podle data, nepokryté zůstanou nejnovější
 *    prodeje,
 *  - prodej navázaný na vyřazenou kartu majetku (řádek faktury s kartou nebo karta
 *    s fakturou prodeje) se nehlásí, vyřazení karty odúčtování zaúčtovalo.
 * Zápisy uzávěrky a otevření účtů se nepočítají: převádějí zůstatky, nejsou to případy.
 */
final class AssetSaleDerecognitionCheck
{
    public const KEY = 'asset_sale_without_derecognition';

    /** Zápisy, které jen převádějí zůstatky mezi obdobími. */
    private const IGNORED_SOURCES = ['closing', 'opening'];

    /** Majetkové účty, jejichž Dal znamená úbytek majetku (DNM, odpisovaný a neodpisovaný DHM). */
    private const ASSET_PREFIXES = ['01', '02', '03'];

    public function __construct(private readonly Connection $db) {}

    /**
     * Účet tržeb z prodeje majetku a účet zůstatkové ceny podle účetního rámce.
     * U rámce 504 jsou 641/541 smluvní pokuty, proto se kontrola nesmí ptát na ně.
     *
     * @return array{revenue:string, residual:string}
     */
    public static function accounts(string $framework): array
    {
        return $framework === AccountingFrameworkService::CZ_504
            ? ['revenue' => '652', 'residual' => '552']
            : ['revenue' => '641', 'residual' => '541'];
    }

    /**
     * @return array{key:string, severity:string, ok:bool, value:array<string,mixed>}
     */
    public function check(int $supplierId, string $periodFrom, string $rangeTo, string $framework): array
    {
        $codes = self::accounts($framework);
        $accounts = $this->accountIds($supplierId, $codes);

        $sales = $accounts['revenue'] === [] ? [] : $this->sales($supplierId, $accounts['revenue'], $periodFrom, $rangeTo);
        $sales = $this->withoutDisposedAssets($supplierId, $sales);
        $capacity = $sales === [] ? 0 : $this->derecognitionCapacity($supplierId, $accounts, $periodFrom, $rangeTo);

        $unmatched = array_slice($sales, min($capacity, count($sales)));
        $items = array_map(static fn (array $s): array => self::finding($s, $codes['residual']), $unmatched);

        return [
            'key' => self::KEY,
            'severity' => 'warning',
            'ok' => $items === [],
            'value' => [
                'count' => count($items),
                'items' => $items,
                'sales' => count($sales),
                'derecognitions' => $capacity,
                'revenue_account' => $codes['revenue'],
                'residual_account' => $codes['residual'],
            ],
        ];
    }

    /**
     * @param array{revenue:string, residual:string} $codes
     * @return array{revenue:list<int>, residual:list<int>, asset:list<int>}
     */
    private function accountIds(int $supplierId, array $codes): array
    {
        $prefixes = [$codes['revenue'], $codes['residual'], ...self::ASSET_PREFIXES];
        $where = implode(' OR ', array_fill(0, count($prefixes), 'account_code LIKE ?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ? AND ({$where})"
        );
        $stmt->execute([$supplierId, ...array_map(static fn (string $p): string => $p . '%', $prefixes)]);

        $out = ['revenue' => [], 'residual' => [], 'asset' => []];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $code = (string) $row['account_code'];
            $id = (int) $row['id'];
            if (str_starts_with($code, $codes['revenue'])) {
                $out['revenue'][] = $id;
            } elseif (str_starts_with($code, $codes['residual'])) {
                $out['residual'][] = $id;
            } else {
                $out['asset'][] = $id;
            }
        }

        return $out;
    }

    /**
     * Zápisy s čistou tržbou z prodeje majetku, chronologicky.
     *
     * @param list<int> $revenueIds
     * @return list<array<string,mixed>>
     */
    private function sales(int $supplierId, array $revenueIds, string $from, string $to): array
    {
        $in = implode(',', array_fill(0, count($revenueIds), '?'));
        $ignored = implode(',', array_fill(0, count(self::IGNORED_SOURCES), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id AS entry_id, e.entry_date, e.source_type, e.source_id, e.document_no, e.description,
                    SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END) AS revenue
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.account_id IN ({$in})
                AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                AND e.entry_date >= ? AND e.entry_date <= ?
                AND e.source_type NOT IN ({$ignored})
              GROUP BY e.id, e.entry_date, e.source_type, e.source_id, e.document_no, e.description
             HAVING revenue > 0.005
              ORDER BY e.entry_date, e.id"
        );
        $stmt->execute([$supplierId, ...$revenueIds, $from, $to, ...self::IGNORED_SOURCES]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Vyřadí prodeje fakturou navázanou na vyřazenou kartu majetku.
     *
     * @param list<array<string,mixed>> $sales
     * @return list<array<string,mixed>>
     */
    private function withoutDisposedAssets(int $supplierId, array $sales): array
    {
        $invoiceIds = [];
        foreach ($sales as $s) {
            if ($s['source_type'] === 'invoice' && $s['source_id'] !== null) {
                $invoiceIds[] = (int) $s['source_id'];
            }
        }
        if ($invoiceIds === []) {
            return $sales;
        }

        $in = implode(',', array_fill(0, count($invoiceIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT ii.invoice_id
               FROM invoice_items ii
               JOIN invoices i ON i.id = ii.invoice_id AND i.supplier_id = ?
               JOIN assets a ON a.id = ii.asset_id AND a.supplier_id = i.supplier_id
              WHERE ii.invoice_id IN ({$in}) AND a.status = 'disposed'
             UNION
             SELECT a.sale_invoice_id
               FROM assets a
              WHERE a.supplier_id = ? AND a.sale_invoice_id IN ({$in}) AND a.status = 'disposed'"
        );
        $stmt->execute([$supplierId, ...$invoiceIds, $supplierId, ...$invoiceIds]);
        $covered = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));

        return array_values(array_filter(
            $sales,
            static fn (array $s): bool => !($s['source_type'] === 'invoice' && isset($covered[(int) $s['source_id']])),
        ));
    }

    /**
     * Kolik prodejů pokrývají zápisy odúčtování. Zápis MD 541 / D 031 je jeden prodej, ne dva:
     * počítá se větší z počtu řádků MD zůstatkové ceny a řádků Dal majetkového účtu.
     *
     * @param array{revenue:list<int>, residual:list<int>, asset:list<int>} $accounts
     */
    private function derecognitionCapacity(int $supplierId, array $accounts, string $from, string $to): int
    {
        $ids = [...$accounts['residual'], ...$accounts['asset']];
        if ($ids === []) {
            return 0;
        }
        $residual = $accounts['residual'] === [] ? [0] : $accounts['residual'];
        $asset = $accounts['asset'] === [] ? [0] : $accounts['asset'];

        $in = implode(',', array_fill(0, count($ids), '?'));
        $inResidual = implode(',', array_fill(0, count($residual), '?'));
        $inAsset = implode(',', array_fill(0, count($asset), '?'));
        $ignored = implode(',', array_fill(0, count(self::IGNORED_SOURCES), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(GREATEST(per_entry.residual_lines, per_entry.asset_lines)), 0)
               FROM (
                   SELECT e.id,
                          SUM(l.side = 'debit' AND l.account_id IN ({$inResidual})) AS residual_lines,
                          SUM(l.side = 'credit' AND l.account_id IN ({$inAsset})) AS asset_lines
                     FROM journal_entry_lines l
                     JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
                    WHERE l.supplier_id = ? AND l.account_id IN ({$in}) AND l.signed_amount > 0
                      AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                      AND e.entry_date >= ? AND e.entry_date <= ?
                      AND e.source_type NOT IN ({$ignored})
                    GROUP BY e.id
               ) per_entry"
        );
        $stmt->execute([...$residual, ...$asset, $supplierId, ...$ids, $from, $to, ...self::IGNORED_SOURCES]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string,mixed> $sale
     * @return array<string,mixed>
     */
    private static function finding(array $sale, string $residualAccount): array
    {
        $entryId = (int) $sale['entry_id'];
        $docType = $sale['source_id'] === null ? 'journal_entry' : match ((string) $sale['source_type']) {
            'invoice' => 'invoice',
            'purchase_invoice' => 'purchase_invoice',
            'other_item' => 'other_item',
            default => 'journal_entry',
        };
        $docId = $docType === 'journal_entry' ? $entryId : (int) $sale['source_id'];
        $description = trim((string) ($sale['description'] ?? ''));

        return [
            'doc_type' => $docType,
            'doc_id' => $docId,
            'doc_no' => (string) ($sale['document_no'] ?? '') !== '' ? (string) $sale['document_no'] : '#' . $entryId,
            'doc_date' => (string) $sale['entry_date'],
            'amount' => round((float) $sale['revenue'], 2),
            'entry_id' => $entryId,
            'note' => ($description !== '' ? $description . ': ' : '')
                . 'chybí odúčtování zůstatkové ceny MD ' . $residualAccount . ' / D 0xx',
        ];
    }
}
