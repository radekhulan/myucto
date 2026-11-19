<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Migration\Shared\MigrationHomeCurrency;

/**
 * Zakázky POHODY (agenda Zakázky, číslo zakázky `CisloZAK` na dokladech a v deníku) jako
 * firemní dimenze Zakázka druhu Projekt (Firma → Dimenze). Sestavy po dimenzích (výsledovka
 * a analýza po dimenzi) tak ukážou zakázku stejně jako v POHODĚ.
 *
 * - Každá zakázka z exportu je hodnota dimenze (kód = číslo, název = text zakázky), i když
 *   ji žádný doklad nepoužívá. Číslo zakázky z dokladu, které v číselníku chybí, dostane
 *   hodnotu se stejným kódem i názvem.
 * - Řádek deníku dostane hodnotu podle své vlastní zakázky (`pUD.CisloZAK`, `act:contract`).
 * - Vydaná a přijatá faktura dostane hodnotu do hlavičky podle zakázky hlavičky; nemá-li ji,
 *   podle zakázky položek, když se shodují. Položka s jinou zakázkou než hlavička ji dostane
 *   na položku, jen když převedený doklad má položky jedna k jedné jako POHODA.
 * - Ostatní doklady s dimenzemi (pokladna, banka) a faktury bez zakázky v exportu dostanou
 *   hodnotu do hlavičky, když ji nesou všechny řádky jejich zápisu se zakázkou.
 * - Zakázka MyÚčta (`projects`, vázaná na klienta) vznikne jen u zakázky s vlastním partnerem
 *   v POHODĚ (vazba na adresář převedená na klienta). Hodnota dimenze se na ni naváže
 *   a faktury i řádky deníku s touto zakázkou dostanou `project_id`, kde ho ještě nemají.
 *   Klient se z dokladů neodvozuje.
 *
 * Opakovaný převod doplní hodnoty a vazby i dokladům a řádkům z dřívějšího převodu, nic
 * nezdvojí a hodnotu změněnou v MyÚčtu nepřepíše. Zápisy a doklady v uzavřeném období nebo
 * se zmrazenými dimenzemi zůstanou beze změny a protokol je vyjmenuje. Export bez zakázek
 * nic nemění a dimenze u firmy nezapíná.
 */
final class ContractImporter
{
    public const STEP = 'contracts';

    public const TYPE_CODE = 'zakazka';

    private const BATCH = 1000;

    /** Zdroj zápisu v deníku => typ dokladu v document_dimensions. */
    private const JOURNAL_DOCUMENTS = [
        'invoice' => 'invoice',
        'purchase_invoice' => 'purchase_invoice',
        'cash' => 'cash_document',
        'bank' => 'bank_transaction',
    ];

    /** Typ dokladu => [tabulka, tabulka položek, sloupec dokladu v položkách]. */
    private const INVOICES = [
        'invoice' => ['invoices', 'invoice_items', 'invoice_id'],
        'purchase_invoice' => ['purchase_invoices', 'purchase_invoice_items', 'purchase_invoice_id'],
    ];

