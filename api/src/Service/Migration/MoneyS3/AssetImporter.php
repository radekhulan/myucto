<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationCalculator;
use MyInvoice\Service\Accounting\Assets\DepreciationContext;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;
use MyInvoice\Service\Migration\Shared\MigratedDepreciation;
use MyInvoice\Service\Migration\Shared\MigratedDisposal;
use MyInvoice\Service\Migration\Shared\SmallAssetCard;

/**
 * Evidence majetku Money (`MajInv` karty, `MjInvPoh` pohyby): dlouhodobý majetek
 * (`TypMajetku` 1) a drobný majetek (`TypMajetku` 0).
 *
 * Pohyby karty (`MjInvPoh.Typ`): Z zařazení, V zvýšení ceny, H technické zhodnocení,
 * S snížení ceny (dotace, dobropis), U účetní odpis, D daňový odpis zaúčtovaný jako
 * účetní (karty, kde se účetní odpis rovná daňovému), X účetní zůstatková cena při roční
 * uzávěrce karty, Y vyřazení. `ZustCena` u odpisů je účetní zůstatková cena po pohybu,
 * `OdpZustCen` označí odpis zůstatkové ceny (při vyřazení ho Money účtuje na 541).
 * Daňové odpisy hmotného majetku Money neukládá, počítá je z parametrů karty. Výjimkou je
 * pomocná karta bez majetkového účtu („pomocná karta - výpočet daňových odpisů"), na které
 * Money vede daňové odpisy karty „jen ÚČETNÍ odpis" s jinou daňovou vstupní cenou.
 *
 * Zařazení, odpisy i vyřazení Money zaúčtovalo interními doklady, které jsou v převzatém
 * deníku. Karta proto vznikne bez zápisu v deníku a vede se stejně jako karta převodu
 * z PREMIER ({@see \MyInvoice\Service\Migration\Premier\AssetImporter}): počáteční stavy
 * pokrývají roky před prvním převedeným rokem, každý převedený rok má účetní řádek
 * zaúčtovaný převzatým deníkem ({@see DepreciationEntryRepository::MIGRATED_JOURNAL},
 * hromadné účtování odpisů ho znovu neúčtuje) a potvrzený daňový řádek.
 */
final class AssetImporter
{
    public const STEP = 'assets';
    public const STEP_SMALL = 'small_assets';

    private const TYPE_LONG_TERM = 1;
    private const TYPE_SMALL = 0;

    /** Pohyby, které mění cenu karty (znaménko). */
    private const PRICE_MOVES = ['Z' => 1, 'V' => 1, 'H' => 1, 'S' => -1];
    private const KNOWN_MOVES = ['Z', 'V', 'H', 'S', 'U', 'D', 'X', 'Y'];

    /** `MajInv.ZpusobOdpi` - způsob daňového odpisu. */
    private const TAX_METHODS = ['Z' => 'accelerated', 'R' => 'straight', 'N' => 'straight'];

