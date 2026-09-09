<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Detekce situací, které aplikace u přiznání k dani z příjmů vědomě NEUMÍ
 * (private/DANE-PLAN.md § D) nebo umí jen zčásti — položka P-1 téhož plánu.
 *
 * Proč to existuje: přiznání se dosud stavělo s natvrdo zapsaným typem poplatníka
 * („ostatní", `typ_popldpp='1'`), typem přiznání („za zdaňovací období",
 * `typ_dapdpp='A'`) a účetní vyhláškou 500/2002 Sb. Poplatník v likvidaci,
 * investiční fond, veřejně prospěšný poplatník nebo FO vedoucí podvojné účetnictví
 * tedy dostal zdánlivě platné přiznání, které o něm tvrdil nepravdu — a nikdo se to
 * nedozvěděl. Zásada projektu zní: aplikace nikdy nesmí vydat podání, o kterém ví,
 * že je neúplné, a mlčet u toho.
 *
 * Závažnost:
 * - `blocker` — finalizace přiznání se zastaví ({@see PreFinalizeCheckService::run()}
 *   sčítá blokující kontroly do `can_finalize`).
 * - `warning` — přiznání se vydá, ale nález je vidět v UI i ve výstupu exportu.
 *
 * Vědomý příznak na firmě s výchozí hodnotou „ne" (migrace 1781) NENÍ tichý
 * předpoklad: kde jde podezření poznat z dat (NACE finančního sektoru, právní
 * povaha veřejně prospěšného poplatníka, zahraniční sídlo, tvar účetního období),
 * vzniká nález i tehdy, když je příznak vypnutý.
 *
 * Volatelné zvenčí (ne `private` helper uvnitř akce): kromě předfinalizační
 * kontroly ho používá {@see TaxReturnService} při stavbě XML.
 */
final class UnsupportedCaseDetector
{
    public const SEVERITY_BLOCKER = 'blocker';
    public const SEVERITY_WARNING = 'warning';

    /**
     * CZ-NACE (bez teček), u kterých se účetní závěrka sestavuje podle vyhlášky
     * 501/2002 Sb. (banky a jiné finanční instituce), 502/2002 Sb. (pojišťovny)
     * nebo 503/2002 Sb. (zdravotní pojišťovny) a poplatník má vlastní typ
     * v číselníku `typ_popldpp`. Záměrně tu NEJSOU 6420 (holdingy), 6491/6492
     * (leasing a nebankovní úvěry) ani 6619 (zprostředkovatelé) — ty účtují podle
     * vyhlášky 500/2002 Sb. jako každý jiný podnikatel a jsou mezi zákazníky běžné,
     * takže by z brány udělaly falešně pozitivní šum.
     */
    private const FINANCIAL_SECTOR_NACE = ['6411', '6419', '6430', '6499', '651', '652', '653', '6612', '6630'];

    /** CZ-NACE činností organizací sdružujících osoby — typický veřejně prospěšný poplatník (§ 17a). */
    private const PUBLIC_BENEFIT_NACE = ['94'];

    /** CZ-NACE výroby elektřiny — indicie fotovoltaické elektrárny odpisované podle § 30b. */
    private const PHOTOVOLTAIC_NACE = ['3511'];

    public function __construct(private readonly Connection $db) {}

    /**
     * Nálezy pro firmu načtenou z databáze.
     *
     * @param array<string,mixed> $podklady výstup {@see TaxReturnService} `compute()['podklady']`
     * @param array<string,mixed> $result   výstup kalkulátoru
     * @param array<string,mixed> $inputs   ruční vstupy přiznání
     * @return list<array{key:string,severity:string,message:string,action:string}>
     */
    public function detect(int $supplierId, string $type, array $podklady = [], array $result = [], array $inputs = []): array
    {
        return self::detectForSupplier($this->loadSupplier($supplierId), $type, $podklady, $result, $inputs);
    }

