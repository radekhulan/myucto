<?php

declare(strict_types=1);

namespace MyInvoice\Service\TaxEvidence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CashJournalRepository;
use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use MyInvoice\Service\Vat\VatStatusService;

/**
 * Peněžní deník daňové evidence (Epic DE, A2) — kasová báze §7b / §23 ZDP.
 *
 * Orchestruje {@see CashJournalRepository} (READ-ONLY UNION tří noh + dedup + tenant
 * scoping banky přes account-number, R4), aplikuje daňový klasifikátor (§5 spec),
 * dopočítá běžný zůstatek (opening + SQL window running_delta) a totály per §7b kbelík.
 *
 * Klasifikátor je čistá funkce nad řádkem unionu:
 *  - R7  DPH rozpad per-movement: u plátce základ = amount × (bez_dph / s_dph), zbytek → nedaňový.
 *  - R8  daňový výdaj jen když purchase_invoices.tax_deductible=1 AND document_kind<>'advance'.
 *  - R9  osvobozené příjmy (invoices.income_tax_exempt=1) → samostatný bucket, mimo základ.
 *  - R10 nezařazený PŘÍCHOZÍ bankovní pohyb → 'nezarazeno' (mimo totály) + blokující varování,
 *        NIKDY tiše 'nedaňový' (podhodnotil by základ daně).
 *  - R11 proforma/tax_document: inkaso počítáno jednou (dedup v repu); dobropis (peníze ven na
 *        credit_note) snižuje daňový příjem přes fyzickou nohu se znaménkem směru.
 *
 * Reconciliation checks{} (R5) je VYSVĚTLENÁ VARIANCE proti TaxProfileRepository::annualIncome,
 * ne rovnostní assert — panel jen vyčíslí a rozepíše rozdíl, nikdy nevyhazuje výjimku.
 */
final class CashJournalService
{
    /**
     * Kolik chybějících kurzových dnů se smí dotáhnout z ČNB v jednom sestavení deníku.
     * Jeden den = jeden HTTP dotaz (~60 ms) a zaplní VŠECHNY měny toho dne, takže deset
     * je pod tři sekundy i při studeném startu. Zbytek dotáhne další otevření deníku
     * nebo jednorázově `api/bin/backfill-cnb-rates.php` — na to seznam varování odkazuje.
     */
    private const RATE_FETCH_LIMIT = 10;

    public function __construct(
        private readonly CashJournalRepository $repo,
        private readonly TaxProfileRepository $taxProfiles,
        private readonly Connection $db,
        private readonly TaxConstantsRepository $constants,
        private readonly TaxExpenseAllocationCalculator $taxExpenses,
        private readonly VatStatusService $vatStatus,
        private readonly CnbExchangeRateClient $cnb,
    ) {}

