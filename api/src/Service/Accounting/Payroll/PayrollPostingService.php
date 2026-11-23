<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\PayrollEmployeeRepository;
use MyInvoice\Repository\PayrollMonthlyRecordRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Payroll\PayrollPeriodOwnedException;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;

/**
 * Zaúčtování měsíční mzdové rekapitulace (Fáze F — dosud se účtovalo ručně).
 *
 * Rozpad počítá {@see PayrollCalculator}, zaúčtování jde VÝHRADNĚ přes
 * {@see PostingService::postDocument()} — jediný zdroj pravdy pro podvojnost,
 * kontrolu období a idempotenci.
 *
 * ── Idempotence ─────────────────────────────────────────────────────────────
 * `source_type='manual'`, `source_id` = RRRRMM. Unikát `uq_je_supplier_source`
 * drží nejvýš jeden zápis na měsíc; opakované volání ho přepíše (PostingService
 * to řeší přes rewriteExisting), nezaloží druhý. Tenhle tvar `source_id` mají
 * i stávající ručně zaúčtované mzdy 2024–2026, takže na ně kalkulátor navazuje.
 *
 * ── Volitelná vazba na zaměstnance (mzdový list, §38j ZDP) ──────────────────
 * Když `post()` dostane `$employeeId`, NAVÍC (beze změny zaúčtování) uloží snapshot
 * rozpadu + měsíčních slev do `payroll_monthly_records` přes
 * {@see PayrollMonthlyRecordRepository} — to je jediný zdroj dat pro
 * {@see \MyInvoice\Service\Accounting\Payroll\PayrollSheetService}.
 */
