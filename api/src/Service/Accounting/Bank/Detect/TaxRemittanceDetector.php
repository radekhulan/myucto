<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank\Detect;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\Accounting\Payroll\PayrollPostingAccountResolver;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollPaymentIdentifierResolver;
use PDO;

final class TaxRemittanceDetector implements BankTransactionDetector
{
    /**
     * Kolik dřívějších, člověkem potvrzených odvodů na TÝŽ účet a TÝŽ VS nahradí chybějící
     * ověření variabilního symbolu. Tři je stejná laťka, jakou {@see \MyInvoice\Service\Accounting\AutoPostingPolicyService}
     * vyžaduje po uživatelském pravidle (`hit_count >= 3`), než ho pustí do plné automatiky.
     */
    private const CONFIRMED_REPETITIONS = 3;

    /** Jak daleko zpět od data platby se opakování hledá (shodně s učením kontací). */
    private const CONFIRMED_HISTORY_DAYS = 400;

    public function __construct(
        private readonly Connection $db,
        private readonly PostingRuleRepository $postingRules,
        private readonly PayrollPaymentIdentifierResolver $payrollIdentifiers,
        private readonly PayrollModuleAccess $payrollAccess,
        private readonly PayrollPostingAccountResolver $payrollAccounts,
    ) {}

    public function key(): string
    {
        return 'tax_remittance';
    }

    public function tier(): int
    {
        return 10;
    }