    /**
     * Postaví deník za rozsah [from, to].
     *
     * @param array{year?:int, limit?:int, offset?:int} $opts
     * @return array<string,mixed> {from, to, opening_balance, closing_balance, rows[], totals{}, checks{}, warnings[]}
     */
    public function build(int $supplierId, string $from, string $to, array $opts = []): array
    {
        $isVatPayer = $this->isVatPayerAt($supplierId, $to);
        $year = isset($opts['year']) ? (int) $opts['year'] : (int) substr($from, 0, 4);
        $taxConstants = $this->constants->forYear($year);

        // #28: chybějící kurz dotáhneme JEŠTĚ PŘED dotazem na pohyby, ať je řádek
        // rovnou oceněný správně a ne jen nouzově sousedním dnem.
        $rateWarnings = $this->ensureExchangeRates($supplierId, $to);

        $opening = $this->repo->openingBalance($supplierId, $from, $isVatPayer);
        $raw = $this->repo->movements($supplierId, $from, $to, $isVatPayer); // vše v rozsahu (totály se počítají nad celkem)

        $totals = [
            'prijem_danovy'     => 0.0,
            'prijem_osvobozeny' => 0.0,
            'prijem_nedanovy'   => 0.0,
            'vydaj_danovy'      => 0.0,
            'vydaj_nedanovy'    => 0.0,
            'prevody'           => 0.0,
            'private'           => 0.0,
            'nezarazeno'        => 0.0,
            'net'               => 0.0,
        ];
        // R5 vysvětlení variance
        $variance = [
            'partial_payment_income'      => 0.0, // inkaso z faktur, které ještě nejsou status='paid'
            'cash_sale_income'            => 0.0, // hotovostní prodej bez faktury (cash purpose='sale')
            'virtual_leg_income'          => 0.0, // noha C (manual/mark_paid/legacy) — timing vůči YEAR(paid_at)
        ];

        $rows = [];
        $warnings = $rateWarnings;
        $bucketKeyMap = [
            'income_taxable'   => 'prijem_danovy',
            'income_exempt'    => 'prijem_osvobozeny',
            'income_nontax'    => 'prijem_nedanovy',
            'expense_taxable'  => 'vydaj_danovy',
            'expense_nontax'   => 'vydaj_nedanovy',
            'transfer'         => 'prevody',
            'private'          => 'private',
        ];

        foreach ($raw as $r) {
            $movementVatPayer = $this->isVatPayerAt($supplierId, (string) $r['movement_date']);
            $c = $this->classify($r, $movementVatPayer, $taxConstants, $supplierId, $year);

            foreach ($c['alloc'] as $bucket => $amt) {
                if (isset($bucketKeyMap[$bucket])) {
                    $totals[$bucketKeyMap[$bucket]] = round($totals[$bucketKeyMap[$bucket]] + $amt, 2);
                }
            }
            if ($c['unclassified']) {
                $totals['nezarazeno'] = round($totals['nezarazeno'] + $r['amount'], 2);
                $warnings[] = [
                    'source_type' => (string) $r['source_type'],
                    'source_id'   => (int) $r['source_id'],
                    'date'        => (string) $r['movement_date'],
                    'direction'   => (string) $r['direction'],
                    'amount'      => (float) $r['amount'],
                    'blocking'    => $c['blocking'],
                    'message'     => $c['blocking']
                        ? 'Nezařazený příchozí bankovní pohyb — zařaďte jej (mimo daňový základ, riziko podhodnocení příjmu).'
                        : 'Nezařazený bankovní pohyb — zařaďte jej pro správný daňový základ.',
                ];
            }

            // #28: cizoměnový pohyb, který se neměl čím ocenit. Po dotažení kurzů z ČNB
            // (ensureExchangeRates) i po nouzovém ocenění nejbližším pozdějším kurzem
            // sem spadne jen pohyb v měně, o které v `exchange_rates` NENÍ ANI JEDEN
            // řádek a doklad nemá vlastní kurz — typicky exotická měna mimo lístek ČNB.
            // Dřív kvůli takovému dokladu spadlo sestavení celého deníku na 500; teď
            // projde s nulou a účetní dostane adresné blokující varování.
            if (($r['fx_rate_missing'] ?? false) === true) {
                $doc = trim((string) $r['doc_no']);
                $partner = trim((string) $r['partner']);
                $warnings[] = [
                    'type'        => 'fx_rate_missing',
                    'source_type' => (string) $r['source_type'],
                    'source_id'   => (int) $r['source_id'],
                    'date'        => (string) $r['movement_date'],
                    'direction'   => (string) $r['direction'],
                    'amount'      => 0.0,
                    'blocking'    => true,
                    'message'     => sprintf(
                        'Cizoměnový pohyb %s ze dne %s%s se nepodařilo ocenit — pro jeho měnu '
                            . 'není znám žádný kurz. Zadejte kurz přímo na dokladu, jinak pohyb '
                            . 'nevstupuje do daňového základu.',
                        $doc !== '' ? '„' . $doc . '"' : '#' . (int) $r['source_id'],
                        (string) $r['movement_date'],
                        $partner !== '' ? ' (' . $partner . ')' : '',
                    ),
                ];
            }

            // R5: sub-agregace pro vysvětlení variance (jen daňový příjem)
            $taxableIncome = $c['alloc']['income_taxable'] ?? 0.0;
            if ($taxableIncome > 0) {
                if (($r['inv_status'] ?? null) !== null && $r['inv_status'] !== 'paid') {
                    $variance['partial_payment_income'] = round($variance['partial_payment_income'] + $taxableIncome, 2);
                }
                if ($r['source_type'] === 'cash' && ($r['cash_purpose'] ?? null) === 'sale') {
                    $variance['cash_sale_income'] = round($variance['cash_sale_income'] + $taxableIncome, 2);
                }
                if ($r['source_type'] === 'invoice_payment') {
                    $variance['virtual_leg_income'] = round($variance['virtual_leg_income'] + $taxableIncome, 2);
                }
            }

            $running = round($opening + (float) ($r['running_delta'] ?? 0.0), 2);
            $rows[] = [
                'source_type'         => (string) $r['source_type'],
                'source_id'           => (int) $r['source_id'],
                'invoice_id'          => $r['invoice_id'] !== null ? (int) $r['invoice_id'] : null,
                'purchase_invoice_id' => $r['purchase_invoice_id'] !== null ? (int) $r['purchase_invoice_id'] : null,
                'date'                => (string) $r['movement_date'],
                'doc_no'              => (string) $r['doc_no'],
                'partner'             => (string) $r['partner'],
                'description'         => (string) $r['description'],
                'direction'           => (string) $r['direction'],
                'income'              => $r['direction'] === 'in' ? (float) $r['amount'] : null,
                'expense'             => $r['direction'] === 'out' ? (float) $r['amount'] : null,
                'running_balance'     => $running,
                'bucket'              => $c['bucket'],
                'base'                => $c['base'],
                'vat'                 => $c['vat'],
                'unclassified'        => $c['unclassified'],
                'blocking'            => $c['blocking'],
                'fx_rate_missing'     => ($r['fx_rate_missing'] ?? false) === true,
            ];
        }

        // H2: bankovní úhrady mimo spárované výpisy (změna účtu / currencies.account_number)
        // → celá bankovní historie by jinak tiše zmizela ze základu daně. Blokující varování.
        $orphanBank = $this->repo->orphanedBankPaymentCount($supplierId);
        if ($orphanBank > 0) {
            $warnings[] = [
                'type'        => 'orphan_bank_payments',
                'source_type' => 'bank',
                'source_id'   => 0,
                'date'        => $to,
                'direction'   => 'in',
                'amount'      => 0.0,
                'count'       => $orphanBank,
                'blocking'    => true,
                'message'     => $orphanBank . ' bankovních úhrad není v žádném spárovaném výpisu '
                    . '(zkontrolujte čísla účtů v Nastavení — jinak chybí v daňovém základu).',
            ];
        }

        $totals['net'] = round($totals['prijem_danovy'] - $totals['vydaj_danovy'], 2);
        $closing = round($opening + $this->sumSigned($raw), 2);

        return [
            'from'            => $from,
            'to'              => $to,
            'year'            => $year,
            'is_vat_payer'    => $isVatPayer,
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'rows'            => $rows,
            'totals'          => $totals,
            'checks'          => $this->reconciliation($supplierId, $year, $isVatPayer, $totals, $variance),
            'warnings'        => $warnings,
        ];
    }

