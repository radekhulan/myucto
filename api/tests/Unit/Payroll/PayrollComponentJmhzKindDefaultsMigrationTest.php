<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceProfileComponents;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSampleProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Výchozí zařazení složek do JMHZ žije v PHP
 * ({@see PayrollComponentJmhzMappingDefaults::targetFor()}) a zmrazené v SQL
 * backfillu 1839. Tenhle test drží obě místa v shodě — pravidlo změněné jen
 * na jedné straně by nové a staré firmy zařadilo různě.
 */
final class PayrollComponentJmhzKindDefaultsMigrationTest extends TestCase
{
    /**
     * Seznam kódů drží víc migrací: 1839 doplnila výchozí číselník, další pak
     * jednotlivé složky, které do číselníku přibyly později. Shodu se zdrojem
     * pravdy ({@see PayrollComponentJmhzMappingDefaults}) musí dávat dohromady.
     *
     * @var list<string>
     */
    private const MIGRATIONS = [
        '1839_payroll_component_jmhz_kind_default_mappings.sql',
        '1847_payroll_component_jmhz_stravovani_mapping.sql',
        '1905_payroll_component_jmhz_compensation_detail_mappings.sql',
        '1926_payroll_standby_rate.sql',
        '1931_payroll_component_vacation_settlement_mapping.sql',
        '1983_payroll_component_meal_allowance_taxable_mapping.sql',
    ];

    /**
     * Kódy, které do výchozího číselníku přibyly až PO přechodu na balík 1903,
     * a dorovnává je proto vlastní pozdější migrace (viz {@see self::MIGRATIONS}).
     *
     * @var list<string>
     */
    private const AFTER_PACKAGE_TRANSITION = ['ODMENA_POHOTOVOST', 'NAHRADA_MZDY_DOVOLENA_VYROVNANI', 'PRISPEVEK_STRAVOVANI_ZDANITELNY'];

    private const PACKAGE_TRANSITION = '1903_payroll_component_jmhz_catalog_1_4_2_10_package.sql';

    /**
     * Výchozí zařazení, která backfill 1839/1903 ještě dělal, ale pravidlo pro
     * NOVĚ zakládané složky už ne: druh => četnost => true. Zařazení, která
     * backfill vytvořil, zůstávají (žádná migrace je nemaže) a účetní je jen
     * neblokujícím upozorněním předložena k ověření
     * ({@see PayrollComponentJmhzMappingDefaults::unverifiedBonusDefaults()}).
     *
     * @var array<string,array<string,true>>
     */
    private const SUPERSEDED_FOR_NEW = [
        'bonus' => ['one_off' => true],
    ];

    /** @var list<string> component_kind z payroll_component_definitions (migrace 1501) */
    private const KINDS = [
        'base_wage', 'hourly_wage', 'task_wage', 'bonus', 'premium', 'commission',
        'allowance', 'compensation', 'severance', 'competitive_clause', 'backpay',
        'non_cash', 'benefit_meal', 'benefit_vehicle', 'benefit_pension', 'benefit_care',
        'benefit_education', 'benefit_recreation', 'benefit_health', 'benefit_accommodation',
        'risky_savings', 'travel_reimbursement', 'other',
    ];

