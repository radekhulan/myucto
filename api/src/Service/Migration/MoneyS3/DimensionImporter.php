<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CostCenterRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;

/**
 * Středisko a zakázka z deníku Money do dimenzí MyÚčta (Firma → Dimenze).
 *
 * - `Stred` → hodnota typu Středisko (firemní) navázaná na číselník `cost_centers`;
 *   textový kód střediska na řádku deníku zůstává jako dosud.
 * - `Zakazka` ve tvaru registrační značky → hodnota typu Vozidlo (firemní) navázaná
 *   na vůz z knihy jízd, je-li tam; různé zápisy téže značky jsou jedna hodnota
 *   ({@see Ms3Journal::jobCode()}).
 * - Ostatní `Zakazka` → hodnota typu Projekt. Patří-li firma do skupiny firem, je
 *   Projekt globální: stejný kód zakázky v mateřské firmě i v SPV je jeden projekt.
 * - `Cinnost` Money skupina nepoužívá, nepřevádí se.
 *
 * Převod dimenze u firmy zapne. Hodnota, kterou poslední převáděný rok nepoužil,
 * vznikne uzavřená; hodnotu, kterou poslední rok použil, převod znovu otevře.
 * Existující hodnotu převod nikdy nezavírá.
 *
 * Dimenze uzavřených let zůstávají beze změny. Pro řádky v otevřených obdobích
 * je rozhodující Money: opakovaný převod přepíše ruční změnu spravovaných typů.
 *
 * Doklad (přijatá a vydaná faktura, pokladní doklad, bankovní pohyb) dostane hodnotu
 * do hlavičky, když všechny řádky jeho zápisů s tímto typem nesou tutéž. Hlavičku,
 * kterou už někdo vyplnil, převod nemění.
 */
final class DimensionImporter
{
    /**
     * Typy dimenzí, které převod na řádcích a dokladech z Money spravuje (středisko, vozidlo,
     * projekt ze zakázky). Dimenze jiného typu přiřadil uživatel v MyÚčtu.
     */
    public const MANAGED_TYPE_CODES = ['stredisko', 'vozidlo', 'projekt'];

    public const STEP = 'dimensions';

    private const BATCH = 1000;

    /** Zdroj zápisu v deníku => typ dokladu v document_dimensions. */
    private const DOCUMENT_SOURCES = [
        'purchase_invoice' => 'purchase_invoice',
        'invoice' => 'invoice',
        'cash' => 'cash_document',
        'bank' => 'bank_transaction',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly JournalImporter $journal,
        private readonly CostCenterRepository $costCenters,
        private readonly DimensionRepository $dimensions,
        private readonly DimensionService $dimensionService,
        private readonly MoneyS3ImportRepository $map,
        private readonly DimensionPeriodGuard $periodGuard,
    ) {}

    public function run(ImportContext $ctx): void
    {
        $this->periodGuard->transaction(fn () => $this->import($ctx));
    }

