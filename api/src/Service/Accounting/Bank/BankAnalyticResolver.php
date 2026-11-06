<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Repository\SupplierBankAccountRepository;

/**
 * Nasměrování bankovní nohy na dedikovanou analytiku vlastního účtu (#35).
 *
 * Účtová osnova vede banku implicitně na plochém syntetickém 221 a měnu nese
 * ŘÁDEK (currency_code / fx_rate / amount_foreign — §4/12 ZoÚ). To je v pořádku
 * pro jednoměnovou firmu, ale jakmile na 221 leží víc účtů v RŮZNÝCH měnách
 * (např. CZK běžný + EUR Creditas + EUR ČSOB), promíchá se cizoměnová pozice
 * a poloautomat přecenění k rozvahovému dni (FxRevaluationService slot 2 /
 * ClosingRepository::bankProposals) takový „plochý" účet vyloučí — Kč zůstatek
 * účtu ≠ Kč hodnota cizoměnových řádků. Cizoměnový zůstatek se pak musí
 * přeceňovat ručně (viz dřívější ruční doúčtování Creditas EUR).
 *
 * Řešení: KAŽDÝ vlastní bankovní účet (supplier_bank_accounts) má vlastní
 * `analytic_suffix`. Bankovní noha jeho pohybů pak nepadá na holé 221, ale na
 * 221<suffix> (221100, 221200 …). Tím vznikne ČISTÝ jednoúčtový (a tím i
 * jednoměnový) účet: zůstatek sedí na výpis, inventarizace k rozvahovému dni je
 * doložitelná, bankProposals ho nabídne a FxRevaluationService přecení
 * automaticky per účet, bez míchání měn.
 *
 * Suffix se nezadává ručně — chybí-li, přidělí ho {@see BankAnalyticAssigner}
 * (první volné číslo, bez kolize s osnovou i s ostatními účty firmy) a rovnou
 * dohraje analytiku do osnovy. Ruční přiřazení v nastavení má přednost a nikdy
 * se nepřepisuje.
 *
 * Přepisuje se JEN řádek s PŘESNÝM kódem '221' (default bankovní nohy z
 * BankPostingRule). Konkrétní analytiky (221.100 termínovaný vklad) zůstávají
 * nedotčené — a to i v bezteččkovém tvaru (221100) z doby před migrací 1322.
 * Bez shody vlastního účtu výpisu = beze změny (no-op).
 */
final class BankAnalyticResolver
{
    /** Syntetický účet banky, jehož default se přesměrovává na analytiku. */
    private const BANK_SYNTHETIC = BankAnalyticAssigner::BANK_SYNTHETIC;

    /** @var array<string, array<string,mixed>|null> cache shody vlastního účtu v rámci requestu */
    private array $ownCache = [];

    public function __construct(
        private readonly SupplierBankAccountRepository $bankAccounts,
        private readonly BankAnalyticAssigner $assigner,
        /**
         * Úvěrový účet kreditní karty (kind = credit_card) nemá vlastní nohu na bance, ale na
         * úvěru 231.x. Pravidla, párování i detektory dál píšou `221` jako „účet výpisu";
         * JEDINÉ místo, kde se z něj stane 231.x, je tady.
         */
        private readonly \MyInvoice\Service\Accounting\CreditCard\CreditCardAccounts $creditCards,
    ) {}

    /**
     * Přepíše bankovní nohu (přesně '221') na analytiku vlastního účtu výpisu.
     *
     * @param array<string,mixed> $tx  řádek bank_transactions s klíči
     *                                  recipient_account / recipient_bank (číslo účtu výpisu)
     * @param list<array<string,mixed>> $lines řádky ve formátu postDocument
     * @return list<array<string,mixed>>
     */
    public function apply(int $supplierId, array $tx, array $lines): array
    {
        $code = $this->analyticCodeFor($supplierId, $tx);
        if ($code === null) {
            return $lines;
        }
        foreach ($lines as $i => $line) {
            if (($line['account_code'] ?? null) === self::BANK_SYNTHETIC) {
                $lines[$i]['account_code'] = $code;
            }
        }
        return $lines;
    }