    /** @return iterable<string,array{string,string,string,string,?string}> */
    public static function targets(): iterable
    {
        yield 'hodinová mzda' => ['X', 'hourly_wage', 'one_off', 'included', '10329'];
        yield 'úkolová mzda' => ['X', 'task_wage', 'one_off', 'included', '10329'];
        yield 'měsíční mzda' => ['X', 'base_wage', 'regular', 'included', '10329'];
        yield 'příplatek' => ['X', 'premium', 'one_off', 'included', '10332'];
        // Odměna zadávaná za měsíc může být pravidelná i nepravidelná (Pokyny MH
        // 1.4.14 kap. 3.5.1); rozhodne účetní. Výchozí ODMENA má zařazení kódem.
        yield 'odměna zadávaná za měsíc' => ['X', 'bonus', 'one_off', 'included', null];
        yield 'výchozí odměna číselníku' => ['ODMENA', 'bonus', 'one_off', 'included', '10331'];
        yield 'pravidelná odměna' => ['X', 'bonus', 'regular', 'included', '10330'];
        yield 'zdaněná náhrada' => ['X', 'compensation', 'one_off', 'included', '10337'];
        yield 'osvobozená náhrada (DPN)' => ['X', 'compensation', 'one_off', 'exempt', null];
        yield 'složka podle hlavičky' => ['DOCH_KONTEJNERY', 'other', 'one_off', 'included', null];
        yield 'provize' => ['X', 'commission', 'one_off', 'included', null];
        yield 'jiné plnění' => ['X', 'allowance', 'one_off', 'included', null];
        yield 'kód má přednost před druhem' => ['PRIPLATEK_NOCNI', 'premium', 'one_off', 'included', '10334'];
        yield 'DPN z číselníku' => ['NAHRADA_MZDY_DPN', 'compensation', 'one_off', 'exempt', '10342'];
        yield 'náhrada za svátek' => ['NAHRADA_MZDY_SVATEK', 'compensation', 'one_off', 'included', '10339'];
        yield 'překážky zaměstnavatele' => ['NAHRADA_MZDY_PREKAZKY_ZAMESTNAVATEL', 'compensation', 'one_off', 'included', '10340'];
        yield 'překážky zaměstnance' => ['NAHRADA_MZDY_PREKAZKY_ZAMESTNANEC', 'compensation', 'one_off', 'included', '10341'];
    }

    #[DataProvider('targets')]
    public function testTargetForDerivesFromCodeThenKind(
        string $code,
        string $kind,
        string $frequency,
        string $tax,
        ?string $expected,
    ): void {
        self::assertSame($expected, PayrollComponentJmhzMappingDefaults::targetFor($code, $kind, $frequency, $tax));
    }

    /**
     * Složky vzoru GIRITON zakládá import přes AttendanceProfileComponents::definition();
     * s tím, co z definice vznikne, musí zařazení odpovídat jejich druhu.
     */
    public function testSampleProfileComponentsAreMappedByTheirKind(): void
    {
        $expected = [
            'MZDA_HODINOVA_DOCH' => '10329',
            'PRIPLATEK_NOCNI' => '10334',
            'PRIPLATKY_K_HODINOVE' => '10332',
            'PRIPLATEK_ODPOLEDNI' => '10332',
            'PRIPLATEK_BOZP' => '10332',
            // Odměny z importu jsou `one_off` z povahy importu; o pravidelnosti
            // rozhodne účetní.
            'ODMENA_KONTEJNERY' => null,
            'ODMENA_MIMORADNA' => null,
            'ODMENA_HOTOVOSTNI' => null,
            'ODMENA_SENIOR' => null,
            'DOPLATEK_MZDY' => '10329',
            'MZDA_SKOLENI' => '10329',
        ];
        $actual = [];
        foreach (AttendanceSampleProfile::components() as $component) {
            $definition = AttendanceProfileComponents::definition($component, '2026-01-01');
            $actual[$component['code']] = PayrollComponentJmhzMappingDefaults::targetFor(
                (string) $definition['code'],
                (string) $definition['component_kind'],
                (string) $definition['frequency_kind'],
                (string) $definition['tax_treatment'],
            );
        }
        self::assertSame($expected, $actual);

        // Auto složka „podle hlavičky" vzniká s druhem `other` a nezařazuje se.
        $auto = AttendanceProfileComponents::definition(
            ['code' => 'DOCH_KONTEJNERY', 'name' => 'Kontejnery', 'kind' => 'other'],
            '2026-01-01',
        );
        self::assertNull(PayrollComponentJmhzMappingDefaults::targetFor(
            (string) $auto['code'],
            (string) $auto['component_kind'],
            (string) $auto['frequency_kind'],
            (string) $auto['tax_treatment'],
        ));
    }

