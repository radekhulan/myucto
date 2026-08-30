<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Které účty použije mzdová rekapitulace u KONKRÉTNÍ firmy.
 *
 * Pořadí zdrojů (první, který v osnově firmy reálně existuje a je aktivní, vyhrává):
 *
 *   1. `payroll_employer_settings` — výslovná konfigurace firmy. Čte ji i novější
 *      engine ({@see \MyInvoice\Service\Payroll\PayrollAccountingDefaults}), takže obě
 *      větve účtují na totéž. Řádek NENÍ povinný; drtivá většina firem ho nemá.
 *   2. {@see self::ANALYTIC_PREFERENCE} — analytika dle účetní. Firma, která si osnovu
 *      rozanalytikovala (`521.100`, `336.100`, `336.200`, …), dostane své analytické
 *      účty automaticky, aniž by cokoli nastavovala.
 *   3. Syntetika ze směrné osnovy ({@see PayrollPostingAccounts::defaults()}).
 *
 * ── Proč se ověřuje existence v osnově ──────────────────────────────────────
 * {@see \MyInvoice\Service\Accounting\PostingService} na neznámý účet zápis odmítne
 * (`unknown_account`). Kdyby se analytika předpokládala, firma bez ní by po nasazení
 * přestala účtovat mzdy vůbec — místo drobného rozdílu v kontaci by spadl celý měsíc.
 * Fallback je proto konstrukční, ne obranný.
 *
 * Neexistující účet z `payroll_employer_settings` se přeskočí stejně jako chybějící
 * analytika: nastavení může přežít smazání účtu z osnovy a mzda kvůli tomu spadnout nesmí.
 */
final class PayrollPostingAccountResolver
{
    /**
     * Analytické účty, na které mzdu účtuje účetní. Ne odvozené pravidlem
     * „syntetika + .100": u 336 se sociální (`.100`) a zdravotní (`.200`) rozcházejí
     * a strojově uhodnout to nejde.
     *
     * @var array<string,list<string>>
     */
    private const ANALYTIC_PREFERENCE = [
        PayrollPostingAccounts::KEY_EMPLOYMENT_EXPENSE => ['521.100'],
        PayrollPostingAccounts::KEY_EMPLOYMENT_PAYABLE => ['331.100'],
        PayrollPostingAccounts::KEY_PARTNER_EXPENSE    => ['522.100'],
        PayrollPostingAccounts::KEY_PARTNER_PAYABLE    => ['366.100'],
        PayrollPostingAccounts::KEY_EMPLOYER_INSURANCE => ['524.100'],
        PayrollPostingAccounts::KEY_SOCIAL_PAYABLE     => ['336.100'],
        PayrollPostingAccounts::KEY_HEALTH_PAYABLE     => ['336.200'],
        // Sražená záloha na daň ze závislé činnosti. `342.100` je běžnější označení
        // zálohové daně, `342.200` používají osnovy, které na 342 vedou jen jednu
        // analytiku — zkusí se v tomhle pořadí a vezme se ta, kterou firma má.
        PayrollPostingAccounts::KEY_INCOME_TAX_PAYABLE => ['342.100', '342.200'],
        // Srážková daň (Ú-13). Pořadí je OBRÁCENÉ proti záloze a je to záměr:
        // firma, která na 342 vede jedinou analytiku, musí obě daně dál účtovat
        // na TÝŽ účet jako dosud — jinak by se jí uprostřed roku rozpadlo saldo.
        // Rozdělí se teprve firma, která má v osnově OBĚ analytiky, protože to
        // je projev vůle účetní (stejná úvaha jako u 336.100/336.200 v 1618).
        PayrollPostingAccounts::KEY_WITHHOLDING_TAX_PAYABLE => ['342.200', '342.100'],
    ];

    /** @var array<int,PayrollPostingAccounts> */
    private array $cache = [];

    public function __construct(private readonly Connection $db) {}

    public function forSupplier(int $supplierId): PayrollPostingAccounts
    {
        if (isset($this->cache[$supplierId])) {
            return $this->cache[$supplierId];
        }

        $postable  = $this->postableAccountCodes($supplierId);
        $configured = $this->configuredAccounts($supplierId);
        $defaults  = PayrollPostingAccounts::defaults()->toMap();

        $resolved = [];
        foreach (PayrollPostingAccounts::KEYS as $key) {
            $candidates = [];
            if (isset($configured[$key])) {
                $candidates[] = $configured[$key];
            }
            foreach (self::ANALYTIC_PREFERENCE[$key] ?? [] as $analytic) {
                $candidates[] = $analytic;
            }
            $candidates[] = $defaults[$key];
            // Poslední záchrana je SYNTETIKA výchozího účtu. Od W7/Ú-08 je
            // výchozí kontace pojistného analytická (336.100 / 336.200) a firma,
            // která analytiku v osnově nemá, by jinak dostala účet, na který
            // PostingService zápis odmítne (`unknown_account`) — místo drobného
            // rozdílu v kontaci by jí spadlo celé zaúčtování mzdy.
            $fallback = $defaults[$key];
            $synthetic = substr($fallback, 0, 3);
            if ($synthetic !== $fallback) {
                $candidates[] = $synthetic;
                $fallback = $synthetic;
            }

            $resolved[$key] = $fallback;
            foreach ($candidates as $candidate) {
                if (isset($postable[$candidate])) {
                    $resolved[$key] = $candidate;
                    break;
                }
            }
        }

        return $this->cache[$supplierId] = PayrollPostingAccounts::fromMap($resolved);
    }

    /** @return array<string,true> aktivní účty firmy jako množina kódů */
    private function postableAccountCodes(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_code
               FROM chart_of_accounts
              WHERE supplier_id = ? AND is_active = 1'
        );
        $stmt->execute([$supplierId]);

        $codes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
            $codes[(string) $code] = true;
        }

        return $codes;
    }

    /**
     * Výslovná kontace firmy z `payroll_employer_settings`. Bez řádku se vrací
     * prázdné pole — nastavení je volitelné, ne předpoklad.
     *
     * @return array<string,string>
     */
    private function configuredAccounts(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment_gross_debit_account, employment_gross_credit_account,
                    partner_gross_debit_account, partner_gross_credit_account,
                    employer_insurance_debit_account, social_insurance_credit_account,
                    health_insurance_credit_account, income_tax_credit_account,
                    withholding_tax_credit_account
               FROM payroll_employer_settings
              WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [];
        }

        $map = [
            PayrollPostingAccounts::KEY_EMPLOYMENT_EXPENSE => 'employment_gross_debit_account',
            PayrollPostingAccounts::KEY_EMPLOYMENT_PAYABLE => 'employment_gross_credit_account',
            PayrollPostingAccounts::KEY_PARTNER_EXPENSE    => 'partner_gross_debit_account',
            PayrollPostingAccounts::KEY_PARTNER_PAYABLE    => 'partner_gross_credit_account',
            PayrollPostingAccounts::KEY_EMPLOYER_INSURANCE => 'employer_insurance_debit_account',
            PayrollPostingAccounts::KEY_SOCIAL_PAYABLE     => 'social_insurance_credit_account',
            PayrollPostingAccounts::KEY_HEALTH_PAYABLE     => 'health_insurance_credit_account',
            PayrollPostingAccounts::KEY_INCOME_TAX_PAYABLE => 'income_tax_credit_account',
            PayrollPostingAccounts::KEY_WITHHOLDING_TAX_PAYABLE => 'withholding_tax_credit_account',
        ];

        $out = [];
        foreach ($map as $key => $column) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
