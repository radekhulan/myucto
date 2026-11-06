<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use DateTimeImmutable;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\ForeignCurrencyAccountPolicy;
use MyInvoice\Service\Accounting\AccountRoleResolver;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Currency\CnbExchangeRateClient;

/**
 * Kurzové rozdíly k rozvahovému dni (Epic F4, R10 — §24 odst. 6+7 ZoÚ, ČÚS 006).
 *
 * (a) Saldokonto 311/321 — open-item metoda z dokladů: cizoměnové saldokontní
 *     řádky deníku a doložená vypořádání dokladu k D; zbytek ve skutečné měně;
 *     přepočet kurzem ČNB k D (cache-first, 7denní fallback).
 * (b) Banka/valutová pokladna — poloautomat: uživatelem potvrzené devizové
 *     zůstatky {account_code, currency_code, foreign_balance}; rozdíl proti
 *     účetnímu Kč zůstatku účtu z deníku.
 * (c) Peněžní účty 378/379 ({@see ForeignCurrencyAccountPolicy}) — automaticky
 *     z deníku: cizoměnový zůstatek řádků s měnou × kurz ČNB proti jejich Kč
 *     hodnotě; zapisuje se do slotu (b).
 *
 * Agregace: pár řádků per doložený případ, účet, měna a úplná dimenze; rozpad per doklad
 * jde do payload kroku (audit + tisk podkladu). Strany (R10): zisk → MD účet /
 * D 663 (fx.gain), ztráta → MD 563 (fx.loss) / D účet — platí shodně pro
 * pohledávku (diff > 0 zisk) i závazek (diff < 0 zisk; diff se počítá
 * z ABSOLUTNÍ výše závazku v Kč). FX řádky saldokonta nesou currency_code,
 * fx_rate = kurz ČNB k D, amount_foreign = 0 (R20 — částka v cizí měně se
 * přeceněním nemění).
 */
final class FxRevaluationService
{
    private static function settlementFindingText(string $finding): string
    {
        return match ($finding) {
            'document_missing', 'document_ledger_missing' => 'Doklad nebo jeho účetní zápis není dostupný.',
            'payment_posting_reversed' => 'Platba je stále přiřazena k dokladu, ale její účetní zápis byl stornován.',
            'document_currency_conflict', 'payment_currency_conflict', 'legacy_payment_currency_unproven',
            'advance_document_currency_conflict', 'advance_receipt_currency_unproven' => 'Měny dokladu, úhrad a účetních zápisů nesouhlasí.',
            'payment_representation_conflict', 'advance_receipt_representation_conflict' => 'Bankovní párování a evidence úhrady uvádějí rozdílné částky.',
            'advance_receipt_pool_conflict' => 'Jedna platba je přiřazena ve vyšší částce, než skutečně obsahuje.',
            'advance_lineage_missing', 'advance_parent_unproven', 'advance_receipt_bank_missing' => 'Záloha nemá doloženou vazbu na platbu a vyúčtování.',
            'advance_final_membership_unproven', 'advance_receipt_entry_ambiguous', 'advance_receipt_account_unproven',
            'advance_receipt_allocation_rounding_unproven', 'advance_receipt_bank_allocation_unproven',
            'advance_original_allocation_unproven' => 'Rozdělení platby nebo zálohy mezi doklady není jednoznačně doloženo.',
            'advance_refund_exceeds_receipts', 'advance_settlement_exceeds_receipts', 'advance_settlement_pair_unproven' => 'Zúčtování zálohy neodpovídá aktuálně zaúčtovaným platbám a vratkám.',
            'advance_tax_currency_unproven', 'advance_tax_valuation_unproven', 'advance_partial_tax_settlement_unproven' => 'Zúčtování daně ze zálohy nelze přesně přiřadit k částce v cizí měně.',
            'advance_rate_missing', 'advance_settlement_valuation_unproven' => 'Čerpání zálohy nelze přesně rozdělit podle doložených kurzů.',
            'manual_allocation_unproven', 'manual_currency_conflict', 'manual_original_allocation_unproven',
            'manual_settlement_amount_conflict' => 'Ruční vypořádání nemá jednoznačné přiřazení k dokladu, jeho částce a měně.',
            default => 'Částky úhrad a zúčtovaných záloh nesouhlasí s doloženým zůstatkem dokladu.',
        };
    }


    public function __construct(
        private readonly ClosingRepository $repo,
        private readonly CnbExchangeRateClient $cnb,
        private readonly PostingRuleRepository $rules,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly AccountRoleResolver $roles,
    ) {}

