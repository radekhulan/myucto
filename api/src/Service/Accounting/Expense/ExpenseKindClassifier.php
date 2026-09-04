<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Expense;

use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use MyInvoice\Service\Logbook\Fuel\FuelKeywords;

/**
 * Klasifikace řádku přijaté faktury na druh výdaje (§DM). Pure, bez DB, jednotkově
 * testovatelná — týž vzor jako BankRuleMatcher.
 *
 * Tři vrstvy v tomhle pořadí:
 *   1. PRAVIDLO (dodavatel + fragment textu) — deterministické, vysvětlitelné, jistota 1,0.
 *   2. VESTAVĚNÁ KLÍČOVÁ SLOVA — jistota 0,9 (silná) nebo 0,6 (slabá, jen návrh).
 *   3. nic → NULL: řádek zůstane na 518 a VYPÍŠE SE do reportu. Tiché přeúčtování je horší
 *      než žádné, takže co si nejsme jistí, needitujeme (§DM „Nehádej").
 *
 * NEGATIVNÍ KLÍČOVÁ SLOVA jsou tvrdá pojistka, ne heuristika: text se slovem „služba",
 * „doprava", „záruka"… NIKDY nesmí být small_asset, ani když dodavatel sedí. Alza prodá
 * notebook i dopravu na jedné faktuře, takže dodavatel sám nestačí.
 *
 * ⚠️ PAST „telefon": účetní vede „telefon Samsung" na 501.200 (drobný majetek), ale
 * „telefony" (vyúčtování Vodafone) na 518.100 (služba). Holé „telefon"/„mobil" proto NENÍ
 * silné klíčové slovo — jen slabý návrh ke kontrole. Jinak by každá faktura od operátora
 * skončila jako drobný majetek.
 */
final class ExpenseKindClassifier
{
    public const CONF_RULE = 1.0;
    public const CONF_STRONG = 0.9;
    public const CONF_WEAK = 0.6;

    /** Práh pro automatické použití bez potvrzení. Radši vyšší — slabý důkaz neúčtuje. */
    public const AUTO_THRESHOLD = 0.9;

    /**
     * Text, po kterém řádek NIKDY není drobný majetek. Normalizovaně (bez diakritiky).
     * Rozšířeno oproti zadání o `vyuctovani`/`tarif`/`pausal`/`poplatek`/`servis`/`oprava`:
     * to jsou přesně ty texty, kterými operátoři a servisy popisují SLUŽBU, a bez nich by
     * past „telefon" výš prošla.
     */
    private const NEGATIVE = [
        'sluzba', 'sluzby', 'sluzeb', 'doprava', 'dopravne', 'postovne', 'zaruka', 'zaruky',
        // Doručení/přeprava musí být tady vedle „doprava". Reálný nález na faktuře Alzy:
        // „Doručení na prodejnu" +37,19 propadlo na drobný majetek, kdežto jeho protipól
        // „Sleva na dopravné" −37,19 chytila „dopravne" a šla na služby. Pár se pak
        // nevyrušil a rozhodil rozpad o svou vlastní částku na obě strany.
        'doruceni', 'dorucne', 'preprava', 'balne', 'expedice',
        'licence', 'licenci', 'predplatne', 'hosting', 'najem', 'najemne', 'vyuctovani',
        'tarif', 'pausal', 'poplatek', 'poplatky', 'servis', 'oprava', 'opravy', 'udrzba',
        'pojisteni', 'skoleni', 'konzultace', 'instalace', 'montaz', 'nastaveni',
        // Nehmotné plnění NIKDY není drobný HMOTNÝ majetek (501.200). Reálný nález: Alza
        // popisuje „Nehmotný produkt Roční členství AlzaPlus+" (i doručení/slevu) prefixem
        // „Nehmotný produkt". Členství/předplatné je služba (518), ne věc — bez tohoto veta
        // ho backfill celého dokladu na 501.200 nechal jako drobný majetek (doklad 2986786906).
        'nehmotny', 'nehmotne', 'clenstvi', 'clenske',
        // Pronájem/půjčovné: pořízením NEJSOU, věc zůstává cizí. Vlastní položka je nutná,
        // protože shoda je na PREFIX slova — „najem" v „pronajem" nezačíná na hranici slova.
        // Reálný nález: Vodafone „Pronájem zařízení – MODUL CA1" navrhoval drobný majetek.
        'pronajem', 'pronajmu', 'pujcovne', 'vypujcka', 'leasing',
    ];