    /**
     * #28: dotáhne z ČNB kurzy pro dny, ke kterým deník nemá čím ocenit cizoměnový
     * pohyb, a vrátí varování o tom, co ani potom ocenit přesně nejde.
     *
     * Proč za běhu a ne jen cronem: `cron-cnb-rates` drží historii souvislou OD SVÉHO
     * ZAVEDENÍ dál, ale nepokryje pohyb starší, než kam sahá kurzová historie instalace
     * — a přesně ten shazoval deník u čerstvého tenanta, který zaeviduje loňské doklady.
     * Doplnění je jednorázové: kurz se uloží do sdílené `exchange_rates`, takže druhé
     * otevření deníku už síť nepotřebuje. V demu klient síť nepoužívá vůbec.
     *
     * Deník se kvůli téhle pomocné akci NIKDY nesmí zastavit — nedostupná ČNB znamená
     * jen nouzové ocenění sousedním dnem a varování, ne chybu. Proto `catch (\Throwable)`.
     *
     * @return list<array<string,mixed>> varování do `warnings[]`
     */
    private function ensureExchangeRates(int $supplierId, string $to): array
    {
        $gaps = $this->repo->unpricedCurrencyDays($supplierId, $to, self::RATE_FETCH_LIMIT + 1);
        if ($gaps === []) {
            return [];
        }

        // Ptáme se JEN na měny, o kterých už nějaký kurz známe (`forward_date`) — tedy
        // takové, které ČNB prokazatelně vyhlašuje. Bez téhle podmínky by exotická měna
        // mimo kurzovní lístek (a takovou nedoplní ani ČNB) posílala osm marných HTTP
        // dotazů při KAŽDÉM otevření deníku, navždy. Neznámou měnu tak neoceníme, ale to
        // je stav, který se stejně řeší kurzem na dokladu.
        // Datum mimo rozsah, za který ČNB kurzy vyhlašuje (od roku 1991 do dneška),
        // se ptát nemá smysl — budoucí kurz ještě neexistuje a starší nikdy nebyl.
        $today = date('Y-m-d');
        $fetchable = array_values(array_filter(
            $gaps,
            static fn (array $g): bool => $g['forward_date'] !== null
                && $g['date'] <= $today
                && $g['date'] >= '1991-01-01',
        ));
        $backlog = count($gaps) > self::RATE_FETCH_LIMIT;
        foreach (array_slice($fetchable, 0, self::RATE_FETCH_LIMIT) as $gap) {
            try {
                $this->cnb->getRate($gap['currency'], new \DateTimeImmutable($gap['date']));
            } catch (\Throwable) {
                // Síť ani ČNB nejsou spolehlivé; zbytek si poradí nouzovým oceněním.
            }
        }

        // Bez pokusu o dotažení se nemohlo nic změnit — druhý dotaz by jen opsal ten první.
        $remaining = $fetchable === []
            ? array_slice($gaps, 0, self::RATE_FETCH_LIMIT)
            : $this->repo->unpricedCurrencyDays($supplierId, $to, self::RATE_FETCH_LIMIT);

        $warnings = [];
        foreach ($remaining as $gap) {
            // Bez jediného známého kurzu měny se pohyb ocenit nedá vůbec — to hlásí
            // adresněji per-řádkové varování `fx_rate_missing` (zná i číslo dokladu).
            if ($gap['forward_date'] === null) {
                continue;
            }
            $warnings[] = [
                'type'        => 'fx_rate_approximate',
                'source_type' => 'exchange_rate',
                'source_id'   => 0,
                'date'        => $gap['date'],
                'direction'   => 'in',
                'amount'      => 0.0,
                'blocking'    => false,
                'message'     => sprintf(
                    'Ke dni %s není znám kurz %s — pohyby toho dne jsou oceněny nejbližším '
                        . 'pozdějším kurzem ze dne %s. Kurz se pokusíme dotáhnout z ČNB při '
                        . 'dalším otevření deníku.',
                    $gap['date'],
                    $gap['currency'],
                    $gap['forward_date'],
                ),
            ];
        }

        if ($backlog) {
            $warnings[] = [
                'type'        => 'fx_rate_backlog',
                'source_type' => 'exchange_rate',
                'source_id'   => 0,
                'date'        => $to,
                'direction'   => 'in',
                'amount'      => 0.0,
                'blocking'    => false,
                'message'     => 'Kurzová historie má víc mezer, než se stihne doplnit najednou — '
                    . 'doplňuje se po částech při každém otevření deníku. Rychlejší je '
                    . 'jednorázové doplnění příkazem `php api/bin/backfill-cnb-rates.php --apply`.',
            ];
        }

        return $warnings;
    }