    /**
     * Doklad bez důkazu úhrady přecenění zastaví, protože jeho zůstatek v cizí měně nejde určit.
     * Výjimkou je doklad, který je v deníku vyrovnaný a nenese dřívější přecenění: není co
     * přeceňovat, ať už párování vypadá jakkoli. Hlásí se všechny doklady naráz i s číslem,
     * jinak by se účetní k dalšímu dostala až po opravě předchozího.
     *
     * @param list<array<string,mixed>> $items projekce `item` + `evidence`
     * @param array<string,int> $carrying
     */
    private function assertSettlementsProven(int $supplierId, array $items, array $carrying, string $endsOn): void
    {
        $unproven = [];
        foreach ($items as $projection) {
            $item = $projection['item'];
            if ($projection['evidence']['complete'] || self::isNonMonetaryAdvanceAccount((string) $item['account_code'])) {
                continue;
            }
            if (($carrying[FxCaseCarryingProjection::key($item)] ?? 0) === 0
                && $this->repo->fxDocumentSettledInLedger($supplierId, (string) $item['doc_type'], (int) $item['doc_id'], (int) $item['account_id'], $endsOn)) {
                continue;
            }
            $unproven[] = sprintf(
                '%s %s: %s',
                $item['doc_type'] === 'invoice' ? 'vydaná faktura' : 'přijatá faktura',
                (string) ($item['varsymbol'] ?: '#' . $item['doc_id']),
                self::settlementFindingText($projection['evidence']['findings'][0]),
            );
        }
        if ($unproven === []) {
            return;
        }
        $shown = array_slice($unproven, 0, 10);
        $more = count($unproven) - count($shown);
        throw new ClosingException(
            'fx_settlement_unproven',
            'Přecenění nelze dokončit, u těchto dokladů nejde určit zůstatek v cizí měně: ' . implode(' ', $shown)
                . ($more > 0 ? ' A dalších ' . $more . '.' : '')
                . ' Opravte párování úhrady na dokladu. Úplný seznam je v kroku Kontroly v řádku Cizoměnové otevřené doklady.',
            422,
        );
    }