    /** Jednoznačný drobný majetek — věc, ne služba. */
    private const SMALL_ASSET_STRONG = [
        'tablet', 'notebook', 'kavovar', 'skartovac', 'tp link', 'flash disk', 'flashdisk',
        'monitor', 'ochr sklo', 'ochranne sklo', 'kryt na mobil', 'dr majetek', 'drobny majetek',
        'klavesnice', 'tiskarna', 'router', 'dokovaci stanice', 'webkamera',
        'sluchatka', 'reproduktor', 'pevny disk',
        // Notebooky/značky — „Lenovo ThinkPad X1 Carbon" nemá slovo „notebook", takže bez
        // těchto klíčů propadne (reálný nález PF 234). Značka počítače = drobný majetek pod
        // limitem; nad limit ho §26/2 stejně překlopí na dlouhodobý (řeší práh v classify()).
        'thinkpad', 'macbook', 'ipad', 'notebook', 'ultrabook', 'chromebook', 'switch qnap',
        'powerbank', 'nas server', 'zalozni zdroj', 'wallbox',
    ];

    /** Nasvědčuje drobnému majetku, ale samo o sobě nestačí → jen návrh ke kontrole. */
    private const SMALL_ASSET_WEAK = [
        'telefon', 'mobil', 'mob tel', 'vybaveni', 'kabely', 'kabel', 'kryt', 'folie',
        'dr vydaje', 'zarizeni',
    ];

    /**
     * OSOBNÍ / daňově NEUZNATELNÉ (§25 ZDP) — jednoznačné vzory. Optika a dioptrické brýle jsou
     * osobní potřeba, ne firemní výdaj (typicky → 528). Druh výdaje zůstává
     * SLUŽBA (není to majetek ani materiál), ale nese příznak `nonDeductible` → editor nabídne
     * nedaňový účet (528/513) a ř.40 DPPO ho pak sečte. Confidence je ZÁMĚRNĚ jen WEAK: pure
     * klasifikátor nezná účtovou osnovu tenanta, takže konkrétní nedaňový účet neurčuje sám —
     * automatické směrování na 528 je věcí SEED pravidel (target_account_code), ne kódu.
     */
    private const NON_DEDUCTIBLE_PERSONAL_STRONG = [
        'optika', 'bryle', 'bryli', 'dioptr', 'dioptricke',
    ];

    /**
     * MOŽNÁ osobní — ale i legitimní firemní, proto jen návrh ke kontrole. Chytré hodinky můžou
     * být osobní i firemní (test/fitness), a POZOR: „masáž/wellness" ZÁMĚRNĚ NENÍ v seznamu —
     * v praxi vede masážní křeslo na 501 (drobný majetek), ne na nedaňové. Nejednoznačné
     * se nechává na účetní (§DM „Nehádej").
     */
    private const NON_DEDUCTIBLE_PERSONAL_WEAK = [
        'chytre hodinky', 'smartwatch', 'smart watch', 'apple watch', 'garmin', 'fenix',
    ];

    /**
     * Spotřební materiál. PHM tu ZÁMĚRNĚ NENÍ — palivo řeší {@see FuelKeywords} jako jediný
     * zdroj pravdy sdílený s knihou tankování (viz fromKeywords). PHM je druhem materiál,
     * NIKOLI drobný majetek.
     */
    private const MATERIAL_STRONG = [
        'dr material', 'drobny material', 'kabelaz', 'toner', 'cartridge',
        'papir', 'kancelarske potreby',
    ];

