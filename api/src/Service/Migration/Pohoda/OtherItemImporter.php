<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PayrollPostingImportRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Import\PamicaPosting\PamicaPostingParser;
use MyInvoice\Service\Accounting\OtherItemCounterpartyAccount;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Migration\Shared\MigratedOtherItemPolicy;
use PDO;

/**
 * Ostatní pohledávky a závazky POHODY mimo přiznání DPH jako doklady agendy Ostatní
 * pohledávky a závazky. Doklady v přiznání převádí {@see InvoiceImporter} na faktury.
 *
 * Doklad s vlastním zápisem převedeného deníku ho převezme (bez nového účtování) i s jeho
 * kontací. Doklad, který POHODA vede bez zaúčtování (předkontace „bez", předpis nese jiný
 * doklad, třeba interní), se převede jako potvrzený bez vlastního zápisu - v agendě je
 * vidět a páruje úhrady, ale znovu se neúčtuje. Úhrady z `liquidations` přiřadí po převodu
 * banky a pokladny {@see DocumentLinker::matchPayments()}. Převádí se i uhrazené doklady,
 * aby agenda nesla celou historii roku.
 *
 * Mzdové závazky (331, 336, 342, {@see MigratedOtherItemPolicy}) se převádějí stejně:
 * předpis s vlastním zápisem (pojistné) ho převezme, mzda zaměstnance (předpis nese interní
 * doklad mezd) a závazek z počátečního stavu se založí bez zápisu. Protokol je počítá
 * zvlášť (`payroll`). Předpisy DPH (343) vlastní daňový modul a nepřevádějí se. Zálohy na
 * daň z příjmů (341) bez zaúčtování převezme daňový modul jako předpisy záloh
 * ({@see self::taxAdvances()}).
 *
 * Doklad bez členění DPH s nulovou daní (MDB export členění u mzdových dokladů nevyplňuje)
 * je mimo přiznání ({@see self::outsideReturnWithoutVatClass()}). Položka nese variabilní
 * symbol, partnera a účet protistrany dokladu, podle kterých se páruje s úhradami z banky.
 *
 * Firmám převedeným dřív, než převod mzdové závazky zakládal, je doplní stejnými pravidly
 * {@see self::backfillPayroll()} (`api/bin/pohoda-backfill-payroll-items.php`).
 */
final class OtherItemImporter
{
    public const STEP = 'other_items';

    /** Účet záloh na daň z příjmů: evidenční závazek na něm je předpis zálohy. */
    private const TAX_ADVANCE_ACCOUNT = '341';

    /** Agendy ostatních dokladů a jejich zdroj v účetním deníku POHODY. */
    private const AGENDAS = ['receivable' => PohodaJournal::RECEIVABLE, 'commitment' => PohodaJournal::COMMITMENT];

    /**
     * Zápisy interních dokladů na mzdových účtech pro poznámku mzdového závazku
     * ({@see payrollSourceDocument()}); načtou se jednou za běh.
     *
     * @var list<array{number:string,date:string,side:string,account:string}>|null
     */
    private ?array $internalPayroll = null;

    /** Předpis mezd převzatého deníku pro mzdové závazky bez zápisu; načte se jednou za běh. */
    private ?PohodaPayrollPredpis $predpis = null;

    /**
     * Mzdové ostatní položky, které nezaložil převod (zadané v MyÚčtu, z PAMICY), po klíči
     * strana|účet|haléře|měsíc předpisu ({@see manualDuplicate()}); `null` = zatím nenačteno.
     *
     * @var array<string,int>|null
     */
    private ?array $manualItems = null;

