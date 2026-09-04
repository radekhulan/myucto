<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Accounting\Expense\ExpenseKindClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * §DM — klasifikace řádku přijaté faktury. Pure, bez DB.
 *
 * Testy jsou psané proti REÁLNÝM textům z hlavní knihy účetní za 2025 (účty 501.100/501.200/
 * 518.100), ne proti vymyšleným vzorkům.
 */
final class ExpenseKindClassifierTest extends TestCase
{
    private const LIMIT = 80000.0;   // §26/2 ZDP

    private ExpenseKindClassifier $c;

    protected function setUp(): void
    {
        $this->c = new ExpenseKindClassifier();
    }

    #[DataProvider('smallAssetTexts')]
    public function testRecognisesSmallAssetsFromAccountantsLedger(string $text): void
    {
        $s = $this->c->classify($text, 'Alza.cz a.s.', null, 5000.0, self::LIMIT);

        self::assertNotNull($s, "„{$text}" . '" má být rozpoznáno.');
        self::assertSame(ExpenseKind::SmallAsset, $s->kind, "„{$text}" . '" = drobný majetek.');
    }

    /** @return iterable<array{string}> */
    public static function smallAssetTexts(): iterable
    {
        // doslovné popisy z účtu 501.200 účetní
        yield ['tablet Galaxy'];
        yield ['kávovar'];
        yield ['skartovač'];
        yield ['tp-link'];
        yield ['flash disk'];
        yield ['ochr. sklo'];
        yield ['Notebook Dell Latitude'];   // skloňování/velká písmena
        yield ['2x monitor 27"'];
        yield ['záložní zdroj UPS'];
        yield ['wallbox pro firemní vozidlo'];
    }