    /**
     * Táž pravidla nad už načteným řádkem `supplier` — používá ji volající, který
     * řádek stejně drží (a testy, které DB nepotřebují).
     *
     * @param array<string,mixed> $supplier
     * @param array<string,mixed> $podklady
     * @param array<string,mixed> $result
     * @param array<string,mixed> $inputs
     * @return list<array{key:string,severity:string,message:string,action:string}>
     */
    public static function detectForSupplier(array $supplier, string $type, array $podklady = [], array $result = [], array $inputs = []): array
    {
        $findings = [];
        $nace = preg_replace('/\D/', '', (string) ($supplier['cz_nace_code'] ?? '')) ?? '';

        self::checkEntityStatus($supplier, $findings);
        self::checkForeignSeat($supplier, $findings);

        if ($type === 'po') {
            self::checkTaxpayerType($supplier, $nace, $findings);
            self::checkAccountingDecree($supplier, $findings);
            self::checkPublicBenefit($supplier, $nace, $findings);
            self::checkInvestmentIncentive($supplier, $findings);
            self::checkAtadCfc($supplier, $findings);
            self::checkPeriodShape($podklady, $findings);
            self::checkPhotovoltaic($nace, $findings);
        } else {
            self::checkFoDoubleEntry($podklady, $findings);
            self::checkCooperatingPerson($supplier, $findings);
            self::checkForeignIncomeCredit($supplier, $result, $inputs, $findings);
            self::checkManualUnsupportedCases($podklady, $findings);
        }

        return $findings;
    }

    /**
     * Varování k zahraničnímu sídlu při typu poplatníka „ostatní" — SSOT sdílená
     * s {@see DppoXmlBuilder}, který ji vydává na úrovni XML (tam je jediné místo,
     * kde se `typ_popldpp` skutečně zapisuje), zatímco detektor ji vydává na úrovni
     * brány pro obě přiznání. Text tedy existuje jednou, ne dvakrát.
     */
    public static function foreignSeatWarning(
        string $countryIso2,
        string $taxpayerTypeCode = TaxpayerTypeCodebook::DEFAULT_CODE,
    ): ?string {
        $seat = strtoupper(trim($countryIso2));
        if ($seat === '' || $seat === 'CZ' || $taxpayerTypeCode !== TaxpayerTypeCodebook::DEFAULT_CODE) {
            return null;
        }

        return 'Sídlo (bydliště) poplatníka je mimo ČR (' . $seat . '), ale přiznání se staví pro '
            . 'typ poplatníka „ostatní" (kód 1). Daňový nerezident má kód 2 a jiný rozsah zdanění '
            . '(§ 17 odst. 4 ZDP u PO, § 2 odst. 3 ZDP u FO) — ověřte, zda je poplatník daňovým '
            . 'rezidentem ČR (u PO rozhoduje místo vedení podle § 17 odst. 3 ZDP).';
    }

