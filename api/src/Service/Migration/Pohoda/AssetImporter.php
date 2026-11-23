<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Migration\MoneyS3\AccountCode;
use MyInvoice\Service\Migration\Shared\MigratedDisposal;

/**
 * Karty dlouhodobého majetku z `90_majetek.xml` (tabulky POHODY, které exportní nástroj
 * čte z datového souboru - XML export POHODY majetek neobsahuje).
 *
 * Karta vznikne jako historický majetek ve stavu zařazeno, bez zápisu v deníku: zařazení
 * i dosavadní odpisy už v převedeném deníku jsou (počáteční stavy a odpisy roku).
 * Počáteční stavy odpisů počítá {@see PohodaAssetPlan} tak, aby další odpis v MyÚčtu
 * připadl na první měsíc, který POHODA ještě nezaúčtovala.
 *
 * Technická zhodnocení z pohybů karty (`IMpohyb`) se převedou na kartu se svým datem, karta
 * se vede od ceny zařazení. Zaúčtování zhodnocení nese převedený deník.
 *
 * Účty karta v POHODĚ nenese, bere je převod z deníku: oprávky z odpisových zápisů karty
 * (zdroj „Dlouhodobý majetek", číslo karty), majetkový účet z počátečního stavu účtu
 * 01x-03x ve výši vstupní ceny (při shodě více účtů podle analytiky oprávek, u účtu více
 * karet podle analytiky oprávek karty).
 *
 * Po založení se zůstatky karty ke konci roku agendy porovnají s POHODOU; rozdíl je
 * varování v protokolu a karta zůstane koncept ke kontrole.
 */
final class AssetImporter
{
    public const STEP = 'assets';

    public function __construct(
        private readonly PohodaImportRepository $map,
        private readonly AssetService $assets,
        private readonly MigratedDisposal $disposals,
    ) {}

    public function import(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        if ($ctx->export->path('assets') === null) {
            $p->info(self::STEP, 'no_asset_export', 'Export neobsahuje majetek z datového souboru POHODY (90_majetek.xml). '
                . 'Vytvoří ho exportní nástroj; bez něj zadejte karty majetku ručně.');
            return;
        }
        $year = $ctx->year();
        $periodStart = $ctx->periodStart();
        $defaultLastBooked = date('Y-m', (int) strtotime($periodStart . ' -1 month'));
        $taxRows = self::byCard($ctx->export->records('assets', 'IModpis'));
        $ownPlan = self::byCard($ctx->export->records('assets', 'IMuodpis'));
        $spreadPlan = self::byCard($ctx->export->records('assets', 'IModpisM'));
        $movements = self::byCard($ctx->export->records('assets', 'IMpohyb'));
        $journal = $this->journalFacts($ctx);
        $existing = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_ASSET);

