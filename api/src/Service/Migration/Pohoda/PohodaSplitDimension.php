<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;

/**
 * Analytické členění POHODY (zakázka, činnost) jako firemní dimenze: společná část
 * {@see ContractImporter} a {@see ActivityImporter}. Členění nese řádek deníku i hlavička
 * a položka dokladu jako `typ:refType` pod jménem elementu ({@see CONTRACT}, {@see ACTIVITY});
 * kód členění na řádky a faktury sbírají {@see ChartJournalImporter} a {@see InvoiceImporter}
 * do {@see PohodaContext::$splitLines} a {@see PohodaContext::$splitDocuments}.
 *
 * - Řádek deníku dostane hodnotu podle svého vlastního členění.
 * - Vydaná a přijatá faktura dostane hodnotu do hlavičky podle členění hlavičky; nemá-li ho,
 *   podle členění položek, když se shodují. Položka s jiným členěním než hlavička ho dostane
 *   na položku, jen když převedený doklad má položky jedna k jedné jako POHODA.
 * - Ostatní doklady s dimenzemi (pokladna, banka) a faktury bez členění v exportu dostanou
 *   hodnotu do hlavičky, když ji nesou všechny řádky jejich zápisu se členěním. Interní
 *   doklad dimenze na hlavičce nevede, členění nese jeho zápis v deníku.
 *
 * Řádek nebo doklad, který hodnotu daného typu už má (i změněnou v MyÚčtu), zůstává.
 * Zápisy a doklady v uzavřeném období nebo se zmrazenými dimenzemi se nemění a volající je
 * vyjmenuje v protokolu ({@see reportLocked()}). Čítače kroku vede {@see count()}.
 */
final class PohodaSplitDimension
{
    /** Zakázka (`CisloZAK`, `typ:contract`). */
    public const CONTRACT = 'contract';
    /** Činnost (`RefCin` do `sCIN`, `typ:activity`). */
    public const ACTIVITY = 'activity';
    /** @var list<string> */
    public const SPLITS = [self::CONTRACT, self::ACTIVITY];

    private const BATCH = 1000;

    /** Zdroj zápisu v deníku => typ dokladu v document_dimensions. */
    private const JOURNAL_DOCUMENTS = [
        'invoice' => 'invoice',
        'purchase_invoice' => 'purchase_invoice',
        'cash' => 'cash_document',
        'bank' => 'bank_transaction',
    ];

    /** Typ dokladu => [tabulka, tabulka položek, sloupec dokladu v položkách]. */
    public const INVOICES = [
        'invoice' => ['invoices', 'invoice_items', 'invoice_id'],
        'purchase_invoice' => ['purchase_invoices', 'purchase_invoice_items', 'purchase_invoice_id'],
    ];

    /** @var array<string,array<string,int>> krok => čítač => hodnota, pro souhrn v protokolu */
    private array $tally = [];

    public function __construct(
        private readonly Connection $db,
        private readonly DimensionRepository $dimensions,
        private readonly DimensionPeriodGuard $periodGuard,
    ) {}

    /** Kód členění z elementu `typ:refType` (řádek deníku, hlavička nebo položka dokladu). */
    public static function code(mixed $node, string $split): string
    {
        return mb_substr(PohodaXml::text($node, $split . '/ids'), 0, 50);
    }

    /**
     * Kódy členění z číselníku exportu a ze všech řádků a dokladů, které ho nesou.
     *
     * @param list<string> $listed
     * @return list<string>
     */
    public static function codes(PohodaContext $ctx, string $split, array $listed): array
    {
        $codes = array_fill_keys($listed, true);
        foreach (array_keys($ctx->splitLines[$split] ?? []) as $code) {
            $codes[(string) $code] = true;
        }
        foreach ($ctx->splitDocuments[$split] ?? [] as $documents) {
            foreach ($documents as $doc) {
                foreach ([$doc['header'], ...$doc['items']] as $code) {
                    if ($code !== '') {
                        $codes[$code] = true;
                    }
                }
            }
        }
        return array_map('strval', array_keys($codes));
    }

    /** Nový běh kroku: čítače souhrnu od nuly. */
    public function reset(string $step): void
    {
        $this->tally[$step] = [];
    }