    /** @param list<array{key:string,severity:string,message:string,action:string}> $findings */
    private static function checkTaxpayerType(array $supplier, string $nace, array &$findings): void
    {
        $code = TaxpayerTypeCodebook::normalize($supplier['epo_taxpayer_code'] ?? null);
        $financialSector = self::naceMatches($nace, self::FINANCIAL_SECTOR_NACE);

        if ($code !== null && !TaxpayerTypeCodebook::isValidTaxpayerType($code)) {
            $findings[] = [
                'key' => 'taxpayer_type_invalid',
                'severity' => self::SEVERITY_BLOCKER,
                'message' => 'Typ poplatníka „' . $code . '" není v číselníku atributu typ_popldpp '
                    . '(přípustné hodnoty 0-9). EPO takové podání odmítne kritickou kontrolou.',
                'action' => 'Opravte typ poplatníka v nastavení firmy (Nastavení → Účetnictví → Daně a EPO).',
            ];

            return;
        }

        if ($code !== null && $code !== TaxpayerTypeCodebook::DEFAULT_CODE) {
            $findings[] = [
                'key' => 'taxpayer_type_unsupported',
                'severity' => self::SEVERITY_BLOCKER,
                'message' => 'Poplatník je vedený jako typ ' . $code . ' — '
                    . TaxpayerTypeCodebook::taxpayerTypeLabel($code) . '. Aplikace sestavuje přiznání '
                    . 'pouze pro typ 1 (ostatní): pro ostatní typy neplní povinné atributy formuláře '
                    . '(např. zakl_if u typu 4, samostatnou sazbu daně u typu 7, snížení základu podle '
                    . '§ 20 odst. 7 u typu 3) ani odpovídající účetní výkazy.',
                'action' => 'Přiznání sestavte v portálu EPO ručně, nebo typ poplatníka opravte, '
                    . 'pokud je v nastavení firmy zadaný chybně.',
            ];

            return;
        }

        if ($code === null && $financialSector) {
            $findings[] = [
                'key' => 'taxpayer_type_undeclared',
                'severity' => self::SEVERITY_BLOCKER,
                'message' => 'Hlavní činnost firmy (CZ-NACE ' . $nace . ') spadá do finančního sektoru, '
                    . 'kde poplatníkem bývá banka, investiční fond, investiční nebo penzijní společnost '
                    . 'anebo pojišťovna. Ty mají v přiznání vlastní typ poplatníka (kódy 4, 5, 6, 7) '
                    . 'a účtují podle vyhlášky 501/502/503, kdežto přiznání se staví natvrdo jako '
                    . 'typ 1 podle vyhlášky 500/2002 Sb. Typ poplatníka zatím nikdo nepotvrdil.',
                'action' => 'Určete typ poplatníka a účetní vyhlášku v nastavení firmy. '
                    . 'Jde-li o běžnou obchodní korporaci, zvolte typ 1 (ostatní) — nález se změní na varování.',
            ];

            return;
        }

        if ($code === TaxpayerTypeCodebook::DEFAULT_CODE && $financialSector) {
            $findings[] = [
                'key' => 'financial_sector_nace',
                'severity' => self::SEVERITY_WARNING,
                'message' => 'Hlavní činnost firmy (CZ-NACE ' . $nace . ') je z finančního sektoru, '
                    . 'ale typ poplatníka je potvrzený jako 1 (ostatní) a účetní závěrka se sestavuje '
                    . 'podle vyhlášky 500/2002 Sb.',
                'action' => 'Ověřte, že se na poplatníka nevztahuje vyhláška 501/502/503 Sb. '
                    . 'ani vlastní typ poplatníka podle § 17b nebo § 20a ZDP.',
            ];
        }
    }

    /** @param list<array{key:string,severity:string,message:string,action:string}> $findings */
    private static function checkAccountingDecree(array $supplier, array &$findings): void
    {
        $decree = trim((string) ($supplier['tax_accounting_decree'] ?? TaxpayerTypeCodebook::SUPPORTED_DECREE));
        if ($decree === '' || $decree === TaxpayerTypeCodebook::SUPPORTED_DECREE) {
            return;
        }
        $findings[] = [
            'key' => 'accounting_decree_unsupported',
            'severity' => self::SEVERITY_BLOCKER,
            'message' => 'Účetní závěrka se podle nastavení firmy sestavuje podle vyhlášky '
                . TaxpayerTypeCodebook::accountingDecreeLabel($decree) . '. Aplikace umí rozvahu '
                . 'a výkaz zisku a ztráty pouze podle vyhlášky 500/2002 Sb. a do přiznání zapisuje '
                . 'uv_vyhl=500 — příloha účetní závěrky by tvrdila nepravdu.',
            'action' => 'Přiznání i přílohu účetní závěrky sestavte v portálu EPO ručně.',
        ];
    }