    /**
     * `MajInv.FL_LGMajSk` - odpisová skupina z číselníku Money, když karta nemá `OdpisSkupi`.
     * 5 je mimořádný odpis bezemisního vozidla (§30a), 20 FVE a 25 neodpisovaný majetek.
     */
    private const FL_GROUPS = [1 => 1, 4 => 2, 6 => 3, 7 => 4, 8 => 5, 9 => 6];
    private const FL_ZERO_EMISSION = 5;

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
        private readonly AssetService $assets,
        private readonly SmallAssetService $smallAssets,
        private readonly DepreciationEntryRepository $entries,
        private readonly DepreciationCalculator $calculator,
        private readonly MigratedDisposal $disposals,
    ) {}

    public function importLongTerm(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        [$cards, $moves] = $this->read($ctx);
        $years = $this->years($ctx);
        if ($years === [] || $cards === []) {
            $p->finish(self::STEP);
            return;
        }
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_ASSET);
        $end = $ctx->calendar->periodEnd(max($years));
        $evidence = [];
        $helpers = self::pairHelpers($cards, $moves);
        foreach ($cards as $no => $card) {
            if ((int) ($card['TypMajetku'] ?? -1) !== self::TYPE_LONG_TERM) {
                continue;
            }
            $helperNo = $helpers[$no] ?? null;
            $this->importCard($ctx, $existing, $years, $card, $moves[$no] ?? [],
                $helperNo !== null ? ['card' => $cards[$helperNo], 'moves' => $moves[$helperNo] ?? []] : null,
                array_search($no, $helpers, true) ?: null);
            // Evidence Money po syntetických účtech: majetek nevyřazený do konce převodu.
            $synthetic = AccountCode::synthetic((string) ($card['PrUcMaj'] ?? ''));
            $disposed = self::date($card['DatVyrazen'] ?? null);
            if (!in_array($synthetic, [null, '000'], true) && ($disposed === null || $disposed > $end)) {
                $evidence[$synthetic] = ($evidence[$synthetic] ?? 0.0) + self::priceAt($moves[$no] ?? [], $end);
            }
        }
        $this->reconcile($ctx, $end, $evidence);
        $p->finish(self::STEP);
    }

    /**
     * Karty proti účetnictví ke konci posledního převedeného roku: pořizovací ceny (vstupní
     * cena a technická zhodnocení) proti zůstatku majetkového účtu, oprávky (počáteční
     * stav a odpisy převedených let) proti zůstatku oprávkového účtu.
     *
     * @param array<string,float> $evidence ceny karet evidence Money po syntetických účtech
     */
    private function reconcile(ImportContext $ctx, string $end, array $evidence): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT a.asset_account_code, a.accumulated_account_code,
                    a.input_price + COALESCE((SELECT SUM(i.amount) FROM asset_improvements i WHERE i.asset_id = a.id AND i.completed_on <= ?), 0) AS price,
                    a.opening_acc_amount + COALESCE((SELECT SUM(d.amount) FROM depreciation_entries d
                        WHERE d.asset_id = a.id AND d.kind = 'accounting' AND d.fiscal_year <= ?), 0) AS accumulated
               FROM assets a
               JOIN money_s3_import_map m ON m.supplier_id = a.supplier_id AND m.kind = ? AND m.target_id = a.id
              WHERE a.supplier_id = ? AND a.status IN ('in_use', 'draft')"
        );
        $lastYear = $ctx->calendar->fiscalYearOfDate($end);
        $stmt->execute([$end, $lastYear, MoneyS3ImportRepository::KIND_ASSET, $ctx->supplierId]);
        $cards = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            // Po syntetických účtech: karta bez analytiky v osnově nese syntetiku a jiné karty
            // téhož účtu analytiku, zůstatky se tak nezapočítají dvakrát.
            $asset = substr((string) $r['asset_account_code'], 0, 3);
            $cards[$asset] = ($cards[$asset] ?? 0.0) + (float) $r['price'];
            if ($r['accumulated_account_code'] !== null) {
                $acc = substr((string) $r['accumulated_account_code'], 0, 3);
                $cards[$acc] = ($cards[$acc] ?? 0.0) - (float) $r['accumulated'];
            }
        }
        if ($cards === []) {
            return;
        }
        $balance = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts c ON c.id = l.account_id
              WHERE l.supplier_id = ? AND e.supplier_id = ? AND e.entry_date BETWEEN ? AND ?
                AND (c.account_code = ? OR c.account_code LIKE CONCAT(?, '.%'))
                AND e.source_type <> 'closing'"
        );
        ksort($cards);
        $diffs = [];
        $inMoney = [];
        foreach ($cards as $code => $amount) {
            // Rok nese počáteční stavy otevíracím zápisem, zůstatek je tedy součet jen za poslední rok.
            $balance->execute([$ctx->supplierId, $ctx->supplierId, $ctx->calendar->periodStart($lastYear), $end, $code, $code]);
            $ledger = round((float) $balance->fetchColumn(), 2);
            $diff = round($amount - $ledger, 2);
            if (abs($diff) < 0.01) {
                continue;
            }
            if (isset($evidence[$code]) && abs(round($evidence[$code], 2) - round($amount, 2)) < 0.01) {
                // Karty sedí na evidenci Money, rozdíl proti účtu je už v Money (majetek mimo evidenci).
                $inMoney[] = sprintf('%s: evidence %s, účet %s', $code, self::money(round($amount, 2)), self::money($ledger));
                continue;
            }
            $diffs[] = sprintf('%s: karty %s, účet %s', $code, self::money(round($amount, 2)), self::money($ledger));
        }
        $ctx->protocol->count(self::STEP, 'accounts_checked', count($cards));
        if ($inMoney !== []) {
            $ctx->protocol->info(self::STEP, 'ledger_diff_in_money', 'Evidence majetku Money nesedí na účty už v Money (majetek účtovaný mimo evidenci), karty ji převzaly beze změny: ' . implode('; ', $inMoney) . '.', ['accounts' => $inMoney]);
        }
        if ($diffs === [] && $inMoney === []) {
            $ctx->protocol->info(self::STEP, 'ledger_match', 'Karty majetku sedí na zůstatky majetkových a oprávkových účtů ke ' . $end . '.');
        } elseif ($diffs !== []) {
            $ctx->protocol->warn(self::STEP, 'ledger_mismatch', 'Karty majetku ke ' . $end . ' nesedí na účty: ' . implode('; ', $diffs) . '. Rozdíl je majetek účtovaný mimo evidenci Money nebo karta ke kontrole.', ['accounts' => $diffs]);
        }
    }

    public function importSmall(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        [$cards, $moves] = $this->read($ctx);
        $years = $this->years($ctx);
        if ($years === [] || $cards === []) {
            $p->finish(self::STEP_SMALL);
            return;
        }
        $end = $ctx->calendar->periodEnd(max($years));
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_SMALL_ASSET);
        foreach ($cards as $no => $card) {
            if ((int) ($card['TypMajetku'] ?? -1) !== self::TYPE_SMALL) {
                continue;
            }
            $key = (string) $no;
            if (isset($existing[$key])) {
                $p->count(self::STEP_SMALL, 'existing');
                continue;
            }
            $list = $moves[$no] ?? [];
            $acquired = self::firstDate($list, 'Z') ?? self::date($card['DatZarazen'] ?? null) ?? self::date($card['DatVyroby'] ?? null);
            if ($acquired === null || $acquired > $end) {
                $p->count(self::STEP_SMALL, 'later_years');
                continue;
            }
            $price = self::priceAt($list, $end);
            if ($price < 0.005) {
                $price = round((float) ($card['UcOdpPorC'] ?? 0), 2);
            }
            $disposed = SmallAssetCard::disposedWithin(self::date($card['DatVyrazen'] ?? null) ?? self::firstDate($list, 'Y'), $acquired, $end);
            $inUse = self::date($card['DatZarazen'] ?? null);
            $vendorNo = (int) ($card['CDodavatel'] ?? 0);
            $reason = trim((string) ($card['ZpVyrazeni'] ?? ''));
            $id = $this->smallAssets->create($ctx->supplierId, SmallAssetCard::payload(
                strtoupper(trim((string) ($card['Druh'] ?? ''))) === 'N' ? 'intangible' : 'tangible',
                trim((string) ($card['Nazev'] ?? '')) ?: SmallAssetCard::DEFAULT_NAME,
                SmallAssetCard::inventoryNumber(trim((string) ($card['InventCisl'] ?? '')), true),
                $acquired,
                $inUse !== null && $inUse >= $acquired ? $inUse : $acquired,
                1,
                max(0.0, $price),
                max(0.0, $price),
                self::text($card['Umisteno'] ?? null, 160),
                $disposed,
                mb_substr('Vyřazeno v Money S3' . ($reason !== '' ? ': ' . $reason : ''), 0, 255),
                'Převzato z evidence majetku Money S3 (karta č. ' . $no . ').',
                [
                    'vendor_client_id' => $vendorNo > 0 ? ($ctx->clientsByMoneyNo[$vendorNo] ?? null) : null,
                    'vendor_name' => self::text($card['SDodavatel'] ?? null, 190),
                ],
            ), $ctx->userId > 0 ? $ctx->userId : null);
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_SMALL_ASSET, $key, $id, $ctx->runId);
            $p->count(self::STEP_SMALL, $disposed !== null ? 'created_disposed' : 'created');
        }
        $p->finish(self::STEP_SMALL);
    }

    /**
     * @param array<string,int> $existing
     * @param list<int> $years převedené roky vzestupně
     * @param array<string,mixed> $card
     * @param list<array<string,mixed>> $list pohyby karty podle data
     * @param array{card:array<string,mixed>,moves:list<array<string,mixed>>}|null $helper pomocná karta
     *        Money s daňovými odpisy této karty
     * @param int|null $helperOf karta, jejíž daňové odpisy tato pomocná karta počítá
     */
    private function importCard(ImportContext $ctx, array $existing, array $years, array $card, array $list, ?array $helper = null, ?int $helperOf = null): void
    {
        $p = $ctx->protocol;
        $no = (int) $card['Cislo'];
        $first = $years[0];
        $last = $years[count($years) - 1];
        // Hranice účetních let firmy (hospodářský rok podle nastavení firmy). První období
        // zkrácené dnem vzniku se tu nebere: majetek před vznikem firma mít nemůže.
        $start = $ctx->calendar->periodStart($first);
        $end = $ctx->calendar->periodEnd($last);
        $beforeStart = $ctx->calendar->periodEnd($first - 1);
        $name = trim((string) ($card['Nazev'] ?? '')) ?: ('Karta ' . $no);
        $disposal = self::date($card['DatVyrazen'] ?? null) ?? self::firstDate($list, 'Y');
        $inUse = self::date($card['DatZarazen'] ?? null) ?? self::firstDate($list, 'Z')
            ?? ($list !== [] ? $list[0]['Datum'] : null) ?? self::date($card['DatVyroby'] ?? null);
        // Karta bez data zařazení (typicky pozemek zadaný jen jako evidence) se vede
        // od začátku převodu, aby nechyběla v inventarizaci účtu.
        $noInUseDate = $inUse === null;
        $inUse ??= $beforeStart;
        if ($inUse > $end) {
            $p->count(self::STEP, 'later_years');
            return;
        }
        if ($disposal !== null && $disposal < $start) {
            $p->count(self::STEP, 'disposed_before');
            return;
        }
        // Karta bez majetkového účtu je v Money pomocná evidence (výpočet daňových odpisů
        // k majetku vedenému na jiné kartě, licence), majetkem v účetnictví není.
        if (self::isHelper($card)) {
            $p->count(self::STEP, 'helper_cards');
            if ($helperOf !== null) {
                $p->info(self::STEP, 'helper_card_paired', "Pomocná karta {$no} ({$name}) počítá v Money daňové odpisy karty {$helperOf}; nepřevádí se, její daňové parametry a odpisy převzala karta {$helperOf}.", ['document_no' => (string) $no]);
            } else {
                $p->info(self::STEP, 'helper_card', "Karta {$no} ({$name}) nemá v Money majetkový účet, jde o pomocnou evidenci; nepřevádí se.", ['document_no' => (string) $no]);
            }
            return;
        }
        $key = (string) $no;
        $review = [];
        $tax = self::taxSetup($card, $list, $disposal, $helper['card'] ?? null);
        $taxMethod = $tax['method'];
        $plan = $this->taxPlan($ctx, $tax, $helper['card'] ?? $card, $helper['moves'] ?? $list, $inUse, $disposal, $helper !== null, $review);
        $this->reportResidualWriteOffs($ctx, $no, $name, $list, $disposal, $start, $end);
        if (isset($existing[$key])) {
            $this->depreciation($ctx, $existing[$key], $years, $list, $inUse, $disposal, $plan, $tax['equal']);
            $p->count(self::STEP, 'existing');
            if ($disposal !== null && $disposal <= $end && $this->unlinkedMigratedDisposal($ctx, $existing[$key])) {
                // Karta vyřazená dřívějším převodem bez vazby na zápis vyřazení: doplní se
                // vazba i typ vyřazení, dokud je karta nemá (ruční změnu nepřepisuje).
                $this->markDisposed($ctx, $existing[$key], $disposal, $card);
            }
            return;
        }

        if ($noInUseDate) {
            $review[] = 'Money nemá datum zařazení, doplňte ho';
        }
        foreach ($list as $m) {
            if (!in_array($m['Typ'], self::KNOWN_MOVES, true) && $m['Datum'] <= $end) {
                $review[] = 'pohyb typu „' . $m['Typ'] . '" z ' . $m['Datum'] . ' převod nezná';
            }
        }
        // Vstupní cena ke dni, od kterého se karta v MyÚčtu vede: u majetku zařazeného
        // před prvním převedeným rokem cena na jeho začátku, jinak cena ke dni zařazení.
        // Pozdější zvýšení ceny a technická zhodnocení jsou technická zhodnocení karty.
        $priceDate = $inUse < $start ? $beforeStart : $inUse;
        $inputPrice = self::priceAt($list, $priceDate);
        if ($inputPrice < 0.01) {
            $inputPrice = round((float) ($card['UcOdpPorC'] ?? 0), 2);
        }
        $improvements = [];
        foreach ($list as $m) {
            if ($m['Datum'] > $priceDate && $m['Datum'] <= $end && isset(self::PRICE_MOVES[$m['Typ']])) {
                if (self::PRICE_MOVES[$m['Typ']] < 0) {
                    // Snížení ceny (dotace, dobropis) se zapíše jako záporné zhodnocení, aby karta
                    // seděla na účet; daňový plán ho promítne do daňové vstupní ceny ({@see taxPlan()}).
                    $improvements[] = $m;
                } elseif ($m['Typ'] !== 'Z' || $m['Datum'] > $inUse) {
                    $improvements[] = $m;
                }
            }
        }
        if ($inputPrice < 0.01) {
            $review[] = 'karta nemá vstupní cenu';
        }

        $kind = strtoupper(trim((string) ($card['Druh'] ?? ''))) === 'N' ? 'intangible' : 'tangible';
        $assetAccount = $this->account($ctx, [(string) ($card['PrUcMaj'] ?? ''), (string) (self::lastValue($list, 'PrUcMaj') ?? '')]);
        $accumulated = $this->account($ctx, [(string) (self::lastValue($list, 'PrUcOpr') ?? ''), (string) ($card['PrUcOpr'] ?? '')]);
        $accMoves = self::accountingMoves($list, $disposal);
        // Skupina „N" je v Money neodpisovaný majetek (pozemky); odpisuje se jen karta s odpisy.
        $depreciable = $accMoves !== [];
        if ($assetAccount === null) {
            $review[] = 'majetkový účet karty ' . trim((string) ($card['PrUcMaj'] ?? '')) . ' v osnově není';
        }
        if ($depreciable && $accumulated === null) {
            $review[] = 'oprávkový účet karty v osnově není';
        }
        // Účetní odpisy před prvním převedeným rokem jsou počáteční stav karty.
        $before = array_values(array_filter($accMoves, static fn (array $m): bool => $m['Datum'] < $start));
        $openingAcc = round(array_sum(array_column($before, 'Castka')), 2);
        $inUseMonth = substr($inUse, 0, 7);
        $openingMonths = $before === [] ? 0 : max(0, self::monthsBetween($inUseMonth, substr((string) end($before)['Datum'], 0, 7)));
        // Daňové odpisy let před převodem jsou počáteční stav karty.
        $taxBefore = array_filter($plan, static fn (array $r): bool => $r['fiscal_year'] < $first);
        $taxYears = count($taxBefore);
        $taxAmount = round(array_sum(array_column($taxBefore, 'full_amount')), 2);
        $planned = in_array($taxMethod, ['straight', 'accelerated', 'extraordinary'], true);

        $card0 = [
            'inventory_number' => $this->inventoryNumber($ctx, trim((string) ($card['InventCisl'] ?? '')) ?: trim((string) ($card['KodMaj'] ?? '')), $no),
            'name' => mb_substr($name, 0, 255),
            'kind' => $kind,
            'input_price' => $inputPrice,
            'acquisition_date' => self::firstDate($list, 'Z') ?? $inUse,
            'put_into_use_date' => $inUse,
            'status' => 'in_use',
            'tax_method' => $taxMethod,
            'tax_group' => in_array($taxMethod, ['straight', 'accelerated'], true) ? $tax['group'] : null,
            'is_first_owner' => $taxMethod === 'extraordinary',
            'is_zero_emission' => $tax['zero_emission'],
            'opening_tax_years' => $planned ? $taxYears : 0,
            'opening_tax_amount' => $planned ? min($taxAmount, $inputPrice) : 0.0,
            'opening_acc_months' => $depreciable ? $openingMonths : 0,
            'opening_acc_amount' => $depreciable ? min($openingAcc, $inputPrice) : 0.0,
            'acc_useful_life_months' => $depreciable ? self::usefulLife($inUseMonth, $accMoves) : null,
            'acc_method' => 'straight_line',
            'acc_residual_value' => 0.0,
            'accumulated_account_code' => $depreciable ? $accumulated : null,
        ];
        if ($card0['acquisition_date'] > $inUse) {
            $card0['acquisition_date'] = $inUse;
        }
        if ($assetAccount !== null) {
            $card0['asset_account_code'] = $assetAccount;
        }
        $acquisition = $this->acquisitionAccount($ctx, $kind);
        if ($acquisition !== null) {
            $card0['acquisition_account_code'] = $acquisition;
        }
        if ($review !== []) {
            $card0['status'] = 'draft';
            $card0['description'] = 'Převod z Money S3 (karta č. ' . $no . ') - ke kontrole: ' . implode('; ', $review) . '.';
        } elseif ($helper !== null) {
            $card0['description'] = 'Převod z Money S3 (karta č. ' . $no . '), daňové odpisy podle pomocné karty Money č. '
                . (int) $helper['card']['Cislo'] . ' (daňová vstupní cena ' . self::money(self::priceAt($helper['moves'], $inUse)) . ').';
        } else {
            $card0['description'] = 'Převod z Money S3 (karta č. ' . $no . ')' . (self::accountingOnly($card) ? ', interní karta jen pro účetní odpisy, bez daňových odpisů.' : '.');
        }
        try {
            $created = $this->assets->create($ctx->supplierId, $card0, ['user_id' => $ctx->userId > 0 ? $ctx->userId : null]);
        } catch (AssetException $e) {
            $p->warn(self::STEP, 'asset_rejected', "Karta majetku {$no} ({$name}) nepřevzata: " . $e->getMessage(), ['document_no' => (string) $no]);
            return;
        }
        $assetId = (int) $created['asset']['id'];
        $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_ASSET, $key, $assetId, $ctx->runId);
        if ($review !== []) {
            // Koncept nese zhodnocení i odpisy z Money, aby karta seděla na účty; ke kontrole
            // zůstává jen to, co převod neumí převzít.
            $p->count(self::STEP, 'drafts');
            $p->warn(self::STEP, 'asset_review', "Karta majetku {$no} ({$name}) převzata jako koncept ke kontrole: " . implode('; ', $review) . '.', ['document_no' => (string) $no]);
        } else {
            $p->count(self::STEP, 'created');
        }
        foreach ($improvements as $m) {
            $decrease = self::PRICE_MOVES[$m['Typ']] < 0;
            $this->db->pdo()->prepare(
                'INSERT INTO asset_improvements (supplier_id, asset_id, completed_on, amount, description) VALUES (?, ?, ?, ?, ?)'
            )->execute([$ctx->supplierId, $assetId, $m['Datum'], round(($decrease ? -1 : 1) * (float) $m['Castka'], 2),
                mb_substr(trim(($m['Popis'] ?: ($decrease ? 'Snížení ceny' : 'Zvýšení ceny')) . ($m['Doklad'] !== '' ? ' (' . $m['Doklad'] . ')' : '')), 0, 255)]);
            $p->count(self::STEP, 'improvements');
        }
        if ($plan !== []) {
            $p->count(self::STEP, 'tax_computed');
        }
        $this->depreciation($ctx, $assetId, $years, $list, $inUse, $disposal, $plan, $tax['equal']);
        if ($disposal !== null && $disposal <= $end) {
            $this->markDisposed($ctx, $assetId, $disposal, $card);
            $p->count(self::STEP, 'disposed');
        }
    }

    /**
     * Odpisy převedených let: účetní přesně podle Money (zaúčtované převzatým deníkem),
     * daňové potvrzené podle daňového plánu karty. Otevřený rok má účetní řádek za dosud
     * zaúčtované měsíce; daňový odpis roku vznikne až uzávěrkou roku (v Money po ní
     * opakovaným převodem).
     *
     * @param list<int> $years
     * @param list<array<string,mixed>> $list
     * @param array<int,array<string,mixed>> $plan daňový plán karty podle roku
     * @param bool $taxEqualsAccounting hmotný majetek s daňovým odpisem rovným účetnímu (`UcRovnyDan`)
     */
    private function depreciation(ImportContext $ctx, int $assetId, array $years, array $list, string $inUse, ?string $disposal, array $plan, bool $taxEqualsAccounting = false): void
    {
        $asset = $this->db->pdo()->prepare('SELECT status, tax_method FROM assets WHERE id = ? AND supplier_id = ?');
        $asset->execute([$assetId, $ctx->supplierId]);
        $row = $asset->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return;
        }
        $accMoves = self::accountingMoves($list, $disposal);
        foreach ($years as $year) {
            $from = $ctx->calendar->periodStart($year);
            $to = $ctx->calendar->periodEnd($year);
            if ($inUse > $to || ($disposal !== null && $disposal < $from)) {
                continue;
            }
            $inYear = array_values(array_filter($accMoves, static fn (array $m): bool => $m['Datum'] >= $from && $m['Datum'] <= $to));
            $booked = round(array_sum(array_column($inYear, 'Castka')), 2);
            if ($booked > 0.0) {
                $this->upsertMigrated($ctx, $assetId, 'accounting', $year, $booked, $booked, round((float) end($inYear)['ZustCena'], 2), self::monthCount($inYear), false, 'posted');
            }
            $closed = $ctx->isLocked($year) || ($disposal !== null && $disposal <= $to) || $year < max($years);
            if (!$closed) {
                continue;
            }
            if ($row['tax_method'] === 'by_accounting') {
                $residual = $inYear !== [] ? round((float) end($inYear)['ZustCena'], 2) : 0.0;
                $this->upsertMigrated($ctx, $assetId, 'tax', $year, $booked, $booked, $residual, null, false, 'confirmed');
            } elseif ($taxEqualsAccounting) {
                $r = self::equalTaxRow($list, $inYear, $to);
                if ($r !== null) {
                    $this->upsertMigrated($ctx, $assetId, 'tax', $year, $r[0], $r[0], $r[1], null, false, 'confirmed');
                }
            } elseif (isset($plan[$year])) {
                $r = $plan[$year];
                $this->upsertMigrated($ctx, $assetId, 'tax', $year, (float) $r['amount'], (float) $r['full_amount'],
                    (float) $r['residual_end'], null, (bool) $r['is_half'], 'confirmed');
            }
        }
    }

    /**
     * Daňový odpis roku hmotného majetku, u kterého Money vede daňový odpis rovný účetnímu
     * (`UcRovnyDan`): roční odpis je měsíční účetní odpis za každý odepisovaný měsíc roku,
     * nejvýš daňová zůstatková cena na začátku roku zvýšená o letošní zhodnocení. Hmotný
     * majetek daňově podle účetních odpisů odpisovat nejde (§24/2/v je jen nehmotný), karta
     * proto nese daňovou metodu „none" a převedené roky potvrzený daňový řádek.
     *
     * @param list<array<string,mixed>> $list
     * @param list<array<string,mixed>> $inYear účetní odpisy roku
     * @param string $yearEnd poslední den účetního (zdaňovacího) období
     * @return array{0:float,1:float}|null [odpis, daňová zůstatková cena na konci roku]
     */
    private static function equalTaxRow(array $list, array $inYear, string $yearEnd): ?array
    {
        if ($inYear === []) {
            return null;
        }
        $regular = array_values(array_filter($inYear, static fn (array $m): bool => $m['OdpZC'] !== 1)) ?: $inYear;
        $monthly = (float) end($regular)['Castka'];
        $first = $inYear[0];
        $cap = (float) $first['ZustCena'] + (float) $first['Castka']
            + self::priceAt($list, $yearEnd) - self::priceAt($list, $first['Datum']);
        $amount = round(max(0.0, min(self::monthCount($inYear) * $monthly, $cap)), 2);
        return [$amount, round(max(0.0, $cap - $amount), 2)];
    }

    /** @param list<array<string,mixed>> $moves */
    private static function monthCount(array $moves): int
    {
        $months = [];
        foreach ($moves as $m) {
            $months[substr($m['Datum'], 0, 7)] = true;
        }
        return count($months);
    }

    /**
     * Karta „jen ÚČETNÍ odpis": interní záznam Money pro účetní odpisy bez vazby na kartu
     * majetku. Nese hodnotu na majetkovém účtu a účetní odpisy; daňové odpisy k ní vede
     * pomocná karta ({@see pairHelpers()}), bez ní se daňově neodpisuje.
     *
     * @param array<string,mixed> $card
     */
    private static function accountingOnly(array $card): bool
    {
        return preg_match('/jen\s+[uú]četn[ií]/iu', (string) ($card['Nazev'] ?? '')) === 1;
    }

    /**
     * Karta bez majetkového účtu je v Money pomocná evidence (výpočet daňových odpisů
     * k majetku vedenému na jiné kartě, licence), majetkem v účetnictví není.
     *
     * @param array<string,mixed> $card
     */
    private static function isHelper(array $card): bool
    {
        return in_array(AccountCode::synthetic((string) ($card['PrUcMaj'] ?? '')), [null, '000'], true);
    }

    /**
     * Pomocné karty pro výpočet daňových odpisů a karty „jen ÚČETNÍ odpis", ke kterým patří.
     * Money vede majetek s rozdílnou účetní a daňovou vstupní cenou na dvou kartách: účetní
     * s majetkovým účtem a pomocnou bez něj (`000000`). Obě mají stejné datum zařazení, kód
     * SKP, odpisovou skupinu číselníku a stejná zvýšení, zhodnocení a snížení ceny, liší se
     * jen vstupní cenou. Bez jednoznačné shody pomocná karta zůstane nespárovaná.
     *
     * @param array<int,array<string,mixed>> $cards
     * @param array<int,list<array<string,mixed>>> $moves
     * @return array<int,int> číslo karty => číslo její pomocné karty
     */
    private static function pairHelpers(array $cards, array $moves): array
    {
        $pairs = [];
        foreach ($cards as $no => $helper) {
            if ((int) ($helper['TypMajetku'] ?? -1) !== self::TYPE_LONG_TERM || !self::isHelper($helper)) {
                continue;
            }
            $inUse = self::date($helper['DatZarazen'] ?? null);
            if ($inUse === null) {
                continue;
            }
            $signature = self::priceSignature($moves[$no] ?? []);
            $candidates = [];
            foreach ($cards as $other => $card) {
                if ($other === $no || isset($pairs[$other]) || (int) ($card['TypMajetku'] ?? -1) !== self::TYPE_LONG_TERM || self::isHelper($card)
                    || self::date($card['DatZarazen'] ?? null) !== $inUse
                    || trim((string) ($card['KodSKP'] ?? '')) !== trim((string) ($helper['KodSKP'] ?? ''))
                    || (int) ($card['FL_LGMajSk'] ?? 0) !== (int) ($helper['FL_LGMajSk'] ?? 0)
                    || self::priceSignature($moves[$other] ?? []) !== $signature) {
                    continue;
                }
                $candidates[] = $other;
            }
            $accountingOnly = array_values(array_filter($candidates, static fn (int $c): bool => self::accountingOnly($cards[$c])));
            if ($accountingOnly !== []) {
                $candidates = $accountingOnly;
            } elseif ($signature === []) {
                // Bez pohybů ceny by shoda stála jen na datu a skupině, to nestačí.
                $candidates = [];
            }
            if (count($candidates) === 1) {
                $pairs[$candidates[0]] = $no;
            }
        }
        return $pairs;
    }

    /**
     * Pohyby ceny karty kromě zařazení, po dnech: zvýšení a zhodnocení zvlášť od snížení.
     *
     * @param list<array<string,mixed>> $list
     * @return array<string,float>
     */
    private static function priceSignature(array $list): array
    {
        $signature = [];
        foreach ($list as $m) {
            if (in_array($m['Typ'], ['V', 'H', 'S'], true)) {
                $key = $m['Datum'] . ($m['Typ'] === 'S' ? '-' : '+');
                $signature[$key] = ($signature[$key] ?? 0.0) + (float) $m['Castka'];
            }
        }
        ksort($signature);
        return array_map(static fn (float $v): float => round($v, 2), $signature);
    }

    /**
     * Daňový způsob a skupina karty. Hmotný majetek podle `ZpusobOdpi` (Z zrychlený,
     * N a R rovnoměrný) a `OdpisSkupi`, bez ní podle skupiny číselníku (`FL_LGMajSk`);
     * bezemisní vozidlo mimořádně (§30a). Karta s daňovým odpisem rovným účetnímu
     * (`UcRovnyDan`): nehmotná odpisuje daňově podle účetních odpisů, hmotná nese potvrzené
     * daňové řádky ({@see equalTaxRow()}). Nehmotná karta bez `UcRovnyDan`, karta bez
     * odpisů (pozemky, skupina „N") a karta „jen ÚČETNÍ" bez pomocné karty se daňově
     * neodpisují. Karta s pomocnou kartou přebírá její daňové parametry.
     *
     * @param array<string,mixed> $card
     * @param list<array<string,mixed>> $list
     * @param array<string,mixed>|null $helper pomocná karta s daňovými odpisy karty
     * @return array{method:string,group:?int,zero_emission:bool,equal:bool}
     */
    private static function taxSetup(array $card, array $list, ?string $disposal, ?array $helper = null): array
    {
        $none = ['method' => 'none', 'group' => null, 'zero_emission' => false, 'equal' => false];
        if (self::accountingMoves($list, $disposal) === []) {
            return $none;
        }
        if ($helper !== null) {
            return self::taxParameters($helper);
        }
        if (self::accountingOnly($card)) {
            return $none;
        }
        // `UcRovnyDan` 1 účetní odpis rovný daňovému, 2 daňový zaúčtovaný jako účetní (pohyby D).
        $equal = (int) ($card['UcRovnyDan'] ?? 0) !== 0;
        if (strtoupper(trim((string) ($card['Druh'] ?? ''))) === 'N') {
            return $equal ? ['method' => 'by_accounting'] + $none : $none;
        }
        if ($equal) {
            return ['equal' => true] + $none;
        }
        return self::taxParameters($card);
    }

    /**
     * @param array<string,mixed> $card
     * @return array{method:string,group:?int,zero_emission:bool,equal:bool}
     */
    private static function taxParameters(array $card): array
    {
        $none = ['method' => 'none', 'group' => null, 'zero_emission' => false, 'equal' => false];
        $group = (int) trim((string) ($card['OdpisSkupi'] ?? ''));
        if ($group < 1 || $group > 6) {
            $catalog = (int) ($card['FL_LGMajSk'] ?? 0);
            if ($catalog === self::FL_ZERO_EMISSION) {
                return ['method' => 'extraordinary', 'zero_emission' => true] + $none;
            }
            $group = self::FL_GROUPS[$catalog] ?? 0;
        }
        $method = self::TAX_METHODS[strtoupper(trim((string) ($card['ZpusobOdpi'] ?? '')))] ?? null;
        if ($method === null || $group < 1 || $group > 6) {
            return $none;
        }
        return ['method' => $method, 'group' => $group] + $none;
    }

    /**
     * Daňový plán karty. Money daňové odpisy hmotného majetku neukládá, počítá je z parametrů
     * karty; stejně je spočte kalkulačka MyÚčta podle ZDP (§30a, §31, §32):
     *   - od zařazení, nebo od roku zahájení daňových odpisů (`DatZDanOdp`), je-li pozdější,
     *   - ze vstupní ceny k tomu dni, pozdější zvýšení ceny jsou technická zhodnocení,
     *   - snížení ceny (dotace) téhož dne jako zhodnocení se s ním započte, samotné snížení do
     *     konce roku po zahájení odpisů sníží vstupní cenu, pozdější je ke kontrole,
     *   - v roce vyřazení podle volby převodu půlodpis (§26/7), nebo bez odpisu,
     *   - daňové odpisy vedené na pomocné kartě (pohyby D) jsou potvrzené roky plánu.
     *
     * @param array{method:string,group:?int,zero_emission:bool,equal:bool} $tax
     * @param array<string,mixed> $card karta s daňovými parametry (pomocná, je-li)
     * @param list<array<string,mixed>> $list její pohyby
     * @param list<string> $review
     * @return array<int,array<string,mixed>> rok => řádek plánu
     */
    private function taxPlan(ImportContext $ctx, array $tax, array $card, array $list, string $inUse, ?string $disposal, bool $fromHelper, array &$review): array
    {
        $method = $tax['method'];
        if (!in_array($method, ['straight', 'accelerated', 'extraordinary'], true)) {
            return [];
        }
        // Daňové odpisy jdou po zdaňovacích obdobích; u právnické osoby s hospodářským rokem
        // je jím hospodářský rok (§ 17a ZDP), roky proto počítá kalendář účetního roku firmy.
        $calendar = $ctx->calendar;
        $start = $inUse;
        $taxStart = self::date($card['DatZDanOdp'] ?? null);
        if ($taxStart !== null && $calendar->fiscalYearOfDate($taxStart) > $calendar->fiscalYearOfDate($inUse)) {
            $start = $taxStart;
        }
        $startYear = $calendar->fiscalYearOfDate($start);
        $price = self::priceAt($list, $start);
        $byDate = [];
        foreach ($list as $m) {
            if ($m['Datum'] > $start && isset(self::PRICE_MOVES[$m['Typ']])) {
                $byDate[$m['Datum']] = ($byDate[$m['Datum']] ?? 0.0) + self::PRICE_MOVES[$m['Typ']] * (float) $m['Castka'];
            }
        }
        $improvements = [];
        foreach ($byDate as $date => $amount) {
            $amount = round($amount, 2);
            if ($amount < 0.0 && $calendar->fiscalYearOfDate((string) $date) <= $startYear + 1) {
                $price = round($price + $amount, 2);
            } elseif ($amount < 0.0) {
                $review[] = 'snížení ceny ' . self::money(-$amount) . ' z ' . $date . ' daňový plán nezahrnuje, ověřte daňovou zůstatkovou cenu';
            } elseif ($amount > 0.0 && $method !== 'extraordinary') {
                $improvements[] = ['completed_on' => $date, 'amount' => $amount];
            }
        }
        if ($price <= 0.0) {
            return [];
        }
        // Daňové odpisy pomocné karty: roky, které Money už odepsalo.
        $confirmed = [];
        $residuals = [];
        if ($fromHelper) {
            foreach ($list as $m) {
                if ($m['Typ'] === 'D') {
                    $year = $calendar->fiscalYearOfDate($m['Datum']);
                    $confirmed[$year] = round(($confirmed[$year] ?? 0.0) + (float) $m['Castka'], 2);
                    $residuals[$year] = round((float) $m['ZustCena'], 2);
                }
            }
        }
        ksort($confirmed);
        $noDisposalYear = $ctx->options->disposalYearTax === ImportOptions::DISPOSAL_YEAR_TAX_NONE;
        $context = new DepreciationContext(
            inputPrice: $price,
            taxGroup: $tax['group'],
            firstYearIncrease: 'none',
            isFirstOwner: true,
            isM1Vehicle: false,
            m1LimitException: false,
            putIntoUseDate: $start,
            disposalDate: $noDisposalYear ? null : $disposal,
            accUsefulLifeMonths: null,
            accResidualValue: 0.0,
            openingTaxYears: 0,
            openingTaxAmount: 0.0,
            openingAccMonths: 0,
            openingAccAmount: 0.0,
            improvements: $improvements,
            confirmedEntries: array_map(static fn (int $year, float $amount): array => [
                'fiscal_year' => $year, 'kind' => 'tax', 'amount' => $amount, 'full_amount' => $amount, 'is_paused' => false, 'is_half' => false,
            ], array_keys($confirmed), array_values($confirmed)),
            calendar: $calendar,
        );
        $plan = [];
        foreach ($this->calculator->plan($context, $method)['tax'] as $r) {
            $year = (int) $r['fiscal_year'];
            if ($noDisposalYear && $disposal !== null && $year >= $calendar->fiscalYearOfDate($disposal)) {
                continue;
            }
            if (isset($residuals[$year])) {
                $r['residual_end'] = $residuals[$year];
            }
            $plan[$year] = $r;
        }
        return $plan;
    }

    /**
     * Řádek odpisů převedeného roku. Řádek, který mezitím zaúčtovalo nebo potvrdilo MyÚčto
     * (ne převod), se nepřepisuje; převodem vzniklý se po opakovaném převodu srovná s Money.
     */
    private function upsertMigrated(ImportContext $ctx, int $assetId, string $kind, int $year, float $amount, float $full, float $residual, ?int $months, bool $half, string $status): void
    {
        $result = (new MigratedDepreciation($this->entries, $this->db))->confirm(
            $ctx->supplierId, $assetId, $kind, $year, $amount, $full, $residual, $full < 0.005, $half, $months,
            'Money S3', $status, MigratedDepreciation::OVERWRITE_OWN, true, true,
        );
        if ($result['written']) {
            $ctx->protocol->count(self::STEP, $kind === 'accounting' ? 'accounting_depreciation_booked' : 'tax_depreciation_confirmed');
        } elseif ($result['kept'] !== null) {
            $number = $this->db->pdo()->prepare('SELECT inventory_number FROM assets WHERE id = ? AND supplier_id = ?');
            $number->execute([$assetId, $ctx->supplierId]);
            MigratedDepreciation::reportKept($ctx->protocol, self::STEP, (string) $number->fetchColumn(), $kind, $year, $amount, $result['kept'], 'Money S3');
        }
    }

    /**
     * Karty a jejich pohyby podle data.
     *
     * @return array{0:array<int,array<string,mixed>>,1:array<int,list<array<string,mixed>>>}
     */
    private function read(ImportContext $ctx): array
    {
        $cards = [];
        $table = $ctx->backup->table('MajInv');
        foreach ($table !== null && $table->hasData() ? $table->rows() : [] as $r) {
            $cards[(int) ($r['Cislo'] ?? 0)] = $r;
        }
        $moves = [];
        $table = $ctx->backup->table('MjInvPoh');
        foreach ($table !== null && $table->hasData() ? $table->rows() : [] as $r) {
            $date = self::date($r['Datum'] ?? null);
            if ($date === null) {
                continue;
            }
            $moves[(int) ($r['CisloMajet'] ?? 0)][] = [
                'Cislo' => (int) ($r['Cislo'] ?? 0),
                'Typ' => strtoupper(trim((string) ($r['Typ'] ?? ''))),
                'Datum' => $date,
                'Castka' => (float) ($r['Castka'] ?? 0),
                'ZustCena' => (float) ($r['ZustCena'] ?? 0),
                'Doklad' => trim((string) ($r['Doklad'] ?? '')),
                'Popis' => trim((string) ($r['Popis'] ?? '')),
                'PrUcMaj' => trim((string) ($r['PrUcMaj'] ?? '')),
                'PrUcOpr' => trim((string) ($r['PrUcOpr'] ?? '')),
                'OdpZC' => (int) ($r['OdpZustCen'] ?? 0),
            ];
        }
        foreach ($moves as &$list) {
            usort($list, static fn (array $a, array $b): int => [$a['Datum'], $a['Cislo']] <=> [$b['Datum'], $b['Cislo']]);
        }
        unset($list);
        return [$cards, $moves];
    }

    /** @return list<int> */
    private function years(ImportContext $ctx): array
    {
        $years = array_map('intval', array_keys($ctx->periods));
        sort($years);
        return $years;
    }

    /** První účet ze seznamu kódů Money, který je v osnově (analytika, jinak syntetika). */
    private function account(ImportContext $ctx, array $moneyCodes): ?string
    {
        foreach ($moneyCodes as $code) {
            $analytic = AccountCode::fromMoney($code);
            if ($analytic !== null && trim($code, '0') !== '' && isset($ctx->accountIds[$analytic])) {
                return $analytic;
            }
        }
        foreach ($moneyCodes as $code) {
            $synthetic = AccountCode::synthetic($code);
            if ($synthetic !== null && $synthetic !== '000' && isset($ctx->accountIds[$synthetic])) {
                return $synthetic;
            }
        }
        return null;
    }

    /** Účet pořízení (041 nehmotný, 042 hmotný): první analytika osnovy, jinak syntetika. */
    private function acquisitionAccount(ImportContext $ctx, string $kind): ?string
    {
        $prefix = $kind === 'intangible' ? '041' : '042';
        $codes = array_filter(array_keys($ctx->accountIds), static fn ($c): bool => str_starts_with((string) $c, $prefix . '.'));
        sort($codes);
        return $codes !== [] ? (string) $codes[0] : (isset($ctx->accountIds[$prefix]) ? $prefix : null);
    }

    /** Inventární číslo jedinečné ve firmě: Money dovolí shodná čísla, MyÚčto ne. */
    private function inventoryNumber(ImportContext $ctx, string $number, int $cardNo): string
    {
        $number = $number !== '' ? mb_substr($number, 0, 30) : 'MS3-' . $cardNo;
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM assets WHERE supplier_id = ? AND inventory_number = ?');
        $stmt->execute([$ctx->supplierId, $number]);
        return $stmt->fetchColumn() === false ? $number : mb_substr($number, 0, 30 - strlen('/' . $cardNo)) . '/' . $cardNo;
    }

    /**
     * Účetní odpisy karty: pohyby U, v měsících bez nich (účetní odpis = daňový, typicky
     * nehmotný majetek) pohyby D. Odpis zůstatkové ceny ke dni vyřazení (`OdpZustCen`,
     * v Money na 541) odpisem není; drobný doodpis zůstatku u karty v užívání ano.
     *
     * @param list<array<string,mixed>> $list
     * @return list<array<string,mixed>>
     */
    private static function accountingMoves(array $list, ?string $disposal): array
    {
        $uMonths = [];
        foreach ($list as $m) {
            if ($m['Typ'] === 'U') {
                $uMonths[substr($m['Datum'], 0, 7)] = true;
            }
        }
        // Karta mohla během let přejít z účetních odpisů na daňové zaúčtované jako účetní
        // (a zpět): měsíc bez pohybu U nese účetní odpis v pohybu D.
        return array_values(array_filter($list, static fn (array $m): bool => match ($m['Typ']) {
            'U' => !(($m['OdpZC'] ?? 0) === 1 && $m['Datum'] === $disposal),
            'D' => !isset($uMonths[substr($m['Datum'], 0, 7)]) && $m['Datum'] !== $disposal && ($m['OdpZC'] ?? 0) !== 1,
            default => false,
        }));
    }

    /**
     * Doba účetního odpisování v měsících: uplynulé měsíce do posledního odpisu a zbytek
     * zůstatkové ceny posledním měsíčním odpisem.
     *
     * @param list<array<string,mixed>> $accMoves
     */
    private static function usefulLife(string $inUseMonth, array $accMoves): int
    {
        if ($accMoves === []) {
            return 1;
        }
        $lastMove = end($accMoves);
        $months = self::monthsBetween($inUseMonth, substr($lastMove['Datum'], 0, 7));
        if ($lastMove['ZustCena'] > 0.005 && $lastMove['Castka'] > 0.005) {
            $months += (int) ceil($lastMove['ZustCena'] / $lastMove['Castka']);
        }
        return max(1, $months);
    }

    /** @param list<array<string,mixed>> $list */
    private static function priceAt(array $list, string $date): float
    {
        $price = 0.0;
        foreach ($list as $m) {
            if ($m['Datum'] <= $date && isset(self::PRICE_MOVES[$m['Typ']])) {
                $price += self::PRICE_MOVES[$m['Typ']] * $m['Castka'];
            }
        }
        return round($price, 2);
    }

    /** @param list<array<string,mixed>> $list */
    private static function firstDate(array $list, string $type): ?string
    {
        foreach ($list as $m) {
            if ($m['Typ'] === $type) {
                return $m['Datum'];
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $list */
    private static function lastValue(array $list, string $field): ?string
    {
        $value = null;
        foreach ($list as $m) {
            if ($m[$field] !== '' && trim($m[$field], '0') !== '') {
                $value = $m[$field];
            }
        }
        return $value;
    }

    /** Typ vyřazení z důvodu Money (`ZpVyrazeni`); null = důvod typ neurčuje, rozhodne deník. */
    private static function disposalType(string $reason): ?string
    {
        $r = mb_strtoupper($reason);
        return match (true) {
            str_contains($r, 'PROD') => 'sold',
            str_contains($r, 'DAR') => 'donated',
            str_contains($r, 'MANK'), str_contains($r, 'ŠKOD'), str_contains($r, 'KRÁD') => 'damaged',
            str_contains($r, 'LIKV') => 'liquidated',
            default => null,
        };
    }

    /**
     * Odpis zůstatkové ceny (`OdpZustCen`) u karty, která ke dni odpisu v užívání zůstala:
     * Money zůstatek odepsalo, ale kartu nevyřadilo. Převod ho bere jako účetní odpis roku
     * ({@see accountingMoves()}); rekonciliace majetku ho hlásí, protože jde buď o skryté
     * vyřazení, nebo o doodpis haléřového zůstatku, který si zaslouží kontrolu.
     *
     * @param list<array<string,mixed>> $list
     */
    private function reportResidualWriteOffs(ImportContext $ctx, int $no, string $name, array $list, ?string $disposal, string $start, string $end): void
    {
        foreach ($list as $m) {
            if ($m['Typ'] !== 'U' || ($m['OdpZC'] ?? 0) !== 1 || $m['Datum'] === $disposal
                || $m['Datum'] < $start || $m['Datum'] > $end || abs((float) $m['Castka']) < 0.005) {
                continue;
            }
            $ctx->protocol->count(self::STEP, 'residual_writeoff_in_use');
            $ctx->protocol->warn(self::STEP, 'residual_writeoff_in_use', sprintf(
                'Karta %d (%s): Money %s odepsalo zůstatkovou cenu %s, karta přitom zůstala v užívání. '
                    . 'Převod ho vede jako účetní odpis roku; ověřte, zda nejde o vyřazení.',
                $no, $name, $m['Datum'], self::money((float) $m['Castka']),
            ), ['document_no' => (string) $no]);
        }
    }

    /** Karta vyřazená převodem (bez zápisu vyřazení z modulu), dosud bez vazby na zápis deníku. */
    private function unlinkedMigratedDisposal(ImportContext $ctx, int $assetId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM assets a
              WHERE a.id = ? AND a.supplier_id = ? AND a.status = 'disposed' AND a.disposal_entry_id IS NULL
                AND NOT EXISTS (SELECT 1 FROM journal_entries je
                                 WHERE je.supplier_id = a.supplier_id AND je.source_type = 'asset_disposal' AND je.source_id = a.id)"
        );
        $stmt->execute([$assetId, $ctx->supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Vyřazení karty zaúčtoval převzatý deník (Money interní doklad 54x / oprávky): karta
     * se jen přepne na vyřazenou a naváže na ten zápis, přiznání z něj bere účetní ZC.
     * Typ vyřazení z důvodu Money, jinak z deníku (tržba 641 ke dni vyřazení = prodej),
     * {@see MigratedDisposal}.
     *
     * @param array<string,mixed> $card karta Money
     */
    private function markDisposed(ImportContext $ctx, int $assetId, string $disposal, array $card): void
    {
        $asset = $this->db->pdo()->prepare('SELECT inventory_number, asset_account_code, accumulated_account_code FROM assets WHERE id = ? AND supplier_id = ?');
        $asset->execute([$assetId, $ctx->supplierId]);
        $accounts = $asset->fetch(\PDO::FETCH_ASSOC) ?: ['asset_account_code' => '', 'accumulated_account_code' => null];
        $type = $this->disposals->type($ctx->supplierId, $disposal, self::disposalType((string) ($card['ZpVyrazeni'] ?? '')));
        $saleInvoice = $type === 'sold' ? $this->disposals->saleEvidence($ctx->supplierId, $disposal)['invoice_id'] : null;
        $entryId = $this->disposals->entry($ctx->supplierId, $accounts, $disposal);
        $this->db->pdo()->prepare(
            "UPDATE assets SET status = 'disposed', disposal_date = ?, disposal_type = ?, sale_invoice_id = ?, disposal_entry_id = ? WHERE id = ? AND supplier_id = ?"
        )->execute([$disposal, $type, $saleInvoice, $entryId, $assetId, $ctx->supplierId]);
        if ($entryId !== null) {
            $ctx->protocol->count(self::STEP, 'disposal_entry_linked');
        }
    }

    private static function monthsBetween(string $from, string $to): int
    {
        return MigratedDepreciation::monthsBetween($from, $to);
    }

    private static function date(mixed $value): ?string
    {
        $v = (string) ($value ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        $v = trim((string) ($value ?? ''));
        return $v !== '' ? mb_substr($v, 0, $max) : null;
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ') . ' Kč';
    }
}
