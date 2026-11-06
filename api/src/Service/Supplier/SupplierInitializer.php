<?php

declare(strict_types=1);

namespace MyInvoice\Service\Supplier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\Accounting\AccountingPeriodProvisioner;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use MyInvoice\Service\Accounting\Bank\BankRuleTemplateSeeder;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\LegalFormProfileRecommendation;
use MyInvoice\Service\Ares\CrpDphClient;
use MyInvoice\Service\Ares\SupplierRegistryEnricher;
use MyInvoice\Service\Bank\OwnBankAccountRegistrar;
use MyInvoice\Service\Vat\VatStatusService;
use Psr\Log\LoggerInterface;

/**
 * Jediná cesta, kterou nově založená firma dostává výchozí nastavení.
 *
 * Firma vzniká dvěma cestami: prvotním setupem (průvodce i bezobslužné zřízení
 * spravované instalace, {@see \MyInvoice\Action\Auth\SetupAction}) a zakládáním
 * další firmy v aplikaci ({@see \MyInvoice\Action\Settings\SettingsAction::createSupplier()}).
 * Dokud si každá vedla inicializaci sama, druhá cesta zaostala: s.r.o. založené
 * v aplikaci vzniklo v daňové evidenci, bez historie režimu, bez směrné osnovy,
 * bez účetního období, bez automatického účtování a plátce bez zdaňovacího období.
 *
 * Volá se ve dvou krocích, protože jen část patří do transakce zakládání:
 *
 *  1. {@see seedWithinInsert()} — DB zápisy, které musí vzniknout atomicky
 *     s řádkem `supplier` (historie plátcovství a režimu, osnova, registr
 *     vlastních účtů, šablony bankovních pravidel);
 *  2. {@see completeAfterCommit()} — až PO commitu, protože volá ARES a registr
 *     plátců po síti; best-effort, výpadek registru nesmí zahodit založenou firmu.
 *
 * Samotný INSERT zůstává u volajících — liší se vstupem (setup nese měny s účtem,
 * aplikace identifikovanou osobu a členství zakladatele) — ale odvození režimu
 * a zdaňovacího období berou oba odsud.
 */