    /**
     * Měsíční kasové ZDANITELNÉ příjmy [1..12] => CZK — kasový protějšek
     * {@see \MyInvoice\Repository\TaxProfileRepository::monthlyIncome()} pro daňovou evidenci.
     * Slouží k projekci běžícího roku (odhad daně a pojistného) i ke sledování limitů
     * paušálního režimu; u plátce DPH je to částka BEZ DPH (prorata už proběhla v classify()).
     *
     * Osvobozené příjmy (§ 4 ZDP, bucket income_exempt) sem ZÁMĚRNĚ nepatří — ani do jedné
     * z těch dvou rolí (#52):
     *  - do základu daně a vyměřovacích základů pojistného osvobozený příjem nevstupuje,
     *  - do rozhodných příjmů pro pásmo paušálního režimu taky ne: rozhodnými příjmy jsou
     *    podle § 2a odst. 5 ZDP „příjmy ze samostatné činnosti do výše …" a § 7a odst. 1
     *    písm. b) bod 1 uvádí příjmy od daně osvobozené jako kategorii, kterou poplatník smí
     *    mít VEDLE rozhodných příjmů — tedy mimo ně.
     * Dřív (pod názvem monthlyIncomeForFlatTax) se oba kbelíky sčítaly, takže osvobozená
     * faktura nafoukla projekci i teploměr limitu 2 M.
     *
     * @return array<int,float>
     */
    public function monthlyTaxableIncome(int $supplierId, int $year): array
    {
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $isVatPayer = $this->isVatPayerAt($supplierId, $to);
        $taxConstants = $this->constants->forYear($year);
        $monthly = array_fill(1, 12, 0.0);

        foreach ($this->repo->movements($supplierId, $from, $to, $isVatPayer) as $row) {
            $classified = $this->classify(
                $row,
                $this->isVatPayerAt($supplierId, (string) $row['movement_date']),
                $taxConstants,
                $supplierId,
                $year,
            );
            $income = (float) ($classified['alloc']['income_taxable'] ?? 0);
            if ($income == 0.0) {
                continue;
            }
            $month = (int) substr((string) $row['movement_date'], 5, 2);
            if ($month >= 1 && $month <= 12) {
                $monthly[$month] = round($monthly[$month] + $income, 2);
            }
        }

        return $monthly;
    }

    // ── klasifikátor §5 ──────────────────────────────────────────────────────