    #[DataProvider('materialTexts')]
    public function testRecognisesMaterialIncludingFuel(string $text): void
    {
        $s = $this->c->classify($text, 'AXIGON a.s.', null, 1500.0, self::LIMIT);

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::Material, $s->kind, "„{$text}" . '" = materiál, ne drobný majetek.');
    }

    /** @return iterable<array{string}> */
    public static function materialTexts(): iterable
    {
        // PHM je materiál (501.100 u účetní) a do evidence drobného majetku NESMÍ
        yield ['phm'];
        yield ['Nafta'];
        yield ['Palivo do vozu'];     // #2: „palivo" doplněno vedle nafta/benzin/PHM
        yield ['natural 95'];
        yield ['kabeláž'];
        yield ['toner do tiskárny'];
    }

    /**
     * Pojištění je druhem SLUŽBA, ale patří na 548 (vyhl. 500/2002 F.5.), NE na default 518.
     * Adresný účet (548) musí přijít z klasifikátoru a být auto-použitelný, aby to „sedělo na účetní".
     */
    #[DataProvider('insuranceTexts')]
    public function testInsuranceGoesToService548(string $text): void
    {
        $s = $this->c->classify($text, 'Generali Česká pojišťovna a.s.', null, 4200.0, self::LIMIT);

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::Service, $s->kind, 'Pojištění je druhem služba.');
        self::assertSame('548', $s->accountCode, 'Pojistné → 548, ne 518.');
        self::assertTrue($s->isAutoApplicable(), 'Pojistné → 548 je jednoznačné, smí se použít bez potvrzení.');
    }

    /** @return iterable<array{string}> */
    public static function insuranceTexts(): iterable
    {
        yield ['Pojištění vozidla 2025'];
        yield ['Havarijní pojištění'];
        yield ['Povinné ručení – vozidlo 9AV 3443'];
        yield ['Pojistné za období 1-12/2025'];
    }

    /**
     * Servis vozu / opravy / údržba → 511 (Opravy a udržování), NE default 518. Klíčový požadavek
     * rekonciliace 2024 (servis vozu → 511). „servis vozu" nese i slovo „servis", které je jinak
     * v NEGATIVE — přesto musí skončit adresně na 511, ne na slabé službě 518.
     */
    #[DataProvider('repairTexts')]
    public function testCarServiceAndRepairsGoTo511(string $text): void
    {
        $s = $this->c->classify($text, 'NC AUTO s.r.o.', null, 8500.0, self::LIMIT);

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::Service, $s->kind, 'Oprava/servis je druhem služba.');
        self::assertSame('511', $s->accountCode, 'Opravy a udržování → 511, ne 518.');
        self::assertTrue($s->isAutoApplicable(), 'Jednoznačná oprava/údržba → 511 smí se použít bez potvrzení.');
    }

    /** @return iterable<array{string}> */
    public static function repairTexts(): iterable
    {
        yield ['Servis vozu BMW 330d'];
        yield ['Autoservis - výměna oleje'];
        yield ['Oprava vozidla po nehodě'];
        yield ['Opravy a udržování stroje'];
        yield ['Údržba klimatizace'];
        yield ['Pneuservis - přezutí'];
        yield ['Servisní prohlídka vozidla'];
    }

    /**
     * PHM je materiál a účtuje se na 501 (kind=Material → posting_rules invoice.material.received,
     * fallback 501). Účet klasifikátor NEsetuje adresně — nechává ho na kontaci druhu, aby si ho
     * tenant mohl přesměrovat přes posting_rules bez zásahu do kódu.
     */
    public function testFuelStaysMaterialWithoutHardcodedAccount(): void
    {
        $s = $this->c->classify('PHM Natural 95', 'AXIGON a.s.', null, 1800.0, self::LIMIT);

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::Material, $s->kind, 'PHM je materiál → 501 přes kontaci druhu.');
        self::assertNull($s->accountCode, 'PHM účet neurčuje klasifikátor adresně — 501 řeší posting_rules druhu.');
    }

    /**
     * Holé „servis" je ZÁMĚRNĚ moc obecné (IT servis, servisní poplatek SaaS) — nesměruje na 511,
     * zůstává slabou službou (default 518), ať se autoservisní 511 nerozlije na softwarové služby.
     */
    public function testBareServisIsNotRoutedTo511(): void
    {
        $s = $this->c->classify('Servisní poplatek za SaaS', 'Dodavatel s.r.o.', null, 5000.0, self::LIMIT);

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::Service, $s->kind);
        self::assertNull($s->accountCode, 'Obecný „servis" nesmí adresně mířit na 511.');
        self::assertFalse($s->isAutoApplicable(), 'Nejednoznačný „servis" je jen slabý návrh na 518.');
    }

    /**
     * Konfigurovatelnost: pravidlo tenanta (fromRules) má přednost před vestavěným keyword účtem.
     * Tenant s vlastní analytikou 511100 pro autoservis přebije default 511.
     */
    public function testTenantRuleOverridesBuiltInRepairAccount(): void
    {
        $rules = [[
            'name' => 'NC AUTO — servis na 511100',
            'vendor_name_contains' => 'NC AUTO',
            'description_contains' => null,
            'vendor_client_id' => null,
            'expense_kind' => 'service',
            'target_account_code' => '511100',
            'is_active' => true,
        ]];

        $s = $this->c->classify('Servis vozu BMW', 'NC AUTO s.r.o.', null, 8500.0, self::LIMIT, $rules);

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::Service, $s->kind);
        self::assertSame('511100', $s->accountCode, 'Pravidlo tenanta přebíjí vestavěný účet 511.');
    }

    /**
     * PAST, kvůli které „telefon" NENÍ silné klíčové slovo: účetní vede „telefon Samsung"
     * na 501.200, ale „telefony"/vyúčtování operátora na 518.100. Kdyby holé „telefon"
     * účtovalo samo, každá faktura od Vodafonu by skončila jako drobný majetek.
     */
    public function testOperatorInvoiceIsNotAutoClassifiedAsSmallAsset(): void
    {
        $s = $this->c->classify('Vyúčtování služeb - telefonní tarif', 'Vodafone Czech Republic a.s.', null, 1475.0, self::LIMIT);

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::Service, $s->kind, 'Vyúčtování operátora je služba.');
    }

    public function testBareMobileTextIsOnlyASuggestionNeverAutoApplied(): void
    {
        $s = $this->c->classify('mobil', 'Neznámý dodavatel', null, 3000.0, self::LIMIT);

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::SmallAsset, $s->kind);
        self::assertFalse($s->isAutoApplicable(), 'Slabá shoda nesmí zaúčtovat sama — jen návrh ke kontrole.');
    }

    #[DataProvider('ambiguousPrefixTexts')]
    public function testAmbiguousPrefixesDoNotClassifyUnrelatedCosts(string $text): void
    {
        $s = $this->c->classify($text, null, null, 3000.0, self::LIMIT);

        self::assertTrue(
            $s === null || !in_array($s->kind, [ExpenseKind::Material, ExpenseKind::SmallAsset, ExpenseKind::FixedAsset], true),
            "„{$text}" . '" nesmí klasifikovat obecný prefix jako PHM nebo majetek.',
        );
    }

    /** @return iterable<array{string}> */
    public static function ambiguousPrefixTexts(): iterable
    {
        yield ['Nastavení serveru'];
        yield ['Supermarket supplies'];
    }

    /** Negativní klíčová slova jsou tvrdá pojistka: Alza prodá notebook i dopravu na jedné faktuře. */
    #[DataProvider('negativeTexts')]
    public function testNegativeKeywordsPreventSmallAssetEvenForGoodsVendor(string $text): void
    {
        $s = $this->c->classify($text, 'Alza.cz a.s.', null, 500.0, self::LIMIT);

        self::assertNotNull($s);
        self::assertNotSame(ExpenseKind::SmallAsset, $s->kind, "„{$text}" . '" nesmí být drobný majetek.');
    }

    /** @return iterable<array{string}> */
    public static function negativeTexts(): iterable
    {
        yield ['Doprava'];
        yield ['Poštovné'];
        yield ['Prodloužená záruka k notebooku'];   // obsahuje i „notebook"!
        yield ['Licence software'];
        yield ['Předplatné hosting'];
        yield ['Nájem kanceláře'];
        yield ['Sleva na dopravné'];
        // Reálný nález z backfillu 2025: Vodafone „Pronájem zařízení – MODUL CA1" navrhoval
        // drobný majetek. Shoda je na PREFIX slova, takže „najem" uvnitř „pronajem" nechytne —
        // pronájem musí být vlastní klíčové slovo. Pronajatá věc navíc pořízením není vůbec.
        yield ['Pronájem zařízení – MODUL CA1'];
        yield ['Pronájem zařízení – Vodafone Station Wi-Fi'];
        // Reálný nález (doklad 2986786906, Alza): „Nehmotný produkt Roční členství AlzaPlus+"
        // propadlo na drobný majetek, protože nemělo žádné negativní slovo — na rozdíl od
        // dvou dalších „Nehmotný produkt …" řádků (doručení/sleva), které chytly dopravu.
        // Nehmotné plnění NIKDY není drobný HMOTNÝ majetek (501.200).
        yield ['Nehmotný produkt Roční členství AlzaPlus+'];
        yield ['Členství AlzaPlus'];
        yield ['Nehmotný produkt - digitální licence'];
    }

    /**
     * REGRESE (reálná škoda z backfillu 2025): negativní klíčové slovo je VETO na drobný
     * majetek, NE důkaz služby.
     *
     * Karta vozu BMW má v popisu „…, Záruka do: 5/2028" jako ATRIBUT vozu. Slovo „záruka"
     * ho označilo za službu s jistotou 0,9, backfill návrh sám použil a přeúčtoval pořízení
     * vozu za 1 157 024,79 Kč z účtu 042 na 518. Návrh smí být jen SLABÝ — neurčeno se
     * stejně účtuje na 518, takže se tím nic neztrácí, jen se to samo nerozhodne.
     */
    public function testNegativeKeywordAloneNeverAutoClassifiesAsService(): void
    {
        $s = $this->c->classify(
            'Prodej vozu BMW 330d xDrive Touring, SPZ: 9AV 3443, Rok první registrace: 2023, Záruka do: 5/2028',
            'Jan Novák',
            null,
            1157024.79,
            self::LIMIT,
        );

        self::assertNotNull($s);
        self::assertFalse(
            $s->isAutoApplicable(),
            'Samotné „záruka" v popisu vozu nesmí automat přeúčtovat na služby — 042 by přišlo o pořízení majetku.',
        );
    }

    /** §26/2 ZDP: nad limit to není drobný majetek, ale DHM na 042 → odpisy. */
    public function testUnitPriceOverLimitBecomesFixedAsset(): void
    {
        $s = $this->c->classify('Notebook Dell', 'Alza.cz a.s.', null, 95000.0, self::LIMIT);

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::FixedAsset, $s->kind);
        self::assertStringContainsString('§26/2', $s->reason, 'Důvod má citovat pravidlo, ať je vidět PROČ.');
    }

    /** Limit platí NA KUS, ne na řádek — 2 ks po 50 000 je pořád drobný majetek. */
    public function testLimitAppliesPerUnitNotPerLine(): void
    {
        $s = $this->c->classify('Notebook Dell', 'Alza.cz a.s.', null, 50000.0, self::LIMIT);

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::SmallAsset, $s->kind);
    }

    public function testUnknownTextStaysUnclassifiedRatherThanGuessing(): void
    {
        // §DM „Nehádej": co nejde určit, zůstane na 518 a vypíše se do reportu.
        self::assertNull($this->c->classify('Zúčtování 4/2025', 'Kdosi s.r.o.', null, 100.0, self::LIMIT));
    }

    public function testRuleWinsOverKeywordsAndIsAutoApplicable(): void
    {
        $rules = [[
            'name' => 'Alza — drobný majetek',
            'vendor_name_contains' => 'Alza',
            'description_contains' => null,
            'vendor_client_id' => null,
            'expense_kind' => 'small_asset',
            'is_active' => true,
        ]];

        $s = $this->c->classify('Nerozpoznatelný text XYZ', 'Alza.cz a.s.', null, 1000.0, self::LIMIT, $rules);

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::SmallAsset, $s->kind);
        self::assertTrue($s->isAutoApplicable(), 'Explicitní pravidlo uživatele je jistota 1,0.');
        self::assertStringContainsString('Alza — drobný majetek', $s->reason);
    }

    public function testGeneratedSuggestionRuleNeverAutoApplies(): void
    {
        $rules = [[
            'name' => 'Návrh asistenta',
            'vendor_name_contains' => 'Testovací dodavatel',
            'description_contains' => 'pracovní stanice',
            'vendor_client_id' => null,
            'expense_kind' => 'small_asset',
            'application_mode' => 'suggest',
            'is_active' => true,
        ]];

        $s = $this->c->classify(
            'Pracovní stanice pro kancelář',
            'Testovací dodavatel s.r.o.',
            null,
            25_000.0,
            self::LIMIT,
            $rules,
        );

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::SmallAsset, $s->kind);
        self::assertFalse($s->isAutoApplicable(), 'Pravidlo vytvořené asistentem musí nejprve zůstat jen návrhem.');
    }

    /** Pravidlo sedí na dodavatele, ale „doprava" ho přebije — dodavatel sám nestačí. */
    public function testNegativeKeywordOverridesEvenAnExplicitRule(): void
    {
        $rules = [[
            'name' => 'Alza — drobný majetek',
            'vendor_name_contains' => 'Alza',
            'description_contains' => null,
            'vendor_client_id' => null,
            'expense_kind' => 'small_asset',
            'is_active' => true,
        ]];

        $s = $this->c->classify('Doprava zásilky', 'Alza.cz a.s.', null, 150.0, self::LIMIT, $rules);

        self::assertNotNull($s);
        self::assertNotSame(ExpenseKind::SmallAsset, $s->kind, 'Doprava od Alzy není drobný majetek.');
    }

    public function testRuleDoesNotMatchDifferentVendor(): void
    {
        $rules = [[
            'name' => 'Alza — drobný majetek',
            'vendor_name_contains' => 'Alza',
            'description_contains' => null,
            'vendor_client_id' => null,
            'expense_kind' => 'small_asset',
            'is_active' => true,
        ]];

        // Text sám o sobě nerozpoznatelný + jiný dodavatel ⇒ nic.
        self::assertNull($this->c->classify('Nerozpoznatelný text XYZ', 'Mironet.cz a.s.', null, 1000.0, self::LIMIT, $rules));
    }

    public function testInactiveRuleIsIgnored(): void
    {
        $rules = [[
            'name' => 'Vypnuté pravidlo',
            'vendor_name_contains' => 'Alza',
            'description_contains' => null,
            'vendor_client_id' => null,
            'expense_kind' => 'small_asset',
            'is_active' => false,
        ]];

        self::assertNull($this->c->classify('Nerozpoznatelný text XYZ', 'Alza.cz a.s.', null, 1000.0, self::LIMIT, $rules));
    }

    /** Kotva na hranici slova zleva: „mobil" se nesmí najít uvnitř „automobilka". */
    public function testWordBoundaryPreventsSubstringFalsePositives(): void
    {
        self::assertNull(
            $this->c->classify('Automobilka XYZ', 'Kdosi', null, 100.0, self::LIMIT),
            '„automobilka" nesmí matchnout klíčové slovo „mobil".',
        );
    }

    /** Skloňování: čeština ohýbá, shoda musí být na prefix slova. */
    public function testInflectedFormsAreRecognised(): void
    {
        foreach (['notebooky pro tým', 'kabely HDMI', 'tablety Samsung'] as $text) {
            self::assertNotNull($this->c->classify($text, 'Alza', null, 1000.0, self::LIMIT), "„{$text}" . '" má projít.');
        }
    }

    /**
     * Hranice § 26 odst. 2 je OSTRÁ: hmotným majetkem je věc se vstupní cenou
     * „vyšší než" limit, takže přesně 80 000 Kč jím ještě není.
     *
     * Bod přesně na limitu byl jediné místo, kde si klasifikátor (`>=` ⇒ DHM)
     * a `AssetService` (`input_price <= limit` ⇒ varování „nepřesahuje hranici")
     * navzájem odporovaly — obě větve se trefily naráz a řekly si opak.
     * Zákonu odpovídá AssetService.
     *
     * @return iterable<string, array{float, ExpenseKind}>
     */
    public static function assetLimitBoundary(): iterable
    {
        yield 'těsně pod limitem'   => [79_999.99, ExpenseKind::SmallAsset];
        yield 'přesně na limitu'    => [80_000.00, ExpenseKind::SmallAsset];
        yield 'o haléř nad limitem' => [80_000.01, ExpenseKind::FixedAsset];
    }

    #[DataProvider('assetLimitBoundary')]
    public function testAssetLimitBoundaryIsStrictlyGreaterThan(float $unitPrice, ExpenseKind $expected): void
    {
        $s = $this->c->classify('notebook Lenovo', 'Alza.cz a.s.', null, $unitPrice, self::LIMIT);

        self::assertNotNull($s);
        self::assertSame(
            $expected,
            $s->kind,
            sprintf('Cena za kus %s Kč při limitu %s Kč (§ 26/2 ZDP).', $unitPrice, self::LIMIT),
        );
    }
}
