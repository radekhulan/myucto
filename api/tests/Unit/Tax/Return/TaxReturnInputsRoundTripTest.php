<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DpfoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Ruční vstupy přiznání musí přežít ULOŽENÍ, ne jen výpočet.
 *
 * `TaxReturnService::sanitizeInputs()` je whitelist klíčů — co v něm není, se při uložení
 * TIŠE zahodí. Vzniklo tím opakovaně přesně to, co tenhle test hlídá: kalkulátor pole čte,
 * formulář ho posílá, a uživateli po uložení zmizí bez jediného hlášení:
 *   - `rnd_deduction` / `education_deduction` (§ 34 odst. 4, ř. 242/243) — odečet zadaný
 *     účetní se nikdy neuložil, přiznání vyšlo s vyšší daní.
 *   - `s16a_separate_base` (§ 16a, samostatný základ 15 %) — volba poplatníka se ztrácela.
 *   - `kind` u ručních položek § 23 — explicitní druh „paušál na dopravu" (§ 24/2/zt), kterému
 *     kalkulátor dává přednost před rozpoznáváním z textu, se do uložených dat nedostal.
 *
 * Testuje se PRŮCHOD: sanitizace (= to, co se uloží a co se zpátky načte) → kalkulátor →
 * řádek přiznání. Samotné tvrzení „kalkulátor to umí spočítat" tuhle třídu chyb nechytá,
 * protože kalkulátoru se v unit testu vstup podává rovnou, mimo whitelist.
 *
 * `sanitizeInputs()` je čistá privátní metoda (jen `$this->money()`/`text()`/…, žádný přístup
 * na vlastnosti instance) — volá se reflexí bez konstruktoru, stejně jako
 * {@see TaxReturnServiceSanitizeInputsBankAndPuzTest}.
 */