    /** @var array{periods:array<string,true>,sym_pars:array<string,true>,dates:array<string,string>} */
    private array $payrollImported = ['periods' => [], 'sym_pars' => [], 'dates' => []];

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly PartnerImporter $partners,
        private readonly PayrollPostingImportRepository $payrollImports,
    ) {}

    public function run(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $sources = $this->sources($ctx);
        $advances = [];
        $payrollImported = [];
        $unposted = [];
        foreach (self::AGENDAS as $agenda => $source) {
            foreach ($ctx->export->records($agenda, 'invoice') as $record) {
                $plan = $this->plan($ctx, $sources, $agenda, $source, $record);
                switch ($plan['action']) {
                    case 'skipped':
                        $p->count(self::STEP, 'skipped');
                        continue 2;
                    case 'existing_item':
                        // Úhrady se párují i při opakovaném převodu - mohly mezitím přibýt.
                        self::captureLiquidations($ctx, 'other_item', $plan['id'], $plan['number'], $record);
                        self::rememberSourcePaid($ctx, $plan['id'], self::candidate($record, $agenda, $ctx->vat), $record);
                        $p->count(self::STEP, 'existing');
                        continue 2;
                    case 'existing_advance':
                        if (!$ctx->skipsDate(PohodaXml::date($plan['header'], 'dateDue') ?? $plan['issued'])) {
                            self::captureLiquidations($ctx, 'tax_advance', $plan['id'], $plan['number'], $record);
                        }
                        $p->count(self::STEP, 'existing');
                        continue 2;
                    case 'existing_invoice':
                        $p->count(self::STEP, 'existing');
                        continue 2;
                }
                if ($ctx->skipsNew(self::STEP)) {
                    continue;
                }
                switch ($plan['action']) {
                    case 'invoice':
                        break; // faktura z kroku dokladů
                    case 'review':
                        $p->count(self::STEP, 'requires_review');
                        $p->warn(self::STEP, 'other_item_review', $plan['message'],
                            ['document_no' => $plan['number']] + (isset($plan['rejection']) ? ['reason' => $plan['rejection']] : []));
                        break;
                    case 'tax_advance':
                        $advances[] = ['key' => $plan['key'], 'number' => $plan['number'], 'record' => $record, 'candidate' => $plan['candidate']];
                        break;
                    case 'module':
                        $p->count(self::STEP, 'payroll_or_tax');
                        break;
                    case 'payroll_import':
                        $p->count(self::STEP, 'payroll_import_month');
                        $payrollImported[] = $plan['number'];
                        break;
                    case 'unposted':
                        $unposted[] = $plan;
                        break;
                    default:
                        $this->create($ctx, $plan);
                }
            }
        }
        // Závazky bez zápisu čerpají předpis mezd: v pořadí dat, ať výsledek nezávisí na pořadí v exportu.
        foreach (self::byDate($unposted) as $plan) {
            $this->create($ctx, $plan);
        }
        if ($payrollImported !== []) {
            $p->info(self::STEP, 'payroll_liabilities_payroll_import', sprintf(
                '%d mzdových závazků převod nezaložil, zaúčtování mezd jejich měsíce je načtené z mzdového programu (PAMICA) i s jeho závazky: %s.',
                count($payrollImported), implode(', ', array_slice($payrollImported, 0, 20)) . (count($payrollImported) > 20 ? ', …' : ''),
            ), ['documents' => array_slice($payrollImported, 0, 50)]);
        }
        $this->taxAdvances($ctx, $advances);
    }

    /**
     * Mzdové závazky (331, 336, 342) firmy převedené dřív, než je převod zakládal: převod je
     * vynechal jako předpisy mzdového modulu a nechal jen v deníku. Z původního exportu
     * POHODY založí přesně ty mzdové doklady, které v agendě chybí, stejně jako je zakládá
     * {@see run()}: závazek s vlastním zápisem ho převezme bez nového účtování, mzda
     * zaměstnance (předpis nese interní doklad mezd) a závazek z počátečního stavu jsou
     * potvrzené bez zápisu. Klíč mapy převodu je stejný, opakované doplnění ani pozdější
     * převod nic nezdvojí. Ostatní doklady exportu nechá beze změny.
     *
     * Mzdovým dokladům, které už v agendě jsou, zachytí úhrady z exportu (jako opakovaný
     * převod), aby je {@see DocumentLinker::matchOtherItemPayments()} doplnil i z další agendy.
     * Mzdový doklad, který založit nejde (zápis deníku chybí, je stornovaný nebo ho už vlastní
     * jiný doklad, doklad je převedený jako faktura, číslo obsadila jiná položka), vrátí
     * s důvodem.
     *
     * @return list<array{agenda:string,number:string,issued:string,account:?string,amount:?float,
     *   status:string,reason:?string,id:?int,entry_id:?int,source_document:?string}>
     */
    public function backfillPayroll(PohodaContext $ctx): array
    {
        $p = $ctx->protocol;
        $sources = $this->sources($ctx);
        $existing = $this->payrollItems($ctx->supplierId, array_map('intval', array_values($sources['mapped'])));
        $plans = [];
        $entryIds = [];
        foreach (self::AGENDAS as $agenda => $source) {
            foreach ($ctx->export->records($agenda, 'invoice') as $record) {
                $plan = $this->plan($ctx, $sources, $agenda, $source, $record);
                if ($plan['action'] === 'skipped' || $plan['action'] === 'existing_advance' || $plan['action'] === 'tax_advance' || $plan['action'] === 'module') {
                    continue;
                }
                $plans[] = $plan;
                array_push($entryIds, ...$plan['entries']);
            }
        }
        $plans = self::byDate($plans);
        $payrollEntries = $this->payrollEntries($ctx->supplierId, $entryIds);
        // Doklady, jejichž zápis v deníku exportu míří na mzdový účet - i když ho deník MyÚčta
        // už nemá (smazaný zápis), nebo jeho mapa převodu chybí.
        $payrollJournal = [];
        foreach ($ctx->export->records('journal', 'accountingItem') as $item) {
            foreach (['accounting/credit', 'accounting/debit'] as $path) {
                if (MigratedOtherItemPolicy::payroll(PohodaXml::text($item, $path))) {
                    $payrollJournal[PohodaJournal::source($item) . '|' . PohodaJournal::number($item)] = true;
                }
            }
        }

        $rows = [];
        $refused = [];
        foreach ($plans as $plan) {
            $row = [
                'agenda' => $plan['agenda'], 'number' => $plan['number'], 'issued' => $plan['issued'],
                'account' => $plan['account'], 'amount' => $plan['candidate']['amount'] ?? null,
                'status' => 'skipped', 'reason' => null, 'id' => null, 'entry_id' => $plan['entry_id'], 'source_document' => null,
            ];
            if ($plan['action'] === 'existing_item') {
                $item = $existing[$plan['id']] ?? null;
                if ($item === null) {
                    continue; // jiná než mzdová ostatní položka
                }
                $row = ['account' => $item['account'], 'amount' => $item['amount'], 'id' => $plan['id'], 'entry_id' => $item['entry_id']] + $row;
                if ($item['deleted']) {
                    $rows[] = ['reason' => 'item_deleted'] + $row;
                    continue;
                }
                // Úhrady z exportu se doplní, uhrazenou část mimo banku ale položka v agendě už má
                // (mohla ji mezitím upravit účetní), doplnění ji nepřepisuje.
                self::captureLiquidations($ctx, 'other_item', $plan['id'], $plan['number'], $plan['record']);
                $p->count(self::STEP, 'existing');
                $rows[] = ['status' => 'existing'] + $row;
                continue;
            }
            $inJournal = isset($payrollJournal[self::AGENDAS[$plan['agenda']] . '|' . $plan['number']]);
            $payroll = ($plan['account'] !== null && MigratedOtherItemPolicy::payroll($plan['account']))
                || ($plan['rule_account'] !== null && MigratedOtherItemPolicy::payroll($plan['rule_account']))
                || array_intersect_key($payrollEntries, array_flip($plan['entries'])) !== [] || $inJournal;
            if (!$payroll) {
                $p->count(self::STEP, 'not_payroll');
                continue;
            }
            if ($plan['action'] === 'unposted' && $inJournal) {
                // Zápis má doklad v deníku POHODY, převzatý deník MyÚčta ho ale nemá.
                $rows[] = ['reason' => 'entry_missing'] + $row;
            } elseif ($plan['action'] === 'existing_invoice') {
                $rows[] = ['reason' => 'converted_as_invoice'] + $row;
            } elseif ($plan['action'] === 'invoice') {
                $rows[] = ['reason' => 'in_vat_return'] + $row;
            } elseif ($plan['action'] === 'payroll_import') {
                $rows[] = ['reason' => 'payroll_imported'] + $row;
            } elseif ($plan['action'] === 'review') {
                $refused[] = count($rows);
                $rows[] = ['reason' => $plan['reason']] + $row;
            } elseif (!MigratedOtherItemPolicy::payroll((string) $plan['account'])) {
                // Doklad se zápisem na mzdový účet, saldokontní účet zápisu je ale jiný.
                $p->count(self::STEP, 'not_payroll');
            } else {
                $created = $this->create($ctx, $plan);
                $rows[] = $created['id'] === null
                    ? ['reason' => $created['reason']] + $row
                    : ['status' => $plan['action'] === 'posted' ? 'created_posted' : 'created_unposted', 'id' => $created['id'],
                        'source_document' => $created['source_document']] + $row;
            }
        }
        // Proč převod zápis odmítl: zjistí se najednou pro všechny odmítnuté doklady.
        $problems = $this->entryProblems($ctx->supplierId, array_values(array_filter(array_map(
            static fn (int $i): ?int => $rows[$i]['reason'] === 'posting' ? $rows[$i]['entry_id'] : null, $refused))));
        foreach ($refused as $i) {
            if ($rows[$i]['reason'] === 'posting' && $rows[$i]['entry_id'] !== null) {
                $rows[$i]['reason'] = $problems[$rows[$i]['entry_id']] ?? 'posting';
            }
        }
        return $rows;
    }

    /**
     * Plány seřazené podle data dokladu a čísla (stabilně).
     *
     * @param list<array<string,mixed>> $plans
     * @return list<array<string,mixed>>
     */
    private static function byDate(array $plans): array
    {
        $order = array_keys($plans);
        usort($order, static fn (int $a, int $b): int => [$plans[$a]['issued'], $plans[$a]['number'], $a] <=> [$plans[$b]['issued'], $plans[$b]['number'], $b]);
        return array_map(static fn (int $i): array => $plans[$i], $order);
    }

    /**
     * Mapy převodu a předkontace, ze kterých {@see plan()} rozhoduje.
     *
     * @return array{mapped:array<string,int|string>,advances:array<string,int|string>,journal:array<string,int|string>,rules:array<string,string>,payroll_imports:array{periods:array<string,true>,sym_pars:array<string,true>,dates:array<string,string>}}
     */
    private function sources(PohodaContext $ctx): array
    {
        $this->internalPayroll = null;
        $this->predpis = null;
        $this->manualItems = null;
        $this->payrollImported = $this->payrollImports->activeImports($ctx->supplierId);
        return [
            'mapped' => $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_OTHER_ITEM),
            'advances' => $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_TAX_ADVANCE),
            'journal' => $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY),
            'rules' => $this->postingRuleAccounts($ctx),
            'payroll_imports' => $this->payrollImported,
        ];
    }

    /**
     * Co převod s dokladem udělá. Nic nezapisuje ani nepočítá do protokolu; zápis provede
     * {@see create()}, počty a upozornění volající ({@see run()}, {@see backfillPayroll()}).
     *
     * Akce: `skipped` (bez čísla, data nebo v roce, který se nepřevádí), `existing_item`,
     * `existing_advance`, `existing_invoice` (doklad už převod zná), `invoice` (doklad
     * v přiznání převádí krok faktur), `review` (zůstává jen v deníku, `reason` amounts|entries|posting),
     * `tax_advance` (předpis zálohy na daň), `module` (předpis daňového modulu), `unposted`
     * (potvrzená položka bez zápisu) a `posted` (převezme vlastní zápis `entry_id`).
     *
     * Mzdový závazek měsíce, jehož zaúčtování mezd je načtené z PAMICY (`payroll_import`),
     * převod nezakládá: import ho založil sám i s předpisem
     * ({@see \MyInvoice\Service\Migration\MoneyS3\PayrollLiabilityImporter}, stejné pravidlo).
     * Měsíc určí párovací symbol dokladu (dataPack PAMICY ho do POHODY předává) nebo měsíc
     * jeho zaúčtování.
     *
     * @param array{mapped:array<string,int|string>,advances:array<string,int|string>,journal:array<string,int|string>,rules:array<string,string>,payroll_imports:array{periods:array<string,true>,sym_pars:array<string,true>,dates:array<string,string>}} $sources
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function plan(PohodaContext $ctx, array $sources, string $agenda, string $source, array $record): array
    {
        $header = PohodaXml::get($record, 'invoiceHeader');
        $number = PohodaXml::text($header, 'number/numberRequested');
        $accounting = PohodaXml::date($header, 'dateAccounting');
        $issued = PohodaXml::date($header, 'date') ?? $accounting;
        $rule = PohodaXml::text($header, 'accounting/ids');
        $plan = [
            'action' => 'skipped', 'agenda' => $agenda, 'record' => $record, 'header' => $header, 'number' => $number,
            'issued' => (string) $issued, 'key' => '', 'id' => null, 'candidate' => null, 'account' => null, 'rule' => $rule,
            'rule_account' => $sources['rules'][$rule] ?? null, 'entries' => [], 'entry_id' => null, 'posting' => null,
            'reason' => null, 'message' => '',
        ];
        if ($number === '' || $issued === null || $ctx->skipsDate($accounting ?? $issued)) {
            return $plan;
        }
        $key = $plan['key'] = $agenda . '|' . $number . '|' . $issued;
        if (isset($sources['mapped'][$key])) {
            return ['action' => 'existing_item', 'id' => (int) $sources['mapped'][$key]] + $plan;
        }
        if (isset($sources['advances'][$key])) {
            return ['action' => 'existing_advance', 'id' => (int) $sources['advances'][$key]] + $plan;
        }
        $invoiceKind = $agenda === 'receivable' ? PohodaImportRepository::KIND_INVOICE : PohodaImportRepository::KIND_PURCHASE_INVOICE;
        if ($this->map->get($ctx->supplierId, $invoiceKind, $key) !== null) {
            return ['action' => 'existing_invoice'] + $plan;
        }
        $class = $agenda === 'receivable'
            ? $ctx->vat->sale(PohodaXml::text($header, 'classificationVAT/ids'), 0.0)
            : $ctx->vat->purchase(PohodaXml::text($header, 'classificationVAT/ids'));
        if ($class !== null && $class['in_return']) {
            return ['action' => 'invoice'] + $plan;
        }
        $candidates = [];
        foreach ($sources['journal'] as $journalKey => $entryId) {
            $parts = explode('|', (string) $journalKey, 4);
            if (count($parts) === 4 && $parts[1] === $source && $parts[2] === $number
                && (int) $parts[0] >= $ctx->year() - 1 && (int) $parts[0] <= $ctx->year() && !$ctx->skipsDate($parts[3])) {
                $candidates[] = ['year' => (int) $parts[0], 'date' => $parts[3], 'id' => (int) $entryId];
            }
        }
        if ($candidates === []) {
            // Doklad přečíslovaný po zaúčtování: zápis nese původní číslo ({@see PohodaJournal::renumberedEntries()}).
            [$bySource, $numbers] = $this->renumberingIndex($ctx, $sources['journal'], $agenda, $source);
            foreach (PohodaJournal::renumberedEntries($bySource, $number, array_values(array_filter([$issued, $accounting])), $numbers) as $entry) {
                if ($entry['year'] >= $ctx->year() - 1 && $entry['year'] <= $ctx->year() && !$ctx->skipsDate($entry['date'])) {
                    $candidates[] = $entry;
                }
            }
        }
        // Stejné číslo dokladu v minulé agendě je jiný doklad ({@see DocumentLinker::agendaEntries()}).
        $entries = array_fill_keys(array_column(DocumentLinker::agendaEntries($candidates, $ctx->year(), $ctx->calendar->periodStart($ctx->year())), 'id'), true);
        $plan['entries'] = array_map('intval', array_keys($entries));
        [$candidate, $rejection] = self::assess($record, $agenda, $ctx->vat);
        $plan['candidate'] = $candidate;
        if ($candidate === null) {
            return ['action' => 'review', 'reason' => 'amounts', 'rejection' => $rejection,
                'message' => "Doklad {$number} (" . self::label($agenda) . ") převod do agendy nepřevezme ({$rejection}); zůstává jen v deníku."] + $plan;
        }
        if ($entries === []) {
            // Bez vlastního zápisu: předpis nese jiný doklad (nebo ho nese počáteční stav).
            $account = $plan['account'] = $plan['rule_account'];
            if ($account !== null && self::moduleOwned($account)) {
                return ['action' => $agenda === 'commitment' && self::isTaxAdvance($account, $candidate['title']) ? 'tax_advance' : 'module'] + $plan;
            }
            return ['action' => $this->payrollImported($ctx, $account, $agenda, $header, $accounting ?? $issued) ? 'payroll_import' : 'unposted'] + $plan;
        }
        if (count($entries) !== 1) {
            return ['action' => 'review', 'reason' => 'entries',
                'message' => "Doklad {$number} (" . self::label($agenda) . ') má v deníku víc zápisů; zůstává jen v deníku.'] + $plan;
        }
        $entryId = $plan['entry_id'] = (int) array_key_first($entries);
        $posting = $this->posting($ctx->supplierId, $entryId, $agenda, $candidate['amount']);
        if ($posting === null) {
            return ['action' => 'review', 'reason' => 'posting',
                'message' => "Doklad {$number} (" . self::label($agenda) . ') má zápis v deníku, který neodpovídá jednomu saldokontnímu účtu proti protiúčtům; zůstává jen v deníku.'] + $plan;
        }
        $plan['account'] = $posting['account'];
        if (self::moduleOwned($posting['account'])) {
            return ['action' => 'module'] + $plan;
        }
        if ($this->payrollImported($ctx, $posting['account'], $agenda, $header, $accounting ?? $issued)) {
            return ['action' => 'payroll_import'] + $plan;
        }
        return ['action' => 'posted', 'posting' => $posting] + $plan;
    }

    /**
     * Patří mzdový doklad k zaúčtování mezd načtenému z PAMICY? Rozhoduje párovací symbol
     * měsíce PAMICY (dataPack ho do POHODY předává, {@see PamicaPostingParser::isMonthSymbol()}):
     * doklad s takovým symbolem, který PAMICA nenačetla, k ní nepatří bez ohledu na datum.
     * Doklad bez symbolu nebo s jiným symbolem patří k měsíci předpisu
     * ({@see PohodaPayrollPredpis::month()}), ne k měsíci data výplaty nebo splatnosti.
     */
    private function payrollImported(PohodaContext $ctx, ?string $account, string $agenda, mixed $header, string $date): bool
    {
        if ($account === null || !MigratedOtherItemPolicy::payroll($account)) {
            return false;
        }
        $symPar = PohodaXml::text($header, 'symPar');
        if (PamicaPostingParser::isMonthSymbol($symPar)) {
            return isset($this->payrollImported['sym_pars'][$symPar]);
        }
        return $this->payrollImported['periods'] !== []
            && isset($this->payrollImported['periods'][$this->predpis($ctx)->month(PohodaPayrollPredpis::key($account, $agenda), $date)]);
    }

    /**
     * Založí položku podle {@see plan()} (`unposted` nebo `posted`), zapíše mapu převodu,
     * zachytí úhrady z exportu a započte ji do protokolu. Převzatý zápis dostane vlastníka
     * položku, nic se neúčtuje. Bez `id` vrací důvod: `number_taken` (číslo dokladu má už
     * jiná položka), `predpis_exceeded` (mzdový závazek bez zápisu, jehož předpis převedený
     * deník nenese, {@see coveredByPredpis()}).
     *
     * @param array<string,mixed> $plan
     * @return array{id:?int,source_document:?string,reason:?string}
     */
    private function create(PohodaContext $ctx, array $plan): array
    {
        $p = $ctx->protocol;
        $number = $plan['number'];
        $candidate = $plan['candidate'];
        $sourceDocument = null;
        if ($plan['account'] !== null && $this->manualDuplicate($ctx, $plan)) {
            $p->count(self::STEP, 'requires_review');
            $p->warn(self::STEP, 'payroll_manual_duplicate', "Mzdový závazek {$number} ({$plan['account']}, " . number_format($candidate['amount'], 2, ',', ' ')
                . ' Kč) má v agendě protějšek se stejnou částkou a měsícem, který převod nezaložil (zadaný v MyÚčtu nebo načtený z PAMICY). '
                . 'Převod ho nezaložil, ověřte ho.', ['document_no' => $number]);
            return ['id' => null, 'source_document' => null, 'reason' => 'possible_manual_duplicate'];
        }
        if ($plan['action'] === 'unposted') {
            $account = $plan['account'];
            if (!$this->coveredByPredpis($ctx, $plan)) {
                $p->count(self::STEP, 'requires_review');
                $p->warn(self::STEP, 'payroll_predpis_exceeded', "Mzdový závazek {$number} ({$account}, " . number_format($candidate['amount'], 2, ',', ' ')
                    . ' Kč) je bez vlastního zápisu, ale předpis mezd v interních dokladech jeho měsíce už pokrývají jiné závazky. '
                    . 'Nejspíš je v POHODĚ dvakrát; zůstává jen v POHODĚ, ověřte ho.', ['document_no' => $number]);
                return ['id' => null, 'source_document' => null, 'reason' => 'predpis_exceeded'];
            }
            $rule = $plan['rule'];
            if ($ctx->taxEvidence) {
                $note = "Převzato z POHODY, doklad {$number}. Agenda je vedená v daňové evidenci, položka se neúčtuje.";
            } else {
                $note = "Převzato z POHODY, doklad {$number}. V POHODĚ veden bez zaúčtování"
                    . ($rule !== '' ? " (předkontace {$rule})" : '');
                $sourceDocument = $account !== null && MigratedOtherItemPolicy::payroll($account)
                    ? $this->payrollSourceDocument($ctx, $plan['agenda'], $account, $plan['issued'])
                    : null;
                $note .= $sourceDocument !== null
                    ? ", předpis nese interní doklad mezd {$sourceDocument} - položka se proto neúčtuje."
                    : ', předpis je v deníku jiným dokladem nebo v počátečním stavu - položka se proto neúčtuje.';
            }
            $id = $this->insertItem($ctx, $plan['agenda'], $plan['header'], $number, $plan['issued'], $candidate, [
                'account' => $account, 'counter' => null, 'lines' => [], 'date' => $plan['issued'],
                'posted_at' => null, 'entry_id' => null, 'status' => 'confirmed', 'note' => $note,
            ]);
            if ($id === null) {
                return ['id' => null, 'source_document' => null, 'reason' => 'number_taken'];
            }
            $this->consumePredpis($ctx, $plan);
            $p->count(self::STEP, 'created_unposted');
        } else {
            $posting = $plan['posting'];
            $entryId = (int) $plan['entry_id'];
            $id = $this->insertItem($ctx, $plan['agenda'], $plan['header'], $number, $plan['issued'], $candidate, [
                'account' => $posting['account'],
                'counter' => count($posting['lines']) === 1 ? $posting['lines'][0]['account_code'] : null,
                'lines' => $posting['lines'], 'date' => $posting['date'], 'posted_at' => $posting['posted_at'],
                'entry_id' => $entryId, 'status' => 'posted', 'note' => 'Převzato z POHODY, doklad ' . $number,
            ]);
            if ($id === null) {
                return ['id' => null, 'source_document' => null, 'reason' => 'number_taken'];
            }
            $owner = $this->db->pdo()->prepare(
                "UPDATE journal_entries SET source_type = 'other_item', source_id = ?
                   WHERE id = ? AND supplier_id = ? AND source_type = 'manual' AND source_id IS NULL"
            );
            $owner->execute([$id, $entryId, $ctx->supplierId]);
            if ($owner->rowCount() !== 1) {
                throw new PohodaException('other_item_journal_claim', 'Převzatý zápis deníku už vlastní jiný doklad.');
            }
            $p->count(self::STEP, 'created');
        }
        $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_OTHER_ITEM, $plan['key'], $id, $ctx->runId);
        self::captureLiquidations($ctx, 'other_item', $id, $number, $plan['record']);
        self::rememberSourcePaid($ctx, $id, $candidate, $plan['record']);
        $p->count(self::STEP, $candidate['remaining'] >= 0.005 ? 'open' : 'settled');
        self::countPayroll($ctx, $plan['account']);
        return ['id' => $id, 'source_document' => $sourceDocument, 'reason' => null];
    }

    /**
     * Pokrývá předpis převedeného deníku mzdový závazek bez vlastního zápisu? Takový závazek
     * nese předpis interního dokladu mezd jeho měsíce ({@see PohodaPayrollPredpis}). Když ho
     * už pokrývají jiné závazky, druhý by v agendě visel bez předpisu v deníku a nikdy by se
     * nespároval: typicky tentýž závazek v POHODĚ dvakrát. Hlídá se jen závazek (Dal účtu);
     * závazek z počátečního stavu a daňová evidence (deník nemá) se nehlídají.
     *
     * @param array<string,mixed> $plan
     */
    private function coveredByPredpis(PohodaContext $ctx, array $plan): bool
    {
        return !$this->predpisGuarded($ctx, $plan)
            || $this->predpis($ctx)->covers(PohodaPayrollPredpis::key((string) $plan['account'], $plan['agenda']), $plan['issued'], self::cents($plan['candidate']['amount']));
    }

    /** @param array<string,mixed> $plan */
    private function consumePredpis(PohodaContext $ctx, array $plan): void
    {
        if ($this->predpisGuarded($ctx, $plan)) {
            $this->predpis($ctx)->consume(PohodaPayrollPredpis::key((string) $plan['account'], $plan['agenda']), $plan['issued'], self::cents($plan['candidate']['amount']));
        }
    }

    /** @param array<string,mixed> $plan */
    private function predpisGuarded(PohodaContext $ctx, array $plan): bool
    {
        return !$ctx->taxEvidence && $plan['agenda'] === 'commitment' && MigratedOtherItemPolicy::payroll((string) ($plan['account'] ?? ''))
            && $plan['issued'] >= $ctx->periodStart();
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Předpis mezd převzatého deníku: Dal (u pohledávky Má dáti) mzdových účtů interních
     * dokladů po měsících, zmenšený o všechny mzdové položky bez vlastního zápisu, ať je založil
     * převod, uživatel nebo import mezd. Den předpisu měsíce bere i ze zaúčtování mezd
     * načteného z PAMICY.
     */
    private function predpis(PohodaContext $ctx): PohodaPayrollPredpis
    {
        if ($this->predpis !== null) {
            return $this->predpis;
        }
        $byEntry = [];
        $startMonth = null;
        foreach ($this->map->all($ctx->supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY) as $key => $entryId) {
            $parts = explode('|', (string) $key, 4);
            if (count($parts) !== 4) {
                continue;
            }
            $month = substr($parts[3], 0, 7);
            if ($startMonth === null || $month < $startMonth) {
                $startMonth = $month;
            }
            if ($parts[1] === PohodaJournal::INTERNAL) {
                $byEntry[(int) $entryId] = substr($parts[3], 0, 10);
            }
        }
        $entries = [];
        $pdo = $this->db->pdo();
        foreach (array_chunk(array_keys($byEntry), 1000) as $chunk) {
            $stmt = $pdo->prepare(
                "SELECT l.entry_id, l.side, SUBSTRING(REGEXP_REPLACE(a.account_code, '[^0-9]', ''), 1, 3) AS synthetic, SUM(l.signed_amount) AS amount
                   FROM journal_entry_lines l
                   JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
                  WHERE l.supplier_id = ? AND l.entry_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                    AND (a.account_code LIKE '331%' OR a.account_code LIKE '336%' OR a.account_code LIKE '342%')
                  GROUP BY l.entry_id, l.side, synthetic"
            );
            $stmt->execute([$ctx->supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $entries[] = [
                    'key' => PohodaPayrollPredpis::key((string) $row['synthetic'], $row['side'] === 'credit' ? 'commitment' : 'receivable'),
                    'date' => $byEntry[(int) $row['entry_id']], 'cents' => self::cents((float) $row['amount']),
                ];
            }
        }
        $predpis = PohodaPayrollPredpis::fromEntries($entries, $this->payrollImported['dates'], $startMonth);
        if ($entries !== []) {
            $stmt = $pdo->prepare(
                "SELECT side, account_code, issued_on, amount FROM other_items
                  WHERE supplier_id = ? AND journal_entry_id IS NULL AND deleted_at IS NULL AND status IN ('confirmed', 'posted')
                    AND issued_on >= ? AND (account_code LIKE '331%' OR account_code LIKE '336%' OR account_code LIKE '342%')"
            );
            $stmt->execute([$ctx->supplierId, $ctx->periodStart()]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $predpis->consume(PohodaPayrollPredpis::key((string) $row['account_code'], $row['side'] === 'payable' ? 'commitment' : 'receivable'),
                    (string) $row['issued_on'], self::cents((float) $row['amount']));
            }
        }
        return $this->predpis = $predpis;
    }

    /**
     * Má mzdový doklad v agendě protějšek, který převod nezaložil (položka zadaná v MyÚčtu po
     * převodu, načtená z PAMICY): stejná strana, syntetický účet, částka a měsíc předpisu
     * a stejný partner. Partnera (název, variabilní symbol nebo účet protistrany) porovná,
     * když ho protějšek má; protějšek bez partnera odpovídá podle částky. Dva zaměstnanci se
     * stejnou mzdou se tak nezamění. Každý protějšek odpovídá jen jednomu dokladu, nejdřív
     * ten se shodným partnerem.
     *
     * @param array<string,mixed> $plan
     */
    private function manualDuplicate(PohodaContext $ctx, array $plan): bool
    {
        if (!MigratedOtherItemPolicy::payroll((string) $plan['account'])) {
            return false;
        }
        if ($this->manualItems === null) {
            $this->manualItems = [];
            $stmt = $this->db->pdo()->prepare(
                "SELECT oi.side, oi.account_code, oi.issued_on, oi.amount, oi.partner_name, oi.variable_symbol, oi.counterparty_account
                   FROM other_items oi
                  WHERE oi.supplier_id = ? AND oi.deleted_at IS NULL AND oi.status IN ('confirmed', 'posted')
                    AND (oi.account_code LIKE '331%' OR oi.account_code LIKE '336%' OR oi.account_code LIKE '342%')
                    AND NOT EXISTS (SELECT 1 FROM pohoda_import_map m
                                     WHERE m.supplier_id = oi.supplier_id AND m.kind = 'other_item' AND m.target_id = oi.id)
                  ORDER BY oi.id"
            );
            $stmt->execute([$ctx->supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = $this->manualKey($ctx, $row['side'] === 'payable' ? 'commitment' : 'receivable', (string) $row['account_code'], (float) $row['amount'], (string) $row['issued_on']);
                $this->manualItems[$key][] = self::party((string) ($row['partner_name'] ?? ''), $row['variable_symbol'], $row['counterparty_account']);
            }
        }
        $key = $this->manualKey($ctx, $plan['agenda'], (string) $plan['account'], (float) $plan['candidate']['amount'], $plan['issued']);
        $own = self::party(PartnerImporter::snapshot(PohodaXml::get($plan['header'], 'partnerIdentity'))['name'],
            $plan['candidate']['symbol'], self::counterpartyAccount($plan['header'])['counterparty_account']);
        $match = null;
        foreach ($this->manualItems[$key] ?? [] as $i => $manual) {
            if (self::sameParty($manual, $own)) {
                $match = $i;
                break;
            }
        }
        if ($match === null) {
            foreach ($this->manualItems[$key] ?? [] as $i => $manual) {
                if (self::partyEmpty($manual) || self::partyEmpty($own)) {
                    $match = $i;
                    break;
                }
            }
        }
        if ($match === null) {
            return false;
        }
        unset($this->manualItems[$key][$match]);
        return true;
    }

    /** @return array{name:string,vs:string,account:string} */
    private static function party(string $name, mixed $symbol, mixed $account): array
    {
        return ['name' => mb_strtolower(trim($name)), 'vs' => trim((string) $symbol), 'account' => trim((string) $account)];
    }

    /** @param array{name:string,vs:string,account:string} $party */
    private static function partyEmpty(array $party): bool
    {
        return $party['name'] === '' && $party['vs'] === '' && $party['account'] === '';
    }

    /**
     * @param array{name:string,vs:string,account:string} $a
     * @param array{name:string,vs:string,account:string} $b
     */
    private static function sameParty(array $a, array $b): bool
    {
        foreach (['vs', 'account'] as $field) {
            if ($a[$field] !== '' && $b[$field] !== '' && $a[$field] !== $b[$field]) {
                return false;
            }
        }
        foreach (['name', 'vs', 'account'] as $field) {
            if ($a[$field] !== '' && $a[$field] === $b[$field]) {
                return true;
            }
        }
        return false;
    }

    private function manualKey(PohodaContext $ctx, string $agenda, string $account, float $amount, string $date): string
    {
        $key = PohodaPayrollPredpis::key($account, $agenda);
        return $key . '|' . self::cents($amount) . '|' . $this->predpis($ctx)->month($key, $date);
    }

    /**
     * Interní doklad mezd, který nese předpis mzdového závazku bez vlastního zápisu: zápis
     * agendy Interní doklady na saldokontním účtu položky (u závazku na straně Dal) za měsíc
     * dokladu nebo měsíc před ním (mzdy se zúčtují k poslednímu dni měsíce, výplata je
     * v dalším). Jen když je takový doklad jeden, jinak `null` (poznámka pak obecná).
     */
    private function payrollSourceDocument(PohodaContext $ctx, string $agenda, string $account, string $issued): ?string
    {
        if ($this->internalPayroll === null) {
            $this->internalPayroll = [];
            $byEntry = [];
            foreach ($this->map->all($ctx->supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY) as $key => $entryId) {
                $parts = explode('|', (string) $key, 4);
                if (count($parts) === 4 && $parts[1] === PohodaJournal::INTERNAL) {
                    $byEntry[(int) $entryId] = ['number' => $parts[2], 'date' => $parts[3]];
                }
            }
            foreach (array_chunk(array_keys($byEntry), 1000) as $chunk) {
                $stmt = $this->db->pdo()->prepare(
                    "SELECT DISTINCT l.entry_id, l.side, a.account_code
                       FROM journal_entry_lines l
                       JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
                      WHERE l.supplier_id = ? AND l.entry_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                        AND (a.account_code LIKE '331%' OR a.account_code LIKE '336%' OR a.account_code LIKE '342%')"
                );
                $stmt->execute([$ctx->supplierId, ...$chunk]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $this->internalPayroll[] = $byEntry[(int) $row['entry_id']] + ['side' => (string) $row['side'], 'account' => (string) $row['account_code']];
                }
            }
        }
        $from = (new \DateTimeImmutable(substr($issued, 0, 7) . '-01'))->modify('-1 month')->format('Y-m-d');
        $side = $agenda === 'receivable' ? 'debit' : 'credit';
        $found = [];
        foreach ($this->internalPayroll as $row) {
            if ($row['side'] === $side && $row['date'] >= $from && $row['date'] <= $issued
                && MigratedOtherItemPolicy::sameSynthetic($row['account'], $account)) {
                $found[$row['number']] = true;
            }
        }
        return count($found) === 1 ? (string) array_key_first($found) : null;
    }

    /**
     * Mzdové ostatní položky mezi položkami z mapy převodu (i smazané).
     *
     * @param list<int> $ids
     * @return array<int,array{account:string,amount:float,entry_id:?int,deleted:bool}>
     */
    private function payrollItems(int $supplierId, array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT id, account_code, amount, journal_entry_id, deleted_at FROM other_items
                  WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['account_code'] !== null && MigratedOtherItemPolicy::payroll((string) $row['account_code'])) {
                    $out[(int) $row['id']] = [
                        'account' => (string) $row['account_code'], 'amount' => (float) $row['amount'],
                        'entry_id' => $row['journal_entry_id'] !== null ? (int) $row['journal_entry_id'] : null,
                        'deleted' => $row['deleted_at'] !== null,
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * Zápisy deníku s řádkem na mzdovém účtu (331, 336, 342).
     *
     * @param list<int> $entryIds
     * @return array<int,true>
     */
    private function payrollEntries(int $supplierId, array $entryIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($entryIds)), 1000) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT DISTINCT l.entry_id FROM journal_entry_lines l
                   JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
                  WHERE l.supplier_id = ? AND l.entry_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                    AND (a.account_code LIKE '331%' OR a.account_code LIKE '336%' OR a.account_code LIKE '342%')"
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $out[(int) $id] = true;
            }
        }
        return $out;
    }

    /**
     * Proč zápis nejde převzít ({@see posting()}): `entry_missing` (zápis v deníku už není),
     * `entry_reversed` (stornovaný), `entry_owned` (vlastní ho jiný doklad), `entry_linked`
     * (navázaný na doklad), `entry_taken` (převzala ho jiná ostatní položka), `entry_origin`
     * (na jeho řádku je položka ze zůstatku bez dokladu, zadaná v MyÚčtu). Zápis bez
     * takové překážky chybí ve výsledku (neodpovídá jednomu saldokontnímu účtu).
     *
     * @param list<int> $entryIds
     * @return array<int,string>
     */
    private function entryProblems(int $supplierId, array $entryIds): array
    {
        $out = array_fill_keys($entryIds, 'entry_missing');
        foreach (array_chunk(array_values(array_unique($entryIds)), 1000) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT e.id, e.reversed_by, e.source_type, e.source_id,
                        EXISTS (SELECT 1 FROM journal_entry_document_links l WHERE l.supplier_id = e.supplier_id AND l.entry_id = e.id) AS linked,
                        EXISTS (SELECT 1 FROM other_items oi WHERE oi.supplier_id = e.supplier_id AND oi.journal_entry_id = e.id) AS taken,
                        EXISTS (SELECT 1 FROM journal_settlement_allocations o WHERE o.supplier_id = e.supplier_id AND o.entry_id = e.id
                                   AND o.kind = 'origin' AND o.released_on IS NULL) AS origin
                   FROM journal_entries e
                  WHERE e.supplier_id = ? AND e.id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int) $row['id'];
                $out[$id] = match (true) {
                    $row['reversed_by'] !== null => 'entry_reversed',
                    (int) $row['taken'] === 1 => 'entry_taken',
                    (int) $row['origin'] === 1 => 'entry_origin',
                    $row['source_type'] !== 'manual' || $row['source_id'] !== null => 'entry_owned',
                    (int) $row['linked'] === 1 => 'entry_linked',
                    default => 'posting',
                };
            }
        }
        return $out;
    }

    /** @var array<string,array{0:array<string,list<array{year:int,date:string,id:int}>>,1:array<string,true>}> */
    private array $renumbering = [];

    /**
     * Zápisy zdroje deníku podle čísla a čísla dokladů agendy v exportu, pro párování
     * přečíslovaných dokladů ({@see PohodaJournal::renumberedEntries()}). Platí pro jeden běh
     * převodu (klíč obsahuje kontext).
     *
     * @param array<string,int|string> $journal mapa převodu zápisů deníku
     * @return array{0:array<string,list<array{year:int,date:string,id:int}>>,1:array<string,true>}
     */
    private function renumberingIndex(PohodaContext $ctx, array $journal, string $agenda, string $source): array
    {
        $cacheKey = spl_object_id($ctx) . '|' . $ctx->supplierId . '|' . $agenda . '|' . $source;
        if (isset($this->renumbering[$cacheKey])) {
            return $this->renumbering[$cacheKey];
        }
        $bySource = [];
        foreach ($journal as $journalKey => $entryId) {
            $parts = explode('|', (string) $journalKey, 4);
            if (count($parts) === 4 && $parts[1] === $source) {
                $bySource[$parts[2]][] = ['year' => (int) $parts[0], 'date' => $parts[3], 'id' => (int) $entryId];
            }
        }
        $numbers = [];
        foreach ($bySource === [] ? [] : $ctx->export->records($agenda, 'invoice') as $record) {
            $numbers[PohodaXml::text(PohodaXml::get($record, 'invoiceHeader'), 'number/numberRequested')] = true;
        }
        return $this->renumbering[$cacheKey] = [$bySource, $numbers];
    }

    /**
     * Zálohy na daň z příjmů, které POHODA vede jako ostatní závazky bez zaúčtování, jako
     * předpisy záloh daňového modulu (`tax_advance_schedules`). Rok předpisu je kalendářní
     * rok splatnosti a pořadí je pořadí splatností v něm, stejně jako u předpisů z přiznání
     * ({@see \MyInvoice\Service\Tax\Return\TaxAdvanceScheduleService}). Rok, pro který firma
     * v MyÚčtu předpisy už má, se nepřepisuje. Úhradu převezme stav POHODY, vazbu na
     * bankovní pohyb doplní krok úhrad.
     *
     * @param list<array{key:string,number:string,record:array,candidate:array}> $advances
     */
    private function taxAdvances(PohodaContext $ctx, array $advances): void
    {
        if ($advances === []) {
            return;
        }
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $type = $pdo->prepare('SELECT taxpayer_type FROM supplier WHERE id = ?');
        $type->execute([$ctx->supplierId]);
        $taxpayer = (string) $type->fetchColumn() === 'fo' ? 'fo' : 'po';
        $byYear = [];
        foreach ($advances as $advance) {
            $due = $advance['candidate']['due'];
            if (!$ctx->skipsDate($due)) {
                $byYear[(int) substr($due, 0, 4)][] = $advance;
            }
        }
        ksort($byYear);
        $existing = $pdo->prepare("SELECT COUNT(*) FROM tax_advance_schedules
            WHERE supplier_id = ? AND taxpayer_type = ? AND advance_kind = 'tax' AND period_year = ?");
        $insert = $pdo->prepare("INSERT INTO tax_advance_schedules
            (supplier_id, taxpayer_type, advance_kind, period_year, seq_no, amount, due_date, variable_symbol,
             status, paid_amount, paid_on, paid_source)
            VALUES (?, ?, 'tax', ?, ?, ?, ?, ?, ?, ?, ?, 'manual')");
        foreach ($byYear as $year => $rows) {
            $existing->execute([$ctx->supplierId, $taxpayer, $year]);
            if ((int) $existing->fetchColumn() > 0) {
                $p->count(self::STEP, 'tax_advances_kept', count($rows));
                $p->info(self::STEP, 'tax_advances_kept', "Předpisy záloh na daň z příjmů za rok {$year} už v MyÚčtu jsou, zálohy z POHODY ("
                    . implode(', ', array_column($rows, 'number')) . ') se nepřevzaly.');
                continue;
            }
            usort($rows, static fn (array $a, array $b): int => [$a['candidate']['due'], $a['number']] <=> [$b['candidate']['due'], $b['number']]);
            foreach ($rows as $seq => $row) {
                $c = $row['candidate'];
                $paid = round($c['amount'] - $c['remaining'], 2);
                $paidOn = null;
                foreach (PohodaXml::all($row['record'], 'liquidations/liquidation') as $l) {
                    $date = PohodaXml::date($l, 'date');
                    if ($ctx->skipsDate($date)) {
                        $paid -= abs(PohodaXml::num($l, 'amount'));
                        continue;
                    }
                    if ($date !== null && ($paidOn === null || $date > $paidOn)) {
                        $paidOn = $date;
                    }
                }
                $paid = max(0.0, round($paid, 2));
                $settled = $c['amount'] - $paid < 0.005;
                $insert->execute([
                    $ctx->supplierId, $taxpayer, $year, $seq + 1, number_format($c['amount'], 2, '.', ''), $c['due'],
                    $c['symbol'] !== null && strlen($c['symbol']) <= 20 ? $c['symbol'] : null,
                    $settled ? 'paid' : 'planned', number_format($paid, 2, '.', ''),
                    $paid > 0 ? ($paidOn ?? $c['due']) : null,
                ]);
                $id = (int) $pdo->lastInsertId();
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_TAX_ADVANCE, $row['key'], $id, $ctx->runId);
                self::captureLiquidations($ctx, 'tax_advance', $id, $row['number'], $row['record']);
                $p->count(self::STEP, 'tax_advances');
            }
        }
    }

    /**
     * Účet předkontace POHODY podle kódu: strana, která je vyplněná (předkontace „bez"
     * nese jen účet, na který míří úhrada). Kód účtu MyÚčta podle převedené osnovy.
     *
     * @return array<string,string>
     */
    private function postingRuleAccounts(PohodaContext $ctx): array
    {
        $codes = [];
        $stmt = $this->db->pdo()->prepare("SELECT account_code FROM chart_of_accounts WHERE supplier_id = ? AND REPLACE(account_code, '.', '') = ? ORDER BY account_code LIMIT 1");
        foreach ($ctx->export->records('posting_rules', 'itemAccounting') as $r) {
            $key = trim((string) ($r['@code'] ?? ''));
            $debit = trim((string) ($r['@debit'] ?? ''));
            $credit = trim((string) ($r['@credit'] ?? ''));
            if ($key === '' || isset($codes[$key]) || ($debit === '') === ($credit === '')) {
                continue;
            }
            $raw = $debit !== '' ? $debit : $credit;
            $stmt->execute([$ctx->supplierId, str_replace('.', '', $raw)]);
            $code = $stmt->fetchColumn();
            if ($code !== false) {
                $codes[$key] = (string) $code;
            }
        }
        return $codes;
    }

    /**
     * Založí ostatní položku. `status` posted = vlastní převzatý zápis, confirmed = bez
     * vlastního zápisu. Číslo dokladu obsazené jinou položkou se jen ohlásí.
     *
     * @param array{amount:float,remaining:float,due:string,title:string,symbol:?string} $candidate
     * @param array{account:?string,counter:?string,lines:list<array{account_code:string,amount:float}>,date:string,posted_at:?string,entry_id:?int,status:string,note:string} $posting
     */
    private function insertItem(PohodaContext $ctx, string $agenda, mixed $header, string $number, string $issued, array $candidate, array $posting): ?int
    {
        $snapshot = PartnerImporter::snapshot(PohodaXml::get($header, 'partnerIdentity'));
        $partnerId = $snapshot['name'] !== '' || $snapshot['ico'] !== ''
            ? $this->partners->resolvePartner($ctx, $snapshot)
            : null;
        $bank = self::counterpartyAccount($header);
        $pdo = $this->db->pdo();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO other_items
                  (supplier_id, side, kind, title, partner_id, partner_name, issued_on, accounting_on, due_on,
                   currency, amount, amount_czk, variable_symbol, counterparty_account, counterparty_bank_code,
                   account_code, counter_account_code,
                   note, status, document_no, journal_entry_id, posted_at, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $ctx->supplierId, $agenda === 'receivable' ? 'receivable' : 'payable', 'other',
                mb_substr($candidate['title'], 0, 255), $partnerId,
                mb_substr($snapshot['name'], 0, 190) ?: null,
                $issued, $posting['date'], $candidate['due'], 'CZK', $candidate['amount'],
                $candidate['amount'], $candidate['symbol'], $bank['counterparty_account'], $bank['counterparty_bank_code'],
                $posting['account'], $posting['counter'],
                $posting['note'], $posting['status'], mb_substr($number, 0, 50),
                $posting['entry_id'], $posting['posted_at'], $ctx->userOrNull(), $ctx->userOrNull(),
            ]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') throw $e;
            $ctx->protocol->warn(self::STEP, 'number_taken', "Doklad {$number} nelze převzít, číslo už má jiná pohledávka nebo závazek.", ['document_no' => $number]);
            return null;
        }
        $id = (int) $pdo->lastInsertId();
        if ($posting['counter'] === null && $posting['lines'] !== []) {
            $line = $pdo->prepare('INSERT INTO other_item_posting_lines
                (supplier_id, other_item_id, position, account_code, amount) VALUES (?, ?, ?, ?, ?)');
            foreach ($posting['lines'] as $position => $row) {
                $line->execute([$ctx->supplierId, $id, $position + 1, $row['account_code'], number_format($row['amount'], 2, '.', '')]);
            }
        }
        return $id;
    }

    /**
     * Účet protistrany z dokladu (`paymentAccount`, u MDB exportu `FA.Ucet`/`KodBanky`) ve tvaru
     * ostatní položky ({@see OtherItemCounterpartyAccount::normalize()}). Páruje s ním úhrady
     * z banky stejně jako variabilní symbol. Účet, který neprojde kontrolou, se nepřevezme.
     *
     * @return array{counterparty_account:?string,counterparty_bank_code:?string}
     */
    public static function counterpartyAccount(mixed $header): array
    {
        try {
            return OtherItemCounterpartyAccount::normalize(
                PohodaXml::text($header, 'paymentAccount/accountNo'),
                PohodaXml::text($header, 'paymentAccount/bankCode'),
            );
        } catch (OtherItemException) {
            return ['counterparty_account' => null, 'counterparty_bank_code' => null];
        }
    }

    /**
     * Doklad mimo přiznání DPH v korunách bez odpočtu zálohy, se součtem dokladu shodným
     * s položkami. `remaining` je zbývající částka podle POHODY (úhrady páruje až krok úhrad).
     *
     * @return array{amount:float,remaining:float,due:string,title:string,symbol:?string}|null
     */
    public static function candidate(array $record, string $agenda, PohodaVat $vat): ?array
    {
        return self::assess($record, $agenda, $vat)[0];
    }

    /**
     * Totéž co {@see self::candidate()}, u odmítnutého dokladu s důvodem pro protokol:
     * bez něj účetní nepozná, co v POHODĚ opravit, a stovky dokladů zůstanou jen v deníku.
     *
     * @return array{0:array{amount:float,remaining:float,due:string,title:string,symbol:?string}|null, 1:?string}
     */
    public static function assess(array $record, string $agenda, PohodaVat $vat): array
    {
        $no = static fn (string $reason): array => [null, $reason];
        if (!in_array($agenda, ['receivable', 'commitment'], true)) return $no('jiná agenda');
        $header = PohodaXml::get($record, 'invoiceHeader');
        $summary = PohodaXml::get($record, 'invoiceSummary/homeCurrency');
        $code = PohodaXml::text($header, 'classificationVAT/ids');
        if (PohodaXml::text($header, 'MOSS/ids') !== '') return $no('doklad v režimu OSS');
        if ($code !== '') {
            $class = $agenda === 'receivable' ? $vat->sale($code, 0.0) : $vat->purchase($code);
            if ($class === null) return $no("členění DPH „{$code}“ převod nezařazuje");
            if ($class['in_return']) return $no("členění DPH „{$code}“ je v přiznání");
        }
        // Bez členění rozhoduje nulová daň, kterou níž hlídají částky souhrnu i položek
        // ({@see self::outsideReturnWithoutVatClass()}).
        if (PohodaXml::text($record, 'invoiceSummary/foreignCurrency/currency/ids') !== '') return $no('doklad v cizí měně');
        if (PohodaXml::all($record, 'invoiceDetail/invoiceAdvancePaymentItem') !== []) return $no('doklad odečítá zálohu');
        foreach (['priceLow', 'priceLowVAT', 'priceHigh', 'priceHighVAT', 'price3', 'price3VAT', 'round/priceRound'] as $field) {
            if (abs(PohodaXml::num($summary, $field)) >= 0.005) return $no("souhrn má částku v poli {$field}, převod bere jen částky bez DPH (priceNone)");
        }
        $amount = round(PohodaXml::num($summary, 'priceNone'), 2);
        $summaryTotal = PohodaXml::text($summary, 'priceSum');
        if ($summaryTotal !== '' && (!is_numeric($summaryTotal) || abs((float) $summaryTotal - $amount) >= 0.005)) return $no('celková částka nesedí na částku bez DPH');
        $detailAmount = 0.0;
        $details = PohodaXml::all($record, 'invoiceDetail/invoiceItem');
        foreach ($details as $detail) {
            if (abs(PohodaXml::num($detail, 'homeCurrency/priceVAT')) >= 0.005) return $no('položka nese DPH');
            $detailAmount += PohodaXml::num($detail, 'homeCurrency/price');
        }
        if ($details !== [] && abs($detailAmount - $amount) >= 0.005) return $no('součet položek nesedí na souhrn');
        $remaining = PohodaXml::text($header, 'liquidation/amountHome');
        $remaining = $remaining === '' ? 0.0 : (is_numeric($remaining) ? round((float) $remaining, 2) : null);
        if ($amount <= 0) return $no('nulová nebo záporná částka');
        if ($remaining === null || $remaining < -0.005 || $remaining - $amount >= 0.005) return $no('zbývá uhradit víc než částka dokladu, nebo záporně');
        $due = PohodaXml::date($header, 'dateDue') ?? PohodaXml::date($header, 'date');
        if ($due === null) return $no('chybí datum splatnosti i vystavení');
        $title = PohodaXml::text($header, 'text');
        $symbol = PohodaXml::text($header, 'symVar');
        return [[
            'amount' => $amount,
            'remaining' => max(0.0, $remaining),
            'due' => $due,
            'title' => $title !== '' ? $title : PohodaXml::text($header, 'number/numberRequested'),
            'symbol' => preg_match('/^[0-9]{1,20}$/', $symbol) === 1 ? $symbol : null,
        ], null];
    }

    private function posting(int $supplierId, int $entryId, string $agenda, float $amount): ?array
    {
        $entry = $this->db->pdo()->prepare(
            "SELECT entry_date, posted_at FROM journal_entries
              WHERE id = ? AND supplier_id = ? AND source_type = 'manual' AND source_id IS NULL
                AND reversed_by IS NULL
                AND NOT EXISTS (SELECT 1 FROM journal_entry_document_links l
                                 WHERE l.supplier_id = journal_entries.supplier_id AND l.entry_id = journal_entries.id)
                AND NOT EXISTS (SELECT 1 FROM other_items oi
                                 WHERE oi.supplier_id = journal_entries.supplier_id AND oi.journal_entry_id = journal_entries.id)
                AND NOT EXISTS (SELECT 1 FROM journal_settlement_allocations o
                                 WHERE o.supplier_id = journal_entries.supplier_id AND o.entry_id = journal_entries.id
                                   AND o.kind = 'origin' AND o.released_on IS NULL)"
        );
        $entry->execute([$entryId, $supplierId]);
        $head = $entry->fetch(PDO::FETCH_ASSOC);
        if ($head === false) return null;
        $lines = $this->db->pdo()->prepare(
            'SELECT l.side, l.amount, a.account_code, a.account_type
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.entry_id = ? AND l.supplier_id = ? ORDER BY l.line_no, l.id'
        );
        $lines->execute([$entryId, $supplierId]);
        $rows = $lines->fetchAll(PDO::FETCH_ASSOC);
        $accounts = self::postingLines($rows, $agenda, $amount);
        if ($accounts === null) return null;
        return $accounts + ['date' => (string) $head['entry_date'], 'posted_at' => (string) $head['posted_at']];
    }

    /**
     * @param list<array{side:string,amount:string|float,account_code:string,account_type?:string}> $rows
     * @return array{account:string,lines:list<array{account_code:string,amount:float}>}|null
     */
    public static function postingLines(array $rows, string $agenda, float $amount): ?array
    {
        if (!in_array($agenda, ['receivable', 'commitment'], true)) return null;
        return MigratedOtherItemPolicy::postingLines($rows, $agenda === 'receivable' ? 'receivable' : 'payable', $amount);
    }

    /**
     * Záloha na daň z příjmů: závazek na 341 s textem zálohy. Na stejném účtu (a se stejnou
     * předkontací) vede POHODA i doplatek daně za uplynulý rok - ten patří k přiznání,
     * ne mezi předpisy záloh, a rozezná se jen podle textu.
     */
    public static function isTaxAdvance(string $account, string $title): bool
    {
        if (!str_starts_with($account, self::TAX_ADVANCE_ACCOUNT)) {
            return false;
        }
        $text = mb_strtolower($title);
        return str_contains($text, 'záloh') || str_contains($text, 'zaloh');
    }

    public static function moduleOwned(string $account): bool
    {
        return MigratedOtherItemPolicy::moduleOwned($account);
    }

    /**
     * Ostatní pohledávka nebo závazek bez členění DPH a s nulovou daní je doklad mimo
     * přiznání, ne faktura. MDB export (účetnictví bez XML datového balíku) členění
     * u dokladů mimo DPH, typicky mezd a odvodů, nevyplňuje. Přijaté a vydané faktury
     * (jiné agendy) bez členění zůstávají v přiznání ({@see InvoiceImporter}).
     */
    public static function outsideReturnWithoutVatClass(string $agenda, string $code, float $vat): bool
    {
        return in_array($agenda, ['receivable', 'commitment'], true) && $code === '' && abs($vat) < 0.005;
    }

    private static function countPayroll(PohodaContext $ctx, ?string $account): void
    {
        if ($account !== null && MigratedOtherItemPolicy::payroll($account)) {
            $ctx->protocol->count(self::STEP, 'payroll');
        }
    }

    /**
     * Uhrazená část podle POHODY. Úhrada v roce, který se nepřevádí, se nepočítá: její pohyb
     * v převodu není a doplní ji až opakovaný převod s tímto rokem.
     *
     * @param array{amount:float,remaining:float}|null $candidate
     */
    private static function rememberSourcePaid(PohodaContext $ctx, int $id, ?array $candidate, array $record): void
    {
        if ($candidate === null) {
            return;
        }
        $skipped = 0.0;
        foreach (PohodaXml::all($record, 'liquidations/liquidation') as $l) {
            if ($ctx->skipsDate(PohodaXml::date($l, 'date'))) {
                $skipped += abs(PohodaXml::num($l, 'amount'));
            }
        }
        $ctx->otherItemPaid[$id] = max(0.0, round($candidate['amount'] - $candidate['remaining'] - $skipped, 2));
    }

    /** @param 'other_item'|'tax_advance' $doc */
    private static function captureLiquidations(PohodaContext $ctx, string $doc, int $id, string $number, array $record): void
    {
        foreach (PohodaXml::all($record, 'liquidations/liquidation') as $l) {
            $ctx->liquidations[] = [
                'doc' => $doc,
                'id' => $id,
                'number' => $number,
                'agenda' => PohodaXml::text($l, 'sourceAgenda'),
                'source' => PohodaXml::text($l, 'sourceDocument/number'),
                'source_id' => PohodaXml::text($l, 'sourceDocument/id'),
                'date' => PohodaXml::date($l, 'date'),
                'amount' => PohodaXml::num($l, 'amount'),
                'liq' => PohodaXml::text($l, 'id') ?: substr(md5((string) json_encode($l)), 0, 12),
            ];
        }
    }

    private static function label(string $agenda): string
    {
        return $agenda === 'receivable' ? 'ostatní pohledávka' : 'ostatní závazek';
    }
}