final class PayrollPostingService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly TaxConstantsRepository $taxConstants,
        private readonly PayrollEmployeeRepository $employees,
        private readonly PayrollMonthlyRecordRepository $records,
        private readonly PayrollPeriodOwnershipService $periodOwnership,
        private readonly PayrollPostingAccountResolver $accountResolver,
    ) {}

    /**
     * Náhled rozpadu bez zápisu do deníku.
     *
     * Se zadaným `$supplierId` a `$employeeId` čte kartu zaměstnance STEJNĚ jako
     * {@see post()} — jinak by náhled ukazoval slevy z požadavku a zaúčtování je přebilo
     * kartou. Rozdíl byl tichý a po zavedení vazby na podepsané prohlášení narostl:
     * náhled mohl slevu uplatnit a zaúčtování ne.
     *
     * @param string $taxpayerType typ poplatníka (kontace 521/331 vs. 522/366). Se zadaným
     *        zaměstnancem se IGNORUJE — rozhoduje karta, stejně jako u slev.
     * @param bool $taxpayerCredit poplatník má podepsané prohlášení (§38k ZDP) →
     *        uplatní se měsíční sleva na poplatníka. Se zadaným zaměstnancem se
     *        IGNORUJE — rozhoduje karta.
     * @param int $childCount počet vyživovaných dětí pro daňové zvýhodnění (§35c)
     *
     * @return array{
     *   year:int, month:int, source_id:int, entry_date:string, taxpayer_type:string,
     *   taxpayer_credit:bool, child_count:int,
     *   credits:array{taxpayer:int,children:int,total:int},
     *   breakdown:array<string,int>,
     *   accounts:array<string,string>,
     *   lines:list<array{account_code:string,side:string,amount:float,description?:string}>
     * }
     */
    public function preview(
        int $year,
        int $month,
        float $gross,
        string $taxpayerType,
        bool $taxpayerCredit = false,
        int $childCount = 0,
        ?float $ytdSocialBase = null,
        ?int $supplierId = null,
        ?int $employeeId = null,
    ): array {
        $this->assertPeriod($year, $month);
        $this->assertType($taxpayerType);

        // Kontace firmy (analytika účetní / `payroll_employer_settings`); bez známé
        // firmy zůstávají syntetiky — náhled bez `supplier_id` se do deníku nedostane.
        $accounts = $supplierId !== null
            ? $this->accountResolver->forSupplier($supplierId)
            : PayrollPostingAccounts::defaults();

        $settlementAccount = null;
        if ($supplierId !== null && $employeeId !== null) {
            [$taxpayerType, $taxpayerCredit, $childCount, $ytdSocialBase, $settlementAccount] =
                $this->employeeContext($supplierId, $employeeId, $year, $month)
                ?? [$taxpayerType, $taxpayerCredit, $childCount, $ytdSocialBase, null];
            // Typ z karty prošel jen CHECK constraintem, ne PayrollCalculator::types() —
            // starší řádek s hodnotou, kterou kalkulátor nezná, musí spadnout tady,
            // ne až na chybějícím účtu v kontaci.
            $this->assertType($taxpayerType);
        }

        // forExactYear(): mzdový rozpad se NESMÍ tiše počítat sazbami jiného roku —
        // doplatek do min. VZ je na roce přímo závislý (viz PayrollCalculator).
        $constants = $this->taxConstants->forExactYear($year);

        // § 6 odst. 4 ZDP — DPP do limitu bez podepsaného prohlášení jde SRÁŽKOVOU daní,
        // ne zálohovou. Musí se rozhodnout PŘED výpočtem: srážka je samostatný základ
        // daně, na který se slevy neuplatňují a ze kterého se neodvádí pojistné, takže
        // běžný rozpad by dal úplně jiná čísla.
        $employee = $supplierId !== null && $employeeId !== null
            ? $this->employees->find($supplierId, $employeeId)
            : null;
        // Měsíc, který už zaevidovaný JE, se dalším zaúčtováním nepřičte, ale přepíše
        // (`uq_pmr_employee_period`). U DPP na tom záleží věcně: § 6 odst. 4 ZDP testuje
        // ÚHRN odměn od téhož plátce za měsíc, takže druhá dohoda zaúčtovaná zvlášť by
        // dostala srážkovou daň z části místo zálohové z celku.
        $replaces = $supplierId !== null && $employeeId !== null
            ? $this->records->grossForMonth($supplierId, $employeeId, $year, $month)
            : null;

        $withholding = $employee === null ? null : $this->withholdingFor($employee, $constants, $gross);
        if ($withholding !== null) {
            $preview = $this->withholdingPreview($year, $month, $taxpayerType, $withholding, $accounts);
            $preview['replaces_gross'] = $replaces;
            $preview['warnings'] = array_merge(
                $preview['warnings'] ?? [],
                self::replacementWarnings($replaces, $gross),
            );

            return $preview;
        }

        $credits = PayrollCalculator::monthlyCredits($constants, $taxpayerCredit, $childCount);
        // §15a se uplatní jen se známým ročním základem KONKRÉTNÍHO zaměstnance;
        // nad rekapitulací za všechny by strop srazil pojistné celé firmě.
        $breakdown = PayrollCalculator::compute($gross, $constants, $credits, $ytdSocialBase);
        $warnings = self::replacementWarnings($replaces, $gross);
        $letterB = $employee === null ? null : WithholdingTaxCalculator::letterBNotice(
            (string) ($employee['employment_type'] ?? 'hpp'),
            $gross,
            $constants,
            (bool) ($employee['tax_declaration_signed'] ?? 0),
        );
        if ($letterB !== null) {
            $warnings[] = $letterB;
        }

        return [
            'year'            => $year,
            'month'           => $month,
            'source_id'       => self::sourceId($year, $month),
            'entry_date'      => self::entryDate($year, $month),
            'taxpayer_type'   => $taxpayerType,
            'taxpayer_credit' => $taxpayerCredit,
            'child_count'     => max(0, $childCount),
            'credits'         => $credits,
            'breakdown'       => $breakdown,
            'settlement_account' => $settlementAccount,
            'accounts'        => $accounts->toMap(),
            'lines'           => PayrollCalculator::lines($breakdown, $taxpayerType, $settlementAccount, $accounts),
            'replaces_gross' => $replaces,
            'warnings'        => $warnings,
        ];
    }

    /**
     * Upozornění, že se už zaevidovaný měsíc PŘEPÍŠE, ne doplní.
     *
     * @return list<string>
     */
    private static function replacementWarnings(?float $replaces, float $gross): array
    {
        if ($replaces === null || abs($replaces - $gross) < 0.005) {
            return [];
        }

        return [sprintf(
            'Za tenhle měsíc už je zaevidovaná hrubá mzda %s Kč — zaúčtováním se NAHRADÍ '
                . 'částkou %s Kč, nepřičte se k ní. Vyplácíte-li v měsíci víc dohod, zadejte '
                . 'jejich součet (§ 6 odst. 4 ZDP počítá úhrn u téhož plátce).',
            number_format($replaces, 2, ',', ' '),
            number_format($gross, 2, ',', ' '),
        )];
    }

    /**
     * Zaúčtuje rekapitulaci. Idempotentní na (supplier, RRRRMM).
     *
     * `$employeeId` je VOLITELNÝ; když je zadaný, jeho evidovaný TYP POPLATNÍKA, prohlášení
     * a počet dětí PŘEBIJÍ hodnoty z požadavku — karta zaměstnance je zdroj pravdy, takže
     * se zaúčtování nemůže rozejít se snapshotem pro mzdový list (§38j).
     *
     * @param array<string,mixed> $meta auditní meta (user_id, ip, user_agent)
     * @return array{journal_entry_id:int, source_id:int, breakdown:array<string,int>}
     */
    public function post(
        int $supplierId,
        int $year,
        int $month,
        float $gross,
        string $taxpayerType,
        array $meta = [],
        ?int $employeeId = null,
        bool $taxpayerCredit = false,
        int $childCount = 0,
    ): array {
        // Odvolanou transakci (1020/1213) zopakuje, jen když ji vlastní. Cron by jinak
        // nahlásil chybu a mzda by zůstala nezaúčtovaná.
        return TransactionFailure::retrying(
            $this->db->pdo(),
            fn (): array => $this->postOnce($supplierId, $year, $month, $gross, $taxpayerType, $meta, $employeeId, $taxpayerCredit, $childCount),
        );
    }

    /**
     * @param array<string,mixed> $meta
     * @return array{journal_entry_id:int, source_id:int, breakdown:array<string,int>}
     */
    private function postOnce(
        int $supplierId,
        int $year,
        int $month,
        float $gross,
        string $taxpayerType,
        array $meta,
        ?int $employeeId,
        bool $taxpayerCredit,
        int $childCount,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $result = $this->postTransactional(
                $supplierId,
                $year,
                $month,
                $gross,
                $taxpayerType,
                $meta,
                $employeeId,
                $taxpayerCredit,
                $childCount,
            );
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $meta
     * @return array{journal_entry_id:int, source_id:int, breakdown:array<string,int>}
     */
    private function postTransactional(
        int $supplierId,
        int $year,
        int $month,
        float $gross,
        string $taxpayerType,
        array $meta,
        ?int $employeeId,
        bool $taxpayerCredit,
        int $childCount,
    ): array {
        try {
            $this->periodOwnership->claimLegacy(
                $supplierId,
                $year,
                $month,
                self::sourceId($year, $month),
                isset($meta['user_id']) ? (int) $meta['user_id'] : null,
            );
        } catch (PayrollPeriodOwnedException $e) {
            throw new PostingException('payroll_period_owned', $e->getMessage(), 409);
        }

        // Zaměstnance je nutné načíst PŘED výpočtem — jeho slevy určují, co se
        // zaúčtuje na 342, ne jen co se uloží do mzdového listu.
        $employee = $employeeId === null ? null : $this->employees->find($supplierId, $employeeId);
        $ytdSocialBase = null;
        $context = $employeeId === null ? null : $this->employeeContext($supplierId, $employeeId, $year, $month);
        if ($context !== null) {
            [$taxpayerType, $taxpayerCredit, $childCount, $ytdSocialBase] = $context;
        }

        // supplierId/employeeId se PŘEDÁVAJÍ dál — jinak by se v náhledu neuplatnila
        // srážková daň u DPP a zaúčtování by se rozešlo s tím, co uživatel viděl.
        $preview = $this->preview(
            $year, $month, $gross, $taxpayerType,
            $taxpayerCredit, $childCount, $ytdSocialBase,
            $supplierId, $employeeId,
        );

        $entryId = $this->posting->postDocument(
            $supplierId,
            'manual',
            $preview['source_id'],
            PayrollCalculator::pairGroups($preview['lines']),
            array_merge($meta, [
                'entry_date'  => $preview['entry_date'],
                // Popis se bere z NÁHLEDU, ne ze vstupu: s vybraným zaměstnancem rozhoduje
                // o kontaci typ z karty, a text „mzda zaměstnance" u zápisu 522/366 by
                // v deníku popíral, co se doopravdy zaúčtovalo.
                'description' => self::description($year, $month, $preview['taxpayer_type']),
                'posted'      => true,
                'posted_by'   => $meta['user_id'] ?? null,
            ]),
        );

        if ($employee !== null) {
            // Slevy už jsou promítnuté v rozpadu, takže snapshot je s deníkem
            // shodný z definice — nepřepočítává se podruhé.
            $this->records->upsert(
                $supplierId,
                (int) $employeeId,
                $year,
                $month,
                $preview['breakdown'],
                $preview['credits'],
                $preview['breakdown']['advance_tax_withheld'],
                $preview['breakdown']['net'],
                $entryId,
            );
        }

        return [
            'journal_entry_id' => $entryId,
            'source_id'        => $preview['source_id'],
            'breakdown'        => $preview['breakdown'],
        ];
    }

    /**
     * Rozpad a kontace pro srážkovou daň. Tvar odpovědi je shodný se zálohovým režimem,
     * aby volající nemusel větvit — liší se obsahem, ne strukturou.
     *
     * Kontace je JEDNODUŠŠÍ než u zálohové daně, a to věcně: žádné pojistné (do limitu se
     * z DPP neodvádí), žádné slevy. Zůstává jen hrubá mzda a sražená daň.
     *
     * @param array<string,mixed> $w výsledek WithholdingTaxCalculator::compute()
     * @return array<string,mixed>
     */
    private function withholdingPreview(
        int $year,
        int $month,
        string $taxpayerType,
        array $w,
        ?PayrollPostingAccounts $accounts = null,
    ): array {
        $accounts ??= PayrollPostingAccounts::defaults();
        $pair = $accounts->forType($taxpayerType);
        $lines = [
            ['account_code' => $pair['expense'], 'side' => 'debit',  'amount' => (float) $w['gross'], 'description' => 'Odměna z DPP'],
            ['account_code' => $pair['payable'], 'side' => 'credit', 'amount' => (float) $w['gross'], 'description' => 'Odměna z DPP'],
        ];
        if ($w['tax'] > 0) {
            $lines[] = ['account_code' => $pair['payable'], 'side' => 'debit',  'amount' => (float) $w['tax'], 'description' => 'Srážková daň (§ 6/4 ZDP)'];
            $lines[] = ['account_code' => $accounts->incomeTaxPayable, 'side' => 'credit', 'amount' => (float) $w['tax'], 'description' => 'Srážková daň (§ 6/4 ZDP)'];
        }

        return [
            'year'            => $year,
            'month'           => $month,
            'source_id'       => self::sourceId($year, $month),
            'entry_date'      => self::entryDate($year, $month),
            'taxpayer_type'   => $taxpayerType,
            // Slevy se na samostatný základ daně neuplatňují — proto natvrdo false/0,
            // ne převzatá hodnota z karty.
            'taxpayer_credit' => false,
            'child_count'     => 0,
            'credits'         => ['taxpayer' => 0, 'children' => 0, 'total' => 0],
            'withholding'     => $w,
            'accounts'        => $accounts->toMap(),
            'breakdown'       => [
                'gross'                => $w['gross'],
                'employee_deductions'  => 0,
                'advance_tax'          => 0,
                'advance_tax_withheld' => $w['tax'],
                'net'                  => $w['net'],
                'employer_total'       => 0,
                'remittance_total'     => $w['tax'],
                'below_participation'  => true,
            ],
            'lines'           => $lines,
            'warnings'        => $w['warnings'],
        ];
    }

    /**
     * § 6 odst. 4 ZDP — srážková daň z dohody o provedení práce.
     *
     * DPP do limitu u JEDNOHO zaměstnavatele a BEZ podepsaného prohlášení tvoří
     * SAMOSTATNÝ ZÁKLAD DANĚ zdaněný srážkou. Není to varianta zálohové daně: neuplatňují
     * se na ni slevy (§ 35ba), nevstupuje do ročního zúčtování ani do přiznání a do limitu
     * se z ní neodvádí sociální ani zdravotní pojištění.
     *
     * {@see WithholdingTaxCalculator} tenhle výpočet uměl už dřív, ale nikdo ho nevolal —
     * firma s dohodáři musela mzdy počítat mimo systém. Tohle je to chybějící zapojení.
     *
     * Vrací `null`, když se srážka neuplatní (jiný typ vztahu, podepsané prohlášení,
     * překročený limit) — v tom případě platí běžný zálohový režim.
     *
     * Spočítat tu jde jen písmeno a) (DPP), viz
     * {@see WithholdingTaxCalculator::reasonForEmploymentType()}. Písmeno b) dopadá
     * na každý druh vztahu včetně výkonu funkce podle § 59 ZOK, rekapitulace ho ale
     * nepočítá a jen na něj upozorní ({@see WithholdingTaxCalculator::letterBNotice()}).
     *
     * @param array<string,mixed> $employee karta zaměstnance
     * @param array<string,mixed> $constants roční daňové konstanty
     * @return array<string,mixed>|null
     */
    private function withholdingFor(array $employee, array $constants, float $gross): ?array
    {
        $reason = WithholdingTaxCalculator::reasonForEmploymentType(
            (string) ($employee['employment_type'] ?? 'hpp')
        );
        if ($reason === null) {
            return null;
        }
        $declarationSigned = (bool) ($employee['tax_declaration_signed'] ?? 0);

        if (!WithholdingTaxCalculator::applies($reason, $gross, $constants, $declarationSigned)) {
            return null;
        }

        return WithholdingTaxCalculator::compute($reason, $gross, $constants, $declarationSigned);
    }

    /**
     * Typ poplatníka, slevy a roční kontext z karty zaměstnance — JEDINÝ zdroj pravdy
     * pro náhled i zaúčtování.
     *
     * Dřív tuhle logiku měl jen `post()`; `preview()` počítal z hodnot v požadavku
     * a zaúčtování je pak přebilo kartou. Rozdíl byl tichý a po zavedení vazby na
     * podepsané prohlášení narostl — náhled mohl slevu uplatnit a zaúčtování ne.
     * Sdílená metoda ten rozpor vylučuje konstrukčně, ne disciplínou.
     *
     * TYP POPLATNÍKA se z karty do teď nebral, ačkoli slevy ano — formulář mohl mít
     * „zaměstnanec" (521/331), zatímco karta říká „jednatel-společník" (522/366),
     * a zaúčtovalo se na jiné účty, než co ukazoval náhled. Nekonzistence tím spíš,
     * že kontace je to jediné, co typ poplatníka rozhoduje.
     *
     * @return array{0:string, 1:bool, 2:int, 3:?float, 4:?string}|null `null` = zaměstnanec neexistuje
     */
    private function employeeContext(int $supplierId, int $employeeId, int $year, int $month): ?array
    {
        $employee = $this->employees->find($supplierId, $employeeId);
        if ($employee === null) {
            return null;
        }

        // § 38h odst. 4 a § 38k odst. 4 ZDP — zálohu lze snížit o měsíční slevu JEN
        // u plátce, u kterého má poplatník podepsané prohlášení. `tax_credit_taxpayer`
        // říká, že na slevu nárok MÁ; `tax_declaration_signed`, že ji lze uplatnit
        // právě tady. Druhá podmínka se do teď ignorovala, takže se sleva uplatnila
        // i bez prohlášení — srazilo se míň a za nesraženou zálohu ručí plátce
        // (§ 38s). Ověřeno proti dokladům účetní za 06/2026: rozdíl 675 Kč.
        // Mzdový modul odvozuje slevu z podpisu sám (TaxpayerCreditEntitlement);
        // tahle jednoduchá karta má pro slevu vlastní zaškrtávátko, které se
        // zakládá zapnuté, proto zůstává čtené odděleně.
        $taxpayerCredit = (bool) $employee['tax_credit_taxpayer']
            && (bool) ($employee['tax_declaration_signed'] ?? 0);

        // Účet pro přeúčtování čisté mzdy (1178) — prázdný řetězec z DB je totéž co NULL.
        $settlement = trim((string) ($employee['net_settlement_account_code'] ?? ''));

        return [
            (string) $employee['taxpayer_type'],
            $taxpayerCredit,
            (int) $employee['child_count'],
            // Se známým zaměstnancem lze uplatnit strop §15a — dosavadní roční základ
            // vezmeme z jeho mzdových listů za předchozí měsíce téhož roku.
            $this->records->socialBaseYearToDate($supplierId, $employeeId, $year, $month),
            $settlement === '' ? null : $settlement,
        ];
    }

    /** RRRRMM — shodné s dosud ručně účtovanými mzdami. */
    public static function sourceId(int $year, int $month): int
    {
        return $year * 100 + $month;
    }

    /** Mzda se účtuje k poslednímu dni měsíce, za který náleží. */
    public static function entryDate(int $year, int $month): string
    {
        return (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
            ->modify('last day of this month')
            ->format('Y-m-d');
    }

    private static function description(int $year, int $month, string $taxpayerType): string
    {
        $what = $taxpayerType === PayrollCalculator::TYPE_MANAGING_PARTNER
            ? 'odměna jednatele-společníka'
            : 'mzda zaměstnance';
        return sprintf('Mzdová rekapitulace %02d/%04d — %s', $month, $year, $what);
    }

    private function assertPeriod(int $year, int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Měsíc musí být 1–12.');
        }
        if ($year < 2018 || $year > 2100) {
            throw new \InvalidArgumentException('Neplatný rok.');
        }
    }

    private function assertType(string $taxpayerType): void
    {
        if (!in_array($taxpayerType, PayrollCalculator::types(), true)) {
            throw new \InvalidArgumentException('Neznámý typ poplatníka: ' . $taxpayerType);
        }
    }
}
