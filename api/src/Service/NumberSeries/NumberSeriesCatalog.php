<?php

declare(strict_types=1);

namespace MyInvoice\Service\NumberSeries;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NumberSeriesRepository;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use MyInvoice\Service\PurchaseInvoice\PurchaseNumberSeries;
use PDO;

/**
 * Převod stávajících řad do číselníku — jediné místo, které ví, jaké řady firma
 * dnes má v dosavadních úložištích:
 *
 *  - vydané doklady: řada faktur, záloh a dobropisů firmy, vlastní řada zjednodušených
 *    dokladů, řady klientů a kategorií tržby s vlastní šablonou (`VarsymbolGenerator`);
 *  - přijaté doklady: společná řada nebo řady po druzích (`PurchaseNumberSeries`);
 *  - řady `accounting_document_series`: PPD/VPD firmy i vlastní řady pokladen, interní
 *    doklady (ID) a ostatní řady, které už firma použila;
 *  - banka: řada bankovního účtu (číslo = řada + měsíc výpisu).
 *
 * Převedená řada (`storage = legacy`) nese jen identitu (`legacy_key`) a vlastnosti
 * číselníku. Šablona i počítadlo zůstávají, kde byly, takže se nezmění číslo žádného
 * dokladu ani další vydané číslo. Převod je idempotentní (klíč `legacy_key`), spouští ho
 * `migrate.php` (api/bin/number-series-convert.php) a znovu číselník při načtení, aby
 * se objevily řady, které vznikly později (nová pokladna s vlastní řadou, klient
 * s vlastní šablonou).
 */
final class NumberSeriesCatalog
{
    /** Řady accounting_document_series, které má každá firma i bez jediného dokladu. */
    private const ALWAYS_DOCUMENT_SERIES = ['cash_in', 'cash_out', 'manual'];

    /** Výchozí převedené řady druhů, ve kterých jde vybírat řadu. */
    public const MAIN_LEGACY_KEYS = [
        'invoice'     => 'issued:invoice',
        'proforma'    => 'issued:proforma',
        'credit_note' => 'issued:credit_note',
        'purchase'    => 'purchase',
        'cash_in'     => 'doc:cash_in:0',
        'cash_out'    => 'doc:cash_out:0',
        'manual'      => 'doc:manual:0',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly NumberSeriesRepository $series,
    ) {}

    /** Převede řady firmy, které v číselníku ještě nejsou. Vrací počet nově převedených. */
    public function sync(int $supplierId): int
    {
        $existing = array_flip($this->series->legacyKeys($supplierId));
        $added = 0;
        foreach ($this->liveLegacy($supplierId) as $row) {
            if (isset($existing[$row['legacy_key']])) {
                continue;
            }
            $this->series->insertLegacy($supplierId, $row);
            $added++;
        }
        return $added;
    }

    /** Převede řady všech firem. @return array{suppliers:int, series:int} */
    public function syncAll(): array
    {
        $ids = $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $series = 0;
        foreach ($ids as $id) {
            $series += $this->sync((int) $id);
        }
        return ['suppliers' => count($ids), 'series' => $series];
    }

