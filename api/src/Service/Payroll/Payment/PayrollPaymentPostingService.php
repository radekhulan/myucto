<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentPostingRepository;
use MyInvoice\Service\Accounting\Bank\BankAnalyticAssigner;
use MyInvoice\Service\Accounting\Bank\BankAnalyticResolver;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;

/**
 * Účetní protizápis spárované mzdové platby (Ú-16).
 *
 * ── Proč to není prosté „spárováno = zaúčtuj 336/221" ───────────────────────
 * `private/Mzdy/04-UCETNI-MUSTEK.md` říká výslovně: „peněžní účty se v předpisu
 * mzdy nepoužijí; úhradu účtuje banka nebo pokladní doklad." A je to tak
 * správně — pohyb je JEDEN a zaúčtovat ho smí právě jeden modul:
 *
 *   * POKLADNA účtuje vždycky. Pokladní doklad se bez zaúčtování ani nestane
 *     platebním důkazem ({@see PayrollPaymentReconciliationService::assertEvidence()}
 *     vyžaduje `status='posted'`), takže mzdová strana by ho zaúčtovala PODRUHÉ.
 *   * BANKA účtuje někdy. Odvod na předčíslí 0710 chytne
 *     {@see \MyInvoice\Service\Accounting\Bank\Detect\TaxRemittanceDetector} a
 *     zaúčtuje ho 336/221 sám — dokonce na tutéž analytiku, kterou mzdový můstek
 *     kreditoval. Stejně tak vlastní pravidlo firmy v režimu `auto`. Přitom
 *     `match_status` zůstává `unmatched`, takže pohyb dál vyhovuje mzdovému
 *     párování a NIC by tomu druhému zápisu nezabránilo.
 *
 * Mzdy proto NEJSOU druhým účtovacím kanálem. Doplňují jedinou skutečnou díru:
 * nespárovaný bankovní pohyb, na který nesedlo žádné pravidlo ani detektor —
 * ten dnes v deníku nekončí vůbec a zůstatek 331/336/342/379 kvůli němu tiše
 * zůstává otevřený, přestože se závazek reálně zaplatil. Kde už zápis je, mzdy
 * si ho jen POZNAMENAJÍ (`posting_status='posted_elsewhere'`) a nesahají na něj.
 *
 * ── Idempotence ─────────────────────────────────────────────────────────────
 * Nevymýšlí se znovu. Protizápis jde do deníku pod
 * `source_type='payroll_payment'` a `source_id = payroll_payment_matches.id`,
 * takže ho drží `uq_je_supplier_source` z migrace 1007 — jedno spárování, jeden
 * zápis. Před zápisem se navíc čte
 * {@see \MyInvoice\Repository\JournalEntryRepository::findBySourceForUpdate()},
 * aby opakované volání (replay idempotentního požadavku, opakované párování)
 * vrátilo existující zápis místo pádu na unikátní index.
 *
 * ── Storno ──────────────────────────────────────────────────────────────────
 * Nepoužívá se `PostingService::reverse()`. Storno platby je na mzdové straně
 * VLASTNÍ řádek `payroll_payment_matches` se `event_kind='reversed'` a záporným
 * `amount_minor`, takže dostane vlastní zápis s obrácenými stranami a vlastní
 * `source_id`. Obě strany tak zůstávají v deníku vidět a `reversed_by` se nikde
 * nepřepisuje.
 *
 * ── Zámky ───────────────────────────────────────────────────────────────────
 * Žádný vlastní. Roční mzdový zámek už ověřil
 * {@see \MyInvoice\Repository\Payroll\PayrollPaymentMatchRepository::insert()}
 * ({@see \MyInvoice\Service\Payroll\PayrollYearCloseGuard::assertOpenForLiability()}),
 * stav účetního období a `locked_until` hlídá {@see PostingService::postDocument()}.
 * Zamčené období se NEOBCHÁZÍ a zároveň NESMÍ shodit párování platby: účetní
 * musí mít možnost platbu zaevidovat i tehdy, když se do knih zrovna nedostane.
 * `PostingException` se proto zachytí a uloží jako `skipped` s vlastním kódem —
 * díra je pojmenovaná a dohledatelná, ne tichá.
 */