        foreach ($ctx->export->records('assets', 'IM') as $row) {
            $id = PohodaXml::text($row, 'ID');
            $number = PohodaXml::text($row, 'Cislo');
            if ($number === '') {
                $p->warn(self::STEP, 'asset_without_number', 'Karta majetku bez inventárního čísla, nepřevzata.');
                continue;
            }
            $key = 'asset|' . $number;
            if (isset($existing[$key])) {
                $p->count(self::STEP, 'existing');
                continue;
            }
            if ($ctx->skipsDate(PohodaXml::date($row, 'DatZar') ?? PohodaXml::date($row, 'Datum'))) {
                $p->count(self::STEP, 'later_year_skipped');
                continue;
            }
            $disposal = PohodaXml::date($row, 'DatLikv');
            if ($disposal !== null && $disposal < $periodStart) {
                $p->count(self::STEP, 'disposed_before');
                continue;
            }
            $booked = $journal['booked'][$number] ?? null;
            // Daňové odpisy (`IModpis.Rok`) čísluje POHODA roky svých agend - zdaňovací období
            // právnické osoby s hospodářským rokem je ten hospodářský rok - proto rok agendy
            // z exportu, ne label období firmy.
            $monthRows = $ownPlan[$id] ?? $spreadPlan[$id] ?? [];
            $plan = PohodaAssetPlan::build($row, $taxRows[$id] ?? [], $monthRows, $ctx->export->year, $booked['last'] ?? $defaultLastBooked, $movements[$id] ?? []);
            $card = $plan['card'];
            $review = $plan['review'];
            $improvements = $plan['improvements'];
            if (array_any($improvements, static fn (array $improvement): bool => $ctx->skipsDate($improvement['completed_on']))) {
                $p->warn(self::STEP, 'asset_improvement_outside_selection', "Karta majetku {$number} obsahuje technické zhodnocení mimo vybrané roky. Ověřte ji samostatně.", ['document_no' => $number]);
                continue;
            }
            // Počáteční stav majetkového účtu obsahuje zhodnocení dokončená před obdobím agendy.
            $openingPrice = (float) $card['input_price'];
            foreach ($improvements as $imp) {
                if ($imp['completed_on'] < $periodStart) {
                    $openingPrice += $imp['amount'];
                }
            }
            // Vyřazení v převáděných letech zaúčtoval převedený deník: karta se vyřadí bez
            // zaúčtování (MigratedDisposal). Ke kontrole zůstává jen vyřazení mimo ně.
            $disposedInJournal = $disposal !== null && $disposal <= ($ctx->lastPeriodEnd() ?? $disposal) && !$ctx->skipsDate($disposal);
            if ($disposal !== null && !$disposedInJournal) {
                $review[] = 'majetek je v POHODĚ vyřazený ' . $disposal . ', vyřazení proveďte v MyÚčtu';
                $card['status'] = 'draft';
            }

            $accumulated = $journal['accumulated'][$number] ?? null;
            $assetAccount = $this->assetAccount($journal['opening'], round($openingPrice, 2), $accumulated);
            if ($assetAccount === null) {
                $review[] = 'majetkový účet se z počátečních stavů nepodařilo určit';
                $card['status'] = 'draft';
            } else {
                $card['asset_account_code'] = AccountCode::fromMoney($assetAccount);
            }
            if ($accumulated !== null) {
                $card['accumulated_account_code'] = AccountCode::fromMoney($accumulated);
            } elseif ($assetAccount !== null && ($guess = $this->matchingAccumulated($ctx, $assetAccount)) !== null) {
                $card['accumulated_account_code'] = $guess;
            }
            $card['acquisition_account_code'] = $this->acquisitionAccount($ctx, $card['kind'] === 'intangible' ? '041' : '042');
            if ($booked !== null && abs($booked['amount'] - self::plannedInPeriod($ownPlan[$id] ?? $spreadPlan[$id] ?? [], $ctx->calendar->periodStart($year), $ctx->calendar->periodEnd($year), $booked['last'])) >= 1.0) {
                $review[] = 'odpisy zaúčtované v deníku roku ' . $year . ' (' . number_format($booked['amount'], 2, ',', ' ')
                    . ' Kč) nesedí s odpisovým plánem karty';
            }
            if ($review !== [] && $card['status'] !== 'draft') {
                $card['status'] = 'draft';
            }
            if ($review !== [] && $disposedInJournal) {
                $review[] = 'majetek je v POHODĚ vyřazený ' . $disposal . ' a vyřazení je v převedeném deníku: po kontrole kartu zařaďte a vyřaďte bez zaúčtování';
            }
            $card['description'] = $review === [] ? null : 'Převod z POHODY - ke kontrole: ' . implode('; ', $review) . '.';

            try {
                $created = $this->assets->createWithImprovements($ctx->supplierId, $card, $improvements, ['user_id' => $ctx->userOrNull()]);
            } catch (AssetException $e) {
                $p->warn(self::STEP, 'asset_rejected', "Karta majetku {$number} nepřevzata: " . $e->getMessage(), ['document_no' => $number]);
                continue;
            }
            $assetId = (int) $created['asset']['id'];
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_ASSET, $key, $assetId, $ctx->runId);
            if ($improvements !== []) {
                $p->count(self::STEP, 'improvements', count($improvements));
            }

