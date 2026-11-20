<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\PohodaImportRepository;
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
 * - Řádky deníku (`pUD.CisloZAK`, `act:contract`) a doklady dostanou hodnotu podle pravidel
 *   společných s činnostmi ({@see PohodaSplitDimension}).
 * - Zakázka MyÚčta (`projects`, vázaná na klienta) vznikne u zakázky s vlastním partnerem
 *   v POHODĚ (vazba na adresář převedená na klienta). Zakázka bez partnera dostane klienta
 *   z vydaných faktur, když jdou všechny jedinému odběrateli (i faktury dřívějších převodů);
 *   při více odběratelích nebo bez vydané faktury zůstane jen hodnotou dimenze. Partner
 *   zakázky, který mezi převedenými kontakty chybí, se z faktur nenahrazuje. Hodnota dimenze
 *   se na zakázku naváže a faktury i řádky deníku s touto zakázkou dostanou `project_id`,
 *   kde ho ještě nemají.
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

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly DimensionRepository $dimensions,
        private readonly DimensionService $dimensionService,
        private readonly DimensionPeriodGuard $periodGuard,
        private readonly MigrationHomeCurrency $homeCurrency,
        private readonly PohodaSplitDimension $split,
    ) {}

    public function run(PohodaContext $ctx): void
    {
        $contracts = $this->contracts($ctx);
        $codes = PohodaSplitDimension::codes($ctx, PohodaSplitDimension::CONTRACT, array_map('strval', array_keys($contracts)));
        $this->split->reset(self::STEP);
        if ($codes !== []) {
            $this->periodGuard->transaction(fn () => $this->import($ctx, $contracts, $codes));
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
     * @param list<string> $codes
     */
    private function import(PohodaContext $ctx, array $contracts, array $codes): void
    {
        $p = $ctx->protocol;
        $this->dimensionService->setEnabled($ctx->supplierId, true);
        $typeId = $this->split->type($ctx->supplierId, self::TYPE_CODE, ['name' => 'Zakázka', 'kind' => 'project', 'sort_order' => 25]);
        $type = (array) $this->dimensions->findType($ctx->supplierId, $typeId);
        $p->setCount(self::STEP, 'contracts', count($contracts));

        /** @var array<string,array{value:int,project:?int}> $values */
        $values = [];
        $unlisted = [];
        foreach ($codes as $code) {
            $name = $contracts[$code]['name'] ?? '';
            if (!isset($contracts[$code])) {
                $unlisted[] = $code;
            }
            $values[$code] = $this->split->value($ctx, self::STEP, 'contract', $type, $code, $name !== '' ? $name : $code);
        }

        $dimensionOnly = [];
        $partnerMissing = [];
        $fromInvoices = [];
        $ambiguous = [];
        $invoiceClients = $this->invoiceClients($ctx, $values);
        foreach ($contracts as $code => $contract) {
            $code = (string) $code;
            $clientId = $contract['partner'] !== '' ? ($ctx->clientsByPohodaId[$contract['partner']] ?? null) : null;
            if ($clientId === null && $contract['partner'] === '' && count($invoiceClients[$code] ?? []) === 1) {
                $clientId = $invoiceClients[$code][0];
                $fromInvoices[] = $code;
            }
            if ($clientId === null) {
                if ($contract['partner'] !== '') {
                    $partnerMissing[] = $code;
                } elseif (count($invoiceClients[$code] ?? []) > 1) {
                    $ambiguous[] = $code;
                } else {
                    $dimensionOnly[] = $code;
                }
                $this->split->count($ctx, self::STEP, 'contract_dimension_only');
                continue;
            }
            $projectId = $this->project($ctx, $code, $contract['name'] !== '' ? $contract['name'] : $code, $clientId);
            $values[$code]['project'] = $this->linkProject($ctx, $values[$code], $projectId);
        }

        ['headers' => $headers, 'locked' => $locked] = $this->split->stamp($ctx, PohodaSplitDimension::CONTRACT, self::STEP, 'contract', $typeId, $values);
        $this->linkDocumentsToProjects($ctx, $headers, $values);

        $tally = fn (string $name): int => $this->split->tally(self::STEP, $name);
        $listed = array_map('strval', array_keys($contracts));
        $p->info(self::STEP, 'contracts_summary', sprintf(
            'Zakázky z POHODY: %d (%s). Dimenze Zakázka (Firma → Dimenze): nové hodnoty %d, existující %d. Nově označené řádky deníku: %d, doklady: %d. Zakázky MyÚčta u klienta partnera zakázky: %d.',
            count($contracts), PohodaSplitDimension::list($listed),
            $tally('contract_values_created'), $tally('contract_values_existing'),
            $tally('contract_lines'), $tally('contract_documents'),
            $tally('contract_projects_created') + $tally('contract_projects_existing'),
        ), ['codes' => $listed]);
        if ($fromInvoices !== []) {
            $p->info(self::STEP, 'contract_client_from_invoices', sprintf(
                'Zakázky bez partnera v POHODĚ s jediným odběratelem na vydaných fakturách: zakázka MyÚčta vznikla u tohoto odběratele: %s.',
                PohodaSplitDimension::list($fromInvoices),
            ), ['codes' => $fromInvoices]);
        }
        if ($ambiguous !== []) {
            $p->info(self::STEP, 'contract_client_ambiguous', sprintf(
                'Vydané faktury zakázky jdou více odběratelům, zakázka MyÚčta nevznikla a zakázka je jen hodnotou dimenze Zakázka: %s.',
                PohodaSplitDimension::list($ambiguous),
            ), ['codes' => $ambiguous]);
        }
        if ($dimensionOnly !== []) {
            $p->info(self::STEP, 'contract_dimension_only', sprintf(
                'Zakázky bez partnera v POHODĚ a bez vydané faktury jsou jen hodnotou dimenze Zakázka, zakázka MyÚčta u klienta nevznikla: %s.',
                PohodaSplitDimension::list($dimensionOnly),
            ), ['codes' => $dimensionOnly]);
        }
        if ($partnerMissing !== []) {
            $p->warn(self::STEP, 'contract_partner_missing', sprintf(
                'Partner zakázky není mezi převedenými kontakty, zakázka zůstala jen hodnotou dimenze Zakázka: %s.',
                PohodaSplitDimension::list($partnerMissing),
            ), ['codes' => $partnerMissing]);
        }
        if ($unlisted !== []) {
            $p->info(self::STEP, 'contract_unlisted', sprintf(
                'Doklady nesou čísla zakázek, které export zakázek neobsahuje; hodnota dimenze má jako název číslo zakázky: %s.',
                PohodaSplitDimension::list($unlisted),
            ), ['codes' => $unlisted]);
        }
        $this->split->reportLocked($ctx, self::STEP, 'contract_period_not_open', 'Zakázka', $locked);
    }

    /**
     * Odběratelé vydaných faktur zakázky: faktury tohoto převodu podle členění hlavičky
     * z exportu (bez něj podle shodného členění položek) a faktury dřívějších převodů
     * podle hodnoty dimenze na hlavičce. Kalendáře splátek jsou interní doklady a nepočítají se.
     *
     * @param array<string,array{value:int,project:?int}> $values
     * @return array<string,list<int>> číslo zakázky => různí klienti
     */
    private function invoiceClients(PohodaContext $ctx, array $values): array
    {
        $byValue = [];
        foreach ($values as $code => $value) {
            $byValue[$value['value']] = (string) $code;
        }
        $docs = [];
        foreach ($ctx->splitDocuments[PohodaSplitDimension::CONTRACT]['invoice'] ?? [] as $id => $split) {
            $items = array_values(array_unique(array_filter($split['items'], static fn (string $c): bool => $c !== '')));
            $code = $split['header'] !== '' ? $split['header'] : (count($items) === 1 ? $items[0] : '');
            if ($code !== '' && isset($values[$code])) {
                $docs[(int) $id] = $code;
            }
        }
        $pdo = $this->db->pdo();
        $types = "'invoice','proforma','credit_note','tax_document'";
        $pairs = [];
        foreach (array_chunk(array_keys($docs), self::BATCH) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT id, client_id FROM invoices WHERE supplier_id = ? AND id IN ({$marks}) AND invoice_type IN ({$types})");
            $st->execute([$ctx->supplierId, ...$chunk]);
            foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$id, $clientId]) {
                $pairs[$docs[(int) $id]][(int) $clientId] = true;
            }
        }
        foreach (array_chunk(array_keys($byValue), self::BATCH) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT DISTINCT dd.dimension_value_id, i.client_id
                  FROM document_dimensions dd
                  JOIN invoices i ON i.id = dd.doc_id AND i.supplier_id = dd.supplier_id
                 WHERE dd.supplier_id = ? AND dd.dimension_value_id IN ({$marks}) AND dd.doc_type = 'invoice' AND dd.item_no = 0
                   AND i.invoice_type IN ({$types})");
            $st->execute([$ctx->supplierId, ...$chunk]);
            foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$valueId, $clientId]) {
                $pairs[$byValue[(int) $valueId]][(int) $clientId] = true;
            }
        }
        return array_map(static fn (array $clients): array => array_keys($clients), $pairs);
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
                $this->split->count($ctx, self::STEP, 'contract_projects_existing');
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
        $this->split->count($ctx, self::STEP, 'contract_projects_created');
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
        foreach (PohodaSplitDimension::INVOICES as $docType => [$table]) {
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
                    $this->split->count($ctx, self::STEP, 'contract_project_documents', $update->rowCount());
                    $lines = $pdo->prepare("UPDATE journal_entries je
                          STRAIGHT_JOIN journal_entry_lines jel ON jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
                            SET jel.project_id = ?
                          WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id IN ({$marks}) AND jel.project_id IS NULL");
                    $lines->execute([$projectId, $ctx->supplierId, $docType, ...$chunk]);
                    $this->split->count($ctx, self::STEP, 'contract_project_lines', $lines->rowCount());
                }
            }
        }
    }
}