    /**
     * Výpočet přecenění bez zápisu — podklad pro FE náhled i pro buildEntries.
     *
     * @param array<string,mixed> $period řádek accounting_periods (starts_on/ends_on/fiscal_year)
     * @param list<array{account_code:string, currency_code:string, foreign_balance:float|int|string}> $bankRows
     * @return array{
     *     rate_info: list<array{currency:string, rate:float, rate_date:string, fallback_used:bool}>,
     *     saldo: array{lines: list<array<string,mixed>>, detail: list<array<string,mixed>>},
     *     bank: array{lines: list<array<string,mixed>>},
     *     totals: array{loss: float, gain: float},
     *     warnings: list<array<string,mixed>>
     * }
     */
    public function preview(int $supplierId, array $period, array $bankRows = [], bool $currentRead = false): array
    {
        $endsOn = (string) $period['ends_on'];
        $rates = [];
        $warnings = [];
        $detail = [];
        $desiredGroups = [];
        $snapshot = $this->repo->fxCaseSnapshot($supplierId, $period, $currentRead);
        $carrying = (new FxCaseCarryingProjection())->project($snapshot, $period);
        if (!$carrying['complete']) {
            throw new ClosingException('fx_case_history_unproven', FxCaseCarryingProjection::findingMessage($carrying['findings'][0]), 422);
        }
        $this->assertSettlementsProven($supplierId, $snapshot['items'], $carrying['carrying'], $endsOn);

        foreach ($snapshot['items'] as $projection) {
            $item = $projection['item'];
            // EP-17: nepeněžní zálohy (314 poskytnuté / 324 přijaté zálohy na budoucí
            // dodání zboží/služby) NEJSOU peněžní položka podle §4 odst. 12 ZoÚ / ČÚS 006 —
            // vypořádají se PLNĚNÍM, ne penězi — a k rozvahovému dni se kurzem
            // NEPŘEPOČÍTÁVAJÍ (na rozdíl od peněžních pohledávek/závazků 311/321 a
            // banky/valutové pokladny 211/221, které přeceňujeme dál beze změny).
            // Datový model nerozlišuje peněžní vs. nepeněžní zálohu jiným spolehlivým
            // příznakem než účtem (advance leg 314/324 se navíc účtuje CZK-only přes
            // bank/cash source a settlement leg bez cizoměnové stopy — do openFxItems
            // by měl vstoupit jen výjimečně, např. při ručně nastavené kontaci
            // saldokonta na 314/324), proto konzervativně vylučujeme celou účtovou
            // skupinu 314/324 z FX přecenění saldokonta.
            if (self::isNonMonetaryAdvanceAccount((string) $item['account_code'])) {
                continue;
            }

            $currency = (string) $item['currency_code'];
            $evidence = $projection['evidence'];
            // Sem dojde jen doklad vyrovnaný v deníku, ostatní odmítl assertSettlementsProven().
            if (!$evidence['complete']) {
                continue;
            }
            foreach ($evidence['diagnostics'] ?? [] as $diagnostic) {
                $warnings[] = ['code' => $diagnostic, 'doc_type' => $item['doc_type'], 'doc_id' => $item['doc_id']];
            }
            $remainingForeign = (float) $evidence['remaining_foreign'];
            $caseKey = FxCaseCarryingProjection::key($item);
            $priorCents = $carrying['carrying'][$caseKey] ?? 0;
            if (JournalLineAmount::cents($evidence['remaining_foreign']) === 0 && $priorCents === 0) {
                continue;
            }
            $rateCnb = $this->rateFor($currency, $endsOn, $rates);
            $bookedCzk = (float) $evidence['booked_czk'];
            $newCents = JournalLineAmount::cents(bcmul((string) $evidence['remaining_foreign'], (string) $rateCnb, 16));
            $diffCents = $newCents - JournalLineAmount::cents($evidence['booked_czk']);
            $newCzk = (float) bcdiv((string) $newCents, '100', 2);
            $diff = (float) bcdiv((string) $diffCents, '100', 2);

            // Požadovaná kumulativní změna carrying amount na saldokontním účtu:
            // pohledávka roste na MD, závazek roste na D.
            $isReceivable = $item['doc_type'] === 'invoice';
            $desiredCents = $isReceivable ? $diffCents : -$diffCents;
            $incrementCents = $desiredCents - $priorCents;
            $direction = $incrementCents > 0 ? 'gain' : 'loss';

            $detail[] = [
                'doc_type'          => $item['doc_type'],
                'doc_id'            => $item['doc_id'],
                'varsymbol'         => $item['varsymbol'],
                'account_code'      => $item['account_code'],
                'currency_code'     => $currency,
                'remaining_foreign' => $remainingForeign,
                'fx_rate'           => (float) $item['fx_rate'],
                'rate_cnb'          => $rateCnb,
                'booked_czk'        => $bookedCzk,
                'new_czk'           => $newCzk,
                'diff'              => $diff,
                'direction'         => $incrementCents === 0 ? null : $direction,
                'case_id'           => $caseKey,
                'source_tuple'      => $item['source_tuple'] ?? FxCaseTuple::fromRow([]),
                'source_proof_hash' => $item['source_proof_hash'] ?? null,
                'source_line_id'    => (int) ($item['source_line_id'] ?? 0),
                'source_entry_id'   => (int) ($item['source_entry_id'] ?? 0),
                'carrying_before'   => bcdiv((string) $priorCents, '100', 2),
                'incremental_signed' => bcdiv((string) $incrementCents, '100', 2),
            ];

            if ($incrementCents === 0) continue;
            $key = $item['doc_type'] . ':' . ($item['case_document_id'] ?? $item['doc_id']) . '|' . $item['account_id'] . '|' . $currency . '|' . FxCaseTuple::hash($item['source_tuple'] ?? []);
            $desiredGroups[$key] ??= [
                'account_id' => (int) $item['account_id'],
                'account_code' => (string) $item['account_code'],
                'currency_code' => $currency,
                'direction' => $direction,
                'source_tuple' => $item['source_tuple'] ?? FxCaseTuple::fromRow([]),
                'amount_cents' => 0,
                'desired_cents' => 0,
                'booked_cents' => 0,
            ];
            $desiredGroups[$key]['amount_cents'] += $incrementCents;
            $desiredGroups[$key]['desired_cents'] += $desiredCents;
            $desiredGroups[$key]['booked_cents'] += $priorCents;
        }
        $groups = array_map(static fn (array $group): array => [
            'account_id' => $group['account_id'],
            'account_code' => $group['account_code'],
            'currency_code' => $group['currency_code'],
            'direction' => $group['amount_cents'] > 0 ? 'gain' : 'loss',
            'source_tuple' => $group['source_tuple'],
            'amount' => (float) bcdiv((string) abs($group['amount_cents']), '100', 2),
            'desired_signed' => (float) bcdiv((string) $group['desired_cents'], '100', 2),
            'already_booked' => (float) bcdiv((string) $group['booked_cents'], '100', 2),
        ], array_values($desiredGroups));

        // (b) banka / valutová pokladna — poloautomat z potvrzených řádků.
        // R10b: každý účet smí v bank_rows figurovat NEJVÝŠE JEDNOU. accountBalance()
        // níže načítá CELÝ Kč zůstatek účtu z deníku, takže dvakrát zadaný stejný
        // account_code by tentýž zůstatek přecenil dvakrát (dvojí zápis) — a při
        // dvou různých měnách navíc nekonzistentně (dva protichůdné diffy proti
        // jednomu zůstatku). Účet je poslední bod pravdy i pro vstupní cesty mimo
        // Action, proto duplicitu odmítáme tady doménovou výjimkou: buildEntries se
        // pak vůbec nespustí → ŽÁDNÝ zápis (idempotence R6, §24/6 ZoÚ).
        $bankLines = [];
        $seenBankAccounts = [];
        foreach ($bankRows as $row) {
            $currency = strtoupper(trim((string) $row['currency_code']));
            $accountCode = trim((string) $row['account_code']);
            if ($currency === '' || $currency === 'CZK' || $accountCode === '') {
                continue;
            }
            if (isset($seenBankAccounts[$accountCode])) {
                throw new ClosingException(
                    'fx_duplicate_bank_account',
                    'Účet ' . $accountCode . ' je v devizových zůstatcích (bank_rows) uveden vícekrát; '
                        . 'každý bankovní/valutový účet smí být přeceněn právě jednou.',
                );
            }
            // 378/379 se přeceňují sami z deníku (část c) — v bank_rows by se přecenili
            // podruhé a navíc z celého Kč zůstatku včetně korunových pohybů.
            if (ForeignCurrencyAccountPolicy::isLedgerRevalued($accountCode)) {
                throw new ClosingException(
                    'fx_ledger_account_in_bank_rows',
                    'Účet ' . $accountCode . ' se přeceňuje automaticky z deníku; do devizových zůstatků '
                        . 'banky a pokladny nepatří.',
                );
            }
            $seenBankAccounts[$accountCode] = true;
            $foreignBalance = round((float) $row['foreign_balance'], 2);
            $rateCnb = $this->rateFor($currency, $endsOn, $rates);
            // Zůstatek BEZ vlastního slot-2 zápisu — jinak by re-run kroku viděl
            // diff 0 a smazal zákonné přecenění (idempotence R6, §24/6 ZoÚ).
            $ledgerCzk = $this->repo->accountBalance(
                $supplierId,
                $accountCode,
                $endsOn,
                'fx_revaluation',
                ClosingSourceId::fxBank((int) $period['id']),
            );
            $newCzk = round($foreignBalance * $rateCnb, 2);
            $diff = round($newCzk - $ledgerCzk, 2);

            $bankLines[] = [
                'account_code'    => $accountCode,
                'currency_code'   => $currency,
                'foreign_balance' => $foreignBalance,
                'rate_cnb'        => $rateCnb,
                'ledger_czk'      => $ledgerCzk,
                'new_czk'         => $newCzk,
                'diff'            => $diff,
                // Banka/pokladna je aktivum: diff > 0 = zisk.
                'direction'       => self::cents($diff) === 0 ? null : ($diff > 0 ? 'gain' : 'loss'),
            ];
        }

        // (c) peněžní účty 378/379 — automaticky z deníku: cizoměnový zůstatek × kurz ČNB
        // proti Kč hodnotě řádků v téže měně. Zápis jde do slotu banky (stejná metoda:
        // přecenění zůstatku bez storna v novém roce), vlastní zápis slotu se z Kč hodnoty
        // vynechá kvůli re-runu.
        $ledgerLines = [];
        foreach ($this->repo->ledgerFxBalances($supplierId, $endsOn, ClosingSourceId::fxBank((int) $period['id'])) as $row) {
            $currency = (string) $row['currency_code'];
            $rateCnb = $this->rateFor($currency, $endsOn, $rates);
            $newCzk = round((float) $row['foreign_balance'] * $rateCnb, 2);
            $diff = round($newCzk - (float) $row['czk_balance'], 2);
            $ledgerLines[] = [
                'account_code'    => (string) $row['account_code'],
                'label'           => (string) $row['label'],
                'currency_code'   => $currency,
                'foreign_balance' => (float) $row['foreign_balance'],
                'rate_cnb'        => $rateCnb,
                'ledger_czk'      => (float) $row['czk_balance'],
                'new_czk'         => $newCzk,
                'diff'            => $diff,
                // Zůstatky jsou MD-kladné (pohledávka i závazek): diff > 0 = MD účtu = zisk.
                'direction'       => self::cents($diff) === 0 ? null : ($diff > 0 ? 'gain' : 'loss'),
            ];
        }

        $loss = 0.0;
        $gain = 0.0;
        foreach ($groups as $g) {
            $g['direction'] === 'gain' ? $gain += $g['amount'] : $loss += $g['amount'];
        }
        foreach ([...$bankLines, ...$ledgerLines] as $b) {
            if ($b['direction'] === 'gain') {
                $gain += abs($b['diff']);
            } elseif ($b['direction'] === 'loss') {
                $loss += abs($b['diff']);
            }
        }

        return [
            'rate_info' => array_values($rates),
            'saldo'     => ['lines' => array_values($groups), 'detail' => $detail],
            'bank'      => ['lines' => $bankLines],
            'ledger'    => ['lines' => $ledgerLines],
            'totals'    => ['loss' => round($loss, 2), 'gain' => round($gain, 2)],
            'warnings'  => $warnings,
            'case_projection_version' => 1,
            'case_history_proof' => $carrying['proof'],
        ];
    }