    private function import(ImportContext $ctx): void
    {
        $p = $ctx->protocol;

        // Deník se čte po letech dvakrát (hodnoty dimenzí, pak razítka řádků), ať v paměti
        // neleží dimenze řádků všech let naráz.
        $centers = [];
        $jobs = [];
        foreach ($this->journal->lineDimensions($ctx) as [, $lines]) {
            foreach ($lines as $key => $byLine) {
                $year = (int) explode('|', (string) $key, 2)[0];
                foreach ($byLine as $no => $d) {
                    // Řádek Money = dva řádky deníku (MD a D); počítá se jednou.
                    if ($no % 2 === 0) {
                        continue;
                    }
                    if ($d['stred'] !== null) {
                        $centers[$d['stred']] = self::used($centers[$d['stred']] ?? null, $year);
                    }
                    if ($d['zakazka'] !== null) {
                        $jobs[$d['zakazka']] = self::used($jobs[$d['zakazka']] ?? null, $year);
                    }
                }
            }
        }
        if ($centers === [] && $jobs === []) {
            $p->finish(self::STEP);
            return;
        }
        $lastYear = $ctx->dirYears === [] ? 0 : max($ctx->dirYears);

        $this->dimensionService->setEnabled($ctx->supplierId, true);
        $types = [];
        if ($centers !== []) {
            $types['center'] = $this->dimensionService->ensureDefaultTypes($ctx->supplierId, [self::MANAGED_TYPE_CODES[0]])['cost_center'];
        }
        $vehicles = array_filter(array_keys($jobs), static fn ($c): bool => Ms3Journal::vehiclePlate((string) $c) !== null);
        if ($vehicles !== []) {
            $types['vehicle'] = $this->dimensionService->ensureDefaultTypes($ctx->supplierId, [self::MANAGED_TYPE_CODES[1]])['vehicle'];
        }
        if (count($vehicles) < count($jobs)) {
            $types['project'] = $this->dimensionService->ensureDefaultTypes($ctx->supplierId, [self::MANAGED_TYPE_CODES[2]])['project'];
        }

        // Číselný kód (středisko `633585`) z klíče pole vyjde jako int.
        $centerValues = [];
        foreach ($centers as $code => $use) {
            $code = (string) $code;
            $centerValues[$code] = $this->value($ctx, $types['center'], 'cost_center', $code, $use['last'] >= $lastYear, [
                'cost_center_id' => $this->costCenter($ctx, $code),
            ]);
        }
        $jobValues = [];
        foreach ($jobs as $code => $use) {
            $code = (string) $code;
            $vehicle = Ms3Journal::vehiclePlate($code) !== null;
            $typeId = $vehicle ? $types['vehicle'] : $types['project'];
            $extra = [];
            if ($vehicle) {
                $car = $this->car($ctx, $code);
                $extra = ['car_id' => $car['id'] ?? null, 'name' => $car !== null && $car['name'] !== '' ? $code . ' - ' . $car['name'] : $code];
            }
            $jobValues[$code] = [$typeId, $this->value($ctx, $typeId, $vehicle ? 'vehicle' : 'project', $code, $use['last'] >= $lastYear, $extra)];
        }

        $managed = array_values($types);
        [$stamped, $entries] = $this->stampLines($ctx, $types['center'] ?? null, $centerValues, $jobValues, $managed);
        $p->count(self::STEP, 'lines', $stamped);
        $documents = $this->stampDocuments($ctx, $managed, $entries);
        $p->count(self::STEP, 'documents', $documents);

        $p->info(self::STEP, 'summary', sprintf(
            'Střediska: %d (%d řádků Money), zakázky: %d (%d řádků Money), z toho vozidla: %d. Dimenze jsou zapnuté v Firma → Dimenze.',
            count($centers),
            array_sum(array_column($centers, 'rows')),
            count($jobs),
            array_sum(array_column($jobs, 'rows')),
            count($vehicles),
        ));
        $p->finish(self::STEP);
    }

    /**
     * @param array{rows:int,last:int}|null $use
     * @return array{rows:int,last:int}
     */
    private static function used(?array $use, int $year): array
    {
        return ['rows' => ($use['rows'] ?? 0) + 1, 'last' => max($use['last'] ?? 0, $year)];
    }

