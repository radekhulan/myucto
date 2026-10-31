<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxSubmissionRepository;

/**
 * Fronta „doklady změněné po podání" (C7', audit 2026-07, vat).
 *
 * Pro dané období najde doklady (vydané/přijaté faktury i daňové pokladní doklady), které spadají
 * do OBDOBÍ POSLEDNÍHO archivovaného DPH přiznání, ale byly změněny (nebo přibyly) až PO jeho
 * vygenerování (`invoices` / `purchase_invoices` / `cash_documents`.`updated_at`
 * > `tax_submissions.generated_at`).
 * To jsou kandidáti na dodatečné přiznání — podklad pro rozhodnutí účetní, nikoli tvrdý gate.
 *
 * Má-li podání snapshot dokladů, rozhoduje jen to, co ovlivňuje DPH: částka, datum plnění, storno
 * a řádky přiznání. Úhrada, upomínka ani `updated_at` důvodem nejsou. `updated_at` se navíc mezi
 * letním a zimním časem čte jinak, když MariaDB nemá tabulky časových zón.
 *
 * Zařazení do období přebírá kanonický {@see VatLedgerService} (stejná logika period membership
 * jako reálné DPHDP3), takže fronta ukazuje přesně doklady, které do podaného období patří.
 */
final class VatPostFilingChangesService
{
    public function __construct(
        private readonly Connection $db,
        private readonly VatLedgerService $ledger,
        private readonly TaxSubmissionRepository $submissions,
    ) {}