    /** @param list<array{key:string,severity:string,message:string,action:string}> $findings */
    private static function checkEntityStatus(array $supplier, array &$findings): void
    {
        $status = trim((string) ($supplier['tax_entity_status'] ?? 'normal'));
        if ($status === '' || $status === 'normal') {
            return;
        }
        $types = TaxpayerTypeCodebook::STATUS_RETURN_TYPES[$status] ?? [];
        $typeList = [];
        foreach ($types as $t) {
            $typeList[] = $t . ' (' . TaxpayerTypeCodebook::returnTypeLabel($t) . ')';
        }
        $date = trim((string) ($supplier['tax_entity_status_date'] ?? ''));
        $findings[] = [
            'key' => 'entity_status_unsupported',
            'severity' => self::SEVERITY_BLOCKER,
            'message' => 'Poplatník je vedený ve stavu „' . TaxpayerTypeCodebook::entityStatusLabel($status) . '"'
                . ($date !== '' ? ' od ' . $date : ' (rozhodný den není vyplněn)') . '. Takový stav mění '
                . 'typ přiznání i zdaňovací období: úřad očekává '
                . ($typeList === [] ? 'jiný typ přiznání' : 'typ přiznání ' . implode(' nebo ', $typeList))
                . ', kdežto aplikace posílá vždy typ A (daňové přiznání za zdaňovací období) '
                . 'za celý kalendářní nebo hospodářský rok.',
            'action' => 'Přiznání za toto období podejte v portálu EPO ručně se správným typem přiznání. '
                . 'Po skončení stavu vraťte v nastavení firmy stav „běžný".',
        ];
    }

    /** @param list<array{key:string,severity:string,message:string,action:string}> $findings */
    private static function checkForeignSeat(array $supplier, array &$findings): void
    {
        $code = TaxpayerTypeCodebook::normalize($supplier['epo_taxpayer_code'] ?? null)
            ?? TaxpayerTypeCodebook::DEFAULT_CODE;
        $warning = self::foreignSeatWarning((string) ($supplier['country_iso2'] ?? 'CZ'), $code);
        if ($warning === null) {
            return;
        }
        $findings[] = [
            'key' => 'foreign_seat',
            'severity' => self::SEVERITY_WARNING,
            'message' => $warning,
            'action' => 'Je-li poplatník nerezident, přiznání sestavte ručně — typ poplatníka 2 '
                . 'aplikace nepodporuje.',
        ];
    }

    /** @param list<array{key:string,severity:string,message:string,action:string}> $findings */
    private static function checkPublicBenefit(array $supplier, string $nace, array &$findings): void
    {
        if (!empty($supplier['tax_public_benefit'])) {
            $findings[] = [
                'key' => 'public_benefit',
                'severity' => self::SEVERITY_BLOCKER,
                'message' => 'Firma je vedená jako veřejně prospěšný poplatník (§ 17a ZDP). '
                    . 'Přiznání takového poplatníka stojí na dělení příjmů z hlavní a doplňkové '
                    . 'činnosti a na snížení základu daně podle § 20 odst. 7 (ř. 251); aplikace ani '
                    . 'jedno nevede a účetní výkazy sestavuje podle vyhlášky 500/2002 Sb. místo 504/2002 Sb.',
                'action' => 'Přiznání sestavte v portálu EPO ručně (typ poplatníka 3).',
            ];

            return;
        }
        if (self::naceMatches($nace, self::PUBLIC_BENEFIT_NACE)) {
            $findings[] = [
                'key' => 'public_benefit_suspected',
                'severity' => self::SEVERITY_WARNING,
                'message' => 'Hlavní činnost firmy (CZ-NACE ' . $nace . ') je z okruhu organizací '
                    . 'sdružujících osoby, kde bývá poplatník veřejně prospěšný podle § 17a ZDP. '
                    . 'Přiznání se staví jako pro běžnou obchodní korporaci.',
                'action' => 'Ověřte postavení poplatníka; jde-li o veřejně prospěšného poplatníka, '
                    . 'zapněte příznak v nastavení firmy.',
            ];
        }
    }