    /**
     * Řádky zápisů pro PostingService — slot 1 (saldo) a slot 2 (banka/pokladna).
     * Prázdná sada → zápis se nezakládá (a případný existující ClosingService
     * maže — re-run). Řádky ve formátu postDocument (account_code, side, amount);
     * saldokontní řádek účtu nese FX stopu (R20).
     *
     * @param array<string,mixed> $preview výstup preview()
     * @return array{saldo: list<array<string,mixed>>, bank: list<array<string,mixed>>}
     */
    public function buildEntries(int $supplierId, array $preview, string $entryDate): array
    {
        [$lossAccount, $gainAccount] = $this->resultAccounts($supplierId, $entryDate);

        $saldo = [];
        foreach ($preview['saldo']['lines'] as $g) {
            $amount = round((float) $g['amount'], 2);
            if (self::cents($amount) === 0) {
                continue;
            }
            $trace = [
                'currency_code'  => (string) $g['currency_code'],
                'fx_rate'        => $this->rateFromInfo($preview, (string) $g['currency_code']),
                'amount_foreign' => 0.0,
            ];
            $tuple = $g['source_tuple'] ?? [];
            if ($g['direction'] === 'gain') {
                $saldo[] = ['account_code' => (string) $g['account_code'], 'side' => 'debit', 'amount' => $amount] + $trace + $tuple;
                $saldo[] = ['account_code' => $gainAccount, 'side' => 'credit', 'amount' => $amount] + $tuple;
            } else {
                $saldo[] = ['account_code' => $lossAccount, 'side' => 'debit', 'amount' => $amount] + $tuple;
                $saldo[] = ['account_code' => (string) $g['account_code'], 'side' => 'credit', 'amount' => $amount] + $trace + $tuple;
            }
        }

        $bank = [];
        foreach ([...$preview['bank']['lines'], ...($preview['ledger']['lines'] ?? [])] as $b) {
            $amount = round(abs((float) $b['diff']), 2);
            if (self::cents($amount) === 0) {
                continue;
            }
            // FX stopa na řádku účtu banky/valutové pokladny (211/221): currency_code +
            // fx_rate, amount_foreign = 0 (R20 — přeceněním se cizoměnová částka nemění).
            // BEZ ní by přeceňovací řádek zůstal jako CZK-only pohyb a účet by od 2. období
            // vypadl z ClosingRepository::bankProposals (currency_code IS NULL) → přecenění
            // by se příště tiše nenabídlo. Result účet (563/663) FX stopu nenese.
            $trace = [
                'currency_code'  => (string) $b['currency_code'],
                'fx_rate'        => isset($b['rate_cnb']) ? (float) $b['rate_cnb'] : null,
                'amount_foreign' => 0.0,
            ];
            if ($b['direction'] === 'gain') {
                $bank[] = ['account_code' => (string) $b['account_code'], 'side' => 'debit', 'amount' => $amount] + $trace;
                $bank[] = ['account_code' => $gainAccount, 'side' => 'credit', 'amount' => $amount];
            } else {
                $bank[] = ['account_code' => $lossAccount, 'side' => 'debit', 'amount' => $amount];
                $bank[] = ['account_code' => (string) $b['account_code'], 'side' => 'credit', 'amount' => $amount] + $trace;
            }
        }

        return ['saldo' => $saldo, 'bank' => $bank];
    }