            // Kontrola zůstatků proti POHODĚ ke konci roku agendy. Vyřazenou kartu POHODA
            // dopočítává k vyřazení, porovnání by nesedělo.
            $mismatch = [];
            if ($disposal === null) {
                $mismatch = $this->balanceMismatch($ctx, $assetId, PohodaAssetPlan::balances($row, $monthRows, isset($ownPlan[$id]), $ctx->periodEnd()));
                $p->count(self::STEP, $mismatch === [] ? 'balances_match' : 'balances_differ');
            }
            if ($mismatch !== []) {
                $note = 'zůstatky k ' . $ctx->periodEnd() . ' nesedí s POHODOU: ' . implode(', ', $mismatch);
                $p->warn(self::STEP, 'asset_balance_mismatch', "Karta majetku {$number}: {$note}.", ['document_no' => $number]);
                if ($review === []) {
                    $this->disposals->toReview($ctx->supplierId, $assetId, 'Převod z POHODY - ke kontrole: ' . $note . '.');
                    $card['status'] = 'draft';
                }
            }
            $p->count(self::STEP, $card['status'] === 'draft' ? 'drafts' : 'created');
            if ($review !== []) {
                $p->warn(self::STEP, 'asset_review', "Karta majetku {$number} převzata jako koncept ke kontrole: " . implode('; ', $review) . '.', ['document_no' => $number]);
            } elseif ($disposedInJournal) {
                $this->disposeMigrated($ctx, $assetId, (string) $disposal, $number);
            }
        }
    }

    /**
     * Vstupní cena, daňová a účetní zůstatková cena karty v MyÚčtu ke konci roku agendy
     * (počáteční stavy + plán odpisů) proti hodnotám karty v POHODĚ.
     *
     * @param array{input_price:float, tax_residual:?float, acc_residual:?float} $pohoda
     * @return list<string> popis rozdílů, prázdný = sedí
     */
    private function balanceMismatch(PohodaContext $ctx, int $assetId, array $pohoda): array
    {
        $plan = $this->assets->plan($ctx->supplierId, $assetId);
        $summary = $plan['asset_summary'];
        $asset = $this->assets->get($ctx->supplierId, $assetId) ?? [];
        $increased = (float) $summary['increased_input_price'];
        $tax = $increased - (float) ($asset['opening_tax_amount'] ?? 0);
        foreach ($plan['tax'] as $row) {
            if ((int) $row['fiscal_year'] <= $ctx->year()) {
                $tax -= (float) $row['full_amount'];
            }
        }
        $endMonth = substr($ctx->periodEnd(), 0, 7);
        $acc = $increased - (float) ($asset['opening_acc_amount'] ?? 0);
        foreach ($plan['accounting'] as $row) {
            foreach ((array) ($row['months'] ?? []) as $month) {
                if ((string) $month['month'] <= $endMonth) {
                    $acc -= (float) $month['amount'];
                }
            }
        }
        $out = [];
        foreach ([
            'vstupní cena' => [$increased, $pohoda['input_price']],
            'daňová zůstatková cena' => [$tax, $pohoda['tax_residual']],
            'účetní zůstatková cena' => [$acc, $pohoda['acc_residual']],
        ] as $label => [$myucto, $source]) {
            if ($source !== null && abs($myucto - $source) >= 1.0) {
                $out[] = $label . ' MyÚčto ' . number_format($myucto, 2, ',', ' ') . ' Kč, POHODA ' . number_format($source, 2, ',', ' ') . ' Kč';
            }
        }
        return $out;
    }

    /**
     * Karta vyřazená v převáděných letech: vyřazení zaúčtoval převedený deník, karta se
     * proto vyřadí bez zaúčtování a naváže na zápis vyřazení ({@see MigratedDisposal}).
     * Nejde-li to, zůstane koncept ke kontrole.
     */
    private function disposeMigrated(PohodaContext $ctx, int $assetId, string $disposal, string $number): void
    {
        $result = $this->disposals->dispose($ctx->supplierId, $assetId, $disposal, null, $ctx->userOrNull());
        if ($result['disposed']) {
            $ctx->protocol->count(self::STEP, 'disposed_from_journal');
            if ($result['entry_id'] !== null) {
                $ctx->protocol->count(self::STEP, 'disposal_entry_linked');
            }
            return;
        }
        $note = 'majetek je v POHODĚ vyřazený ' . $disposal . ' a vyřazení je v převedeném deníku, kartu se ale nepodařilo vyřadit ('
            . $result['message'] . '): vyřaďte ji bez zaúčtování';
        $this->disposals->toReview($ctx->supplierId, $assetId, 'Převod z POHODY - ke kontrole: ' . $note . '.');
        $ctx->protocol->warn(self::STEP, 'asset_review', "Karta majetku {$number} převzata jako koncept ke kontrole: {$note}.", ['document_no' => $number]);
    }

    /**
     * Z deníku exportu: odpisy roku po kartách (poslední měsíc, součet, účet oprávek)
     * a počáteční stavy účtů 01x-03x (kandidáti majetkového účtu).
     *
     * @return array{booked:array<string,array{last:string,amount:float}>, accumulated:array<string,string>, opening:array<string,float>}
     */
    private function journalFacts(PohodaContext $ctx): array
    {
        $booked = [];
        $accumulated = [];
        $opening = [];
        foreach ($ctx->export->records('journal', 'accountingItem') as $item) {
            $effect = PohodaJournal::effect($item);
            if ($effect === null) {
                continue;
            }
            if (PohodaJournal::isOpening($item)) {
                if (preg_match('/^0[123]/', $effect['debit']) === 1) {
                    $opening[$effect['debit']] = ($opening[$effect['debit']] ?? 0.0) + $effect['amount'];
                }
                continue;
            }
            if (PohodaJournal::source($item) !== PohodaJournal::ASSETS) {
                continue;
            }
            $number = PohodaJournal::number($item);
            $date = (string) PohodaXml::date($item, 'date');
            if ($number === '' || $date === '' || $ctx->skipsDate($date) || preg_match('/^0[78]/', $effect['credit']) !== 1) {
                continue;
            }
            $accumulated[$number] ??= $effect['credit'];
            $month = substr($date, 0, 7);
            $booked[$number] = [
                'last' => max($booked[$number]['last'] ?? '', $month),
                'amount' => ($booked[$number]['amount'] ?? 0.0) + $effect['amount'],
            ];
        }
        return ['booked' => $booked, 'accumulated' => $accumulated, 'opening' => $opening];
    }

    /**
     * Majetkový účet: počáteční stav 01x-03x ve výši vstupní ceny. Shoduje-li se víc účtů,
     * rozhodne analytika shodná s účtem oprávek (022007 ↔ 082007). Vede-li účet víc karet
     * (počáteční stav je jejich součet), určí ho účet oprávek z odpisů karty: majetkový účet
     * se stejnou analytikou, je-li v počátečních stavech a pokryje vstupní cenu.
     *
     * @param array<string,float> $opening
     */
    private function assetAccount(array $opening, float $inputPrice, ?string $accumulated): ?string
    {
        $matched = $this->matchingOpening($opening, $inputPrice, $accumulated);
        if ($matched !== false || $accumulated === null || preg_match('/^0[78]/', $accumulated) !== 1) {
            return $matched === false ? null : $matched;
        }
        $derived = '0' . ((int) substr($accumulated, 1, 1) - 6) . substr($accumulated, 2);
        return isset($opening[$derived]) && $opening[$derived] + 1.0 >= $inputPrice ? $derived : null;
    }

    /**
     * Účet s počátečním stavem ve výši vstupní ceny; null = víc kandidátů, false = žádný.
     *
     * @param array<string,float> $opening
     */
    private function matchingOpening(array $opening, float $inputPrice, ?string $accumulated): string|false|null
    {
        // Nejdřív přesná shoda; karta v POHODĚ může mít vstupní cenu zaokrouhlenou na koruny
        // proti haléřovému zůstatku účtu, proto pak shoda do 1 Kč - vždy jen jednoznačná.
        foreach ([0.005, 1.0] as $tolerance) {
            $candidates = array_keys(array_filter($opening, static fn (float $amount): bool => abs($amount - $inputPrice) < $tolerance));
            if (count($candidates) > 1 && $accumulated !== null) {
                $candidates = array_values(array_filter($candidates, static fn (string $code): bool => substr($code, 3) === substr($accumulated, 3)));
            }
            if (count($candidates) === 1) {
                return (string) $candidates[0];
            }
            if ($candidates !== []) {
                return null;
            }
        }
        return false;
    }

    /** Účet oprávek se stejnou analytikou jako majetkový účet (`022007` → `082007`), existuje-li. */
    private function matchingAccumulated(PohodaContext $ctx, string $assetAccount): ?string
    {
        $code = AccountCode::fromMoney('0' . ((int) substr($assetAccount, 1, 1) + 6) . substr($assetAccount, 2));
        return $code !== null && isset($ctx->accountIds[$code]) ? $code : null;
    }

    private function acquisitionAccount(PohodaContext $ctx, string $synthetic): string
    {
        foreach (array_keys($ctx->accountIds) as $code) {
            if (str_starts_with((string) $code, $synthetic . '.')) {
                return (string) $code;
            }
        }
        return $synthetic;
    }

    /**
     * Plánovaný odpis roku do posledního zaúčtovaného měsíce včetně (kontrola proti deníku).
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function plannedInPeriod(array $rows, string $periodStart, string $periodEnd, string $lastBooked): float
    {
        $sum = 0.0;
        $from = substr($periodStart, 0, 7);
        $to = substr($periodEnd, 0, 7);
        foreach ($rows as $row) {
            $month = substr((string) PohodaXml::date($row, 'Mesic'), 0, 7);
            if ($month !== '' && $month >= $from && $month <= $to && $month <= $lastBooked) {
                $sum += PohodaXml::num($row, 'KcOdpis');
            }
        }
        return round($sum, 2);
    }

    /**
     * @param iterable<array<string,mixed>> $rows
     * @return array<string,list<array<string,mixed>>> ID karty => řádky
     */
    private static function byCard(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[PohodaXml::text($row, 'RefAg')][] = $row;
        }
        return $out;
    }
}