final class PayrollPaymentPostingService
{
    /**
     * Druhy závazků, které mzdový můstek NEÚČTUJE, takže není co odúčtovat.
     *
     * Zákonné pojištění odpovědnosti zaměstnavatele (§ 205d zákoníku práce)
     * i benefity placené třetí straně vznikají vlastním dokladem (přijatá
     * faktura, interní doklad) a v deníku už závazek mají odtud. Shodný seznam
     * drží informativní kategorie `unposted_liabilities` v
     * {@see \MyInvoice\Service\Payroll\Posting\PayrollPostingReconciliationService}.
     *
     * @var list<string>
     */
    private const UNPOSTED_LIABILITY_KINDS = [
        'statutory_insurance',
        'benefit',
        'other',
    ];

    /**
     * Druh závazku → klíč předkontace, na které závazek visí.
     *
     * `net_wage` tu ZÁMĚRNĚ není: závazkový účet čisté mzdy plyne z typu
     * pracovního vztahu (331 zaměstnanec, 366 společník i člen orgánu) a jeden
     * člověk může mít vztahy obojího druhu najednou. Řeší ho
     * {@see self::netWageAccount()}.
     *
     * @var array<string,string>
     */
    private const LIABILITY_ACCOUNT_KEYS = [
        'social_insurance' => 'social_insurance_credit',
        'health_insurance' => 'health_insurance_credit',
        'advance_tax' => 'income_tax_credit',
        'withholding_tax' => 'withholding_tax_credit',
        'deduction' => 'other_deductions_credit',
        'enforcement' => 'enforcement_deductions_credit',
        // Insolvenční srážka sdílí účet s exekuční — je to totéž zadržení
        // z výplaty na cizí pohledávku, jen podle jiného zákona. Rozlišuje je
        // `liability_kind` a reconciliace je počítá v jedné kategorii.
        'insolvency' => 'enforcement_deductions_credit',
        'risky_savings' => 'risky_savings_credit',
    ];

    public function __construct(
        private readonly PayrollPaymentPostingRepository $repository,
        private readonly PostingService $posting,
        private readonly AccountingModeRepository $accountingModes,
        private readonly ?BankAnalyticResolver $bankAnalytics = null,
    ) {}

    /**
     * Zaúčtuje pohyb spárované platby a výsledek si poznamená na spárování.
     *
     * Volá se UVNITŘ transakce párování, hned po založení řádku
     * `payroll_payment_matches` — protizápis a spárování musí vzniknout
     * společně, jinak by pád jednoho nechal druhé viset.
     *
     * @param array{
     *   id:int,
     *   liability_id:int,
     *   event_kind:string,
     *   amount_minor:int,
     *   bank_statement_id:?int,
     *   bank_transaction_id:?int,
     *   cash_document_id:?int,
     *   actual_payment_date:string
     * } $match uložený řádek spárování
     * @return array{status:string,journal_entry_id:?int,reason:?string}
     */
    public function postForMatch(
        int $supplierId,
        array $match,
        ?int $userId,
    ): array {
        $outcome = $this->resolve($supplierId, $match, $userId);
        $this->repository->markPosting(
            $supplierId,
            $match['id'],
            $outcome['status'],
            $outcome['journal_entry_id'],
            $outcome['reason'],
        );

        return $outcome;
    }