    /**
     * Zařadí jeden řádek unionu do §7b/§23 kbelíků. Vrací:
     *  alloc: [bucketKey => signedAmount], bucket: primární label, base/vat: daňový split
     *  (pro zobrazení), unclassified/blocking: R10.
     *
     * @param array<string,mixed> $r
     * @return array{alloc:array<string,float>, bucket:string, base:float, vat:float, unclassified:bool, blocking:bool}
     */
    private function classify(
        array $r,
        bool $isVatPayer,
        array $taxConstants,
        int $supplierId,
        int $year,
    ): array
    {
        $amount    = (float) $r['amount'];
        $direction = (string) $r['direction'];
        $source    = (string) $r['source_type'];

        // 0) Ruční override (1027) — nejvyšší priorita; celý pohyb do daného kbelíku.
        $override = $r['override_bucket'] ?? null;
        if ($override !== null && $override !== '') {
            return $this->wholeToBucket((string) $override, $amount, $direction, $r, $isVatPayer);
        }

        // 1) Hotovost — self-klasifikace přes purpose.
        if ($source === 'cash') {
            $purpose = (string) ($r['cash_purpose'] ?? 'other');
            switch ($purpose) {
                case 'sale':
                    return $this->cashVatAlloc($amount, $r, true, $isVatPayer, $supplierId, $year);
                case 'purchase':
                    return $this->cashVatAlloc($amount, $r, false, $isVatPayer, $supplierId, $year);
                case 'invoice_payment':
                    return $this->incomeAlloc($amount, $direction, $r, $isVatPayer);
                case 'purchase_payment':
                    return $this->expenseAlloc($amount, $direction, $r, $isVatPayer, $taxConstants, $supplierId, $year);
                case 'transfer':
                    return $this->wholeToBucket('transfer', $amount, $direction);
                default: // 'other' — uživatelem zvolený nestandardní účel → nedaňový
                    return $direction === 'in'
                        ? $this->single('income_nontax', $amount, 'income_nontax')
                        : $this->single('expense_nontax', $amount, 'expense_nontax');
            }
        }

        // 1b) Banka — AGREGOVANÝ příjem/výdaj (repo předpočítal CZK base per bank_transaction_id;
        //     jeden pohyb = jeden řádek i u sloučené úhrady N faktur/PF, #89). base/vat/exempt
        //     hotové v CZK — klasifikátor jen rozdělí do kbelíků.
        if ($source === 'bank' && ($r['bank_class'] ?? null) === 'income') {
            return $this->bankIncomeAlloc($amount, $direction, $r);
        }
        if ($source === 'bank' && ($r['bank_class'] ?? null) === 'expense') {
            $base = $this->taxExpenses->forBankPayment(
                $supplierId,
                (int) $r['source_id'],
                $isVatPayer,
                $year,
                (float) ($taxConstants['fixed_asset_limit'] ?? 80000),
            );
            return $this->bankExpenseAlloc($amount, $base);
        }

        // 2) Vazba na vydanou fakturu → příjem (i dobropis: out = záporný příjem, R11).
        //    (Legacy 1:1 přes matched_invoice_id — bez předpočítané agregace, single-invoice.)
        if ($r['invoice_id'] !== null) {
            return $this->incomeAlloc($amount, $direction, $r, $isVatPayer);
        }

        // 3) Vazba na přijatou fakturu → výdaj (R8 filtry).
        if ($r['purchase_invoice_id'] !== null) {
            return $this->expenseAlloc($amount, $direction, $r, $isVatPayer, $taxConstants, $supplierId, $year);
        }

        // 4) Banka bez vazby → bankovní poplatek (heuristika) nebo NEZAŘAZENO (R10).
        if ($source === 'bank') {
            if ($direction === 'out' && $this->looksLikeOwnTaxOrInsurance((string) $r['description'])) {
                return $this->single('expense_nontax', $amount, 'expense_nontax');
            }
            if ($direction === 'out' && $this->looksLikeBankFee((string) $r['description'])) {
                return $this->single('expense_taxable', $amount, 'expense_taxable', $amount, 0.0);
            }
            // NIKDY tiše nedaňový: příchozí je blokující (podhodnotil by základ daně).
            return [
                'alloc'        => [],
                'bucket'       => 'nezarazeno',
                'base'         => 0.0,
                'vat'          => 0.0,
                'unclassified' => true,
                'blocking'     => $direction === 'in',
            ];
        }

        // 5) Fallback (nemělo by nastat — noha C má vždy vazbu).
        return $direction === 'in'
            ? $this->single('income_nontax', $amount, 'income_nontax')
            : $this->single('expense_nontax', $amount, 'expense_nontax');
    }

    /**
     * Příjem z vydané faktury: R9 (osvobozeno → income_exempt), jinak daňový základ s
     * R7 proratou; znaménko dle směru (out = dobropis/vratka snižuje příjem, R11).
     *
     * @param array<string,mixed> $r
     * @return array{alloc:array<string,float>, bucket:string, base:float, vat:float, unclassified:bool, blocking:bool}
     */
    private function incomeAlloc(float $amount, string $direction, array $r, bool $isVatPayer): array
    {
        $sign = $direction === 'in' ? 1.0 : -1.0;

        if ((int) ($r['inv_exempt'] ?? 0) === 1) {
            // #52: osvobozená noha se u plátce DPH dělí na základ a DPH stejně jako zdanitelná
            // níž — DPH z osvobozené faktury je průběžná položka státu, ne příjem poplatníka.
            // Bez toho by „z toho vyloučeno" bylo brutto, kdežto TaxProfileRepository
            // ::annualExemptIncome() vedle toho hlásí netto.
            $exemptBase = $this->prorateBase($amount, $r['inv_without_vat'] ?? null, $r['inv_with_vat'] ?? null, $isVatPayer);
            $exemptVat  = round($amount - $exemptBase, 2);
            $alloc = ['income_exempt' => round($sign * $exemptBase, 2)];
            if ($exemptVat != 0.0) {
                $alloc['income_nontax'] = round($sign * $exemptVat, 2);
            }
            return [
                'alloc'        => $alloc,
                'bucket'       => 'income_exempt',
                // `base` = DAŇOVÝ základ (§7), ten je u osvobozené faktury nulový — shodně
                // s bankIncomeAlloc(), kde do base jde jen income_taxable.
                'base'         => 0.0,
                'vat'          => round($sign * $exemptVat, 2),
                'unclassified' => false,
                'blocking'     => false,
            ];
        }

        $base = $this->prorateBase($amount, $r['inv_without_vat'] ?? null, $r['inv_with_vat'] ?? null, $isVatPayer);
        $vat  = round($amount - $base, 2);
        $alloc = ['income_taxable' => round($sign * $base, 2)];
        if ($vat != 0.0) {
            $alloc['income_nontax'] = round($sign * $vat, 2);
        }
        return [
            'alloc'        => $alloc,
            'bucket'       => 'income_taxable',
            'base'         => round($sign * $base, 2),
            'vat'          => round($sign * $vat, 2),
            'unclassified' => false,
            'blocking'     => false,
        ];
    }