    /** @param list<array{key:string,severity:string,message:string,action:string}> $findings */
    private static function checkInvestmentIncentive(array $supplier, array &$findings): void
    {
        if (empty($supplier['tax_investment_incentive'])) {
            return;
        }
        $findings[] = [
            'key' => 'investment_incentive',
            'severity' => self::SEVERITY_BLOCKER,
            'message' => 'Firma je vedená jako nositel příslibu investiční pobídky (§ 35a/§ 35b ZDP). '
                . 'Sleva na dani se u ní počítá ze zvláštního výpočtu a její podmínky se sledují roky '
                . 'zpětně mimo účetnictví; aplikace pobídku neeviduje, do přiznání ji nepromítá '
                . 'a posílá typ poplatníka 1 místo 0, 8 nebo 9.',
            'action' => 'Přiznání sestavte v portálu EPO ručně včetně tabulky H přílohy II. oddílu.',
        ];
    }

    /** @param list<array{key:string,severity:string,message:string,action:string}> $findings */
    private static function checkAtadCfc(array $supplier, array &$findings): void
    {
        if (empty($supplier['tax_atad_cfc'])) {
            return;
        }
        $findings[] = [
            'key' => 'atad_cfc',
            'severity' => self::SEVERITY_BLOCKER,
            'message' => 'Firma je vedená jako dotčená pravidly ATAD/CFC. Omezení uznatelnosti '
                . 'nadměrných výpůjčních výdajů (§ 23e-23h ZDP) ani zahrnutí příjmů ovládané '
                . 'zahraniční společnosti (§ 38fa ZDP) aplikace nepočítá — základ daně by byl podhodnocený.',
            'action' => 'Úpravy základu daně podle § 23e-23h a § 38fa doplňte ručně a přiznání '
                . 'sestavte v portálu EPO.',
        ];
    }

    /**
     * @param array<string,mixed> $podklady
     * @param list<array{key:string,severity:string,message:string,action:string}> $findings
     */
    private static function checkPeriodShape(array $podklady, array &$findings): void
    {
        $period = is_array($podklady['period'] ?? null) ? $podklady['period'] : null;
        $shape = TaxPeriodShape::classify(
            $period['starts_on'] ?? null,
            $period['ends_on'] ?? null,
        );
        if ($shape === TaxPeriodShape::CALENDAR) {
            return;
        }
        $typZo = TaxPeriodShape::typZo($shape);
        if ($shape === TaxPeriodShape::MISSING) {
            $findings[] = [
                'key' => 'tax_period_missing',
                'severity' => self::SEVERITY_WARNING,
                'message' => 'Účetní období roku není v aplikaci založené, takže se do přiznání '
                    . 'dosadí kalendářní rok a typ zdaňovacího období „A" (§ 21a písm. a) ZDP).',
                'action' => 'Založte účetní období roku, ať zdaňovací období v přiznání odpovídá skutečnosti.',
            ];

            return;
        }
        if ($shape === TaxPeriodShape::FISCAL) {
            $findings[] = [
                'key' => 'tax_period_fiscal_year',
                'severity' => self::SEVERITY_WARNING,
                'message' => 'Poplatník má hospodářský rok (' . $period['starts_on'] . ' - '
                    . $period['ends_on'] . '), do přiznání jde typ zdaňovacího období „B" '
                    . '(§ 21a písm. b) ZDP). Lhůty, zálohy a roční daňové konstanty aplikace '
                    . 'odvozuje od kalendářního roku.',
                'action' => 'Ověřte zdaňovací období, lhůtu pro podání a použité roční sazby '
                    . 'proti hospodářskému roku poplatníka.',
            ];

            return;
        }

        $findings[] = [
            'key' => 'tax_period_atypical',
            'severity' => self::SEVERITY_BLOCKER,
            'message' => 'Účetní období ' . $period['starts_on'] . ' - ' . $period['ends_on']
                . ' neodpovídá kalendářnímu ani hospodářskému roku'
                . ($shape === TaxPeriodShape::LONG ? ' a je delší než dvanáct měsíců' : '')
                . '. Do přiznání by šel typ zdaňovacího období „' . $typZo . '" a typ přiznání „A", '
                . 'jenže takové období úřad označuje vlastním typem přiznání (např. L při změně '
                . 'zdaňovacího období, M za období od vzniku poplatníka) — přiznání by o zdaňovacím '
                . 'období tvrdilo nepravdu.',
            'action' => 'Přiznání za toto období podejte v portálu EPO ručně se správným typem '
                . 'přiznání podle § 21a ZDP.',
        ];
    }