    public function detect(int $supplierId, array $tx): ?DetectionResult
    {
        if ((float) ($tx['amount'] ?? 0) >= 0) {
            return null;
        }
        $bank = AccountNumberNormalizer::canonicalBankCode(
            isset($tx['counterparty_bank']) ? (string) $tx['counterparty_bank'] : null,
            isset($tx['counterparty_account']) ? (string) $tx['counterparty_account'] : null,
        );
        if ($bank !== '0710') {
            return null;
        }
        $account = (string) ($tx['counterparty_account'] ?? '');
        // Nekorunová platba na účet u ČNB je u ostatních odvodů signál „tohle není odvod",
        // ale daň v režimu jednoho správního místa se odvádí v EURECH (přiznání i platba
        // jsou v měně podání), takže korunová podmínka ji vyřazovala dřív, než se vůbec
        // dala poznat — a platba pak končila jako neurčená. Výjimka se neváže na měnu,
        // ale na PŘÍJEMCE, a bere se z mapy odvodů, aby číslo účtu nebylo zadrátované
        // ve dvou různých vrstvách.
        if (strtoupper((string) ($tx['currency'] ?? $tx['statement_currency'] ?? 'CZK')) !== 'CZK'
            && !$this->isForeignCurrencyRemittanceAccount($account)
        ) {
            return null;
        }

        $vs = VariableSymbolNormalizer::forMatching((string) ($tx['variable_symbol'] ?? ''));
        if ($vs !== '') {
            $schedule = $this->schedule($supplierId, $vs, (string) $tx['posted_at']);
            if ($schedule !== null) {
                $ruleKey = match ((string) $schedule['advance_kind']) {
                    'tax' => 'tax.income.advance.paid',
                    'social' => 'insurance.social.paid',
                    'health' => 'insurance.health.paid',
                    default => null,
                };
                $operation = match ((string) $schedule['advance_kind']) {
                    'tax' => OperationType::REMITTANCE_INCOME,
                    'social' => OperationType::REMITTANCE_SOCIAL,
                    'health' => OperationType::REMITTANCE_HEALTH,
                    default => null,
                };
                if ($ruleKey !== null && $operation !== null) {
                    $difference = abs(abs((float) $tx['amount']) - (float) $schedule['amount']);
                    return $this->fromRule(
                        $supplierId,
                        $operation,
                        'schedule',
                        $difference <= 100.00001 ? 0.95 : 0.70,
                        $ruleKey,
                        'Platba předpisu zálohy ' . (string) $schedule['due_date'],
                        (int) $schedule['id'],
                        $difference <= 100.00001 ? null : 'schedule_amount_differs',
                        $difference <= 100.00001,
                    );
                }
            }
        }

        $supplier = $this->supplierIdentifiers($supplierId);
        if ($supplier === null) {
            return null;
        }
        $employerIdentifier = $this->payrollIdentifiers->matchEmployerRemittance(
            $supplierId,
            $vs,
            (new DateTimeImmutable((string) $tx['posted_at']))->format('Y-m-d'),
            $account,
            isset($tx['counterparty_bank']) ? (string) $tx['counterparty_bank'] : null,
        );
        if (($employerIdentifier['ambiguous'] ?? false) === true) {
            return $this->fromRule(
                $supplierId,
                OperationType::REMITTANCE_OTHER,
                'detector',
                0.40,
                'insurance.social.paid',
                'Nejednoznačný identifikátor odvodu zaměstnavatele',
                null,
                'remittance_unclassified',
                false,
            );
        }
        $vsType = $employerIdentifier === null
            ? $this->vsType($vs, $supplier, $this->payrollAccess->isEnabled($supplierId))
            : match ($employerIdentifier['operation_type']) {
                OperationType::REMITTANCE_SOCIAL_EMPLOYER => 'cssz_vsdp',
                OperationType::REMITTANCE_HEALTH_EMPLOYER => 'health_insurance_number',
                default => 'other',
            };
        $mapTaxpayerType = $employerIdentifier === null
            ? (string) ($supplier['taxpayer_type'] ?? 'fo')
            : 'po';
        $prefix = AccountNumberNormalizer::czechAccountPrefix($account);
        $base = AccountNumberNormalizer::czechAccountBase($account);
        $map = $this->map($vsType, $mapTaxpayerType, $prefix, $base);
        if ($map === null) {
            return null;
        }
        if ($employerIdentifier !== null
            && !$employerIdentifier['legacy_fallback']
            && (string) $map['operation_type'] !== $employerIdentifier['operation_type']
        ) {
            return $this->fromRule(
                $supplierId,
                OperationType::REMITTANCE_OTHER,
                'detector',
                0.40,
                'insurance.social.paid',
                'Neshoda účtu a identifikátoru odvodu zaměstnavatele',
                null,
                'remittance_unclassified',
                false,
            );
        }
        $specificVs = $vsType !== 'other' && (string) $map['vs_type'] === $vsType;
        $specificPrefix = $prefix !== null && $map['account_prefix'] !== null;
        // Zdravotní pojišťovny nemají předčíslí — jejich účet pojistného je celé číslo
        // (VZP 1111006311/0710). Konkrétní účet je proto stejně silný identifikátor jako
        // předčíslí u FÚ: platí se z něj jediná věc a nejde splést s jiným příjemcem.
        // Navíc drží i tehdy, když banka do VS pošle DIČ místo čísla pojištěnce.
        $specificAccount = $base !== null && $map['account_number'] !== null;
        $fallback = (string) $map['vs_type'] === 'other'
            && $map['account_prefix'] === null && $map['account_number'] === null;
        $institutionAccountMatch = $employerIdentifier['account_match'] ?? false;
        $legacyFallback = $employerIdentifier['legacy_fallback'] ?? false;
        $identified = $institutionAccountMatch
            || (($specificVs || $specificAccount) && ($specificPrefix || $specificAccount));
        // Opakování jako náhrada za neověřený VS.
        //
        // Předčíslí účtu u ČNB říká DRUH odvodu (21012 = pojistné na sociální zabezpečení),
        // VS říká, ČÍ odvod to je. Zdravotní pojišťovny mají místo předčíslí celé číslo účtu,
        // takže jim samotný účet stačí (viz `$specificAccount`) — u ČSSZ se ale matriková část
        // liší podle OSSZ, mapa proto drží jen předčíslí a bez ověřeného VS spadne jistota na
        // 0,70. Firma, která VS zaměstnavatele nikde nemá zadaný (právnická osoba s vypnutými
        // Mzdami nemá kam), tak zůstane navěky na ručním schvalování téhož odvodu každý měsíc.
        //
        // Chybějící ověření nahradí DŮKAZ, ne sleva z laťky: tentýž účet a tentýž VS už člověk
        // opakovaně potvrdil jako tento druh odvodu. Práh 0,90 zůstává nedotčený, jen se uzná
        // druhý zdroj identifikace. Vlastní ochrany dál platí — kontace se pořád odvozuje
        // z mapy, nikoli z historie, a vybočení částky degraduje na návrh přes anomálie.
        if (!$identified && !$fallback && !$legacyFallback && $specificPrefix
            && $this->hasConfirmedRepetitions($supplierId, $tx, (string) $map['operation_type'])
        ) {
            $identified = true;
        }
        $confidence = $legacyFallback
            ? 0.70
            : ($fallback
            ? 0.40
            : ($identified ? 0.90 : 0.70));
        return $this->fromRule(
            $supplierId,
            (string) $map['operation_type'],
            'detector',
            $confidence,
            (string) $map['rule_key'],
            (string) $map['label_cs'],
            null,
            $legacyFallback
                ? 'remittance_unclassified'
                : ($fallback ? 'remittance_unclassified' : null),
            !$legacyFallback && (bool) $map['auto_allowed'],
        );
    }