    /**
     * Zrcadlo saldokontní části pro FX storno k 1. dni nového období (R11,
     * slot 3): prohozené strany, stejné částky a FX stopa.
     *
     * @param list<array<string,mixed>> $saldoLines řádky slotu 1 (formát buildEntries)
     * @return list<array<string,mixed>>
     */
    public function buildReversal(array $saldoLines): array
    {
        return array_map(JournalLineAmount::reversal(...), $saldoLines);
    }

    /**
     * Rámec k datu zápisu určuje FX role; dosavadní kontace zůstávají platné pro 500.
     *
     * @return array{0: string, 1: string} [loss, gain]
     */
    private function resultAccounts(int $supplierId, string $entryDate): array
    {
        $lossRule = $this->rules->resolve($supplierId, 'fx.loss');
        $gainRule = $this->rules->resolve($supplierId, 'fx.gain');
        $profile = $this->roles->forDate($supplierId, $entryDate);
        $loss = $profile->account('fx.loss', $lossRule['debit_account_code'] ?? null);
        $gain = $profile->account('fx.gain', $gainRule['credit_account_code'] ?? null);
        return [$loss, $gain];
    }

    /**
     * Kurz ČNB k rozvahovému dni (cache-first + 7denní fallback); memoizace per
     * měna do $rates (jde 1:1 do rate_info). Nedostupný kurz → ClosingException.
     *
     * @param array<string,array{currency:string, rate:float, rate_date:string, fallback_used:bool}> $rates
     */
    private function rateFor(string $currency, string $asOf, array &$rates): float
    {
        if (isset($rates[$currency])) {
            return $rates[$currency]['rate'];
        }
        $info = $this->cnb->getRate($currency, new DateTimeImmutable($asOf));
        if ($info === null) {
            throw new ClosingException(
                'fx_rate_unavailable',
                'Kurz ČNB pro měnu ' . $currency . ' k ' . $asOf . ' není k dispozici.',
            );
        }
        // §24/6/b ZoÚ: kurz musí být vyhlášený K rozvahovému dni — last_known
        // fallback klienta může vrátit kurz s datem PO něm (starší rok, prázdná
        // cache); zpětný fallback je v pořádku, dopředný ne.
        if ((string) $info['rate_date'] > $asOf) {
            throw new ClosingException(
                'fx_rate_unavailable',
                'Kurz ČNB pro měnu ' . $currency . ' k ' . $asOf . ' není k dispozici '
                    . '(nejbližší známý kurz je až z ' . $info['rate_date'] . ' — doplňte kurzy ČNB).',
            );
        }
        $rates[$currency] = [
            'currency'      => $currency,
            'rate'          => (float) $info['rate'],
            'rate_date'     => (string) $info['rate_date'],
            'fallback_used' => (bool) $info['fallback_used'],
        ];
        return $rates[$currency]['rate'];
    }

    /**
     * @param array<string,mixed> $preview
     */
    private function rateFromInfo(array $preview, string $currency): ?float
    {
        foreach ($preview['rate_info'] as $info) {
            if ($info['currency'] === $currency) {
                return (float) $info['rate'];
            }
        }
        return null;
    }

    /**
     * Nepeněžní záloha (§4/12 ZoÚ, ČÚS 006): 314 poskytnutá / 324 přijatá záloha na
     * budoucí plnění se vypořádá dodáním, ne penězi, a k rozvahovému dni se kurzem
     * NEPŘECEŇUJE. Prefixový match kryje i analytiky (3141x, 3241x) — stejný vzor
     * jako guard účtů nákladu v PostingService.
     */
    private static function isNonMonetaryAdvanceAccount(string $accountCode): bool
    {
        $code = trim($accountCode);
        return str_starts_with($code, '314') || str_starts_with($code, '324');
    }

    /** Peníze → haléře (int) — porovnání nikdy přes float ==. */
    private static function cents(float $amount): int
    {
        return (int) round($amount * 100.0);
    }
}