final class TaxReturnInputsRoundTripTest extends TestCase
{
    /**
     * @param array<string,mixed> $inputs
     * @return array<string,mixed>
     */
    private function saveAndLoad(string $type, array $inputs): array
    {
        $service = (new ReflectionClass(TaxReturnService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'sanitizeInputs');

        // Uložená hodnota jde do DB jako JSON (TaxReturnRepository::updateInputs) a stejně se
        // zpátky načte — round-trip přes json_encode/decode tu cestu věrně napodobí.
        $stored = json_encode($method->invoke($service, $type, $inputs, 'radne'), JSON_THROW_ON_ERROR);

        return (array) json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string,mixed>> $lines */
    private static function line(array $lines, int $n): float
    {
        foreach ($lines as $l) {
            if ((int) $l['line'] === $n) {
                return (float) $l['value'];
            }
        }
        self::fail('Řádek ' . $n . ' ve výpočtu chybí.');
    }

    /** @return array<string,mixed> */
    private function dppoConstants(): array
    {
        return [
            'corporate_tax_rate' => 0.21,
            'donation_cap_po_pct' => 0.30,
            'disabled_employee_credit' => 18000,
            'disabled_employee_credit_severe' => 60000,
            'advance_threshold_low' => 30000,
            'advance_threshold_high' => 150000,
            'rounding_base_po' => 1000,
        ];
    }

    public function testRndAndEducationDeductionSurviveSave(): void
    {
        $loaded = $this->saveAndLoad('po', ['rnd_deduction' => 300000, 'education_deduction' => 100000]);

        // JSON round-trip vrací celé částky jako int — kalkulátor je stejně přetypuje;
        // podstatné je, že klíč po uložení VŮBEC existuje (dřív se zahodil).
        self::assertArrayHasKey('rnd_deduction', $loaded);
        self::assertArrayHasKey('education_deduction', $loaded);
        self::assertSame(300000.0, (float) $loaded['rnd_deduction']);
        self::assertSame(100000.0, (float) $loaded['education_deduction']);
    }

    /**
     * Celý řetězec: zadám odečty → uložím → načtu → spočítám. Před opravou tady vyšly
     * ř. 242 i 243 na nule a daň o 84 000 Kč vyšší, aniž by o tom cokoli hlásilo.
     */
    public function testSavedDeductionsReachTheReturnLines(): void
    {
        $loaded = $this->saveAndLoad('po', ['rnd_deduction' => 300000, 'education_deduction' => 100000]);
        $r = (new DppoReturnCalculator())->compute(['vh' => 1000000.0], $loaded, $this->dppoConstants());

        self::assertSame(300000.0, self::line($r['lines'], 242));
        self::assertSame(100000.0, self::line($r['lines'], 243));
        self::assertSame(600000.0, self::line($r['lines'], 250));
        self::assertSame(126000.0, self::line($r['lines'], 290), '600 000 × 21 %.');
    }

    /** Záporný/nesmyslný vstup se ořízne na nulu jako u ostatních částek. */
    public function testDeductionsAreCoercedToNonNegativeMoney(): void
    {
        $loaded = $this->saveAndLoad('po', ['rnd_deduction' => -5000, 'education_deduction' => 'abc']);

        self::assertSame(0.0, (float) $loaded['rnd_deduction']);
        self::assertSame(0.0, (float) $loaded['education_deduction']);
    }

    /**
     * Explicitní druh položky § 23 má u kalkulátoru PŘEDNOST před textem — musí se tedy uložit.
     * Před opravou `kind` ze vstupu vypadl, takže položka bez klíčových slov v textu skončila
     * na obecném ř. 62 místo ř. 40, přestože ji účetní za paušál na dopravu označila.
     */
    public function testManualItemKindSurvivesSaveAndMovesItemToLine40(): void
    {
        $loaded = $this->saveAndLoad('po', ['manual_increase_items' => [
            ['text' => 'Add-back PHM', 'amount' => 45000, 'kind' => DppoReturnCalculator::KIND_FLAT_RATE_TRAVEL],
        ]]);

        self::assertSame(DppoReturnCalculator::KIND_FLAT_RATE_TRAVEL, $loaded['manual_increase_items'][0]['kind'] ?? null);

        $r = (new DppoReturnCalculator())->compute(['vh' => 1000000.0], $loaded, $this->dppoConstants());
        self::assertSame(45000.0, self::line($r['lines'], 40));
        self::assertSame(0.0, self::line($r['lines'], 62));
    }

    /** Neznámý druh se nepropustí — vypnul by heuristiku podle textu, aniž by ji nahradil. */
    public function testUnknownManualItemKindIsDropped(): void
    {
        $loaded = $this->saveAndLoad('po', ['manual_increase_items' => [
            ['text' => 'Paušál na dopravu', 'amount' => 45000, 'kind' => 'neco_jineho'],
        ]]);

        self::assertArrayNotHasKey('kind', $loaded['manual_increase_items'][0]);
    }

    /**
     * § 16a — volba samostatného základu daně. Kalkulátor DPFO ji čte, whitelist ji neměl,
     * takže se uložením ztratila a daň ze samostatného základu se nikdy nepřipočetla.
     */
    public function testSeparateBaseSurvivesSaveAndIsTaxed(): void
    {
        $loaded = $this->saveAndLoad('fo', ['s16a_separate_base' => 200000]);

        self::assertArrayHasKey('s16a_separate_base', $loaded);
        self::assertSame(200000.0, (float) $loaded['s16a_separate_base']);

        $r = (new DpfoReturnCalculator())->compute(
            ['s7_base' => 0, 'expense_mode' => 'pausal', 'expense_rate' => 60],
            $loaded,
            [],
            TaxConstants::forYear(2025),
        );
        self::assertSame(200000.0, (float) $r['summary']['separate_base']);
        self::assertSame(30000.0, (float) $r['summary']['separate_base_tax'], '200 000 × 15 %.');
    }
}