    /**
     * POJIŠTĚNÍ → adresně 548 (Ostatní provozní náklady, vyhláška 500/2002 F.5.), NE default 518.
     *
     * Druh výdaje zůstává SLUŽBA (evidence, práh §26/2), ale ÚČET je 548 — táž dvojosost
     * jako u pojistného v PostingService::purchaseExpenseWeights („pojistné je druhem SLUŽBA,
     * ale vyhláška 500/2002 ho řadí na 548"). Na rozdíl od nedaňového 528/513 (kde účet volí
     * účetní jednotka, proto NON_DEDUCTIBLE vrací null) je 548 STANDARDNÍ kontace ČÚS platná
     * napříč tenanty → default smí žít v kódu. Tenant ho přesměruje na vlastní analytiku
     * pravidlem (fromRules má přednost před klíčovými slovy) nebo přes posting_rules.
     */
    private const INSURANCE_ACCOUNT = '548';
    private const INSURANCE_STRONG = ['pojisteni', 'pojistne', 'pojistneho', 'havarijni pojisteni', 'povinne ruceni'];

    /**
     * OPRAVY A UDRŽOVÁNÍ → adresně 511, NE default 518. Autoservis, pneuservis, oprava,
     * údržba vozu/stroje/budovy. Účet 511 je v ČÚS správný pro VŠECHNY opravy bez ohledu na to,
     * co se opravuje (auto, budova, stroj), takže default v kódu je věcně jistý, ne odhad.
     *
     * Holé „servis" tu ZÁMĚRNĚ NENÍ — je příliš obecné (IT servis, servisní poplatek SaaS)
     * a zůstává slabou službou na 518 (viz NEGATIVE). Adresně na 511 jde jen jednoznačná
     * OPRAVA / ÚDRŽBA / AUTOSERVIS. Tenant přebije pravidlem (target_account_code) nebo posting_rules.
     */
    private const REPAIR_ACCOUNT = '511';
    private const REPAIR_STRONG = [
        'oprava', 'opravy', 'opravu', 'udrzba', 'udrzbu', 'udrzovani', 'servisni prohlidka',
    ];

    /**
     * OPRAVA VOZIDLA — podmnožina oprav, která smí jít na analytiku servisu vozidel
     * (např. 511.100). Oddělené od obecných oprav schválně: „oprava střechy" je taky 511,
     * ale na analytiku VOZIDEL nepatří. Bez nastavené analytiky se chová stejně jako
     * ostatní opravy (511).
     */
    private const VEHICLE_REPAIR_STRONG = [
        'autoservis', 'pneuservis', 'servis vozu', 'servis vozidla', 'servis vozidel',
        'servis auta', 'stk', 'emise', 'pneu', 'geometrie',
    ];