    /** @return array{status:string,journal_entry_id:?int,reason:?string} */
    private function resolve(
        int $supplierId,
        array $match,
        ?int $userId,
    ): array {
        $paymentDate = $match['actual_payment_date'];
        $year = (int) substr($paymentDate, 0, 4);
        if ($this->accountingModes->forYear($supplierId, $year) !== 'double_entry') {
            return self::outcome('not_applicable');
        }

        // Pokladna účtuje pohyb vždycky sama — viz třídní docblock.
        $cashDocumentId = $match['cash_document_id'];
        if ($cashDocumentId !== null) {
            $entryId = $this->repository->cashEntryId($supplierId, $cashDocumentId);

            return $entryId === null
                ? self::outcome('skipped', null, 'cash_document_not_posted')
                : self::outcome('posted_elsewhere', $entryId);
        }

        $statementId = $match['bank_statement_id'];
        $transactionId = $match['bank_transaction_id'];
        if ($statementId === null || $transactionId === null) {
            return self::outcome('skipped', null, 'evidence_incomplete');
        }
        $evidence = $this->repository->bankEvidence(
            $supplierId,
            $statementId,
            $transactionId,
        );
        if ($evidence === null) {
            return self::outcome('skipped', null, 'evidence_incomplete');
        }
        if ($evidence['bank_entry_id'] !== null) {
            return self::outcome('posted_elsewhere', $evidence['bank_entry_id']);
        }

        $liability = $this->repository->liability(
            $supplierId,
            $match['liability_id'],
        );
        if ($liability === null) {
            return self::outcome('skipped', null, 'liability_not_found');
        }
        if (in_array(
            $liability['liability_kind'],
            self::UNPOSTED_LIABILITY_KINDS,
            true,
        )) {
            return self::outcome('skipped', null, 'liability_posted_elsewhere');
        }

        $snapshot = $this->repository->revisionSnapshot(
            $supplierId,
            $liability['revision_id'],
        );
        if ($snapshot === null) {
            return self::outcome('skipped', null, 'revision_snapshot_missing');
        }
        $accounts = self::frozenAccounts($snapshot);
        if ($accounts === null) {
            return self::outcome('skipped', null, 'revision_snapshot_missing');
        }

        $liabilityAccount = $liability['liability_kind'] === 'net_wage'
            ? self::netWageAccount($snapshot, $accounts, $liability['employee_id'])
            : self::institutionAccount($accounts, $liability['liability_kind']);
        if ($liabilityAccount === null) {
            return self::outcome(
                'skipped',
                null,
                $liability['liability_kind'] === 'net_wage'
                    ? 'ambiguous_net_wage_account'
                    : 'unknown_liability_account',
            );
        }

        // Znaménko peněz: odchozí závazek peníze UBÍRÁ, příchozí vratka je
        // přidává; storno nese zápornou částku, takže obojí obrací samo.
        $moneySigned = ($liability['direction'] === 'incoming' ? 1 : -1)
            * $match['amount_minor'];
        if ($moneySigned === 0) {
            return self::outcome('skipped', null, 'zero_amount');
        }
        $amount = number_format(abs($moneySigned) / 100, 2, '.', '');
        $moneySide = $moneySigned > 0 ? 'debit' : 'credit';
        $liabilitySide = $moneySigned > 0 ? 'credit' : 'debit';

        $lines = [
            [
                'account_code' => $liabilityAccount,
                'side' => $liabilitySide,
                'amount' => $amount,
            ],
            [
                'account_code' => BankAnalyticAssigner::BANK_SYNTHETIC,
                'side' => $moneySide,
                'amount' => $amount,
            ],
        ];
        // Analytika vlastního bankovního účtu (221.xxx) — týž překlad, jaký
        // dělá bankovní modul, ať úhrada nesedí na jiném účtu než zbytek pohybů
        // z téhož výpisu. Bez resolveru zůstane syntetika 221, kterou má
        // v osnově každá firma.
        $lines = $this->bankAnalytics?->apply(
            $supplierId,
            [
                'recipient_account' => $evidence['recipient_account'],
                'recipient_bank' => $evidence['recipient_bank'],
            ],
            $lines,
        ) ?? $lines;

        try {
            $entryId = $this->posting->postDocument(
                $supplierId,
                'payroll_payment',
                $match['id'],
                $lines,
                [
                    'entry_date' => $paymentDate,
                    'document_no' => 'MZP-' . $match['id'],
                    'description' => 'Úhrada mzdového závazku '
                        . $liability['liability_kind'],
                    'posted' => true,
                    'posted_by' => $userId,
                    'user_id' => $userId,
                ],
            );
        } catch (PostingException $exception) {
            // Zamčené období, chybějící období, uzamčené datum, neznámý účet.
            // Párování platby kvůli tomu spadnout NESMÍ — účetní musí platbu
            // zaevidovat i tehdy, když se do knih zrovna nedostane. Důvod se
            // uloží, takže díra je pojmenovaná, ne tichá.
            return self::outcome(
                'skipped',
                null,
                substr($exception->getMessage(), 0, 64),
            );
        }

        return self::outcome('posted', $entryId);
    }

    /**
     * Zmrazená sada předkontací revize (`employer.accounting_accounts`).
     *
     * Bere se ZMRAZENÁ, ne dnešní: úhrada musí odúčtovat týž účet, který mzdový
     * můstek u té revize kreditoval. S dnešním nastavením by firma, která si
     * mezitím kontaci změnila, odúčtovala jiný účet a obě salda by se rozešla.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,string>|null
     */
    private static function frozenAccounts(array $snapshot): ?array
    {
        $employer = $snapshot['employer'] ?? null;
        $accounts = is_array($employer) ? ($employer['accounting_accounts'] ?? null) : null;
        if (!is_array($accounts) || array_is_list($accounts)) {
            return null;
        }
        $result = [];
        foreach ($accounts as $key => $code) {
            if (is_string($key) && is_string($code) && $code !== '') {
                $result[$key] = $code;
            }
        }

        return $result === [] ? null : $result;
    }