    /**
     * Výdaj z přijaté faktury: R8 (nedaňová / záloha → expense_nontax), jinak daňový
     * základ s R7 proratou. Znaménko dle směru (in = přijatý dobropis/vratka snižuje
     * výdaj, R11) — zrcadlo {@see incomeAlloc}.
     *
     * Bez toho by vratka z přijatého dobropisu (peníze přišly ZPĚT, tedy direction='in')
     * výdaj ZVÝŠILA místo snížila — chyba 2× částka v základu DPFO § 7.
     *
     * @param array<string,mixed> $r
     * @return array{alloc:array<string,float>, bucket:string, base:float, vat:float, unclassified:bool, blocking:bool}
     */
    private function expenseAlloc(
        float $amount,
        string $direction,
        array $r,
        bool $isVatPayer,
        array $taxConstants,
        int $supplierId,
        int $year,
    ): array
    {
        // Výdaj je 'out'; opačný směr = vratka, tedy záporný výdaj.
        $sign = $direction === 'out' ? 1.0 : -1.0;
        $base = $this->taxExpenses->forPurchaseInvoice(
            $supplierId,
            (int) $r['purchase_invoice_id'],
            $amount,
            $isVatPayer,
            $year,
            (float) ($taxConstants['fixed_asset_limit'] ?? 80000),
        );
        $vat  = round($sign * ($amount - $base), 2);
        $base = round($sign * $base, 2);
        $alloc = ['expense_taxable' => $base];
        if ($vat != 0.0) {
            $alloc['expense_nontax'] = $vat;
        }
        return [
            'alloc'        => $alloc,
            'bucket'       => 'expense_taxable',
            'base'         => $base,
            'vat'          => $vat,
            'unclassified' => false,
            'blocking'     => false,
        ];
    }

    /**
     * Bankovní PŘÍJEM z předpočítané agregace (repo, CZK): jeden bankovní pohyb může
     * settlovat N vydaných faktur (sloučená úhrada #89). base i exempt jsou už sečtené
     * per-faktura s R7 proratou (od #52 se prorata dělá i na osvobozené noze, tj. obě jsou
     * u plátce DPH bez DPH); DPH složka = amount − base − exempt → income_nontax.
     * Znaménko dle směru (out = dobropis/vratka snižuje příjem, R11).
     *
     * @param array<string,mixed> $r
     * @return array{alloc:array<string,float>, bucket:string, base:float, vat:float, unclassified:bool, blocking:bool}
     */
    private function bankIncomeAlloc(float $amount, string $direction, array $r): array
    {
        $sign   = $direction === 'in' ? 1.0 : -1.0;
        $base   = round((float) ($r['bank_income_base'] ?? 0.0), 2);
        $exempt = round((float) ($r['bank_income_exempt'] ?? 0.0), 2);
        $vat    = round($amount - $base - $exempt, 2);

        $alloc = [];
        if ($base   != 0.0) $alloc['income_taxable'] = round($sign * $base, 2);
        if ($exempt != 0.0) $alloc['income_exempt']  = round($sign * $exempt, 2);
        if ($vat    != 0.0) $alloc['income_nontax']  = round($sign * $vat, 2);

        $bucket = $base != 0.0 ? 'income_taxable' : ($exempt != 0.0 ? 'income_exempt' : 'income_nontax');
        return [
            'alloc'        => $alloc,
            'bucket'       => $bucket,
            'base'         => round($sign * $base, 2),
            'vat'          => round($sign * $vat, 2),
            'unclassified' => false,
            'blocking'     => false,
        ];
    }

    /**
     * Bankovní VÝDAJ z předpočítané agregace (repo, CZK): jeden odchozí pohyb může uhradit
     * N přijatých faktur (payment_matches, H3). Daňový základ = jen deductible ne-zálohové PF
     * (R8, per-PF proratou); zbytek (nedaňové PF, zálohy, DPH složka) → expense_nontax.
     *
     * @param array<string,mixed> $r
     * @return array{alloc:array<string,float>, bucket:string, base:float, vat:float, unclassified:bool, blocking:bool}
     */
    private function bankExpenseAlloc(float $amount, float $taxableAmount): array
    {
        $base   = round($taxableAmount, 2);
        $nontax = round($amount - $base, 2);

        $alloc = [];
        if ($base   != 0.0) $alloc['expense_taxable'] = $base;
        if ($nontax != 0.0) $alloc['expense_nontax']  = $nontax;

        return [
            'alloc'        => $alloc,
            'bucket'       => $base != 0.0 ? 'expense_taxable' : 'expense_nontax',
            'base'         => $base,
            'vat'          => $nontax,
            'unclassified' => false,
            'blocking'     => false,
        ];
    }