    /**
     * @param list<array<string,mixed>> $rules pravidla tenanta, seřazená dle priority ASC
     * @param array{fuel?:?string, vehicle_repair?:?string} $accounts analytiky firmy pro PHM
     *        a servis vozidel (z accounting_supplier_settings). Prázdné = firma analytiky
     *        nevede a účet se odvodí postaru z druhu výdaje.
     */
    public function classify(
        string $description,
        ?string $vendorName,
        ?int $vendorClientId,
        float $unitPriceWithoutVat,
        float $fixedAssetLimit,
        array $rules = [],
        array $accounts = [],
        array $catalog = [],
        bool $rulesOnly = false,
    ): ?ExpenseKindSuggestion {
        $text = BankMessageNormalizer::normalizeKeepDigits($description);
        $vendor = $vendorName !== null ? BankMessageNormalizer::normalizeKeepDigits($vendorName) : '';
        $accounts = array_filter($accounts, static fn (?string $v): bool => $v !== null && $v !== '');

        $suggestion = $this->fromRules($text, $vendor, $vendorClientId, $rules);
        if ($suggestion === null && !$rulesOnly) {
            $suggestion = $this->fromCatalog($text, $catalog, $accounts)
                ?? $this->fromKeywords($text, $accounts);
        }

        if ($suggestion === null) {
            return null;
        }

        // POJISTKA NA ANALYTIKU PHM. Pravidla tenanta se běžně matchují jen podle
        // DODAVATELE („vše od AXIGONu → PHM"), takže by na palivovou analytiku spadl
        // i řádek „Mytí vozu", „Dálniční známka" nebo „Občerstvení" z téže benzínky —
        // reálně to dry-run back-fillu 2026 ukázal hned na dvou řádcích.
        //
        // Na účet vyhrazený pro PHM proto smí AUTOMATICKY jen řádek, který jako palivo
        // i vypadá ({@see FuelKeywords} — týž zdroj pravdy jako kniha tankování). Zbytek
        // se nezahazuje: zůstane návrhem se slabou jistotou a BEZ účtu, takže ho automat
        // nepoužije a rozhodne účetní (§DM „Nikdy neúčtuj automaticky, když si nejsi jistý").
        $fuelAccount = $accounts['fuel'] ?? null;
        if ($fuelAccount !== null
            && $suggestion->accountCode === $fuelAccount
            && !FuelKeywords::isFuelForAccounting($text)
        ) {
            return new ExpenseKindSuggestion(
                $suggestion->kind,
                self::CONF_WEAK,
                $suggestion->reason . '; řádek ale nevypadá jako palivo ⇒ účet zkontroluj ručně',
                $suggestion->source,
                null,
                $suggestion->nonDeductible,
            );
        }

        // §26/2 ZDP: hmotná věc nad limit (default 80 000) není drobný majetek, ale DHM —
        // patří na 042 → 02x a odpisuje se. Limit je z TaxConstants (mění se), ne natvrdo.
        // Rozhoduje cena ZA KUS, ne za řádek: 2 ks po 50 000 je pořád drobný majetek.
        //
        // Nerovnost je OSTRÁ: § 26 odst. 2 mluví o vstupní ceně „vyšší než" limit, takže
        // cena přesně 80 000 Kč hmotným majetkem NENÍ. Do fáze F2 tu bylo `>=`, což se
        // v jediném bodě rozcházelo s `AssetService` (`input_price <= $assetLimit` →
        // varování „nepřesahuje hranici"): při ceně přesně 80 000 Kč se trefily obě větve
        // najednou a řekly si opak. Zákonu odpovídá AssetService.
        if ($suggestion->kind === ExpenseKind::SmallAsset && $unitPriceWithoutVat > $fixedAssetLimit) {
            return new ExpenseKindSuggestion(
                ExpenseKind::FixedAsset,
                $suggestion->confidence,
                sprintf(
                    '%s; cena za kus %s Kč > limit %s Kč (§26/2 ZDP) ⇒ dlouhodobý majetek',
                    $suggestion->reason,
                    number_format($unitPriceWithoutVat, 2, ',', ' '),
                    number_format($fixedAssetLimit, 2, ',', ' '),
                ),
                'threshold',
                null,
                $suggestion->nonDeductible,
            );
        }

        return $suggestion;
    }