    /**
     * Závazkový účet institucionálního odvodu.
     *
     * Snapshot, který nový klíč nenese, se chová stejně jako při zaúčtování
     * mzdy: degraduje na účet, na kterém závazek u té revize skutečně skončil
     * ({@see PayrollAccountingDefaults::SNAPSHOT_GATED_ACCOUNTS}). Kdyby se
     * použil nový, úhrada by odúčtovala účet, na kterém nikdy nic nebylo.
     *
     * @param array<string,string> $accounts
     */
    private static function institutionAccount(
        array $accounts,
        string $liabilityKind,
    ): ?string {
        $key = self::LIABILITY_ACCOUNT_KEYS[$liabilityKind] ?? null;
        if ($key === null) {
            return null;
        }
        if (!PayrollAccountingDefaults::snapshotAllowsSplit($accounts, $key)) {
            // Doslovná historická hodnota má přednost: rizikové spoření
            // sourozence nemá a účtovalo se na konstantu, ne na firemní účet
            // srážek. Zrcadlo PayrollPostingLineBuilder::riskySavingsAccount().
            $preSplit = PayrollAccountingDefaults::preSplitCode($key);
            if ($preSplit !== null) {
                return $preSplit;
            }
            $key = match ($key) {
                'withholding_tax_credit' => 'income_tax_credit',
                'enforcement_deductions_credit' => 'other_deductions_credit',
                default => $key,
            };
        }
        $code = $accounts[$key] ?? PayrollAccountingDefaults::defaultCode($key);

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Závazkový účet ČISTÉ MZDY jedné osoby.
     *
     * Účet plyne z typu pracovního vztahu (331 zaměstnanec, 366 společník
     * i člen orgánu). Osoba ale může mít vztahy obojího druhu najednou a její
     * čistá mzda je pak rozdělená mezi DVA účty poměrem, který zná jen
     * {@see \MyInvoice\Service\Payroll\Posting\PayrollPostingLineBuilder} se
     * svými vypořádacími koši. Rozdělovat úhradu podle vlastního odhadu by
     * znamenalo druhý zdroj pravdy a haléřový rozjezd obou sald.
     *
     * Vrací se proto účet jen tehdy, když je JEDINÝ. Jinak `null` a platba se
     * poznamená jako nezaúčtovaná s vlastním důvodem — to je pravda, kterou
     * účetní vidí a doúčtuje ručně, na rozdíl od tiše špatného rozdělení.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,string> $accounts
     */
    private static function netWageAccount(
        array $snapshot,
        array $accounts,
        ?int $employeeId,
    ): ?string {
        if ($employeeId === null) {
            return null;
        }
        $people = $snapshot['people'] ?? null;
        if (!is_array($people)) {
            return null;
        }
        $codes = [];
        foreach ($people as $person) {
            if (!is_array($person)) {
                continue;
            }
            $employee = $person['employee'] ?? null;
            if (!is_array($employee)
                || (int) ($employee['id'] ?? 0) !== $employeeId
            ) {
                continue;
            }
            $employments = $person['employments'] ?? null;
            if (!is_array($employments)) {
                return null;
            }
            foreach ($employments as $employment) {
                $identity = is_array($employment)
                    ? ($employment['employment'] ?? null)
                    : null;
                $relationType = is_array($identity)
                    ? ($identity['relation_type'] ?? null)
                    : null;
                if (!is_string($relationType)) {
                    return null;
                }
                try {
                    $keys = PayrollAccountingDefaults::relationAccountKeys($relationType);
                } catch (\InvalidArgumentException) {
                    return null;
                }
                $code = $accounts[$keys['gross_credit']] ?? null;
                if (!is_string($code) || $code === '') {
                    return null;
                }
                $codes[$code] = true;
            }
        }

        return count($codes) === 1 ? (string) array_key_first($codes) : null;
    }

    /** @return array{status:string,journal_entry_id:?int,reason:?string} */
    private static function outcome(
        string $status,
        ?int $journalEntryId = null,
        ?string $reason = null,
    ): array {
        return [
            'status' => $status,
            'journal_entry_id' => $journalEntryId,
            'reason' => $reason,
        ];
    }
}