    /**
     * Potvrdil už člověk nejméně {@see self::CONFIRMED_REPETITIONS}× TÝŽ odvod?
     *
     * Shoda je úmyslně úzká — stejný účet protistrany, stejný kód banky, stejný VS a stejný
     * druh odvodu. Počítají se jen návrhy, které člověk SCHVÁLIL (`reviewed_by`), nikdy
     * automaticky zaúčtované: jinak by jedno chybné zaúčtování zplodilo důkaz samo pro sebe
     * a jistota by se vyšplhala bez jediného lidského rozhodnutí. Transakce, u které si
     * uživatel kontaci přepsal (`approve_override`) nebo ji zaúčtoval ručně mimo návrh,
     * se nepočítá — potvrzením detekce zjevně nebyla.
     *
     * @param array<string,mixed> $tx
     */
    private function hasConfirmedRepetitions(int $supplierId, array $tx, string $operationType): bool
    {
        $account = isset($tx['counterparty_account']) ? (string) $tx['counterparty_account'] : '';
        $normalizedAccount = AccountNumberNormalizer::normalize($account);
        $vs = VariableSymbolNormalizer::digits((string) ($tx['variable_symbol'] ?? ''));
        if ($normalizedAccount === '' || $vs === '') {
            return false;
        }
        $date = (new DateTimeImmutable((string) $tx['posted_at']))->format('Y-m-d');
        $bank = isset($tx['counterparty_bank']) ? (string) $tx['counterparty_bank'] : null;

        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(DISTINCT s.bank_transaction_id)
               FROM bank_posting_suggestions s
               JOIN bank_transactions bt ON bt.id = s.bank_transaction_id
              WHERE s.supplier_id = ?
                AND s.status = 'approved'
                AND s.reviewed_by IS NOT NULL
                AND s.operation_type = ?
                AND s.bank_transaction_id <> ?
                AND bt.amount < 0
                AND bt.posted_at < ?
                AND bt.posted_at >= DATE_SUB(?, INTERVAL " . self::CONFIRMED_HISTORY_DAYS . " DAY)
                AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bt.counterparty_account, ''), '[^0-9]', '')) = ?
                AND REGEXP_REPLACE(IFNULL(bt.variable_symbol, ''), '[^0-9]', '') = ?
                AND (? IS NULL OR bt.counterparty_bank IS NULL OR bt.counterparty_bank = ?)
                AND NOT EXISTS (
                    SELECT 1 FROM accounting_corrections c
                     WHERE c.supplier_id = s.supplier_id
                       AND c.entity_type = 'bank_transaction'
                       AND c.entity_id = s.bank_transaction_id
                       AND c.event_type IN ('approve_override', 'manual_post')
                )"
        );
        $stmt->execute([
            $supplierId,
            $operationType,
            (int) ($tx['id'] ?? 0),
            $date,
            $date,
            $normalizedAccount,
            $vs,
            $bank,
            $bank,
        ]);

        return (int) $stmt->fetchColumn() >= self::CONFIRMED_REPETITIONS;
    }

    /** @return array<string,mixed>|null */
    private function schedule(int $supplierId, string $vs, string $postedAt): ?array
    {
        $date = (new DateTimeImmutable($postedAt))->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, advance_kind, amount, due_date
               FROM tax_advance_schedules
              WHERE supplier_id = ? AND status = 'planned' AND variable_symbol = ?
                AND due_date BETWEEN DATE_SUB(?, INTERVAL 31 DAY) AND DATE_ADD(?, INTERVAL 31 DAY)
              ORDER BY ABS(DATEDIFF(due_date, ?)), id
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $vs, $date, $date, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Vede mapa odvodů pro tenhle účet druh odvodu, který se platí v CIZÍ MĚNĚ?
     *
     * Dnes je takový jediný — daň v režimu jednoho správního místa (§ 110 a násl. ZDPH),
     * kterou Finanční správa přijímá v eurech. Ptá se ale MAPY, ne konstanty v kódu:
     * číslo účtu je data (migrace 1301) a druhá kopie v PHP by se s ním rozešla, jakmile
     * se účet změní nebo přibude další režim. Dotaz se dělá jen u nekorunové platby,
     * takže na běžný běh nemá vliv.
     *
     * `account_prefix`/`account_number` se porovnávají v téže podobě jako v {@see map()}
     * (bez vodicích nul, předčíslí zvlášť od matrikové části), aby národní i GPC zápis
     * účtu vyšly stejně.
     */
    private function isForeignCurrencyRemittanceAccount(string $account): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1
               FROM remittance_map
              WHERE bank_code = '0710'
                AND operation_type = ?
                AND ((account_prefix IS NOT NULL AND account_prefix = ?)
                     OR (account_number IS NOT NULL AND account_number = ?))
              LIMIT 1"
        );
        $stmt->execute([
            OperationType::REMITTANCE_OSS,
            (string) AccountNumberNormalizer::czechAccountPrefix($account),
            (string) AccountNumberNormalizer::czechAccountBase($account),
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    private function supplierIdentifiers(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT dic, cssz_vsdp, health_insurance_number, taxpayer_type FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $supplier
     *
     * U OSVČ jsou `cssz_vsdp`/`health_insurance_number` osobní identifikátory a platí vždy.
     * U právnické osoby je nese mzdový modul (MZ-03) — dokud běží, legacy pole se ignorují,
     * ať se detekce neopírá o zastaralou kopii. S vypnutými Mzdami ale kanonický zdroj
     * neexistuje: legacy pole jsou jedinou evidencí VS zaměstnavatele a mapa odvodů pro ně
     * má vlastní PO řádky (migrace 1082), takže se sem musí dostat, jinak odvod skončí
     * jako neurčený.
     */
    private function vsType(string $vs, array $supplier, bool $payrollEnabled): string
    {
        if ($vs === '') {
            return 'other';
        }
        $dic = preg_replace('/\D/', '', strtoupper((string) ($supplier['dic'] ?? ''))) ?? '';
        $legacyApplies = (string) ($supplier['taxpayer_type'] ?? 'fo') === 'fo'
            || !$payrollEnabled;
        $cssz = $legacyApplies
            ? VariableSymbolNormalizer::forMatching((string) ($supplier['cssz_vsdp'] ?? ''))
            : '';
        $health = $legacyApplies
            ? VariableSymbolNormalizer::forMatching(
                (string) ($supplier['health_insurance_number'] ?? '')
            )
            : '';
        return match (true) {
            $dic !== '' && $vs === $dic => 'dic_kmen',
            $cssz !== '' && $vs === $cssz => 'cssz_vsdp',
            $health !== '' && $vs === $health => 'health_insurance_number',
            default => 'other',
        };
    }

    /** @return array<string,mixed>|null */
    private function map(string $vsType, string $taxpayerType, ?string $prefix, ?string $base): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT vs_type, taxpayer_type, account_prefix, account_number, operation_type, rule_key, auto_allowed, label_cs
               FROM remittance_map
              WHERE bank_code = '0710'
                AND (account_number = ? OR account_number IS NULL)
                AND (account_prefix = ? OR account_prefix IS NULL)
                AND (vs_type = ? OR vs_type = 'other' OR account_prefix = ? OR account_number = ?)
                AND (taxpayer_type = ? OR taxpayer_type = 'any')
              ORDER BY (account_number IS NOT NULL) DESC,
                       (account_prefix IS NOT NULL) DESC,
                       (vs_type <> 'other') DESC,
                       (taxpayer_type <> 'any') DESC,
                       id
              LIMIT 1"
        );
        $stmt->execute([$base, $prefix, $vsType, $prefix, $base, in_array($taxpayerType, ['fo', 'po'], true) ? $taxpayerType : 'fo']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @var array<string, bool> per-request cache "supplierId|code" => účet je v osnově a aktivní. */
    private array $activeAccountCache = [];

    private function hasActiveAccount(int $supplierId, string $code): bool
    {
        $key = $supplierId . '|' . $code;
        if (!array_key_exists($key, $this->activeAccountCache)) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT 1 FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ? AND is_active = 1 LIMIT 1'
            );
            $stmt->execute([$supplierId, $code]);
            $this->activeAccountCache[$key] = $stmt->fetchColumn() !== false;
        }

        return $this->activeAccountCache[$key];
    }

    /**
     * Účet, na kterém firmě visí závazek z mzdové rekapitulace, nebo `null`
     * u operace, která se mzdami nesouvisí.
     *
     * Pojistné OSVČ za sebe ({@see OperationType::REMITTANCE_SOCIAL} a
     * `…_HEALTH`) sem NEPATŘÍ: kryje ho předpis 526/336, ne mzdová
     * rekapitulace, a kontace mezd o něm nic neví. Stejně tak
     * {@see OperationType::REMITTANCE_OTHER} — to je nezařazený odvod, u
     * kterého se neví ani druh, natož účet.
     */
    private function payrollLiabilityAccount(int $supplierId, string $operation): ?string
    {
        $key = match ($operation) {
            OperationType::REMITTANCE_SOCIAL_EMPLOYER => 'social',
            OperationType::REMITTANCE_HEALTH_EMPLOYER => 'health',
            OperationType::REMITTANCE_PAYROLL => 'advance_tax',
            OperationType::REMITTANCE_WITHHOLDING => 'withholding_tax',
            default => null,
        };
        if ($key === null) {
            return null;
        }
        $accounts = $this->payrollAccounts->forSupplier($supplierId);

        return match ($key) {
            'social' => $accounts->socialPayable,
            'health' => $accounts->healthPayable,
            'advance_tax' => $accounts->incomeTaxPayable,
            default => $accounts->withholdingTaxPayable,
        };
    }

    private function fromRule(
        int $supplierId,
        string $operation,
        string $source,
        float $confidence,
        string $ruleKey,
        ?string $description,
        ?int $scheduleId,
        ?string $note,
        bool $autoAllowed,
    ): ?DetectionResult {
        $rule = $this->postingRules->resolve($supplierId, $ruleKey);
        // Očekávaná MD strana per operace — pojistka proti překlepu v posting_rules (níže se jí
        // kontace ověří a případně přebije). Zaměstnavatelské pojistné jde na 336 stejně jako
        // OSVČ: liší se předpis, který úhradu kryje (524/336 + 331/336 vs. 526/336), ne účet úhrady.
        // U ODVODŮ ZAMĚSTNAVATELE je tahle syntetika jen poslední záchranou; konkrétní účet
        // dodává níž kontace mzdové rekapitulace té které firmy.
        $expectedDebit = match ($operation) {
            OperationType::REMITTANCE_SOCIAL, OperationType::REMITTANCE_HEALTH,
            OperationType::REMITTANCE_SOCIAL_EMPLOYER, OperationType::REMITTANCE_HEALTH_EMPLOYER => '336',
            OperationType::REMITTANCE_INCOME, OperationType::REMITTANCE_FLAT => '341',
            OperationType::REMITTANCE_WITHHOLDING, OperationType::REMITTANCE_PAYROLL => '342',
            // Úhrada / vratka DPH míří na ZÚČTOVACÍ analytiku 343.900, ne na holé 343:
            // interní doklad na konci období ({@see \MyInvoice\Service\Accounting\Vat\VatClearingService})
            // tam převede daň období z 343.100/343.200, takže právě 343.900 nese to, co se
            // reálně odvádí. Platba svedená na syntetiku by zůstatek zúčtovacího účtu
            // nevynulovala a saldo vůči FÚ by trvale viselo.
            OperationType::REMITTANCE_VAT => PostingService::VAT_SETTLEMENT_ACCOUNT,
            // Daň v režimu OSS má vlastní ANALYTIKU, ne jen vlastní syntetický účet:
            // předpis ji účtuje na 345.100 ({@see \MyInvoice\Service\Accounting\PostingService}),
            // takže úhrada svedená na holé 345 (kde sedí daň z nemovitostí a silniční)
            // by závazek nevynulovala o nic líp než dosavadní 343.
            OperationType::REMITTANCE_OSS => '345.100',
            OperationType::REMITTANCE_PROPERTY, OperationType::REMITTANCE_ROAD => '345',
            OperationType::REMITTANCE_OTHER => '336',
            default => null,
        };
        if ($expectedDebit === null) {
            return null;
        }
        // ── Odvod zaměstnavatele musí dosednout na TÝŽ účet, kde visí závazek ──
        //
        // Systémové šablony (migrace 1082) zakládaly úhradu odvodů na syntetiku
        // 336, jenže mzdová rekapitulace účtuje závazek na účet z kontace FIRMY
        // — od Ú-08 je výchozí 336.100 / 336.200 a od Ú-13 342.100 / 342.200.
        // Úhrada svedená na syntetiku by závazek nevynulovala: na analytice by
        // závazek zůstal a na syntetice by vznikl přeplatek, takže by
        // {@see \MyInvoice\Service\Payroll\Posting\PayrollPostingReconciliationService}
        // vykazovala rozdíl trvale a nešel by odstranit jinak než ručním
        // přeúčtováním každého měsíce.
        //
        // Bere se {@see PayrollPostingAccountResolver}, ne konstanta: čte
        // `payroll_employer_settings` a degraduje na účet, který firma v osnově
        // reálně má. Firma na syntetice tak dostane přesně dosavadní 336 / 342.
        $payrollDebit = $this->payrollLiabilityAccount($supplierId, $operation);
        if ($payrollDebit !== null) {
            $expectedDebit = $payrollDebit;
        }
        // Pojistka je PREFIXOVÁ, default je KONKRÉTNÍ účet. U DPH se ty dvě věci rozešly:
        // přijatelná je jakákoli analytika 343 (tenant, který si vědomě nechal plochý účet,
        // se nesmí přebít), ale když kontace chybí nebo míří jinam, doplní se 343.900.
        // Dřív se pro obojí bralo totéž '343', takže se každé pravidlo srovnávalo na
        // syntetiku a analytika zúčtování se nemohla uplatnit vůbec.
        $expectedPrefix = $expectedDebit === PostingService::VAT_SETTLEMENT_ACCOUNT
            ? PostingService::VAT_SYNTHETIC
            : $expectedDebit;
        // U mzdových odvodů je přijatelná jakákoli analytika téže syntetiky —
        // firemní override v `posting_rules` se přebít nesmí (viz níž).
        if ($payrollDebit !== null) {
            $expectedPrefix = substr($payrollDebit, 0, 3);
        }
        // Degradace na syntetiku, dokud tenant analytiku nemá (nedoběhlá migrace 1323,
        // ručně smazaný účet) — zrcadlo PostingService::vatAccount(). Bez toho by návrh
        // mířil na účet, který v osnově není, a zaúčtování by spadlo na `unknown_account`.
        if ($expectedDebit === PostingService::VAT_SETTLEMENT_ACCOUNT
            && !$this->hasActiveAccount($supplierId, $expectedDebit)
            && $this->hasActiveAccount($supplierId, PostingService::VAT_SYNTHETIC)
        ) {
            $expectedDebit = PostingService::VAT_SYNTHETIC;
        }
        $debit = (string) ($rule['debit_account_code'] ?? '');
        $credit = (string) ($rule['credit_account_code'] ?? '');
        // GLOBÁLNÍ šablona (`supplier_id IS NULL`) není projevem vůle firmy — je to
        // seed, který o kontaci mezd nic neví a u odvodů zaměstnavatele by svou
        // syntetikou přebil účet, na kterém závazek reálně visí. Firemní override
        // (`supplier_id` = firma) se naopak respektuje dál: kdo si kontaci úhrady
        // vědomě přepsal, ví, co dělá.
        $ruleOverridesPayrollAccount = $payrollDebit === null
            || ($rule['supplier_id'] ?? null) !== null;
        if (!$ruleOverridesPayrollAccount
            || !str_starts_with($debit, $expectedPrefix)
            || !str_starts_with($credit, '221')
        ) {
            $debit = $expectedDebit;
            $credit = '221';
        }
        return new DetectionResult(
            $operation,
            $source,
            $confidence,
            $debit,
            $credit,
            $description,
            $scheduleId,
            $note,
            $autoAllowed,
            $this->key(),
        );
    }
}