    /** @param list<array{key:string,severity:string,message:string,action:string}> $findings */
    private static function checkPhotovoltaic(string $nace, array &$findings): void
    {
        if (!self::naceMatches($nace, self::PHOTOVOLTAIC_NACE)) {
            return;
        }
        $findings[] = [
            'key' => 'photovoltaic_30b',
            'severity' => self::SEVERITY_WARNING,
            'message' => 'Hlavní činnost firmy (CZ-NACE ' . $nace . ') je výroba elektřiny. '
                . 'Zařízení fotovoltaických elektráren se odpisuje podle § 30b ZDP a evidence '
                . 'majetku ten režim nerozlišuje — ř. 9 tabulky B přílohy č. 1 II. oddílu '
                . '(odpisy podle § 30b) i ř. 12 (účetní odpisy majetku nevymezeného zákonem, '
                . '§ 24 odst. 2 písm. v) ZDP) zůstanou prázdné.',
            'action' => 'Máte-li majetek v režimu § 30b nebo § 24 odst. 2 písm. v), doplňte '
                . 'příslušné řádky tabulky B v portálu EPO.',
        ];
    }

    /**
     * @param array<string,mixed> $podklady
     * @param list<array{key:string,severity:string,message:string,action:string}> $findings
     */
    private static function checkFoDoubleEntry(array $podklady, array &$findings): void
    {
        if (($podklady['accounting_mode'] ?? '') !== 'double_entry') {
            return;
        }
        $findings[] = [
            'key' => 'fo_double_entry_statements',
            'severity' => self::SEVERITY_BLOCKER,
            'message' => 'Fyzická osoba vede podvojné účetnictví (do přiznání jde uc_soust=2). '
                . 'K takovému přiznání patří účetní výkazy (věty VetaUA-UE formuláře DPFDP7), '
                . 'jenže rozvahu a výkaz zisku a ztráty pro fyzickou osobu aplikace nikde nesestavuje '
                . '— podání by odešlo bez povinné přílohy.',
            'action' => 'Účetní výkazy doplňte při odeslání v portálu EPO, nebo přiznání sestavte celé ručně.',
        ];
    }