    public function tally(string $step, string $name): int
    {
        return $this->tally[$step][$name] ?? 0;
    }

    public function count(PohodaContext $ctx, string $step, string $name, int $by = 1): void
    {
        if ($by === 0) {
            return;
        }
        $this->tally[$step][$name] = ($this->tally[$step][$name] ?? 0) + $by;
        $ctx->protocol->count($step, $name, $by);
    }

    /**
     * Firemní typ dimenze členění; existující typ se stejným kódem se použije, jak je.
     *
     * @param array{name:string,kind:string,sort_order:int} $definition
     */
    public function type(int $supplierId, string $code, array $definition): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM dimension_types WHERE supplier_id = ? AND code = ?');
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        return $this->dimensions->createType($supplierId, false, ['code' => $code] + $definition);
    }

    /**
     * Hodnota dimenze podle kódu členění: existující, nebo nová. Čítače `<prefix>_values_existing`
     * a `<prefix>_values_created`.
     *
     * @param array<string,mixed> $type
     * @return array{value:int,project:?int}
     */
    public function value(PohodaContext $ctx, string $step, string $prefix, array $type, string $code, string $name): array
    {
        $existing = $this->dimensions->findValueByCode((int) $type['id'], $code);
        if ($existing !== null) {
            $this->count($ctx, $step, $prefix . '_values_existing');
            return ['value' => (int) $existing['id'], 'project' => $existing['project_id'] !== null ? (int) $existing['project_id'] : null];
        }
        $this->count($ctx, $step, $prefix . '_values_created');
        return ['value' => $this->dimensions->createValue($type, [
            'code' => $code,
            'name' => mb_substr($name, 0, 190),
            'is_active' => true,
            'car_id' => null,
            'cost_center_id' => null,
        ]), 'project' => null];
    }

    /**
     * Razítko řádků deníku a dokladů hodnotami členění. Čítače `<prefix>_lines`,
     * `<prefix>_project_lines`, `<prefix>_documents`, `<prefix>_items` a `<prefix>_items_skipped`.
     *
     * @param array<string,array{value:int,project:?int}> $values kód členění => hodnota a zakázka MyÚčta
     * @return array{headers:array<string,array<int,int>>,locked:array{entries:array<int,true>,documents:array<string,true>}}
     *         headers = typ dokladu => id => hodnota hlavičky
     */
    public function stamp(PohodaContext $ctx, string $split, string $step, string $prefix, int $typeId, array $values): array
    {
        $locked = ['entries' => [], 'documents' => []];
        $this->stampLines($ctx, $split, $step, $prefix, $typeId, $values, $locked);
        $headers = $this->stampSourceDocuments($ctx, $split, $step, $prefix, $typeId, $values, $locked);
        $headers = array_replace_recursive($headers, $this->stampJournalDocuments($ctx, $step, $prefix, $typeId, $locked));
        return ['headers' => $headers, 'locked' => $locked];
    }

    /**
     * Upozornění na zápisy a doklady, do kterých se členění nedoplnilo.
     *
     * @param array{entries:array<int,true>,documents:array<string,true>} $locked
     */
    public function reportLocked(PohodaContext $ctx, string $step, string $code, string $label, array $locked): void
    {
        if ($locked['entries'] === [] && $locked['documents'] === []) {
            return;
        }
        $ctx->protocol->warn($step, $code, sprintf(
            '%s se nedoplnila do %d zápisů a %d dokladů v uzavřeném účetním období nebo se zmrazenými dimenzemi. Po znovuotevření knih ji doplní opakovaný převod.',
            $label, count($locked['entries']), count($locked['documents']),
        ), ['entries' => array_slice(array_keys($locked['entries']), 0, 200), 'documents' => array_slice(array_keys($locked['documents']), 0, 200)]);
    }

    /** @param list<string> $codes */
    public static function list(array $codes): string
    {
        return implode(', ', array_slice($codes, 0, 30)) . (count($codes) > 30 ? ', …' : '');
    }

    /**
     * Řádky deníku podle členění řádku POHODY. Řádek, který už hodnotu typu má (i změněnou
     * v MyÚčtu), zůstává; `project_id` se doplní jen tam, kde chybí.
     *
     * @param array<string,array{value:int,project:?int}> $values
     * @param array{entries:array<int,true>,documents:array<string,true>} $locked MĚNÍ SE
     */
    private function stampLines(PohodaContext $ctx, string $split, string $step, string $prefix, int $typeId, array $values, array &$locked): void
    {
        $byCode = $ctx->splitLines[$split] ?? [];
        if ($byCode === []) {
            return;
        }
        $entryIds = [];
        foreach ($byCode as $lines) {
            foreach ($lines as [$entryId]) {
                $entryIds[$entryId] = true;
            }
        }
        $mutable = $this->mutableEntries($ctx->supplierId, array_keys($entryIds), $locked);
        $pdo = $this->db->pdo();
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_pohoda_split_lines');
        $pdo->exec('CREATE TEMPORARY TABLE tmp_pohoda_split_lines (
                entry_id BIGINT UNSIGNED NOT NULL,
                line_no INT UNSIGNED NOT NULL,
                value_id BIGINT UNSIGNED NOT NULL,
                project_id BIGINT UNSIGNED NULL,
                PRIMARY KEY (entry_id, line_no)
            ) ENGINE=InnoDB');
        $batch = [];
        $flush = static function () use (&$batch, $pdo): void {
            if ($batch === []) {
                return;
            }
            $pdo->prepare('INSERT IGNORE INTO tmp_pohoda_split_lines (entry_id, line_no, value_id, project_id) VALUES '
                . implode(',', array_fill(0, count($batch), '(?,?,?,?)')))->execute(array_merge(...$batch));
            $batch = [];
        };
        foreach ($byCode as $code => $lines) {
            $value = $values[(string) $code];
            foreach ($lines as [$entryId, $lineNo]) {
                if (!isset($mutable[$entryId])) {
                    continue;
                }
                $batch[] = [$entryId, $lineNo, $value['value'], $value['project']];
                if (count($batch) >= self::BATCH) {
                    $flush();
                }
            }
        }
        $flush();
        $insert = $pdo->prepare("INSERT IGNORE INTO journal_entry_line_dimensions
                (line_id, dimension_type_id, supplier_id, dimension_value_id, assignment_origin, assignment_origin_ref)
            SELECT jel.id, ?, jel.supplier_id, t.value_id, 'explicit', 'pohoda_import'
              FROM tmp_pohoda_split_lines t
              STRAIGHT_JOIN journal_entry_lines jel ON jel.supplier_id = ? AND jel.entry_id = t.entry_id AND jel.line_no = t.line_no");
        $insert->execute([$typeId, $ctx->supplierId]);
        $this->count($ctx, $step, $prefix . '_lines', $insert->rowCount());
        $project = $pdo->prepare('UPDATE tmp_pohoda_split_lines t
              STRAIGHT_JOIN journal_entry_lines jel ON jel.supplier_id = ? AND jel.entry_id = t.entry_id AND jel.line_no = t.line_no
                SET jel.project_id = t.project_id
              WHERE t.project_id IS NOT NULL AND jel.project_id IS NULL');
        $project->execute([$ctx->supplierId]);
        $this->count($ctx, $step, $prefix . '_project_lines', $project->rowCount());
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_pohoda_split_lines');
    }

    /**
     * Hlavičky a položky faktur podle členění z exportu.
     *
     * @param array<string,array{value:int,project:?int}> $values
     * @param array{entries:array<int,true>,documents:array<string,true>} $locked MĚNÍ SE
     * @return array<string,array<int,int>> typ dokladu => id => hodnota hlavičky
     */
    private function stampSourceDocuments(PohodaContext $ctx, string $split, string $step, string $prefix, int $typeId, array $values, array &$locked): array
    {
        $pdo = $this->db->pdo();
        $insert = $pdo->prepare("INSERT IGNORE INTO document_dimensions
            (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id, assignment_origin, assignment_origin_ref)
            VALUES (?, ?, ?, ?, ?, ?, 'explicit', 'pohoda_import')");
        $headers = [];
        foreach (self::INVOICES as $docType => [$table, $itemsTable, $parent]) {
            $documents = $ctx->splitDocuments[$split][$docType] ?? [];
            if ($documents === []) {
                continue;
            }
            $mutable = $this->mutableDocuments($ctx->supplierId, $docType, $table, array_keys($documents), $locked);
            $itemCounts = [];
            foreach (array_chunk(array_keys($documents), self::BATCH) as $chunk) {
                $count = $pdo->prepare("SELECT {$parent}, COUNT(*) FROM {$itemsTable} WHERE {$parent} IN (" . implode(',', array_fill(0, count($chunk), '?')) . ") GROUP BY {$parent}");
                $count->execute($chunk);
                $itemCounts += array_map('intval', $count->fetchAll(\PDO::FETCH_KEY_PAIR));
            }
            foreach ($documents as $docId => $doc) {
                if (!isset($mutable[$docId])) {
                    continue;
                }
                $coded = array_values(array_unique(array_filter($doc['items'], static fn (string $code): bool => $code !== '')));
                $header = $doc['header'] !== '' ? $doc['header'] : (count($coded) === 1 ? $coded[0] : '');
                $stamped = false;
                if ($header !== '') {
                    $insert->execute([$ctx->supplierId, $docType, $docId, 0, $typeId, $values[$header]['value']]);
                    $stamped = $insert->rowCount() > 0;
                    $headers[$docType][$docId] = $values[$header]['value'];
                }
                $own = array_filter($doc['items'], static fn (string $code): bool => $code !== '' && $code !== $header);
                if ($own !== [] && ($itemCounts[$docId] ?? 0) !== count($doc['items'])) {
                    $this->count($ctx, $step, $prefix . '_items_skipped');
                } else {
                    foreach ($own as $index => $code) {
                        $insert->execute([$ctx->supplierId, $docType, $docId, $index + 1, $typeId, $values[$code]['value']]);
                        $stamped = $stamped || $insert->rowCount() > 0;
                        $this->count($ctx, $step, $prefix . '_items', $insert->rowCount());
                    }
                }
                if ($stamped) {
                    $this->count($ctx, $step, $prefix . '_documents');
                }
            }
        }
        return $headers;
    }

    /**
     * Hlavička dokladu podle řádků jeho zápisu: všechny řádky s hodnotou typu nesou tutéž
     * a doklad hodnotu typu ještě nemá (stejně jako střediska, {@see CostCenterImporter}).
     *
     * @param array{entries:array<int,true>,documents:array<string,true>} $locked MĚNÍ SE
     * @return array<string,array<int,int>> typ dokladu => id => hodnota hlavičky
     */
    private function stampJournalDocuments(PohodaContext $ctx, string $step, string $prefix, int $typeId, array &$locked): array
    {
        $pdo = $this->db->pdo();
        // STRAIGHT_JOIN jako u středisek: čerstvě naplněné tabulky nemají statistiky.
        $rows = $pdo->prepare("SELECT x.source_id, x.value_id, x.entries
              FROM (SELECT je.source_id, MIN(jd.dimension_value_id) AS value_id, GROUP_CONCAT(DISTINCT je.id) AS entries
                      FROM journal_entries je
                      STRAIGHT_JOIN journal_entry_lines jel ON jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
                      STRAIGHT_JOIN journal_entry_line_dimensions jd ON jd.line_id = jel.id
                     WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id IS NOT NULL AND jd.dimension_type_id = ?
                     GROUP BY je.source_id
                    HAVING COUNT(DISTINCT jd.dimension_value_id) = 1) x
             WHERE NOT EXISTS (SELECT 1 FROM document_dimensions dd
                                WHERE dd.supplier_id = ? AND dd.doc_type = ? AND dd.doc_id = x.source_id
                                  AND dd.item_no = 0 AND dd.dimension_type_id = ?)
               AND NOT EXISTS (SELECT 1 FROM document_dimension_splits ds
                                WHERE ds.supplier_id = ? AND ds.doc_type = ? AND ds.doc_id = x.source_id
                                  AND ds.item_no = 0 AND ds.dimension_type_id = ?)");
        $insert = $pdo->prepare("INSERT IGNORE INTO document_dimensions
            (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id, assignment_origin, assignment_origin_ref)
            VALUES (?, ?, ?, 0, ?, ?, 'explicit', 'pohoda_import')");
        $headers = [];
        foreach (self::JOURNAL_DOCUMENTS as $sourceType => $docType) {
            $rows->execute([$ctx->supplierId, $sourceType, $typeId, $ctx->supplierId, $docType, $typeId, $ctx->supplierId, $docType, $typeId]);
            $candidates = $rows->fetchAll(\PDO::FETCH_ASSOC);
            if ($candidates === []) {
                continue;
            }
            $entryIds = [];
            foreach ($candidates as $candidate) {
                foreach (explode(',', (string) $candidate['entries']) as $entryId) {
                    $entryIds[(int) $entryId] = true;
                }
            }
            $mutable = $this->mutableEntries($ctx->supplierId, array_keys($entryIds), $locked);
            foreach ($candidates as $candidate) {
                $docId = (int) $candidate['source_id'];
                foreach (explode(',', (string) $candidate['entries']) as $entryId) {
                    if (!isset($mutable[(int) $entryId])) {
                        $locked['documents'][$docType . ':' . $docId] = true;
                        continue 2;
                    }
                }
                $insert->execute([$ctx->supplierId, $docType, $docId, $typeId, (int) $candidate['value_id']]);
                if ($insert->rowCount() > 0) {
                    $headers[$docType][$docId] = (int) $candidate['value_id'];
                    $this->count($ctx, $step, $prefix . '_documents');
                }
            }
        }
        return $headers;
    }

    /**
     * Zápisy, jejichž dimenze jde měnit (otevřené období, nezmrazené dimenze). Zamyká je
     * jako ruční změna dimenzí ({@see DimensionPeriodGuard}).
     *
     * @param list<int> $entryIds
     * @param array{entries:array<int,true>,documents:array<string,true>} $locked MĚNÍ SE
     * @return array<int,true>
     */
    private function mutableEntries(int $supplierId, array $entryIds, array &$locked): array
    {
        $out = [];
        foreach ($this->periodGuard->lockEntries($supplierId, $entryIds) as $entryId => $entry) {
            try {
                $this->periodGuard->assertMutable($entry);
                $out[(int) $entryId] = true;
            } catch (DimensionException $e) {
                if ($e->errorCode !== 'period_not_open') {
                    throw $e;
                }
                $locked['entries'][(int) $entryId] = true;
            }
        }
        return $out;
    }

    /**
     * Doklady, jejichž dimenze jde měnit: všechny jejich zápisy jsou měnitelné, doklad bez
     * zápisu leží v otevřeném období (podle data zdanitelného plnění, jinak vystavení).
     *
     * @param list<int> $docIds
     * @param array{entries:array<int,true>,documents:array<string,true>} $locked MĚNÍ SE
     * @return array<int,true>
     */
    private function mutableDocuments(int $supplierId, string $docType, string $table, array $docIds, array &$locked): array
    {
        $pdo = $this->db->pdo();
        $entries = [];
        $dates = [];
        foreach (array_chunk($docIds, self::BATCH) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("SELECT id, source_id FROM journal_entries WHERE supplier_id = ? AND source_type = ? AND source_id IN ({$marks})");
            $stmt->execute([$supplierId, $docType, ...$chunk]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $entries[(int) $row['source_id']][] = (int) $row['id'];
            }
            $stmt = $pdo->prepare("SELECT id, COALESCE(tax_date, issue_date) FROM {$table} WHERE supplier_id = ? AND id IN ({$marks})");
            $stmt->execute([$supplierId, ...$chunk]);
            $dates += $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        }
        $mutableEntries = $this->mutableEntries($supplierId, array_merge([], ...array_values($entries)), $locked);
        $periods = $pdo->prepare('SELECT starts_on, ends_on, status FROM accounting_periods WHERE supplier_id = ?');
        $periods->execute([$supplierId]);
        $periods = $periods->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($docIds as $docId) {
            $open = true;
            foreach ($entries[$docId] ?? [] as $entryId) {
                $open = $open && isset($mutableEntries[$entryId]);
            }
            if (!isset($entries[$docId])) {
                $date = (string) ($dates[$docId] ?? '');
                foreach ($periods as $period) {
                    if ($date >= $period['starts_on'] && $date <= $period['ends_on'] && AccountingPeriodStatus::isClosed((string) $period['status'])) {
                        $open = false;
                    }
                }
            }
            if ($open) {
                $out[$docId] = true;
            } else {
                $locked['documents'][$docType . ':' . $docId] = true;
            }
        }
        return $out;
    }
}