    /** Počet firem, kterým převod ještě neproběhl (pro auto-backfill v migrate.php). */
    public function pending(): int
    {
        if (!$this->db->hasTable('number_series')) {
            return 0;
        }
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM supplier s
              WHERE NOT EXISTS (SELECT 1 FROM number_series n
                                 WHERE n.supplier_id = s.id AND n.legacy_key = 'issued:invoice')"
        )->fetchColumn();
    }

    /**
     * Řady, které firma v dosavadních úložištích právě teď má.
     *
     * @return list<array{legacy_key:string, doc_kind:string, scope_type:string, scope_id:int,
     *                    name:string, prefix:?string, is_default:bool, sort_order:int}>
     */
    public function liveLegacy(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $out = [];
        $add = static function (string $key, string $kind, string $scopeType = 'company', int $scopeId = 0,
            string $name = '', ?string $prefix = null, bool $default = false, int $sort = 0) use (&$out): void {
            $out[] = [
                'legacy_key' => $key, 'doc_kind' => $kind, 'scope_type' => $scopeType, 'scope_id' => $scopeId,
                'name' => $name, 'prefix' => $prefix, 'is_default' => $default, 'sort_order' => $sort,
            ];
        };

        $supplierCols = ['simplified_number_format', 'accounting_mode', ...PurchaseNumberSeries::supplierColumns()];
        $supplierCols = array_values(array_filter($supplierCols, fn (string $c): bool => $this->db->hasColumn('supplier', $c)));
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $supplierCols) . ' FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach (['invoice', 'proforma', 'credit_note'] as $type) {
            $add("issued:{$type}", $type, 'company', 0, '', null, true);
        }
        if (trim((string) ($supplier['simplified_number_format'] ?? '')) !== '') {
            $add('issued:simplified', 'simplified', 'company', 0, '', null, true);
        }

        foreach (['clients' => ['client', 'company_name'], 'revenue_categories' => ['revenue_category', 'label']] as $table => [$scope, $label]) {
            $stmt = $pdo->prepare(
                "SELECT id, {$label} AS label, invoice_number_format, proforma_number_format, credit_note_number_format
                   FROM {$table}
                  WHERE supplier_id = ?
                    AND (COALESCE(invoice_number_format, '') <> '' OR COALESCE(proforma_number_format, '') <> ''
                         OR COALESCE(credit_note_number_format, '') <> '')
                  ORDER BY id"
            );
            $stmt->execute([$supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                foreach (['invoice', 'proforma', 'credit_note'] as $type) {
                    if (trim((string) ($row["{$type}_number_format"] ?? '')) !== '') {
                        $add("{$scope}:{$row['id']}:{$type}", $type, $scope, (int) $row['id'], (string) $row['label'], null, false, 10);
                    }
                }
            }
        }

        foreach (array_keys(PurchaseNumberSeries::all($supplier)) as $key) {
            $add($key === '' ? 'purchase' : "purchase:{$key}", 'purchase', 'company', 0, '', null, $key === '', $key === '' ? 0 : 1);
        }

        // Deníkové řady (ID, uzávěrka, zápočty) vydává jen podvojné účetnictví, stejně jako
        // je nabízí DocumentSeriesAction.
        $doubleEntry = ($supplier['accounting_mode'] ?? 'tax_evidence') === 'double_entry';
        $documentSeries = [];
        foreach (self::ALWAYS_DOCUMENT_SERIES as $code) {
            if (!$doubleEntry && in_array($code, DocumentSeriesService::DOUBLE_ENTRY_ONLY_SERIES, true)) {
                continue;
            }
            $documentSeries["{$code}:0"] = ['code' => $code, 'register' => 0, 'prefix' => null];
        }
        $stmt = $pdo->prepare(
            'SELECT series_code, register_id, prefix FROM accounting_document_series
              WHERE supplier_id = ? ORDER BY series_code, register_id, fiscal_year DESC'
        );
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!$doubleEntry && in_array((string) $row['series_code'], DocumentSeriesService::DOUBLE_ENTRY_ONLY_SERIES, true)) {
                continue;
            }
            $k = $row['series_code'] . ':' . (int) $row['register_id'];
            if (!isset($documentSeries[$k]) || $documentSeries[$k]['prefix'] === null) {
                $documentSeries[$k] = ['code' => (string) $row['series_code'], 'register' => (int) $row['register_id'], 'prefix' => (string) $row['prefix']];
            }
        }
        // Pokladna s vlastní řadou ji má i před prvním dokladem (řádek řady vzniká lazy).
        $stmt = $pdo->prepare('SELECT id FROM cash_registers WHERE supplier_id = ? AND own_series = 1 ORDER BY id');
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $registerId) {
            foreach (['cash_in', 'cash_out'] as $code) {
                $documentSeries["{$code}:{$registerId}"] ??= ['code' => $code, 'register' => (int) $registerId, 'prefix' => null];
            }
        }
        $registerNames = $this->names('cash_registers', 'name', $supplierId);
        foreach ($documentSeries as $s) {
            if (!NumberSeriesKinds::exists($s['code'])) {
                continue;
            }
            $isCash = in_array($s['code'], ['cash_in', 'cash_out'], true);
            $scopeType = $s['register'] === 0 ? 'company' : ($isCash ? 'cash_register' : 'other_item_kind');
            $name = $isCash && $s['register'] > 0 ? (string) ($registerNames[$s['register']] ?? '') : '';
            $add("doc:{$s['code']}:{$s['register']}", $s['code'], $scopeType, $s['register'], $name, $s['prefix'], true);
        }

        if ($this->db->hasColumn('supplier_bank_accounts', 'document_series')) {
            $stmt = $pdo->prepare(
                "SELECT id, COALESCE(NULLIF(label, ''), account_number, '') AS label, document_series
                   FROM supplier_bank_accounts
                  WHERE supplier_id = ? AND COALESCE(document_series, '') <> ''
                  ORDER BY id"
            );
            $stmt->execute([$supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $add("bank:{$row['id']}", 'bank', 'bank_account', (int) $row['id'], (string) $row['label'], (string) $row['document_series'], true);
            }
        }

        return $out;
    }

    /** @return array<int,string> */
    private function names(string $table, string $column, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT id, {$column} FROM {$table} WHERE supplier_id = ?");
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) ?: [] as [$id, $name]) {
            $out[(int) $id] = (string) $name;
        }
        return $out;
    }
}