    /** @param list<array{key:string,severity:string,message:string,action:string}> $findings */
    private static function checkCooperatingPerson(array $supplier, array &$findings): void
    {
        if (empty($supplier['tax_cooperating_person'])) {
            return;
        }
        $findings[] = [
            'key' => 'cooperating_person_13',
            'severity' => self::SEVERITY_BLOCKER,
            'message' => 'Poplatník rozděluje příjmy a výdaje na spolupracující osobu (§ 13 ZDP). '
                . 'Vazbu mezi poplatníky aplikace nevede: dílčí základ § 7 se počítá z celého '
                . 'peněžního deníku, podíl převedený na spolupracující osobu se z něj neodečte '
                . 'a přiznání by vykázalo vyšší základ daně, než jaký poplatníkovi náleží.',
            'action' => 'Rozdělení podle § 13 promítněte ručně a přiznání dokončete v portálu EPO.',
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $inputs
     * @param list<array{key:string,severity:string,message:string,action:string}> $findings
     */
    private static function checkForeignIncomeCredit(array $supplier, array $result, array $inputs, array &$findings): void
    {
        if (!empty($supplier['tax_foreign_income_credit'])) {
            $findings[] = [
                'key' => 'foreign_income_credit_38f',
                'severity' => self::SEVERITY_BLOCKER,
                'message' => 'Poplatník má zahraniční příjmy se zápočtem daně zaplacené v zahraničí '
                    . '(§ 38f ZDP). Zápočet se vyčísluje v Příloze č. 3 přiznání po jednotlivých '
                    . 'státech a aplikace evidenci příjmů a daní po státech nevede — Příloha č. 3 '
                    . 'se nesestaví a zápočet by v přiznání chyběl.',
                'action' => 'Přílohu č. 3 vyplňte v portálu EPO ručně.',
            ];

            return;
        }
        $separateBase = (float) ($inputs['s16a_separate_base'] ?? $result['s16a_separate_base'] ?? 0);
        if ($separateBase > 0.0) {
            $findings[] = [
                'key' => 'foreign_income_suspected',
                'severity' => self::SEVERITY_WARNING,
                'message' => 'Přiznání obsahuje samostatný základ daně podle § 16a ZDP (zahraniční '
                    . 'podíly na zisku), ale příznak zahraničních příjmů se zápočtem daně (§ 38f) '
                    . 'je vypnutý. Příloha č. 3 se proto nesestaví.',
                'action' => 'Byla-li ze zahraničních příjmů sražena daň v zahraničí, zapněte příznak '
                    . 'v nastavení firmy a Přílohu č. 3 vyplňte v portálu EPO.',
            ];
        }
    }

    /**
     * Ruční seznam nepodporovaných situací z roční uzávěrky daňové evidence
     * (`tax_evidence_annual_closings.unsupported_cases`) — vyplňuje ho účetní.
     * Slévá se sem, aby měla obsluha jeden seznam nálezů, ne dva.
     *
     * @param array<string,mixed> $podklady
     * @param list<array{key:string,severity:string,message:string,action:string}> $findings
     */
    private static function checkManualUnsupportedCases(array $podklady, array &$findings): void
    {
        $closing = is_array($podklady['closing'] ?? null) ? $podklady['closing'] : null;
        $cases = $closing !== null ? (array) ($closing['unsupported_cases'] ?? []) : [];
        if ($cases === []) {
            return;
        }
        $labels = [];
        foreach ($cases as $case) {
            $label = is_array($case) ? (string) ($case['label'] ?? $case['note'] ?? '') : (string) $case;
            $label = trim($label);
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        $findings[] = [
            'key' => 'manual_unsupported_cases',
            'severity' => self::SEVERITY_BLOCKER,
            'message' => 'Roční uzávěrka daňové evidence obsahuje situace označené účetní jako '
                . 'vyžadující ruční zpracování'
                . ($labels === [] ? '.' : ': ' . implode('; ', $labels) . '.'),
            'action' => 'Situace vyřešte a odškrtněte v roční uzávěrce daňové evidence, '
                . 'nebo přiznání dokončete v portálu EPO ručně.',
        ];
    }

    /** @param list<string> $prefixes */
    private static function naceMatches(string $nace, array $prefixes): bool
    {
        if ($nace === '') {
            return false;
        }
        foreach ($prefixes as $prefix) {
            if (str_starts_with($nace, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.taxpayer_type, s.cz_nace_code,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.epo_taxpayer_code, s.tax_entity_status, s.tax_entity_status_date,
                    s.tax_accounting_decree, s.tax_investment_incentive, s.tax_atad_cfc,
                    s.tax_public_benefit, s.tax_cooperating_person, s.tax_foreign_income_credit
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? [] : $row;
    }
}