    /**
     * Zakládání číselníku zařazuje jedním SQL příkazem. Jeho dohledávací
     * tabulka musí pro KAŽDOU hodnotu druhu, četnosti i daňového zacházení
     * vrátit totéž co targetFor() — jinak by stránka zařazení a založení
     * číselníku zařadily tutéž složku různě.
     */
    public function testSeedTableAnswersExactlyLikeTargetFor(): void
    {
        $table = PayrollComponentJmhzMappingDefaults::seedTable();
        self::assertSame(PayrollComponentJmhzMappingDefaults::all(), $table['codes']);
        foreach (self::KINDS as $kind) {
            foreach (['one_off', 'regular'] as $frequency) {
                foreach (['included', 'exempt', 'withholding_candidate', 'manual_review'] as $tax) {
                    $found = null;
                    foreach ($table['kinds'] as $row) {
                        if ($row['component_kind'] === $kind
                            && $row['frequency_kind'] === $frequency
                            && $row['tax_treatment'] === $tax
                        ) {
                            $found = $row['target'];
                        }
                    }
                    self::assertSame(
                        PayrollComponentJmhzMappingDefaults::targetFor('__BEZ_KODU__', $kind, $frequency, $tax),
                        $found,
                        "Druh {$kind}, četnost {$frequency}, daň {$tax}: tabulka zakládání se rozchází s targetFor().",
                    );
                }
            }
        }
    }

    public function testMigrationCodeListMatchesTheCatalogDefaults(): void
    {
        preg_match_all("/SELECT '([A-Z0-9_]+)'(?: AS code)?, '(\\d+)'/", self::sql(), $matches, PREG_SET_ORDER);
        $codes = [];
        foreach ($matches as $match) {
            $codes[$match[1]] = $match[2];
        }
        $expected = PayrollComponentJmhzMappingDefaults::all();
        ksort($codes);
        ksort($expected);
        self::assertSame($expected, $codes);
    }

    /**
     * Pravidlo podle druhu se v SQL vyhodnotí pro každou kombinaci druhu,
     * četnosti a daňového zacházení a porovná s PHP. Kód, který v číselníku
     * není, nechá rozhodnout jen druh.
     */
    public function testMigrationKindRuleMatchesTargetForEverywhere(): void
    {
        $clauses = self::kindClauses();
        self::assertNotSame([], $clauses);
        foreach (self::KINDS as $kind) {
            foreach (['one_off', 'regular'] as $frequency) {
                foreach (['included', 'exempt', 'manual_review'] as $tax) {
                    $migrated = self::evaluate($clauses, $kind, $frequency, $tax);
                    if (isset(self::SUPERSEDED_FOR_NEW[$kind][$frequency])) {
                        self::assertSame('10331', $migrated, "Backfill u {$kind}/{$frequency} měl zařazovat do 10331.");
                        $migrated = null;
                    }
                    self::assertSame(
                        PayrollComponentJmhzMappingDefaults::targetFor('__BEZ_KODU__', $kind, $frequency, $tax),
                        $migrated,
                        "Druh {$kind}, četnost {$frequency}, daň {$tax}: SQL backfill a PHP se rozcházejí.",
                    );
                }
            }
        }
    }

    /**
     * Přechod na balík s katalogem 1.4.2.10 (migrace 1903) doplňuje výchozí
     * zařazení znovu, do nového balíku. Pravidlo musí být doslova totéž jako
     * v 1839 a 1847, jinak by firmy zařazené před přechodem a po něm dostaly
     * různé cíle.
     */
    public function testPackageTransitionRepeatsTheSameDefaultRule(): void
    {
        $transition = self::sql([self::PACKAGE_TRANSITION]);
        $original = self::sql(self::MIGRATIONS);

        preg_match_all("/SELECT '([A-Z0-9_]+)'(?: AS code)?, '(\\d+)'/", $transition, $matches, PREG_SET_ORDER);
        $codes = [];
        foreach ($matches as $match) {
            $codes[$match[1]] = $match[2];
        }
        $expected = array_diff_key(
            PayrollComponentJmhzMappingDefaults::all(),
            array_flip(self::AFTER_PACKAGE_TRANSITION),
        );
        ksort($codes);
        ksort($expected);
        self::assertSame($expected, $codes);
        self::assertSame(self::kindClauses($original), self::kindClauses($transition));
        self::assertStringContainsString('existing.component_definition_id = target.id', $transition);
        self::assertStringContainsString("controls-source-1.4.2.10_manifest-v1", $transition);
    }