    /**
     * Analytický kód banky pro účet daného výpisu (221<suffix>), nebo null, když
     * vlastní účet výpisu nejde jednoznačně dohledat (cizí/neznámé číslo). Vedlejší
     * efekt: chybějící suffix přidělí a chybějící analytiku dohraje do osnovy
     * (obojí idempotentně), aby na ni šlo účtovat.
     *
     * @param array<string,mixed> $tx
     */
    public function analyticCodeFor(int $supplierId, array $tx): ?string
    {
        $account = trim((string) ($tx['recipient_account'] ?? ''));
        if ($account === '') {
            return null;
        }
        $bankRaw = $tx['recipient_bank'] ?? null;
        $bank = ($bankRaw === null || (string) $bankRaw === '') ? null : (string) $bankRaw;

        $key = $supplierId . '|' . $account . '|' . ($bank ?? '');
        $own = $this->ownAccount($key, $supplierId, $account, $bank);
        if (!is_array($own)) {
            return null;
        }
        if (($own['kind'] ?? null) === BankAnalyticAssigner::CREDIT_CARD_KIND) {
            // Bez 231 v osnově vyhodí, pohyb kreditky nesmí tiše zůstat na 221.
            return $this->creditCards->codeForBankAccount($supplierId, $own);
        }
        $suffix = $this->assigner->ensureSuffix($supplierId, $own);
        if ($suffix === null) {
            return null;
        }
        // Cache drží řádek načtený PŘED přidělením — bez téhle aktualizace by další
        // řádek téhož zápisu viděl prázdný suffix a přidělil účtu druhé číslo.
        $this->ownCache[$key]['analytic_suffix'] = $suffix;
        return $this->assigner->ensureChartAccount($supplierId, $suffix, (string) ($own['label'] ?? ''));
    }

    /**
     * Analytika banky pro NÁHLED — čte jen to, co už existuje, a nic nezakládá.
     *
     * {@see analyticCodeFor()} má vedlejší efekty (přidělí suffix, dohraje účet do osnovy),
     * protože bez nich by zaúčtování spadlo. Náhled návrhu se ale vykresluje při každém
     * načtení fronty, klidně pro stovky řádků, a zakládat účetní osnovu při GET požadavku
     * je nepřijatelné. Když analytika ještě neexistuje, vrátí null a volající zobrazí
     * syntetiku — s příznakem, že kontace ještě není konečná.
     *
     * @param array<string,mixed> $tx
     */
    public function existingAnalyticCodeFor(int $supplierId, array $tx, bool $currentRead = false): ?string
    {
        $account = trim((string) ($tx['recipient_account'] ?? ''));
        if ($account === '' && !$currentRead) {
            return null;
        }
        $bankRaw = $tx['recipient_bank'] ?? null;
        $bank = ($bankRaw === null || (string) $bankRaw === '') ? null : (string) $bankRaw;
        $own = $currentRead ? $this->bankAccounts->matchCounterparty($supplierId, $account, $bank, false, true) : $this->ownAccount(
            $supplierId . '|' . $account . '|' . ($bank ?? ''),
            $supplierId,
            $account,
            $bank,
        );
        if (!is_array($own)) {
            return null;
        }
        if (($own['kind'] ?? null) === BankAnalyticAssigner::CREDIT_CARD_KIND) {
            if ($currentRead) {
                throw new \MyInvoice\Service\Accounting\PostingException('property_payment_unsupported_source', 'Úvěrový účet kreditní karty není podporovaným zdrojem tohoto vypořádání.', 422);
            }
            return $this->creditCards->existingCodeForBankAccount($supplierId, $own);
        }
        $suffix = $own['analytic_suffix'] ?? null;
        if (!BankAnalyticAssigner::isValidSuffix($suffix)) {
            return null;
        }

        return BankAnalyticAssigner::codeFor((string) $suffix);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function ownAccount(string $key, int $supplierId, string $account, ?string $bank): ?array
    {
        if (array_key_exists($key, $this->ownCache)) {
            return $this->ownCache[$key];
        }
        return $this->ownCache[$key] = $this->bankAccounts->matchCounterparty($supplierId, $account, $bank);
    }
}
