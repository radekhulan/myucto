<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Service\Accounting\Assets\DepreciationCalculator;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\FiscalCalendar;
use PDO;

/**
 * Inventární karty dlouhodobého majetku (§29–30 ZoÚ) — uzávěrkový balíček #49, doplněk
 * k soupisu z {@see AssetInventoryReportService}. Jedna karta = jeden majetek: atributy
 * karty + CELÝ víceletý daňový odpisový plán (minulost z potvrzených řádků, budoucnost
 * dopočtená), stejně jako `AssetService::plan()` — výpočet se nikde neduplikuje, jen se
 * skládá do karty. Populace karet je shodná s AssetInventoryReportService (existoval
 * k rozvahovému dni). Drobný majetek (§DM) se neodpisuje a v `assets` tabulce vůbec
 * není (má vlastní evidenci `small_assets`) — karty se pro něj negenerují.
 */
final class AssetDepreciationCardReportService
{
    /** Standardní odpisové skupiny + zvláštní kategorie pro vizuální zvýraznění na kartě. */
    private const TAX_GROUP_TOKENS = ['1', '1a', '2', '3', '4', '5', '6', 'N', 'D', 'S'];

    /** @var array<int, FiscalCalendar> memo režimu firmy v rámci běhu */
    private array $calendarCache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly AssetRepository $assets,
        private readonly AccountingPeriodRepository $periods,
        private readonly DepreciationCalculator $calculator,
        private readonly DepreciationPostingService $depreciationPosting,
    ) {}

    /**
     * Karta jednoho konkrétního majetku „na počkání" (tlačítko stažení v detailu karty,
     * #49) — bez vazby na uzávěrkové období/populaci; jen aktuální stav karty k dnešku.
     *
     * @return array<string,mixed>
     */
    public function buildForAsset(int $supplierId, int $assetId): array
    {
        $asset = $this->assets->find($supplierId, $assetId);
        if ($asset === null) {
            throw new ReportException('not_found', 'Majetek nenalezen.', 404);
        }

        $impTotal = 0.0;
        foreach ($this->assets->improvements($assetId) as $imp) {
            $impTotal += (float) $imp['amount'];
        }
        $asset['increased_input_price'] = round((float) $asset['input_price'] + $impTotal, 2);

        $asOf = (new \DateTimeImmutable())->format('Y-m-d');
        $fiscalYear = $this->supplierCalendar($supplierId)->fiscalYearOfDate($asOf);

        return [
            'period'  => [
                'id'          => null,
                'fiscal_year' => $fiscalYear,
                'starts_on'   => null,
                'ends_on'     => null,
            ],
            'as_of'   => $asOf,
            'entity'  => $this->entity($supplierId),
            'cards'   => [$this->buildCard($supplierId, $asset, 1)],
            'count'   => 1,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function build(int $supplierId, int $periodId): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $endsOn = (string) $period['ends_on'];

        $matched = [];
        $page = 1;
        $total = 0;
        do {
            $res = $this->assets->list($supplierId, ['per_page' => 200, 'page' => $page]);
            $total = (int) ($res['total'] ?? 0);
            $pageCards = [];
            foreach ($res['items'] as $a) {
                $acqDate = (string) ($a['acquisition_date'] ?? '');
                if ($acqDate === '' || $acqDate > $endsOn) {
                    continue; // pořízeno až po rozvahovém dni
                }
                $disposalDate = $a['disposal_date'] ?? null;
                if ($disposalDate !== null && (string) $disposalDate <= $endsOn) {
                    continue; // vyřazeno do rozvahového dne včetně — už není v soupisu
                }
                $pageCards[] = $a;
            }
            $plans = $this->assets->importedPlansForAssets($supplierId, array_column($pageCards, 'id'));
            foreach ($pageCards as $a) {
                if (isset($plans[(int) $a['id']])) {
                    $a['imported_depreciation_plan'] = $plans[(int) $a['id']];
                }
                $matched[] = $a;
            }
            $page++;
        } while (($page - 1) * 200 < $total);

        usort($matched, static fn (array $x, array $y): int => strcmp((string) $x['inventory_number'], (string) $y['inventory_number']));

        $cards = [];
        $n = 0;
        foreach ($matched as $a) {
            $n++;
            $cards[] = $this->buildCard($supplierId, $a, $n);
        }

        return [
            'period'  => [
                'id'          => (int) $period['id'],
                'fiscal_year' => (int) $period['fiscal_year'],
                'starts_on'   => (string) $period['starts_on'],
                'ends_on'     => $endsOn,
            ],
            'as_of'   => $endsOn,
            'entity'  => $this->entity($supplierId),
            'cards'   => $cards,
            'count'   => count($cards),
        ];
    }

    /**
     * @param array<string,mixed> $asset řádek z AssetRepository::list() (obsahuje i increased_input_price)
     * @return array<string,mixed>
     */
    private function buildCard(int $supplierId, array $asset, int $cardNumber): array
    {
        $ctx = $this->depreciationPosting->buildContext($asset);
        $taxMethod = (string) $asset['tax_method'];
        $accMethod = (string) $asset['acc_method'];
        $plan = $this->calculator->plan($ctx, $taxMethod, $accMethod);
        $taxRows = $plan['tax'] ?? [];

        $calendar = $this->supplierCalendar($supplierId);

        $improvementsByYear = [];
        foreach ($this->assets->improvements((int) $asset['id']) as $imp) {
            $y = $calendar->fiscalYearOfDate((string) $imp['completed_on']);
            $improvementsByYear[$y] = ($improvementsByYear[$y] ?? 0.0) + (float) $imp['amount'];
        }

        $yearEndDates = [];
        foreach ($taxRows as $row) {
            $y = (int) $row['fiscal_year'];
            $periodRow = $this->periods->findByYear($supplierId, $y);
            $yearEndDates[$y] = $periodRow !== null ? (string) $periodRow['ends_on'] : $calendar->periodEnd($y);
        }

        $rows = self::assembleRows($taxRows, $improvementsByYear, $yearEndDates);
        $kind = (string) $asset['kind'];
        $taxGroup = $asset['tax_group'] !== null ? (int) $asset['tax_group'] : null;

        return [
            'card_number'          => $cardNumber,
            'inventory_number'     => $asset['inventory_number'] !== null ? (string) $asset['inventory_number'] : null,
            'name'                 => (string) $asset['name'],
            'description'          => $asset['description'] !== null && $asset['description'] !== '' ? (string) $asset['description'] : null,
            'kind'                 => $kind,
            'status'               => (string) $asset['status'],
            'input_price'          => round((float) $asset['input_price'], 2),
            'increased_input_price' => round((float) ($asset['increased_input_price'] ?? $asset['input_price']), 2),
            'acquisition_date'     => (string) $asset['acquisition_date'],
            'put_into_use_date'    => $asset['put_into_use_date'] !== null ? (string) $asset['put_into_use_date'] : null,
            'tax_group'            => $taxGroup,
            'tax_group_tokens'     => self::taxGroupTokens($taxGroup, $kind),
            'tax_method'           => $taxMethod,
            'tax_method_label'     => self::methodLabel($taxMethod),
            'acc_method'           => $accMethod,
            'first_year_increase'  => $asset['tax_first_year_increase'] !== null ? (string) $asset['tax_first_year_increase'] : 'none',
            'is_first_year_increase' => (string) $asset['tax_first_year_increase'] !== 'none',
            'is_extraordinary'    => $taxMethod === 'extraordinary',
            'rows'                 => $rows,
            'rows_total'           => [
                'depreciation' => round(array_sum(array_column($rows, 'depreciation')), 2),
                'improvement'  => round(array_sum(array_column($rows, 'improvement')), 2),
            ],
            'final_residual'       => $rows !== [] ? (float) $rows[count($rows) - 1]['residual_end'] : round((float) ($asset['increased_input_price'] ?? $asset['input_price']), 2),
        ];
    }

    /**
     * Poskládá řádky karty z čistého daňového plánu (DepreciationCalculator::plan()['tax'])
     * a TZ po letech — bez DB závislostí, čistá funkce (testovatelné bez mocku databáze).
     *
     * @param list<array<string,mixed>> $taxRows výstup DepreciationCalculator::plan()['tax']
     * @param array<int,float> $improvementsByYear Σ TZ dokončených v daném roce
     * @param array<int,string> $yearEndDates poslední den zdaňovacího období daného roku
     * @return list<array{no:int, fiscal_year:int, date:string, residual_start:float,
     *     improvement:float, depreciation:float, residual_end:float, note:?string}>
     */
    public static function assembleRows(array $taxRows, array $improvementsByYear, array $yearEndDates): array
    {
        $rows = [];
        $no = 0;
        foreach ($taxRows as $row) {
            $no++;
            $year = (int) $row['fiscal_year'];
            $notes = [];
            if (!empty($row['note'])) {
                $notes[] = (string) $row['note'];
            }
            if (!empty($row['is_half'])) {
                $notes[] = 'půlodpis (§26 odst. 7 ZDP)';
            }
            if (!empty($row['is_paused'])) {
                $notes[] = 'přerušeno (§26 odst. 8 ZDP)';
            }
            $rows[] = [
                'no'             => $no,
                'fiscal_year'    => $year,
                'date'           => $yearEndDates[$year] ?? sprintf('%04d-12-31', $year),
                'residual_start' => round((float) $row['residual_start'], 2),
                'improvement'    => round($improvementsByYear[$year] ?? 0.0, 2),
                'depreciation'   => round((float) $row['amount'], 2),
                // R7: teoreticky nikdy záporná (cap na ZC ve strategiích), zaokrouhlení
                // na dvě desetiny by ale mohlo vyrobit -0.00 — bránit se explicitně.
                'residual_end'   => max(0.0, round((float) $row['residual_end'], 2)),
                'note'           => $notes !== [] ? implode('; ', $notes) : null,
            ];
        }
        return $rows;
    }

    /**
     * @return list<array{label:string, active:bool}>
     */
    public static function taxGroupTokens(?int $taxGroup, string $kind): array
    {
        $active = null;
        if ($kind === 'intangible') {
            $active = 'N'; // nehmotný majetek nemá odpisovou skupinu HM
        } elseif ($taxGroup !== null) {
            $active = (string) $taxGroup;
        }
        $out = [];
        foreach (self::TAX_GROUP_TOKENS as $token) {
            $out[] = ['label' => $token, 'active' => $token === $active];
        }
        return $out;
    }

    private static function methodLabel(string $method): string
    {
        return match ($method) {
            'straight' => 'rovnoměrný (§31 ZDP)',
            'accelerated' => 'zrychlený (§32 ZDP)',
            'extraordinary' => 'mimořádný (§30a ZDP)',
            'by_accounting' => 'dle účetnictví (§24 odst. 2 písm. v) ZDP)',
            'none' => 'neodpisuje se',
            default => $method,
        };
    }

    /**
     * @return array{name:string, ico:?string, address:string, prepared_at:string}
     */
    private function entity(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, street, city, zip, ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $addressParts = array_filter([
            trim((string) ($row['street'] ?? '')),
            trim((string) ($row['zip'] ?? '') . ' ' . (string) ($row['city'] ?? '')),
        ], static fn (string $p): bool => $p !== '');

        return [
            'name'        => (string) ($row['company_name'] ?? ''),
            'ico'         => $row['ic'] !== null && $row['ic'] !== '' ? (string) $row['ic'] : null,
            'address'     => implode(', ', $addressParts),
            'prepared_at' => (new \DateTimeImmutable())->format('d.m.Y H:i'),
        ];
    }

    /** Kalendář firmy (kalendářní vs hospodářský rok) — shodné s AssetService/DepreciationPostingService. */
    private function supplierCalendar(int $supplierId): FiscalCalendar
    {
        return $this->calendarCache[$supplierId]
            ??= $this->periods->fiscalCalendar($supplierId);
    }
}