    /**
     * Pravidlo změněné jen pro nové složky nesmí mít migraci, která by stávající
     * zařazení smazala nebo přepsala: firmě s podanými měsíci by se bez zásahu
     * zastavila příprava hlášení. Výchozí ODMENA zůstává podle kódu v 10331.
     */
    public function testSupersededRuleKeepsExistingMappings(): void
    {
        $migrations = glob(dirname(__DIR__, 4) . '/db/migrations/*.sql') ?: [];
        foreach ($migrations as $file) {
            $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
            self::assertDoesNotMatchRegularExpression(
                '/(DELETE|UPDATE)\b[^;]*payroll_component_jmhz_mappings[^;]*component_kind\s*=\s*\'bonus\'/is',
                $sql,
                basename($file) . ': stávající zařazení odměn se nesmí měnit migrací.',
            );
        }
        foreach (self::SUPERSEDED_FOR_NEW as $kind => $frequencies) {
            foreach (array_keys($frequencies) as $frequency) {
                self::assertNull(PayrollComponentJmhzMappingDefaults::targetFor('__BEZ_KODU__', $kind, $frequency, 'included'));
            }
        }
        self::assertSame('10331', PayrollComponentJmhzMappingDefaults::targetForCode('ODMENA'));
    }

    public function testMigrationIsIdempotentAndNeverTouchesExistingChoice(): void
    {
        $sql = self::sql();
        self::assertStringContainsString('NOT EXISTS', $sql);
        self::assertStringContainsString('existing.component_definition_id = target.id', $sql);
        self::assertStringContainsString("definition.jmhz_treatment = 'included'", $sql);
        self::assertStringNotContainsString('UPDATE ', $sql);
        self::assertStringNotContainsString('DELETE ', $sql);
    }

    /**
     * @param list<array{kinds:list<string>,frequency:?string,tax:?string,target:string}> $clauses
     */
    private static function evaluate(array $clauses, string $kind, string $frequency, string $tax): ?string
    {
        foreach ($clauses as $clause) {
            if (in_array($kind, $clause['kinds'], true)
                && ($clause['frequency'] === null || $clause['frequency'] === $frequency)
                && ($clause['tax'] === null || $clause['tax'] === $tax)
            ) {
                return $clause['target'];
            }
        }

        return null;
    }

    /** @return list<array{kinds:list<string>,frequency:?string,tax:?string,target:string}> */
    private static function kindClauses(?string $sql = null): array
    {
        preg_match_all('/WHEN\s+(.*?)\s+THEN\s+\'(\d+)\'/s', $sql ?? self::sql(), $matches, PREG_SET_ORDER);
        $clauses = [];
        foreach ($matches as $match) {
            $condition = $match[1];
            if (preg_match("/component_kind\\s+IN\\s+\\(([^)]*)\\)/", $condition, $in) === 1) {
                preg_match_all("/'([a-z_]+)'/", $in[1], $kinds);
                $kindList = $kinds[1];
            } elseif (preg_match("/component_kind\\s*=\\s*'([a-z_]+)'/", $condition, $single) === 1) {
                $kindList = [$single[1]];
            } else {
                self::fail("Neznámá podmínka backfillu: {$condition}");
            }
            $frequency = preg_match("/frequency_kind\\s*=\\s*'([a-z_]+)'/", $condition, $f) === 1 ? $f[1] : null;
            $tax = preg_match("/tax_treatment\\s*=\\s*'([a-z_]+)'/", $condition, $t) === 1 ? $t[1] : null;
            $clauses[] = ['kinds' => $kindList, 'frequency' => $frequency, 'tax' => $tax, 'target' => $match[2]];
        }

        return $clauses;
    }

    /** @param list<string>|null $migrations */
    private static function sql(?array $migrations = null): string
    {
        $sql = '';
        foreach ($migrations ?? self::MIGRATIONS as $migration) {
            $part = file_get_contents(dirname(__DIR__, 4) . '/db/migrations/' . $migration);
            self::assertIsString($part, "Migrace {$migration} chybí.");
            $sql .= $part . "\n";
        }

        // Komentáře pryč — hlídá se SQL, ne to, co o sobě tvrdí.
        return (string) preg_replace('/^\s*--.*$/m', '', $sql);
    }
}
