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
        foreach ($contracts as $code => $contract) {
            $code = (string) $code;
            $clientId = $contract['partner'] !== '' ? ($ctx->clientsByPohodaId[$contract['partner']] ?? null) : null;
            if ($clientId === null) {
                if ($contract['partner'] !== '') {
                    $partnerMissing[] = $code;
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
        if ($dimensionOnly !== []) {
            $p->info(self::STEP, 'contract_dimension_only', sprintf(
                'Zakázky bez vlastního partnera v POHODĚ jsou jen hodnotou dimenze Zakázka, zakázka MyÚčta u klienta nevznikla: %s.',
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