    /**
     * @return array{
     *   has_filing: bool, snapshot_available: bool,
     *   submission: array{id:int, generated_at:string, form_variant:string}|null,
     *   documents: list<array{source:string, invoice_id:int, doc_number:?string,
     *                          total:float, updated_at:string}>
     * }
     */
    public function changes(
        int $supplierId,
        int $year,
        int $month,
        string $period = 'monthly',
        string $formCode = 'dphdp3',
        array $variants = ['B', 'O', 'D', 'E'],
    ): array {
        [$start, $end, $quarter] = $this->periodBounds($year, $month, $period);

        // Základna = poslední skutečně podané přiznání (řádné/opravné/dodatečné/opravné-dodatečné) —
        // změny po JAKÉMKOLI z nich znamenají, že podaný stav už neodpovídá dokladům. Bereme
        // všechny druhy kromě neplatných (validation_status='failed' filtruje repo).
        // Formulář je parametr, ne konstanta: tytéž doklady stojí za přiznáním i za
        // kontrolním hlášením, takže „co se změnilo po podání" je pro obě tvrzení stejná
        // otázka. KH ji dřív nemělo jak položit a UI u něj nemělo z čeho nabídnout opravné.
        $filing = $this->submissions->findLatestForPeriod(
            $supplierId,
            $formCode,
            $year,
            $quarter !== null ? null : $month,
            $quarter,
            $variants,
        );
        if ($filing === null) {
            return ['has_filing' => false, 'snapshot_available' => false, 'submission' => null, 'documents' => []];
        }
        $generatedAt = (string) $filing['generated_at'];

        // Doklady patřící do období (dle kanonického ledgeru) → množina ID per zdroj.
        // POZOR: pokladní řádky (document_kind='cash') mají source 'sale'/'purchase', ale jejich
        // invoice_id je cash_documents.id — NE invoices.id. Musí se rozlišit, jinak by se ID
        // pokladního dokladu dotazovalo v tabulce invoices (false positive/negative, viz C7' fix).
        $saleIds = [];
        $purchaseIds = [];
        $cashIds = [];
        $snapshots = ['sale' => [], 'purchase' => [], 'cash' => []];
        $vatLines = ['sale' => [], 'purchase' => [], 'cash' => []];
        foreach ($this->ledger->iterateReturnRows($supplierId, $start, $end, includeDrafts: false) as $r) {
            $id = (int) $r['invoice_id'];
            if (($r['document_kind'] ?? null) === 'cash') {
                $cashIds[$id] = true; // cash_documents.id
                self::addVatLine($vatLines['cash'][$id], $r);
            } elseif ($r['source'] === 'sale') {
                $saleIds[$id] = true;
                self::addVatLine($vatLines['sale'][$id], $r);
            } elseif ($r['source'] === 'purchase') {
                $purchaseIds[$id] = true;
                self::addVatLine($vatLines['purchase'][$id], $r);
            }
        }

        // M42/M25: přidej snapshot ID ze skutečně archivovaného podání. Po stornu nebo
        // přesunu DUZP už doklad v aktuálním ledgeru není, ale stále musíme zachytit jeho
        // updated_at > generated_at. Historické archivy bez snapshotu zůstávají omezené
        // na aktuální projekci; API to přizná přes snapshot_available=false.
        $documentRefs = $filing['summary']['document_refs'] ?? null;
        $snapshotAvailable = is_array($documentRefs);
        foreach ($snapshotAvailable ? $documentRefs : [] as $ref) {
            $id = (int) ($ref['invoice_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            match ((string) ($ref['source'] ?? '')) {
                'sale'     => $saleIds[$id] = true,
                'purchase' => $purchaseIds[$id] = true,
                'cash'     => $cashIds[$id] = true,
                default    => null,
            };
            $source = (string) ($ref['source'] ?? '');
            if (isset($snapshots[$source])) {
                $snapshots[$source][$id] = $ref;
            }
        }

        $documents = array_merge(
            $this->changedDocuments($supplierId, 'sale', array_keys($saleIds), $generatedAt, $snapshots['sale'], $vatLines['sale']),
            $this->changedDocuments($supplierId, 'purchase', array_keys($purchaseIds), $generatedAt, $snapshots['purchase'], $vatLines['purchase']),
            $this->changedCashDocuments($supplierId, array_keys($cashIds), $generatedAt, $snapshots['cash'], $vatLines['cash']),
        );
        // Nejnovější změny nahoře.
        usort($documents, static fn ($a, $b) => strcmp((string) $b['updated_at'], (string) $a['updated_at']));

        return [
            'has_filing' => true,
            'snapshot_available' => $snapshotAvailable,
            'submission' => [
                'id'           => (int) $filing['id'],
                'generated_at' => $generatedAt,
                'form_variant' => (string) ($filing['form_variant'] ?? 'B'),
            ],
            'documents' => $documents,
        ];
    }

    /**
     * Doklady z dané množiny ID, jejichž `updated_at` je STRIKTNĚ pozdější než podání (generated_at).
     *
     * @param list<int> $ids
     * @return list<array{source:string, invoice_id:int, doc_number:?string, total:float, updated_at:string}>
     */
    private function changedDocuments(
        int $supplierId,
        string $source,
        array $ids,
        string $generatedAt,
        array $snapshots,
        array $vatLines,
    ): array
    {
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        if ($source === 'sale') {
            $sql = "SELECT id, varsymbol AS doc_number, total_with_vat AS total, status,
                           effective_tax_date AS tax_date, updated_at
                      FROM invoices
                     WHERE supplier_id = ? AND id IN ({$ph})";
        } else {
            $sql = "SELECT id, vendor_invoice_number AS doc_number, total_with_vat AS total, status,
                           COALESCE(tax_date, issue_date) AS tax_date, updated_at
                      FROM purchase_invoices
                     WHERE supplier_id = ? AND id IN ({$ph})";
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, ...$ids]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!self::changedAfterFiling($row, $generatedAt, $snapshots[(int) $row['id']] ?? null, $vatLines[(int) $row['id']] ?? [])) {
                continue;
            }
            $out[] = [
                'source'     => $source,
                'invoice_id' => (int) $row['id'],
                'doc_number' => $row['doc_number'] !== null ? (string) $row['doc_number'] : null,
                'total'      => (float) $row['total'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }
        return $out;
    }

    /**
     * Daňové pokladní doklady (cash_documents) změněné po podání. Vlastní dotaz nad
     * `cash_documents.updated_at` — pokladní ID nikdy nesmí téct do dotazu na `invoices`
     * (kolize primárních klíčů napříč tabulkami). `source='cash'`, `invoice_id`=cash_documents.id.
     *
     * @param list<int> $ids
     * @return list<array{source:string, invoice_id:int, doc_number:?string, total:float, updated_at:string}>
     */
    private function changedCashDocuments(
        int $supplierId,
        array $ids,
        string $generatedAt,
        array $snapshots,
        array $vatLines,
    ): array
    {
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT id, doc_number, total_amount AS total, status,
                       COALESCE(tax_date, issue_date) AS tax_date, updated_at
                  FROM cash_documents
                 WHERE supplier_id = ? AND id IN ({$ph})";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, ...$ids]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!self::changedAfterFiling($row, $generatedAt, $snapshots[(int) $row['id']] ?? null, $vatLines[(int) $row['id']] ?? [])) {
                continue;
            }
            $out[] = [
                'source'     => 'cash',
                'invoice_id' => (int) $row['id'],
                'doc_number' => $row['doc_number'] !== null ? (string) $row['doc_number'] : null,
                'total'      => (float) $row['total'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }
        return $out;
    }

    /**
     * Součet jednoho řádku evidence do řádků přiznání dokladu. Sdílí ho snapshot podání
     * i dnešní porovnání, aby obě strany počítaly totéž.
     *
     * @param array<string,array{base:float,vat:float}>|null $lines by-ref
     * @param array<string,mixed> $row řádek {@see VatLedgerService::iterateReturnRows()}
     */
    public static function addVatLine(?array &$lines, array $row): void
    {
        if (($row['code'] ?? null) === null || ($row['dphdp3_line'] ?? null) === null) {
            return;
        }
        $key = $row['code'] . '|' . $row['dphdp3_line'] . '|' . round((float) ($row['vat_rate'] ?? 0.0), 2);
        $lines[$key] = [
            'base' => round(($lines[$key]['base'] ?? 0.0) + (float) ($row['base_czk'] ?? 0.0), 2),
            'vat'  => round(($lines[$key]['vat'] ?? 0.0) + (float) ($row['vat_czk'] ?? 0.0), 2),
        ];
    }

    /**
     * @param array<string,mixed> $row aktuální doklad
     * @param array<string,mixed>|null $snapshot doklad z podání; null = v podání nebyl
     * @param array<string,array{base:float,vat:float}> $vatLines dnešní řádky přiznání dokladu
     */
    public static function changedAfterFiling(array $row, string $generatedAt, ?array $snapshot, array $vatLines = []): bool
    {
        if ($snapshot === null) {
            return (string) $row['updated_at'] >= $generatedAt;
        }
        if (self::isOutOfReturn((string) $row['status']) !== self::isOutOfReturn((string) ($snapshot['status'] ?? ''))
            || (string) $row['tax_date'] !== (string) ($snapshot['tax_date'] ?? '')
            || round((float) $row['total'], 2) !== round((float) ($snapshot['total'] ?? 0.0), 2)) {
            return true;
        }
        // Starší snapshot řádky přiznání nenese, zůstává porovnání výše.
        return isset($snapshot['vat_lines']) && !self::sameVatLines((array) $snapshot['vat_lines'], $vatLines);
    }

    /** Koncept a storno do přiznání nepatří, ostatní stavy (vystaveno, upomínka, uhrazeno) DPH nemění. */
    private static function isOutOfReturn(string $status): bool
    {
        return in_array($status, ['draft', 'cancelled'], true);
    }

    /**
     * @param array<string,mixed> $filed
     * @param array<string,array{base:float,vat:float}> $current
     */
    private static function sameVatLines(array $filed, array $current): bool
    {
        if (array_diff_key($filed, $current) !== [] || array_diff_key($current, $filed) !== []) {
            return false;
        }
        foreach ($filed as $key => $line) {
            if (round((float) $line['base'], 2) !== round((float) $current[$key]['base'], 2)
                || round((float) $line['vat'], 2) !== round((float) $current[$key]['vat'], 2)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Hranice období (start, end, quarter|null) — shodně s KontrolniHlaseniBuilder/DphBookBuilder.
     *
     * @return array{0:string, 1:string, 2:?int}
     */
    private function periodBounds(int $year, int $month, string $period): array
    {
        if ($period === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $startMonth = ($quarter - 1) * 3 + 1;
            $endMonth = $quarter * 3;
            $start = sprintf('%04d-%02d-01', $year, $startMonth);
        } else {
            $quarter = null;
            $endMonth = $month;
            $start = sprintf('%04d-%02d-01', $year, $month);
        }
        $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endMonth)))
            ->modify('last day of this month')->format('Y-m-d');
        return [$start, $end, $quarter];
    }
}