    /**
     * @param list<array<string,mixed>> $rules
     */
    private function fromRules(string $text, string $vendor, ?int $vendorClientId, array $rules): ?ExpenseKindSuggestion
    {
        foreach ($rules as $rule) {
            if (!($rule['is_active'] ?? true)) {
                continue;
            }
            $kind = ExpenseKind::tryFromNullable((string) ($rule['expense_kind'] ?? ''));
            if ($kind === null) {
                continue;
            }

            // Kritéria jsou nullable a platí AND přes ty vyplněné — týž kontrakt jako
            // bank_posting_rules. Prázdné pravidlo by matchlo všechno, proto ho DB CHECK
            // nepustí; tady se navíc pojistíme počítadlem.
            $matched = 0;

            $ruleClientId = isset($rule['vendor_client_id']) ? (int) $rule['vendor_client_id'] : 0;
            if ($ruleClientId > 0) {
                if ($vendorClientId !== $ruleClientId) {
                    continue;
                }
                $matched++;
            }

            $vendorFragment = self::str($rule['vendor_name_contains'] ?? null);
            if ($vendorFragment !== null) {
                if ($vendor === '' || !str_contains($vendor, BankMessageNormalizer::normalizeKeepDigits($vendorFragment))) {
                    continue;
                }
                $matched++;
            }

            $textFragment = self::str($rule['description_contains'] ?? null);
            if ($textFragment !== null) {
                if (!str_contains($text, BankMessageNormalizer::normalizeKeepDigits($textFragment))) {
                    continue;
                }
                $matched++;
            }

            if ($matched === 0) {
                continue;
            }

            // Negativní slova přebijí i pravidlo: dodavatel sedí, ale „doprava" na jeho
            // faktuře drobný majetek není.
            if ($kind === ExpenseKind::SmallAsset && $this->negativeHit($text) !== null) {
                continue;
            }

            $account = self::str($rule['target_account_code'] ?? null);

            $applicationMode = (string) ($rule['application_mode'] ?? 'auto');
            return new ExpenseKindSuggestion(
                $kind,
                $applicationMode === 'suggest' ? self::CONF_WEAK : self::CONF_RULE,
                'dle pravidla „' . (string) ($rule['name'] ?? '?') . '"'
                    . ($account !== null ? ' → účet ' . $account : ''),
                'rule',
                $account,
            );
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $catalog
     */
    private function fromCatalog(string $text, array $catalog, array $accounts): ?ExpenseKindSuggestion
    {
        if ($catalog === []) {
            return null;
        }

        $vetos = [];
        foreach ($catalog as $entry) {
            if (($entry['polarity'] ?? '') !== 'veto') {
                continue;
            }
            $phrase = self::str($entry['phrase'] ?? null);
            if ($phrase !== null && self::containsWord($text, BankMessageNormalizer::normalizeKeepDigits($phrase))) {
                $vetos[(string) ($entry['concept_key'] ?? '')] = $phrase;
            }
        }

        foreach ($catalog as $entry) {
            if (($entry['polarity'] ?? 'positive') !== 'positive') {
                continue;
            }
            $kind = ExpenseKind::tryFromNullable((string) ($entry['expense_kind'] ?? ''));
            $phrase = self::str($entry['phrase'] ?? null);
            if ($kind === null || $phrase === null
                || !self::containsWord($text, BankMessageNormalizer::normalizeKeepDigits($phrase))) {
                continue;
            }
            if ($kind === ExpenseKind::SmallAsset && isset($vetos['asset_veto'])) {
                continue;
            }
            if (($entry['concept_key'] ?? '') === 'fuel' && isset($vetos['fuel_veto'])) {
                continue;
            }

            $confidence = (float) ($entry['confidence'] ?? self::CONF_WEAK);
            if (!empty($entry['requires_review'])) {
                $confidence = min($confidence, self::CONF_WEAK);
            }
            $concept = (string) ($entry['concept_key'] ?? '');
            $account = self::str($entry['target_account_code'] ?? null);
            if ($concept === 'fuel') {
                $account = $accounts['fuel'] ?? $account;
            } elseif ($concept === 'vehicle_repair') {
                $account = $accounts['vehicle_repair'] ?? $account;
            }
            return new ExpenseKindSuggestion(
                $kind,
                $confidence,
                'vícejazyčný katalog (' . (string) ($entry['locale'] ?? '?') . ') obsahuje „' . $phrase . '“',
                'catalog',
                $account,
                $concept === 'personal',
            );
        }
        return null;
    }

    /** @param array<string,string> $accounts analytiky firmy (fuel, vehicle_repair) */
    private function fromKeywords(string $text, array $accounts = []): ?ExpenseKindSuggestion
    {
        // PHM úplně první a z JEDNOHO zdroje pravdy s knihou tankování ({@see FuelKeywords}).
        // Dokud měl klasifikátor vlastní zkrácený seznam, uměla faktura vyrobit tankování a
        // přitom se zaúčtovat jako obyčejná služba na 518 (reálný případ: „Prémiová nafta"
        // od AXIGONu, kde klasifikátor neznal „premiova"). Účet je analytika PHM z nastavení
        // firmy; NULL = firma analytiku nemá a účet se odvodí z druhu (materiál → 501).
        if (FuelKeywords::isFuelForAccounting($text)) {
            $account = $accounts['fuel'] ?? null;
            return new ExpenseKindSuggestion(
                ExpenseKind::Material,
                self::CONF_STRONG,
                'text odpovídá pohonným hmotám ⇒ PHM' . ($account !== null ? ', účet ' . $account : ''),
                'keyword',
                $account,
            );
        }

        // Materiál: „toner“ apod. — jednoznačné a nesmí je přebít slabá shoda níž.
        if (($hit = $this->firstHit($text, self::MATERIAL_STRONG)) !== null) {
            return new ExpenseKindSuggestion(
                ExpenseKind::Material,
                self::CONF_STRONG,
                'text obsahuje „' . $hit . '" ⇒ spotřební materiál',
                'keyword',
            );
        }

        // Pojištění a opravy jsou druhem SLUŽBA, ale patří na ADRESNÝ účet (548 / 511), ne na
        // default 518 (dvě různé osy — druh vs. účet, viz accountCode). PŘED negativním vetem
        // i drobným majetkem: „oprava"/„udrzba" jsou zároveň v NEGATIVE (vetují drobný majetek),
        // tady je ale chceme rovnou nasměrovat na 511, ne nechat spadnout do slabé služby.
        if (($hit = $this->firstHit($text, self::INSURANCE_STRONG)) !== null) {
            return new ExpenseKindSuggestion(
                ExpenseKind::Service,
                self::CONF_STRONG,
                'text obsahuje „' . $hit . '" ⇒ pojištění, účet 548 (vyhl. 500/2002 F.5.)',
                'keyword',
                self::INSURANCE_ACCOUNT,
            );
        }
        // Servis vozidla PŘED obecnou opravou — „autoservis" je oboje, ale patří adresně
        // na analytiku vozidel, když ji firma vede.
        if (($hit = $this->firstHit($text, self::VEHICLE_REPAIR_STRONG)) !== null) {
            $account = $accounts['vehicle_repair'] ?? self::REPAIR_ACCOUNT;
            return new ExpenseKindSuggestion(
                ExpenseKind::Service,
                self::CONF_STRONG,
                'text obsahuje „' . $hit . '" ⇒ servis vozidla, účet ' . $account,
                'keyword',
                $account,
            );
        }
        if (($hit = $this->firstHit($text, self::REPAIR_STRONG)) !== null) {
            return new ExpenseKindSuggestion(
                ExpenseKind::Service,
                self::CONF_STRONG,
                'text obsahuje „' . $hit . '" ⇒ opravy a udržování, účet 511',
                'keyword',
                self::REPAIR_ACCOUNT,
            );
        }

        // Osobní / nedaňové (§25 ZDP) PŘED drobným majetkem: „dioptrické brýle" nesmí propadnout
        // jako věc/majetek. Vždy jen WEAK (návrh) + příznak nonDeductible; účet nemění (viz konstanty).
        if (($nd = $this->fromNonDeductiblePersonal($text)) !== null) {
            return $nd;
        }

        $negative = $this->negativeHit($text);

        if (($hit = $this->firstHit($text, self::SMALL_ASSET_STRONG)) !== null) {
            if ($negative !== null) {
                // „Prodloužená záruka k notebooku" je služba, ne drobný majetek.
                return new ExpenseKindSuggestion(
                    ExpenseKind::Service,
                    self::CONF_WEAK,
                    'text obsahuje „' . $hit . '", ale zároveň „' . $negative . '" ⇒ spíš služba, zkontroluj',
                    'keyword',
                );
            }
            return new ExpenseKindSuggestion(
                ExpenseKind::SmallAsset,
                self::CONF_STRONG,
                'text obsahuje „' . $hit . '" ⇒ drobný majetek',
                'keyword',
            );
        }

        if ($negative !== null) {
            // ⚠️ SLABĚ, ne silně. Negativní klíčové slovo je VETO na drobný majetek, NE důkaz
            // služby — to jsou dvě různá tvrzení a spletl jsem si je.
            //
            // Reálná škoda: karta vozu BMW má v popisu „…, Záruka do: 5/2028" jako ATRIBUT
            // vozu. Slovo „záruka" ho označilo za službu s jistotou 0,9, backfill to sám
            // použil a přeúčtoval pořízení vozu za 1 157 024,79 z účtu 042 na 518.
            //
            // Neurčeno se stejně účtuje na 518, takže se slabým návrhem nic neztrácí —
            // jen se to samo nerozhodne.
            return new ExpenseKindSuggestion(
                ExpenseKind::Service,
                self::CONF_WEAK,
                'text obsahuje „' . $negative . '" ⇒ spíš služba, zkontroluj',
                'keyword',
            );
        }

        if (($hit = $this->firstHit($text, self::SMALL_ASSET_WEAK)) !== null) {
            return new ExpenseKindSuggestion(
                ExpenseKind::SmallAsset,
                self::CONF_WEAK,
                'text obsahuje „' . $hit . '" — může být i služba (např. vyúčtování operátora), zkontroluj',
                'keyword',
            );
        }

        return null;
    }

    /**
     * Osobní / daňově neuznatelný vzor (§25 ZDP). Vrací návrh druhu SLUŽBA (není to majetek ani
     * materiál) s příznakem `nonDeductible`, VŽDY jen WEAK — nikdy neúčtuje sám a účet nemění;
     * konkrétní nedaňový účet (528/513) je rozhodnutí účetní jednotky (seed pravidel), ne kódu.
     */
    private function fromNonDeductiblePersonal(string $text): ?ExpenseKindSuggestion
    {
        if (($hit = $this->firstHit($text, self::NON_DEDUCTIBLE_PERSONAL_STRONG)) !== null) {
            return new ExpenseKindSuggestion(
                ExpenseKind::Service,
                self::CONF_WEAK,
                'text obsahuje „' . $hit . '" ⇒ pravděpodobně osobní/nedaňový výdaj (§25 ZDP) — '
                    . 'zkontroluj a případně účtuj na nedaňový účet (528/513)',
                'keyword',
                null,
                true,
            );
        }
        if (($hit = $this->firstHit($text, self::NON_DEDUCTIBLE_PERSONAL_WEAK)) !== null) {
            return new ExpenseKindSuggestion(
                ExpenseKind::Service,
                self::CONF_WEAK,
                'text obsahuje „' . $hit . '" — může být osobní/nedaňové (§25 ZDP), zkontroluj',
                'keyword',
                null,
                true,
            );
        }
        return null;
    }

    /** @param list<string> $needles */
    private function firstHit(string $text, array $needles): ?string
    {
        foreach ($needles as $needle) {
            if (self::containsWord($text, $needle)) {
                return $needle;
            }
        }
        return null;
    }

    private function negativeHit(string $text): ?string
    {
        return $this->firstHit($text, self::NEGATIVE);
    }

    /**
     * Shoda na ZAČÁTKU slova (prefix), ne na celém slově.
     *
     * Čeština skloňuje: „notebooky", „kabelů"→„kabelu", „tablety", „kávovaru". Shoda na celé
     * slovo by je všechny minula. Naopak holý `str_contains` by „mobil" našel v „automobilka"
     * a „oprava" v „neopravitelny" — proto kotva na hranici slova ZLEVA. Text je normalizovaný
     * na `[a-z0-9 ]`, takže hranice = začátek řetězce nebo mezera.
     */
    private static function containsWord(string $text, string $needle): bool
    {
        return preg_match('/(?:^| )' . preg_quote($needle, '/') . '/', $text) === 1;
    }

    private static function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