    /**
     * Hotovostní prodej/nákup: DPH split z cash_document_vat_lines (u plátce), jinak
     * celé brutto = základ.
     *
     * @param array<string,mixed> $r
     * @return array{alloc:array<string,float>, bucket:string, base:float, vat:float, unclassified:bool, blocking:bool}
     */
    private function cashVatAlloc(
        float $amount,
        array $r,
        bool $income,
        bool $isVatPayer,
        int $supplierId,
        int $year,
    ): array
    {
        if (!$income) {
            $base = $this->taxExpenses->forCashDocument(
                $supplierId,
                (int) $r['source_id'],
                $isVatPayer,
                $year,
            );
            $nontax = round($amount - $base, 2);
            $alloc = ['expense_taxable' => $base];
            if ($nontax != 0.0) {
                $alloc['expense_nontax'] = $nontax;
            }
            return ['alloc' => $alloc, 'bucket' => 'expense_taxable', 'base' => $base, 'vat' => $nontax,
                'unclassified' => false, 'blocking' => false];
        }

        // $amount i DPH základ/částka z cash_document_vat_lines jsou v DB už v CZK —
        // valutový doklad přepočítá CashDocumentService::resolveCurrency() kurzem PŘED
        // uložením (migrace 1114). Žádný další přepočet kurzem se tu tedy nedělá.
        $base = $amount;
        $vat  = 0.0;
        $vatBase = $r['cash_vat_base'] ?? null;
        $vatAmount = $r['cash_vat_amount'] ?? null;
        if ($isVatPayer && $vatBase !== null && $vatAmount !== null && (float) $vatBase > 0) {
            $base = round((float) $vatBase, 2);
            $vat  = round((float) $vatAmount, 2);
        }
        // Zbytek (amount − base − vat, např. zaokrouhlení nebo nepokrytá část) NESMÍ tiše zmizet
        // (M2) — spadne do nedaňového kbelíku (DPH/ostatní složka).
        $remainder = round($amount - $base - $vat, 2);
        $nontax = round($vat + $remainder, 2);
        if ($income) {
            $alloc = ['income_taxable' => $base];
            if ($nontax != 0.0) {
                $alloc['income_nontax'] = $nontax;
            }
            return ['alloc' => $alloc, 'bucket' => 'income_taxable', 'base' => $base, 'vat' => $nontax,
                    'unclassified' => false, 'blocking' => false];
        }
        throw new \LogicException('Nedostupná větev pokladní klasifikace.');
    }

    /** R7: základ = amount × (bez_dph / s_dph) u plátce; u neplátce / bez dat celé brutto. */
    private function prorateBase(float $amount, mixed $without, mixed $with, bool $isVatPayer): float
    {
        if (!$isVatPayer || $without === null || $with === null) {
            return round($amount, 2);
        }
        $w = (float) $with;
        if ($w <= 0.0) {
            return round($amount, 2);
        }
        return round($amount * ((float) $without / $w), 2);
    }

    /** @param array<string,mixed> $row */
    private function fixedAssetEntryPrice(array $row, bool $isVatPayer): float
    {
        $net = (float) ($row['pi_without_vat'] ?? 0);
        $gross = (float) ($row['pi_with_vat'] ?? $net);
        if (!$isVatPayer || ($row['pi_vat_deduction'] ?? 'full') === 'none') {
            return $gross;
        }
        $deductionPercent = ($row['pi_vat_deduction'] ?? 'full') === 'full'
            ? 100.0
            : max(0.0, min(100.0, (float) ($row['pi_vat_deduction_percent'] ?? 0)));
        return round($net + max(0.0, $gross - $net) * (1 - $deductionPercent / 100), 2);
    }