    private function costCenter(ImportContext $ctx, string $code): int
    {
        $mapped = $this->map->get($ctx->supplierId, MoneyS3ImportRepository::KIND_COST_CENTER, $code);
        if ($mapped !== null) {
            return $mapped;
        }
        $existing = $this->costCenters->findByCode($ctx->supplierId, $code);
        if ($existing !== null) {
            $id = (int) $existing['id'];
        } else {
            $id = $this->costCenters->create($ctx->supplierId, $code, $code);
            $ctx->protocol->count(self::STEP, 'cost_centers_created');
        }
        $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_COST_CENTER, $code, $id, $ctx->runId);
        return $id;
    }

    /**
     * Hodnota dimenze pro kód z Money: z mapy převodu, podle kódu v typu (globální
     * projekt mohla založit jiná firma skupiny), nebo nová.
     *
     * @param array<string,mixed> $extra
     */
    private function value(ImportContext $ctx, int $typeId, string $kind, string $code, bool $active, array $extra): int
    {
        $key = $kind . ':' . $code;
        $mapped = $this->map->get($ctx->supplierId, MoneyS3ImportRepository::KIND_DIMENSION_VALUE, $key);
        $existing = $mapped !== null
            ? $this->dimensions->findValue($ctx->supplierId, $mapped)
            : $this->dimensions->findValueByCode($typeId, mb_substr($code, 0, 50));
        if ($existing !== null && (int) $existing['type_id'] === $typeId) {
            $id = (int) $existing['id'];
            if ($active && !$existing['is_active']) {
                $this->dimensions->updateValue($ctx->supplierId, $id, ['is_active' => true]);
            }
            $ctx->protocol->count(self::STEP, 'values_existing');
        } else {
            $type = $this->dimensions->findType($ctx->supplierId, $typeId);
            $id = $this->dimensions->createValue((array) $type, [
                'code' => mb_substr($code, 0, 50),
                'name' => mb_substr((string) ($extra['name'] ?? $code), 0, 190),
                'is_active' => $active,
                'car_id' => $extra['car_id'] ?? null,
                'cost_center_id' => $type !== null && $type['level'] === 'company' ? ($extra['cost_center_id'] ?? null) : null,
            ]);
            $ctx->protocol->count(self::STEP, 'values_created');
        }
        if ($mapped === null) {
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_DIMENSION_VALUE, $key, $id, $ctx->runId);
        } elseif ($mapped !== $id) {
            $this->map->repoint($ctx->supplierId, MoneyS3ImportRepository::KIND_DIMENSION_VALUE, $key, $id, $ctx->runId);
        }
        return $id;
    }

    /** @return array{id:int,name:string}|null vůz z knihy jízd podle registrační značky */
    private function car(ImportContext $ctx, string $plate): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, TRIM(CONCAT_WS(' ', NULLIF(name, ''), NULLIF(brand, ''), NULLIF(model, ''))) AS name
               FROM cars WHERE supplier_id = ? AND REPLACE(UPPER(registration), ' ', '') = ?
              ORDER BY is_archived, id LIMIT 1"
        );
        $stmt->execute([$ctx->supplierId, str_replace(' ', '', $plate)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : ['id' => (int) $row['id'], 'name' => trim((string) $row['name'])];
    }

    /**
     * @param array<string,int> $centerValues
     * @param array<string,array{0:int,1:int}> $jobValues kód => [typ, hodnota]
     * @param list<int> $managedTypes typy, které převod na řádcích z Money spravuje
     * @return array{int,array<int,array>} počet změněných vazeb a zamčené zápisy
     */
    private function stampLines(ImportContext $ctx, ?int $centerType, array $centerValues, array $jobValues, array $managedTypes): array
    {
        $pdo = $this->db->pdo();
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_money_s3_dimensions');
        $pdo->exec(
            'CREATE TEMPORARY TABLE tmp_money_s3_dimensions (
                entry_id BIGINT UNSIGNED NOT NULL,
                line_no INT UNSIGNED NOT NULL,
                cost_center VARCHAR(50) NULL,
                center_type BIGINT UNSIGNED NULL,
                center_value BIGINT UNSIGNED NULL,
                job_type BIGINT UNSIGNED NULL,
                job_value BIGINT UNSIGNED NULL,
                PRIMARY KEY (entry_id, line_no)
            ) ENGINE=InnoDB'
        );
        $batch = [];
        $flush = static function () use (&$batch, $pdo): void {
            if ($batch === []) {
                return;
            }
            $pdo->prepare(
                'INSERT INTO tmp_money_s3_dimensions (entry_id, line_no, cost_center, center_type, center_value, job_type, job_value) VALUES '
                . implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?)'))
            )->execute(array_merge(...$batch));
            $batch = [];
        };
        foreach ($this->journal->lineDimensions($ctx) as [$prefix, $lines]) {
            // Klíč zápisu v mapě je `rok|skupina` - stačí zápisy roku.
            $entries = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY, $prefix);
            foreach ($lines as $key => $byLine) {
                $entryId = $entries[$key] ?? null;
                if ($entryId === null) {
                    continue;
                }
                foreach ($byLine as $no => $d) {
                    $center = $d['stred'] !== null ? ($centerValues[$d['stred']] ?? null) : null;
                    $job = $d['zakazka'] !== null ? ($jobValues[$d['zakazka']] ?? null) : null;
                    $batch[] = [
                        $entryId, $no, $d['stred'],
                        $center !== null ? $centerType : null, $center,
                        $job[0] ?? null, $job[1] ?? null,
                    ];
                    if (count($batch) >= self::BATCH) {
                        $flush();
                    }
                }
            }
            unset($entries, $lines);
        }
        $flush();

        $this->periodGuard->lockPeriods($ctx->supplierId);
        $sources = implode(',', array_fill(0, count(self::DOCUMENT_SOURCES), '?'));
        $ids = $pdo->prepare("SELECT je.id FROM journal_entries je
            WHERE je.supplier_id = ? AND (je.source_type IN ({$sources})
                OR EXISTS (SELECT 1 FROM tmp_money_s3_dimensions t WHERE t.entry_id = je.id)) FOR UPDATE");
        $ids->execute([$ctx->supplierId, ...array_keys(self::DOCUMENT_SOURCES)]);
        $entries = $this->periodGuard->lockEntries($ctx->supplierId, array_map('intval', $ids->fetchAll(\PDO::FETCH_COLUMN)));
        $marks = implode(',', array_fill(0, count($managedTypes), '?'));
        $changed = $pdo->prepare("SELECT DISTINCT t.entry_id
            FROM tmp_money_s3_dimensions t
            JOIN journal_entry_lines jel ON jel.entry_id = t.entry_id AND jel.line_no = t.line_no
            WHERE jel.supplier_id = ? AND (
                NOT (jel.cost_center <=> t.cost_center)
                OR EXISTS (SELECT 1 FROM journal_entry_line_dimensions jd
                    WHERE jd.supplier_id = jel.supplier_id AND jd.line_id = jel.id AND jd.dimension_type_id IN ({$marks})
                    AND NOT (jd.dimension_type_id <=> t.center_type AND jd.dimension_value_id <=> t.center_value)
                    AND NOT (jd.dimension_type_id <=> t.job_type AND jd.dimension_value_id <=> t.job_value))
                OR EXISTS (SELECT 1 FROM journal_entry_line_dimension_splits ds
                    WHERE ds.supplier_id = jel.supplier_id AND ds.line_id = jel.id AND ds.dimension_type_id IN ({$marks}))
                OR (t.center_value IS NOT NULL AND NOT EXISTS (SELECT 1 FROM journal_entry_line_dimensions jd
                    WHERE jd.supplier_id = jel.supplier_id AND jd.line_id = jel.id
                    AND jd.dimension_type_id = t.center_type AND jd.dimension_value_id = t.center_value))
                OR (t.job_value IS NOT NULL AND NOT EXISTS (SELECT 1 FROM journal_entry_line_dimensions jd
                    WHERE jd.supplier_id = jel.supplier_id AND jd.line_id = jel.id
                    AND jd.dimension_type_id = t.job_type AND jd.dimension_value_id = t.job_value)))");
        $changed->execute([$ctx->supplierId, ...$managedTypes, ...$managedTypes]);
        $changedIds = array_fill_keys(array_map('intval', $changed->fetchAll(\PDO::FETCH_COLUMN)), true);
        $dropIds = [];
        foreach ($entries as $entryId => $entry) {
            if (!isset($changedIds[$entryId])) {
                $dropIds[] = $entryId;
                continue;
            }
            try {
                $this->periodGuard->assertMutable($entry);
            } catch (DimensionException $e) {
                if ($e->errorCode !== 'period_not_open') {
                    throw $e;
                }
                $dropIds[] = $entryId;
                $ctx->protocol->warn(self::STEP, $e->errorCode, 'Dimenze účetního zápisu v uzavřeném období zůstaly beze změny.', ['entry_id' => $entryId]);
            }
        }
        foreach (array_chunk($dropIds, self::BATCH) as $batchIds) {
            $dropMarks = implode(',', array_fill(0, count($batchIds), '?'));
            $pdo->prepare("DELETE FROM tmp_money_s3_dimensions WHERE entry_id IN ({$dropMarks})")->execute($batchIds);
        }

        $count = 0;
        // Textový kód střediska zůstává na řádku jako dosud (mzdy a starší sestavy).
        $stmt = $pdo->prepare(
            'UPDATE journal_entry_lines jel
               JOIN tmp_money_s3_dimensions t ON t.entry_id = jel.entry_id AND t.line_no = jel.line_no
                SET jel.cost_center = t.cost_center
              WHERE jel.supplier_id = ? AND NOT (jel.cost_center <=> t.cost_center)'
        );
        $stmt->execute([$ctx->supplierId]);

        if ($managedTypes !== []) {
            $marks = implode(',', array_fill(0, count($managedTypes), '?'));
            $delete = $pdo->prepare(
                "DELETE jd FROM journal_entry_line_dimensions jd
                   JOIN journal_entry_lines jel ON jel.id = jd.line_id AND jel.supplier_id = jd.supplier_id
                   JOIN tmp_money_s3_dimensions t ON t.entry_id = jel.entry_id AND t.line_no = jel.line_no
                  WHERE jd.supplier_id = ? AND jd.dimension_type_id IN ({$marks})
                    AND NOT (jd.dimension_type_id <=> t.center_type AND jd.dimension_value_id <=> t.center_value)
                    AND NOT (jd.dimension_type_id <=> t.job_type AND jd.dimension_value_id <=> t.job_value)"
            );
            $delete->execute([$ctx->supplierId, ...$managedTypes]);
            $count += $delete->rowCount();
            // Rozpad řádku (pravidla dimenzí) u typu, který převod spravuje, by vedle
            // hodnoty z Money dal řádku dvě odpovědi — pro řádky z Money rozhoduje Money.
            $deleteSplits = $pdo->prepare(
                "DELETE s FROM journal_entry_line_dimension_splits s
                   JOIN journal_entry_lines jel ON jel.id = s.line_id AND jel.supplier_id = s.supplier_id
                   JOIN tmp_money_s3_dimensions t ON t.entry_id = jel.entry_id AND t.line_no = jel.line_no
                  WHERE s.supplier_id = ? AND s.dimension_type_id IN ({$marks})"
            );
            $deleteSplits->execute([$ctx->supplierId, ...$managedTypes]);
            $count += $deleteSplits->rowCount();
        }
        foreach (['center', 'job'] as $col) {
            $insert = $pdo->prepare(
                "INSERT IGNORE INTO journal_entry_line_dimensions (line_id, dimension_type_id, supplier_id, dimension_value_id)
                 SELECT jel.id, t.{$col}_type, jel.supplier_id, t.{$col}_value
                   FROM tmp_money_s3_dimensions t
                   JOIN journal_entry_lines jel ON jel.entry_id = t.entry_id AND jel.line_no = t.line_no
                  WHERE jel.supplier_id = ? AND t.{$col}_value IS NOT NULL"
            );
            $insert->execute([$ctx->supplierId]);
            $count += $insert->rowCount();
        }
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_money_s3_dimensions');
        return [$count, $entries];
    }

    /**
     * @param list<int> $managedTypes
     * @return int počet doplněných hlaviček (doklad × typ)
     */
    private function stampDocuments(ImportContext $ctx, array $managedTypes, array $entries): int
    {
        // STRAIGHT_JOIN: čerstvě naplněná vazební tabulka nemá statistiky a optimalizátor
        // jinak volí průchod dimenzemi celé firmy pro každý zápis (desítky minut).
        if ($managedTypes === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($managedTypes), '?'));
        $count = 0;
        $byDocument = [];
        foreach ($entries as $entry) {
            if ($entry['source_id'] !== null) {
                $byDocument[$entry['source_type']][(int) $entry['source_id']][] = $entry;
            }
        }
        $insert = $this->db->pdo()->prepare('INSERT IGNORE INTO document_dimensions
            (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id) VALUES (?, ?, ?, 0, ?, ?)');
        foreach (self::DOCUMENT_SOURCES as $sourceType => $docType) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT x.source_id, x.type_id, x.value_id
                   FROM (SELECT je.source_id, jd.dimension_type_id AS type_id, MIN(jd.dimension_value_id) AS value_id
                           FROM journal_entries je
                           STRAIGHT_JOIN journal_entry_lines jel ON jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
                           STRAIGHT_JOIN journal_entry_line_dimensions jd ON jd.line_id = jel.id
                          WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id IS NOT NULL
                            AND jd.dimension_type_id IN ({$marks})
                          GROUP BY je.source_id, jd.dimension_type_id
                         HAVING COUNT(DISTINCT jd.dimension_value_id) = 1) x
                  WHERE NOT EXISTS (SELECT 1 FROM document_dimensions dd
                                     WHERE dd.supplier_id = ? AND dd.doc_type = ? AND dd.doc_id = x.source_id
                                       AND dd.item_no = 0 AND dd.dimension_type_id = x.type_id)
                    AND NOT EXISTS (SELECT 1 FROM document_dimension_splits ds
                                     WHERE ds.supplier_id = ? AND ds.doc_type = ? AND ds.doc_id = x.source_id
                                       AND ds.item_no = 0 AND ds.dimension_type_id = x.type_id)"
            );
            $stmt->execute([$ctx->supplierId, $sourceType, ...$managedTypes, $ctx->supplierId, $docType, $ctx->supplierId, $docType]);
            $candidates = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($candidates as $candidate) {
                $docId = (int) $candidate['source_id'];
                try {
                    foreach ($byDocument[$sourceType][$docId] ?? [] as $entry) {
                        $this->periodGuard->assertMutable($entry);
                    }
                } catch (DimensionException $e) {
                    if ($e->errorCode !== 'period_not_open') {
                        throw $e;
                    }
                    $ctx->protocol->warn(self::STEP, $e->errorCode, 'Dimenze dokladu v uzavřeném období zůstaly beze změny.', ['doc_type' => $docType, 'doc_id' => $docId]);
                    continue;
                }
                $insert->execute([$ctx->supplierId, $docType, $docId, $candidate['type_id'], $candidate['value_id']]);
                $count += $insert->rowCount();
            }
        }
        return $count;
    }
}
