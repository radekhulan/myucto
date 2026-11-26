<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Evidence načteného zaúčtování mezd z mzdového programu (migrace 2300). Jeden řádek
 * na firmu, program, IČO a párovací symbol měsíce; drží doklady, které import založil.
 */
final class PayrollPostingImportRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, string $application, string $ico, string $symPar, bool $forUpdate = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_posting_imports
              WHERE supplier_id = ? AND source_application = ? AND source_ico = ? AND sym_par = ?'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $application, $ico, $symPar]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['internal_document_ids'] = self::ids($row['internal_document_ids']);
        $row['other_item_ids'] = self::ids($row['other_item_ids']);
        return $row;
    }

    /**
     * Platí ještě některý doklad dřívějšího načtení? Interní doklad zaúčtovaný nebo koncept,
     * ostatní závazek nesmazaný a nestornovaný.
     *
     * @param array<string,mixed> $row
     */
    public function hasActiveDocuments(int $supplierId, array $row): bool
    {
        $pdo = $this->db->pdo();
        $internal = $row['internal_document_ids'];
        if ($internal !== []) {
            $in = implode(',', array_fill(0, count($internal), '?'));
            $stmt = $pdo->prepare("SELECT 1 FROM internal_documents
                WHERE supplier_id = ? AND id IN ({$in}) AND status IN ('draft','posted') LIMIT 1");
            $stmt->execute([$supplierId, ...$internal]);
            if ($stmt->fetchColumn() !== false) return true;
        }
        $items = $row['other_item_ids'];
        if ($items !== []) {
            $in = implode(',', array_fill(0, count($items), '?'));
            $stmt = $pdo->prepare("SELECT 1 FROM other_items
                WHERE supplier_id = ? AND id IN ({$in}) AND deleted_at IS NULL AND status IN ('draft','confirmed','posted') LIMIT 1");
            $stmt->execute([$supplierId, ...$items]);
            if ($stmt->fetchColumn() !== false) return true;
        }
        return false;
    }

    /**
     * Měsíce (`YYYY-MM`), jejichž zaúčtování mezd je načtené z mzdového programu a některý
     * jeho doklad platí. Převod z jiného účetního programu za ně mzdové závazky nezakládá.
     *
     * @param list<string> $periods
     * @return array<string,true>
     */
    public function activePeriods(int $supplierId, array $periods): array
    {
        $periods = array_values(array_unique($periods));
        if ($periods === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT period, internal_document_ids, other_item_ids FROM payroll_posting_imports
              WHERE supplier_id = ? AND period IN (' . implode(',', array_fill(0, count($periods), '?')) . ')'
        );
        $stmt->execute([$supplierId, ...$periods]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['internal_document_ids'] = self::ids($row['internal_document_ids']);
            $row['other_item_ids'] = self::ids($row['other_item_ids']);
            if (!isset($out[(string) $row['period']]) && $this->hasActiveDocuments($supplierId, $row)) {
                $out[(string) $row['period']] = true;
            }
        }
        return $out;
    }

    /**
     * Načtená zaúčtování mezd, jejichž některý doklad platí: měsíce (`YYYY-MM`) a párovací
     * symboly. Převod z jiného účetního programu za ně mzdové závazky nezakládá
     * ({@see \MyInvoice\Service\Migration\Pohoda\OtherItemImporter}).
     *
     * `dates` = den zaúčtování (nejdřívější interní doklad) načteného měsíce.
     *
     * @return array{periods:array<string,true>,sym_pars:array<string,true>,dates:array<string,string>}
     */
    public function activeImports(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT period, sym_par, internal_document_ids, other_item_ids FROM payroll_posting_imports WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $out = ['periods' => [], 'sym_pars' => [], 'dates' => []];
        $documents = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['internal_document_ids'] = self::ids($row['internal_document_ids']);
            $row['other_item_ids'] = self::ids($row['other_item_ids']);
            if ($this->hasActiveDocuments($supplierId, $row)) {
                $out['periods'][(string) $row['period']] = true;
                if ((string) $row['sym_par'] !== '') {
                    $out['sym_pars'][(string) $row['sym_par']] = true;
                }
                foreach ($row['internal_document_ids'] as $id) {
                    $documents[$id] = (string) $row['period'];
                }
            }
        }
        if ($documents !== []) {
            $dates = $this->db->pdo()->prepare('SELECT id, entry_date FROM internal_documents WHERE supplier_id = ? AND id IN ('
                . implode(',', array_fill(0, count($documents), '?')) . ") AND status = 'posted'");
            $dates->execute([$supplierId, ...array_keys($documents)]);
            foreach ($dates->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $period = $documents[(int) $row['id']];
                $date = (string) $row['entry_date'];
                if (!isset($out['dates'][$period]) || $date < $out['dates'][$period]) {
                    $out['dates'][$period] = $date;
                }
            }
        }
        return $out;
    }

    public function delete(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM payroll_posting_imports WHERE id = ? AND supplier_id = ?')
            ->execute([$id, $supplierId]);
    }

    /**
     * @param array{application:string,ico:string,sym_par:string,period:string,pack_id:?string,
     *   file_name:?string,file_sha256:string,total_amount:float} $source
     * @param list<int> $internalDocumentIds
     * @param list<int> $otherItemIds
     */
    public function insert(int $supplierId, array $source, array $internalDocumentIds, array $otherItemIds, ?int $userId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_posting_imports
                (supplier_id, source_application, source_ico, sym_par, period, pack_id, file_name, file_sha256,
                 total_amount, internal_document_ids, other_item_ids, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId, $source['application'], $source['ico'], $source['sym_par'], $source['period'],
            $source['pack_id'], $source['file_name'], $source['file_sha256'], $source['total_amount'],
            json_encode(array_values($internalDocumentIds), JSON_THROW_ON_ERROR),
            json_encode(array_values($otherItemIds), JSON_THROW_ON_ERROR),
            $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return list<int> */
    private static function ids(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;
        return is_array($decoded) ? array_values(array_map('intval', $decoded)) : [];
    }
}