    /** @var array<string,int> čítače běhu pro souhrn v protokolu */
    private array $tally = [];

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly DimensionRepository $dimensions,
        private readonly DimensionService $dimensionService,
        private readonly DimensionPeriodGuard $periodGuard,
        private readonly MigrationHomeCurrency $homeCurrency,
    ) {}

    public function run(PohodaContext $ctx): void
    {
        $contracts = $this->contracts($ctx);
        $codes = array_fill_keys(array_map('strval', array_keys($contracts)), true);
        foreach (array_keys($ctx->contractLines) as $code) {
            $codes[(string) $code] = true;
        }
        foreach ($ctx->documentContracts as $documents) {
            foreach ($documents as $doc) {
                foreach ([$doc['header'], ...$doc['items']] as $code) {
                    if ($code !== '') {
                        $codes[$code] = true;
                    }
                }
            }
        }
        $this->tally = [];
        if ($codes !== []) {
            $this->periodGuard->transaction(fn () => $this->import($ctx, $contracts, array_keys($codes)));
        }
        $ctx->protocol->finish(self::STEP);
    }

    /**
     * Zakázky z exportu: číslo => název a partner (id adresáře POHODY).
     *
     * @return array<string,array{name:string,partner:string}>
     */
    private function contracts(PohodaContext $ctx): array
    {
        $out = [];
        foreach ($ctx->export->records('contracts', 'contract') as $r) {
            $d = PohodaXml::get($r, 'contractDesc');
            $code = mb_substr(PohodaXml::text($d, 'number/numberRequested') ?: PohodaXml::text($d, 'number'), 0, 50);
            if ($code === '') {
                continue;
            }
            $out[$code] = [
                'name' => PohodaXml::text($d, 'text'),
                'partner' => PohodaXml::text($d, 'partnerIdentity/id'),
            ];
        }
        return $out;
    }

    /**
     * @param array<string,array{name:string,partner:string}> $contracts
     * @param list<int|string> $codes
     */
    private function import(PohodaContext $ctx, array $contracts, array $codes): void
    {
        $p = $ctx->protocol;
        $this->dimensionService->setEnabled($ctx->supplierId, true);
        $typeId = $this->type($ctx->supplierId);
        $type = (array) $this->dimensions->findType($ctx->supplierId, $typeId);
        $p->setCount(self::STEP, 'contracts', count($contracts));

        /** @var array<string,array{value:int,project:?int}> $values */
        $values = [];
        $unlisted = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            $name = $contracts[$code]['name'] ?? '';
            if (!isset($contracts[$code])) {
                $unlisted[] = $code;
            }
            $values[$code] = $this->value($ctx, $type, $code, $name !== '' ? $name : $code);
        }

        $dimensionOnly = [];
        $partnerMissing = [];
        foreach ($contracts as $code => $contract) {
            $code = (string) $code;
            $clientId = $contract['partner'] !== '' ? ($ctx->clientsByPohodaId[$contract['partner']] ?? null) : null;
            if ($clientId === null) {
                if ($contract['partner'] !== '') {
                    $partnerMissing[] = $code;
                } else {
                    $dimensionOnly[] = $code;
                }
                $this->add($ctx, 'contract_dimension_only');
                continue;
            }
            $projectId = $this->project($ctx, $code, $contract['name'] !== '' ? $contract['name'] : $code, $clientId);
            $values[$code]['project'] = $this->linkProject($ctx, $values[$code], $projectId);
        }

        $locked = ['entries' => [], 'documents' => []];
        $this->stampLines($ctx, $typeId, $values, $locked);
        $headers = $this->stampSourceDocuments($ctx, $typeId, $values, $locked);
        $headers = array_replace_recursive($headers, $this->stampJournalDocuments($ctx, $typeId, $locked));
        $this->linkDocumentsToProjects($ctx, $headers, $values);

        $listed = array_map('strval', array_keys($contracts));
        $p->info(self::STEP, 'contracts_summary', sprintf(
            'Zakázky z POHODY: %d (%s). Dimenze Zakázka (Firma → Dimenze): nové hodnoty %d, existující %d. Nově označené řádky deníku: %d, doklady: %d. Zakázky MyÚčta u klienta partnera zakázky: %d.',
            count($contracts), self::list($listed),
            ($this->tally['contract_values_created'] ?? 0), ($this->tally['contract_values_existing'] ?? 0),
            ($this->tally['contract_lines'] ?? 0), ($this->tally['contract_documents'] ?? 0),
            ($this->tally['contract_projects_created'] ?? 0) + ($this->tally['contract_projects_existing'] ?? 0),
        ), ['codes' => $listed]);
        if ($dimensionOnly !== []) {
            $p->info(self::STEP, 'contract_dimension_only', sprintf(
                'Zakázky bez vlastního partnera v POHODĚ jsou jen hodnotou dimenze Zakázka, zakázka MyÚčta u klienta nevznikla: %s.',
                self::list($dimensionOnly),
            ), ['codes' => $dimensionOnly]);
        }
        if ($partnerMissing !== []) {
            $p->warn(self::STEP, 'contract_partner_missing', sprintf(
                'Partner zakázky není mezi převedenými kontakty, zakázka zůstala jen hodnotou dimenze Zakázka: %s.',
                self::list($partnerMissing),
            ), ['codes' => $partnerMissing]);
        }
        if ($unlisted !== []) {
            $p->info(self::STEP, 'contract_unlisted', sprintf(
                'Doklady nesou čísla zakázek, které export zakázek neobsahuje; hodnota dimenze má jako název číslo zakázky: %s.',
                self::list($unlisted),
            ), ['codes' => $unlisted]);
        }
        if ($locked['entries'] !== [] || $locked['documents'] !== []) {
            $p->warn(self::STEP, 'contract_period_not_open', sprintf(
                'Zakázka se nedoplnila do %d zápisů a %d dokladů v uzavřeném účetním období nebo se zmrazenými dimenzemi. Po znovuotevření knih ji doplní opakovaný převod.',
                count($locked['entries']), count($locked['documents']),
            ), ['entries' => array_slice(array_keys($locked['entries']), 0, 200), 'documents' => array_slice(array_keys($locked['documents']), 0, 200)]);
        }
    }

    /** Firemní typ Zakázka druhu Projekt; existující typ s kódem `zakazka` se použije. */
    private function type(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM dimension_types WHERE supplier_id = ? AND code = ?');
        $stmt->execute([$supplierId, self::TYPE_CODE]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        return $this->dimensions->createType($supplierId, false, [
            'code' => self::TYPE_CODE, 'name' => 'Zakázka', 'kind' => 'project', 'sort_order' => 25,
        ]);
    }

    /**
     * @param array<string,mixed> $type
     * @return array{value:int,project:?int}
     */
    private function value(PohodaContext $ctx, array $type, string $code, string $name): array
    {
        $existing = $this->dimensions->findValueByCode((int) $type['id'], $code);
        if ($existing !== null) {
            $this->add($ctx, 'contract_values_existing');
            return ['value' => (int) $existing['id'], 'project' => $existing['project_id'] !== null ? (int) $existing['project_id'] : null];
        }
        $this->add($ctx, 'contract_values_created');
        return ['value' => $this->dimensions->createValue($type, [
            'code' => $code,
            'name' => mb_substr($name, 0, 190),
            'is_active' => true,
            'car_id' => null,
            'cost_center_id' => null,
        ]), 'project' => null];
    }

    /** Zakázka MyÚčta u klienta partnera zakázky: z mapy převodu, nebo nová. */
    private function project(PohodaContext $ctx, string $code, string $name, int $clientId): int
    {
        $pdo = $this->db->pdo();
        $mapped = $this->map->get($ctx->supplierId, PohodaImportRepository::KIND_PROJECT, $code);
        if ($mapped !== null) {
            $exists = $pdo->prepare('SELECT 1 FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ? AND c.supplier_id = ?');
            $exists->execute([$mapped, $ctx->supplierId]);
            if ($exists->fetchColumn() !== false) {
                $this->add($ctx, 'contract_projects_existing');
                return $mapped;
            }
        }
        $currency = $pdo->prepare('SELECT default_currency_id FROM supplier WHERE id = ?');
        $currency->execute([$ctx->supplierId]);
        $currencyId = (int) $currency->fetchColumn() ?: $this->homeCurrency->id($ctx->supplierId);
        // Stav zakázky POHODA vede vlastním číselníkem stavů, MyÚčto ho nemá: zakázka je aktivní.
        $pdo->prepare("INSERT INTO projects (client_id, name, project_number, currency_id, status, note) VALUES (?, ?, ?, ?, 'active', ?)")
            ->execute([$clientId, mb_substr($name, 0, 190), $code, $currencyId, 'Převzato z POHODY (zakázka ' . $code . ')']);
        $id = (int) $pdo->lastInsertId();
        $mapped === null
            ? $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PROJECT, $code, $id, $ctx->runId)
            : $this->map->repoint($ctx->supplierId, PohodaImportRepository::KIND_PROJECT, $code, $id, $ctx->runId);
        $this->add($ctx, 'contract_projects_created');
        return $id;
    }

    /**
     * Hodnota dimenze ukazuje na zakázku MyÚčta. Hodnotu navázanou v MyÚčtu jinam převod nemění.
     *
     * @param array{value:int,project:?int} $value
     */
    private function linkProject(PohodaContext $ctx, array $value, int $projectId): ?int
    {
        if ($value['project'] === null) {
            $this->dimensions->updateValue($ctx->supplierId, $value['value'], ['project_id' => $projectId], $ctx->userOrNull(), 'pohoda_import');
            return $projectId;
        }
        return $value['project'];
    }

    /**
     * Řádky deníku podle zakázky řádku POHODY. Řádek, který už hodnotu typu Zakázka má
     * (i změněnou v MyÚčtu), zůstává; `project_id` se doplní jen tam, kde chybí.
     *
     * @param array<string,array{value:int,project:?int}> $values
     * @param array{entries:array<int,true>,documents:array<string,true>} $locked MĚNÍ SE
     */
    private function stampLines(PohodaContext $ctx, int $typeId, array $values, array &$locked): void
    {
        if ($ctx->contractLines === []) {
            return;
        }
        $entryIds = [];
        foreach ($ctx->contractLines as $lines) {
            foreach ($lines as [$entryId]) {
                $entryIds[$entryId] = true;
            }
        }
        $mutable = $this->mutableEntries($ctx->supplierId, array_keys($entryIds), $locked);
        $pdo = $this->db->pdo();
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_pohoda_contract_lines');
        $pdo->exec('CREATE TEMPORARY TABLE tmp_pohoda_contract_lines (
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
            $pdo->prepare('INSERT IGNORE INTO tmp_pohoda_contract_lines (entry_id, line_no, value_id, project_id) VALUES '
                . implode(',', array_fill(0, count($batch), '(?,?,?,?)')))->execute(array_merge(...$batch));
            $batch = [];
        };
        foreach ($ctx->contractLines as $code => $lines) {
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
              FROM tmp_pohoda_contract_lines t
              STRAIGHT_JOIN journal_entry_lines jel ON jel.supplier_id = ? AND jel.entry_id = t.entry_id AND jel.line_no = t.line_no");
        $insert->execute([$typeId, $ctx->supplierId]);
        $this->add($ctx, 'contract_lines', $insert->rowCount());
        $project = $pdo->prepare('UPDATE tmp_pohoda_contract_lines t
              STRAIGHT_JOIN journal_entry_lines jel ON jel.supplier_id = ? AND jel.entry_id = t.entry_id AND jel.line_no = t.line_no
                SET jel.project_id = t.project_id
              WHERE t.project_id IS NOT NULL AND jel.project_id IS NULL');
        $project->execute([$ctx->supplierId]);
        $this->add($ctx, 'contract_project_lines', $project->rowCount());
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_pohoda_contract_lines');
    }

    /**
     * Hlavičky a položky faktur podle zakázky z exportu.
     *
     * @param array<string,array{value:int,project:?int}> $values
     * @param array{entries:array<int,true>,documents:array<string,true>} $locked MĚNÍ SE
     * @return array<string,array<int,int>> typ dokladu => id => hodnota hlavičky
     */
    private function stampSourceDocuments(PohodaContext $ctx, int $typeId, array $values, array &$locked): array
    {
        $pdo = $this->db->pdo();
        $insert = $pdo->prepare("INSERT IGNORE INTO document_dimensions
            (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id, assignment_origin, assignment_origin_ref)
            VALUES (?, ?, ?, ?, ?, ?, 'explicit', 'pohoda_import')");
        $headers = [];
        foreach (self::INVOICES as $docType => [$table, $itemsTable, $parent]) {
            $documents = $ctx->documentContracts[$docType] ?? [];
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
                    $this->add($ctx, 'contract_items_skipped');
                } else {
                    foreach ($own as $index => $code) {
                        $insert->execute([$ctx->supplierId, $docType, $docId, $index + 1, $typeId, $values[$code]['value']]);
                        $stamped = $stamped || $insert->rowCount() > 0;
                        $this->add($ctx, 'contract_items', $insert->rowCount());
                    }
                }
                if ($stamped) {
                    $this->add($ctx, 'contract_documents');
                }
            }
        }
        return $headers;
    }

    /**
     * Hlavička dokladu podle řádků jeho zápisu: všechny řádky se zakázkou nesou tutéž
     * a doklad hodnotu typu Zakázka ještě nemá (stejně jako střediska, {@see CostCenterImporter}).
     *
     * @param array{entries:array<int,true>,documents:array<string,true>} $locked MĚNÍ SE
     * @return array<string,array<int,int>> typ dokladu => id => hodnota hlavičky
     */
    private function stampJournalDocuments(PohodaContext $ctx, int $typeId, array &$locked): array
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
                    $this->add($ctx, 'contract_documents');
                }
            }
        }
        return $headers;
    }

    /**
     * Faktura s hodnotou navázanou na zakázku MyÚčta dostane `project_id` a řádky jejího
     * zápisu taky, kde ho ještě nemají. Zakázku vybranou v MyÚčtu převod nemění.
     *
     * @param array<string,array<int,int>> $headers typ dokladu => id => hodnota hlavičky
     * @param array<string,array{value:int,project:?int}> $values
     */
    private function linkDocumentsToProjects(PohodaContext $ctx, array $headers, array $values): void
    {
        $projects = [];
        foreach ($values as $value) {
            if ($value['project'] !== null) {
                $projects[$value['value']] = $value['project'];
            }
        }
        if ($projects === []) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach (self::INVOICES as $docType => [$table]) {
            $byProject = [];
            foreach ($headers[$docType] ?? [] as $docId => $valueId) {
                if (isset($projects[$valueId])) {
                    $byProject[$projects[$valueId]][] = (int) $docId;
                }
            }
            foreach ($byProject as $projectId => $docIds) {
                foreach (array_chunk($docIds, self::BATCH) as $chunk) {
                    $marks = implode(',', array_fill(0, count($chunk), '?'));
                    $update = $pdo->prepare("UPDATE {$table} SET project_id = ? WHERE supplier_id = ? AND project_id IS NULL AND id IN ({$marks})");
                    $update->execute([$projectId, $ctx->supplierId, ...$chunk]);
                    $this->add($ctx, 'contract_project_documents', $update->rowCount());
                    $lines = $pdo->prepare("UPDATE journal_entries je
                          STRAIGHT_JOIN journal_entry_lines jel ON jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
                            SET jel.project_id = ?
                          WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id IN ({$marks}) AND jel.project_id IS NULL");
                    $lines->execute([$projectId, $ctx->supplierId, $docType, ...$chunk]);
                    $this->add($ctx, 'contract_project_lines', $lines->rowCount());
                }
            }
        }
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

    private function add(PohodaContext $ctx, string $name, int $by = 1): void
    {
        if ($by === 0) {
            return;
        }
        $this->tally[$name] = ($this->tally[$name] ?? 0) + $by;
        $ctx->protocol->count(self::STEP, $name, $by);
    }

    /** @param list<string> $codes */
    private static function list(array $codes): string
    {
        return implode(', ', array_slice($codes, 0, 30)) . (count($codes) > 30 ? ', …' : '');
    }
}