final class SupplierInitializer
{
    public function __construct(
        private readonly Connection $db,
        private readonly SupplierRegistryEnricher $enricher,
        private readonly CrpDphClient $crpdph,
        private readonly VatStatusService $vatStatus,
        // SEC-01: doplnění účtu z registru si nesmí nárokovat cizí účet.
        private readonly BankStatementOwnershipResolver $bankOwnership,
        private readonly ChartOfAccountsSeeder $coaSeeder,
        private readonly AccountingModeRepository $accountingModes,
        private readonly AccountingFrameworkService $accountingFramework,
        private readonly AccountingPeriodProvisioner $periodProvisioner,
        private readonly AutoPostingPolicyService $autoPosting,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * Typ poplatníka ze vstupu; `null` = volající ho neposlal a rozhodne ARES.
     *
     * @param array<string,mixed> $input
     */
    public static function taxpayerType(array $input): ?string
    {
        return in_array($input['taxpayer_type'] ?? null, ['fo', 'po'], true)
            ? (string) $input['taxpayer_type']
            : null;
    }

    /**
     * ⚠️ Režim účetnictví se ODVOZUJE z právní formy, nedědí se DB default.
     *
     * Sloupec `supplier.accounting_mode` má default `tax_evidence` (migrace 1001).
     * Daňová evidence je ale režim pro fyzické osoby — právnická osoba je ze
     * zákona účetní jednotka a vede podvojné účetnictví. Přepnout to zpětně NENÍ
     * zadarmo: doklady vzniklé v daňové evidenci se zapnutím podvojného účetnictví
     * nedoúčtují. Správně se proto musí trefit hned při založení.
     *
     * `fo` i neznámá hodnota zůstávají na daňové evidenci: u OSVČ je to správně
     * a u neznámé právní formy je to ta zvratitelnější volba — tu pak po obohacení
     * z ARESu srovná {@see alignAccountingModeWithLegalForm()}.
     */
    public static function accountingMode(?string $taxpayerType): string
    {
        return $taxpayerType === 'po' ? 'double_entry' : 'tax_evidence';
    }

    /**
     * ⚠️ Zdaňovací období plátce se musí trefit hned — sestavy DPH a kontrolní
     * hlášení z něj berou, jestli podávat měsíčně, nebo čtvrtletně.
     *
     * Bez výslovné volby `monthly`: nový plátce je podle § 99 ZDPH měsíční ze
     * zákona a čtvrtletní období si smí zvolit až po podmínkách § 99a. Měsíční
     * default je proto ta bezpečnější strana omylu.
     */
    public static function vatPeriod(bool $isVatPayer, mixed $requested): ?string
    {
        if (!$isVatPayer) {
            return null;
        }

        return in_array($requested, ['monthly', 'quarterly'], true) ? (string) $requested : 'monthly';
    }

    /**
     * Zápisy, které patří do TÉŽE transakce jako INSERT firmy. Volat až po
     * doplnění `supplier.default_currency_id` a se zapnutou kontrolou cizích klíčů
     * — registr vlastních účtů se váže na `currencies.id`.
     */
    public function seedWithinInsert(
        \PDO $pdo,
        int $supplierId,
        string $accountingMode,
        bool $isVatPayer,
        bool $isIdentified = false,
        ?int $userId = null,
    ): void {
        VatStatusService::seedInitialStatus($pdo, $supplierId, $isVatPayer, $isIdentified);
        $this->accountingFramework->initializeCz500Baseline($supplierId, $userId);

        // ⚠️ Historie účetního režimu musí vzniknout spolu s firmou — jinak dotazy
        // „jaký režim platil v roce X" padají na dnešní `supplier.accounting_mode`.
        //
        // ⚠️ NE `1900-01-01` jako u DPH statusu. `continuousDoubleEntrySince()` z ní
        // počítá 5 účetních období podle § 4 odst. 7 ZoÚ, než smí firma účetnictví
        // ukončit; datum od roku 1900 by ze zákonné pojistky udělalo formalitu.
        // 1. leden letošního roku je nejstarší datum, které umíme doložit.
        $this->accountingModes->record($supplierId, date('Y-01-01'), $accountingMode);

        // ⚠️ Podvojné účetnictví BEZ směrné osnovy je rozbitý stav — `PostingService`
        // nemá na co mapovat `account_code`. Doúčtování minulosti, které řeší přepínač
        // v Nastavení, tu odpadá: nová firma žádné doklady nemá.
        if ($accountingMode === 'double_entry') {
            $this->coaSeeder->seedForSupplier($supplierId);
        }

        // ⚠️ Účet zapsaný na měnu musí rovnou do registru vlastních účtů. Účtování
        // banky, analytika 221 i rozpoznání vlastní protistrany čtou
        // `supplier_bank_accounts`, ne `currencies`.
        OwnBankAccountRegistrar::syncSupplier($pdo, $supplierId, $this->bankOwnership);
        BankRuleTemplateSeeder::seed($pdo, $supplierId);
    }

    /**
     * Dokončení po commitu: obohacení z veřejných registrů a dorovnání profilu.
     * Nikdy nevyhazuje — neúplný profil se dá spravit v Nastavení, zahozená firma ne.
     *
     * @param array<string,mixed> $input vstup zakládání (ic, dic, taxpayer_type,
     *                                   is_vat_payer, bank_account)
     */
    public function completeAfterCommit(int $supplierId, array $input, ?int $userId = null): void
    {
        try {
            $this->enricher->enrich(
                $supplierId,
                isset($input['ic']) ? (string) $input['ic'] : null,
                isset($input['dic']) ? (string) $input['dic'] : null,
            );
        } catch (\Throwable $e) {
            $this->log->warning('supplier-init: obohacení z registrů selhalo', ['error' => $e->getMessage()]);
        }
        $this->applyVatRegistryData($supplierId, $input);
        $this->alignAccountingModeWithLegalForm($supplierId, $input);
        $this->alignAccountingFrameworkWithLegalForm($supplierId, $userId);
        $this->finalizeProfile($supplierId, $userId);
    }

    /**
     * Nevýdělečná organizace a SVJ účtují podle vyhlášky 504. Právní formu zná
     * až ARES, proto se rámec srovnává tady: výchozí 500 se u nové firmy přepíše
     * na 504 a nedotčená šablona osnovy 500 se vymění za šablonu 504. Rozhoduje
     * {@see LegalFormProfileRecommendation}, ostatní firmy zůstávají beze změny.
     */
    public function alignAccountingFrameworkWithLegalForm(int $supplierId, ?int $userId = null): void
    {
        try {
            $stmt = $this->db->pdo()->prepare('SELECT legal_form, accounting_mode FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return;
            }
            $recommendation = LegalFormProfileRecommendation::forLegalForm(
                $row['legal_form'] === null ? null : (string) $row['legal_form'],
                (string) ($row['accounting_mode'] ?? ''),
            );
            if ($recommendation['accounting_framework'] !== AccountingFrameworkService::CZ_504) {
                return;
            }
            if ($this->accountingFramework->adoptCz504BaselineForNewSupplier($supplierId, $userId)) {
                $this->log->info('supplier-init: účetní rámec 504 podle právní formy', ['supplier_id' => $supplierId]);
            }
        } catch (\Throwable $e) {
            $this->log->warning('supplier-init: účetní rámec se nepodařilo srovnat s právní formou', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Doplní plátcovství a bankovní účet z registru plátců DPH (podle DIČ).
     *
     * ⚠️ Bere se JEN zveřejněný účet z registru — účet, který u správce daně
     * ohlásil sám plátce. Jen do PRÁZDNÉ CZK měny a jen když vstup vlastní číslo
     * účtu neobsahoval.
     *
     * ⚠️ Plátcovství jen tehdy, když ho vstup neobsahoval (`is_vat_payer` chybí).
     * Kdo ho poslal výslovně, rozhodl — registr jeho volbu nepřebíjí. Zapisuje
     * se přes historii (VH-01), `supplier.is_vat_payer` je jen živá cache.
     *
     * @param array<string,mixed> $input
     */
    public function applyVatRegistryData(int $supplierId, array $input): void
    {
        $ownAccount = isset($input['bank_account']) && is_array($input['bank_account'])
            && trim((string) ($input['bank_account']['account_number'] ?? '')) !== '';
        $dic = trim((string) ($input['dic'] ?? ''));
        if ($dic === '') {
            return;
        }

        try {
            $res = $this->crpdph->lookup($dic);

            if (($res['found'] ?? false) === true && !array_key_exists('is_vat_payer', $input)) {
                $this->vatStatus->upsert($supplierId, '1900-01-01', true, false, 'Zjištěno z registru plátců DPH při zřízení.');
                $this->vatStatus->refreshLiveCache($supplierId);
                $this->log->info('supplier-init: firma je podle registru plátce DPH', ['supplier_id' => $supplierId]);
            }

            $accounts = is_array($res['accounts'] ?? null) ? $res['accounts'] : [];
            if (!$accounts || $ownAccount) {
                return;
            }

            // První zveřejněný účet je ten, který plátce uvádí jako hlavní.
            $first = $accounts[0];
            $number = trim((string) ($first['prefix'] ?? '')) !== ''
                ? trim((string) $first['prefix']) . '-' . trim((string) ($first['number'] ?? ''))
                : trim((string) ($first['number'] ?? ''));
            $bankCode = trim((string) ($first['bank_code'] ?? ''));
            $iban = trim((string) ($first['iban'] ?? '')) ?: null;
            if ($number === '' && $iban === null) {
                return;
            }

            // SEC-01: ani doplnění z registru si nesmí nárokovat účet, který už
            // patří jinému dodavateli nebo na který chodí cizí výpisy.
            if ($this->bankOwnership->accountClaimedByOtherSupplier($supplierId, $number ?: null, $iban)
                || $this->bankOwnership->accountBlockedByForeignStatements($supplierId, $number ?: null, $iban)) {
                $this->log->warning('supplier-init: zveřejněný účet se nedoplnil — patří jinému dodavateli');
                return;
            }

            $this->db->pdo()->prepare(
                "UPDATE currencies SET account_number = ?, bank_code = ?, iban = ?
                   WHERE supplier_id = ? AND code = 'CZK'
                     AND (account_number IS NULL OR account_number = '')
                     AND (iban IS NULL OR iban = '')"
            )->execute([$number ?: null, $bankCode ?: null, $iban, $supplierId]);
        } catch (\Throwable $e) {
            $this->log->warning('supplier-init: zveřejněné účty se nepodařilo načíst', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Srovná účetní režim s právní formou zjištěnou z ARESu.
     *
     * Bezobslužné zřízení typ poplatníka neposílá (provozovatel zná jen IČ),
     * takže s.r.o. by vzniklo v daňové evidenci. ARES právní formu doplní až po
     * vložení řádku — proto se srovnává tady.
     *
     * ⚠️ Jen když typ poplatníka NEPŘIŠEL ve vstupu. Rozhodnutí volajícího se
     * registrem nepřepisuje. S přepnutím se seeduje i směrná osnova; nová firma
     * nemá doklady, takže odpadá doúčtování minulosti.
     *
     * @param array<string,mixed> $input
     */
    public function alignAccountingModeWithLegalForm(int $supplierId, array $input): void
    {
        if (self::taxpayerType($input) !== null) {
            return;
        }

        try {
            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare('SELECT taxpayer_type, accounting_mode FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)
                || (string) ($row['taxpayer_type'] ?? '') !== 'po'
                || (string) ($row['accounting_mode'] ?? '') !== 'tax_evidence') {
                return;
            }

            $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
                ->execute([$supplierId]);
            $this->coaSeeder->seedForSupplier($supplierId);
            $this->log->info('supplier-init: právnická osoba převedena do podvojného účetnictví podle ARESu', ['supplier_id' => $supplierId]);
        } catch (\Throwable $e) {
            $this->log->warning('supplier-init: účetní režim se nepodařilo srovnat s právní formou', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Dorovná profil firmy podle hotového obrazu (po obohacení z registrů).
     *
     * ⚠️ Musí běžet AŽ ZA obohacením: právní formu, plátcovství i účet doplní teprve
     * ARES a registr plátců, takže při vkládání byly odvozené ze špatného obrazu:
     *
     *   - historie účetního režimu by nesla `tax_evidence`, i když firmu
     *     {@see alignAccountingModeWithLegalForm()} převedl na `double_entry`,
     *   - účetní období pro rok založení — firma v podvojném účetnictví jinak
     *     odchází bez jediného období a pozná to až u prvního „Zaúčtovat",
     *   - výchozí automatika účetní jednotky (automatické účtování faktur a preset
     *     `full`) — táž pravidla jako po aktivačním průvodci; jen pro podvojné
     *     účetnictví, daňová evidence deník nevede,
     *   - `vat_period` plátce zjištěného až z registru,
     *   - registr vlastních účtů pro účet doplněný z registru plátců.
     */
    public function finalizeProfile(int $supplierId, ?int $userId = null): void
    {
        try {
            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare('SELECT accounting_mode, is_vat_payer, vat_period FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return;
            }

            // Upsert přes UNIQUE (supplier_id, effective_from).
            $this->accountingModes->record($supplierId, date('Y-01-01'), (string) $row['accounting_mode']);

            // V daňové evidenci provisioner správně neudělá nic.
            $this->periodProvisioner->ensureOpenPeriodForDate(
                $supplierId,
                date('Y-m-d'),
                AccountingPeriodProvisioner::REASON_SETUP,
                $userId,
            );

            if ((string) $row['accounting_mode'] === 'double_entry') {
                $this->autoPosting->applyAccountingUnitDefaults($supplierId, $userId);
            }

            if ((int) $row['is_vat_payer'] === 1 && ($row['vat_period'] ?? null) === null) {
                $pdo->prepare("UPDATE supplier SET vat_period = 'monthly' WHERE id = ? AND vat_period IS NULL")
                    ->execute([$supplierId]);
                $this->log->info('supplier-init: plátci DPH doplněno měsíční zdaňovací období (§ 99 ZDPH)', ['supplier_id' => $supplierId]);
            }

            OwnBankAccountRegistrar::syncSupplier($pdo, $supplierId, $this->bankOwnership);
        } catch (\Throwable $e) {
            $this->log->warning('supplier-init: profil firmy se nepodařilo dorovnat', ['error' => $e->getMessage()]);
        }
    }
}