    /**
     * Override / jednoduchá alokace celého pohybu do kbelíku dle mapy 1027, znaménko dle směru
     * u příjmových/výdajových kbelíků není potřeba (bucket sám nese směr).
     *
     * @return array{alloc:array<string,float>, bucket:string, base:float, vat:float, unclassified:bool, blocking:bool}
     */
    private function wholeToBucket(string $bucket, float $amount, string $direction, array $r = [], bool $isVatPayer = false): array
    {
        $known = ['income_taxable', 'income_exempt', 'income_nontax',
                  'expense_taxable', 'expense_nontax', 'transfer', 'private'];
        if (!in_array($bucket, $known, true)) {
            // neznámý override → chová se jako nezařazeno (bezpečné, mimo totály)
            return ['alloc' => [], 'bucket' => 'nezarazeno', 'base' => 0.0, 'vat' => 0.0,
                    'unclassified' => true, 'blocking' => $direction === 'in'];
        }
        // LOW/pozn.: ruční 1027 override alokuje celé brutto (vč. DPH) jako základ a obchází
        // R7 proratu. Je to přijatelné pro pohyby BEZ faktury (nezná se DPH rozpad); u pohybu
        // s vazbou by měl uživatel spíš opravit klasifikaci dokladu než override.
        if ($bucket === 'income_taxable' && ($r['invoice_id'] ?? null) !== null) {
            $base = $this->prorateBase($amount, $r['inv_without_vat'] ?? null, $r['inv_with_vat'] ?? null, $isVatPayer);
            return $this->single($bucket, $base, $bucket, $base, round($amount - $base, 2));
        }
        if ($bucket === 'expense_taxable' && ($r['purchase_invoice_id'] ?? null) !== null) {
            $base = $this->prorateBase($amount, $r['pi_without_vat'] ?? null, $r['pi_with_vat'] ?? null, $isVatPayer);
            return $this->single($bucket, $base, $bucket, $base, round($amount - $base, 2));
        }
        $base = in_array($bucket, ['income_taxable', 'expense_taxable'], true) ? $amount : 0.0;
        return $this->single($bucket, $amount, $bucket, $base, 0.0);
    }

    /**
     * @return array{alloc:array<string,float>, bucket:string, base:float, vat:float, unclassified:bool, blocking:bool}
     */
    private function single(string $bucket, float $amount, string $label, float $base = 0.0, float $vat = 0.0): array
    {
        return [
            'alloc'        => [$bucket => round($amount, 2)],
            'bucket'       => $label,
            'base'         => round($base, 2),
            'vat'          => round($vat, 2),
            'unclassified' => false,
            'blocking'     => false,
        ];
    }

    /**
     * Heuristika bankovního poplatku (nespárovaný odchozí pohyb). M3: skenuje JEN popis
     * pohybu (description), NE protistranu — jinak by partner 'Coffee'/'Feeder s.r.o.'
     * planě spadl do daňového výdaje. Vzory s hranicí slova, ať 'fee' nematchne 'Feeder'.
     */
    private function looksLikeBankFee(string $description): bool
    {
        $hay = mb_strtolower($description);
        $patterns = [
            '/\bpoplat(ek|ky|ku)\b/u',
            '/\bfee\b/u',
            '/bank charge/u',
            '/cena za veden/u',
            '/veden[ií] účtu/u',
            '/\búplata za\b/u',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $hay) === 1) {
                return true;
            }
        }
        return false;
    }

    private function looksLikeOwnTaxOrInsurance(string $description): bool
    {
        $hay = mb_strtolower($description);
        foreach ([
            '/\b(čssz|cssz|ossz|mssz)\b/u',
            '/\b(zdravotní|zdravotni)\s+pojišt/u',
            '/\b(vzp|ozp|zp[mšs]|čpzp|cpzp|rbp)\b/u',
            '/\bdaň\s+z\s+příjm/u',
            '/\bfinanční\s+úřad\b/u',
        ] as $pattern) {
            if (preg_match($pattern, $hay) === 1) {
                return true;
            }
        }
        return false;
    }

    // ── reconciliation checks{} (R5) ─────────────────────────────────────────

    /**
     * Vysvětlená variance deník vs TaxProfileRepository::annualIncome — NE rovnostní
     * assert. Vrací rozdíl a jeho rozpad; panel je informativní, nikdy nevyhazuje.
     *
     * @param array<string,float> $totals
     * @param array<string,float> $variance
     * @return array<string,mixed>
     */
    private function reconciliation(int $supplierId, int $year, bool $isVatPayer, array $totals, array $variance): array
    {
        $denikIncome = $totals['prijem_danovy'];
        $annualIncome = $this->taxProfiles->annualIncome($supplierId, $year, $isVatPayer);
        $diff = round($denikIncome - $annualIncome, 2);

        $explained = round(
            $variance['partial_payment_income']
            + $variance['cash_sale_income']
            + $variance['virtual_leg_income'],
            2
        );

        return [
            'denik_prijem_danovy' => $denikIncome,
            'annual_income'       => $annualIncome,
            'variance'            => $diff,
            'explanations'        => [
                'partial_payments'          => $variance['partial_payment_income'],
                'cash_sales_without_invoice' => $variance['cash_sale_income'],
                'virtual_leg'               => $variance['virtual_leg_income'],
            ],
            'explained_total'     => $explained,
            'residual'            => round($diff - $explained, 2),
            'is_equal_assert'     => false, // R5: reconciliation je vysvětlená odchylka, ne rovnost
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param list<array<string,mixed>> $rows */
    private function sumSigned(array $rows): float
    {
        $sum = 0.0;
        foreach ($rows as $r) {
            $sum += $r['direction'] === 'in' ? (float) $r['amount'] : -(float) $r['amount'];
        }
        return round($sum, 2);
    }

    private function isVatPayerAt(int $supplierId, string $date): bool
    {
        return $this->vatStatus->isVatPayerAt($supplierId, $date);
    }
}
