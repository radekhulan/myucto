<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Ruleset;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRuleParameterCatalog;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetCapability;
use PHPUnit\Framework\TestCase;

/**
 * Administrace legislativních pravidel je pro účetní, ne pro autora klíčů.
 * Tenhle test drží dvě věci, na které se dá při přidání parametru zapomenout:
 * český název u KAŽDÉHO parametru a nulový výskyt anglického textu, který
 * v české účetní aplikaci vidí uživatel.
 */
final class PayrollRuleParameterCatalogTest extends TestCase
{
    public function testEveryShippedParameterHasCzechLabel(): void
    {
        self::assertSame(
            [],
            PayrollRuleParameterCatalog::missingLabels(CzechPayrollRulesets2026::provider()),
            'Nový parametr rulesetu nemá český název. Doplňte ho do PayrollRuleParameterCatalog::LABELS — '
            . 'bez něj se v administraci ukáže jen technický klíč.',
        );
    }

    public function testEveryManualReviewParameterExplainsWhyAndWhatToDo(): void
    {
        self::assertSame(
            [],
            PayrollRuleParameterCatalog::missingManualReviewExplanations(
                CzechPayrollRulesets2026::provider(),
            ),
            'Parametr s ručním posouzením nemá vysvětlení. Bez něj uživatel čte „Výpočet blokován" '
            . 'jako frontu ke schválení, kterou to není.',
        );
    }

    /**
     * Každý parametr, ze kterého výpočet nečerpá, má jednu ze tří kategorií.
     * „Čeká na vyhlášení" musí říct kdy a odkud, „posuzuje se jinde" musí vést
     * na routu, která ve frontendu opravdu existuje.
     */
    public function testEveryReviewExplanationHasCategoryAndWorkingPointer(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 5) . '/web/src/router/workspaceRoutes.ts');
        $categories = [
            PayrollRuleParameterCatalog::REVIEW_MANUAL,
            PayrollRuleParameterCatalog::REVIEW_PENDING_PUBLICATION,
            PayrollRuleParameterCatalog::REVIEW_ASSESSED_ELSEWHERE,
        ];
        $seen = [];
        foreach (\MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets::provider()->versions() as $version) {
            foreach ($version->parameters as $key => $parameter) {
                if ($parameter->capability !== PayrollRulesetCapability::ManualReview) {
                    continue;
                }
                $explanation = PayrollRuleParameterCatalog::manualReview($version->domain->value, $key);
                self::assertNotNull($explanation, "{$version->id}:{$key}");
                self::assertContains($explanation['category'], $categories, $key);
                if ($explanation['category'] === PayrollRuleParameterCatalog::REVIEW_PENDING_PUBLICATION) {
                    self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $explanation['expected'] ?? '', $key);
                    self::assertStringStartsWith('https://', $explanation['source_url'] ?? '', $key);
                }
                if (isset($explanation['route'])) {
                    self::assertStringContainsString("name: '{$explanation['route']}'", $routes, $key);
                }
                $seen[$version->domain->value . '|' . $key] = $explanation['category'];
            }
        }

        self::assertSame(
            PayrollRuleParameterCatalog::REVIEW_ASSESSED_ELSEWHERE,
            $seen['income_tax|credit.spouse.eligibility'] ?? null,
        );
        self::assertSame(
            'payroll-annual-settlement',
            PayrollRuleParameterCatalog::manualReview('income_tax', 'credit.spouse.eligibility')['route'] ?? null,
        );
        self::assertArrayNotHasKey('income_tax|benefit_exemption.non_cash_leisure.yearly', $seen);
    }

    public function testManualReviewIsRareAndTheRestOfTheSetIsUsable(): void
    {
        $manual = 0;
        $total = 0;
        foreach (CzechPayrollRulesets2026::provider()->versions() as $version) {
            foreach ($version->parameters as $parameter) {
                $total++;
                if ($parameter->capability === PayrollRulesetCapability::ManualReview) {
                    $manual++;
                }
            }
        }

        self::assertGreaterThan(100, $total);
        self::assertLessThan(
            (int) ($total / 10),
            $manual,
            'Ruční posouzení se má týkat jednotek parametrů. Pokud jich přibylo, přestane platit '
            . 'to, co administrace uživateli slibuje.',
        );
    }

    /**
     * Anglicky psaná věta v rulesetu prosákne do UI jako hodnota parametru,
     * jako poznámka nebo jako důkaz technické kontroly. Detekuje se přes typicky
     * anglická slova ve VOLNÉM TEXTU (ne v klíčích, URL a identifikátorech).
     */
    public function testNoEnglishSentenceReachesTheUser(): void
    {
        $offenders = [];
        foreach (CzechPayrollRulesets2026::provider()->versions() as $version) {
            $texts = [];
            foreach ($version->parameters as $key => $parameter) {
                if ($parameter->note !== null) {
                    $texts["{$version->id}:{$key}:note"] = $parameter->note;
                }
                if ($parameter->type === 'manual_review' && is_string($parameter->value)) {
                    $texts["{$version->id}:{$key}:value"] = $parameter->value;
                }
            }
            $review = $version->technicalReview;
            if ($review !== null) {
                $texts["{$version->id}:technical_review"] = $review->evidence;
            }
            foreach ($version->sources as $source) {
                $texts["{$version->id}:source:{$source->id}"] = $source->title;
            }

            foreach ($texts as $where => $text) {
                if (self::looksEnglish($text)) {
                    $offenders[$where] = $text;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Text určený uživateli je anglicky. České účetnictví se obsluhuje česky.',
        );
    }

    public function testEnglishDetectorActuallyCatchesTheOldWording(): void
    {
        self::assertTrue(self::looksEnglish(
            'Deadlines depend on agenda, event, channel and transition rules and are not inferred.',
        ));
        self::assertTrue(self::looksEnglish(
            'The 0.298 rate is official, but occupational classification requires manual review.',
        ));
        self::assertFalse(self::looksEnglish(
            'Lhůty závisí na agendě, události a kanálu podání; aplikace je neodvozuje.',
        ));
        self::assertFalse(self::looksEnglish('VZP: Platby zdravotního pojištění v roce 2026'));
    }

    public function testEnumValuesAreExplainedInCzech(): void
    {
        self::assertSame(
            'zaokrouhlit nahoru na celé koruny',
            PayrollRuleParameterCatalog::valueLabel('ceil-to-1-czk'),
        );
        self::assertSame(
            'zaokrouhlit nahoru na celé stokoruny',
            PayrollRuleParameterCatalog::valueLabel('ceil-to-100-czk'),
        );
        // Datum ani verze schématu není výčet — popisek by tam lhal.
        self::assertNull(PayrollRuleParameterCatalog::valueLabel('2022-01-01'));
        self::assertNull(PayrollRuleParameterCatalog::valueLabel('1.4.3.4'));
        self::assertNull(PayrollRuleParameterCatalog::valueLabel(12_000));
    }

    public function testLabelIsResolvedPerDomainNotPerBareKey(): void
    {
        // Tentýž klíč znamená v jiné doméně jiný odvod — proto dvojice doména+klíč.
        self::assertNotSame(
            PayrollRuleParameterCatalog::label('social_insurance', 'participation.dpp.minimum'),
            PayrollRuleParameterCatalog::label('health_insurance', 'participation.dpp.minimum'),
        );
        self::assertNull(PayrollRuleParameterCatalog::label('income_tax', 'participation.dpp.minimum'));
    }

    private static function looksEnglish(string $text): bool
    {
        $words = [
            'and', 'are', 'but', 'depends', 'must', 'not', 'the', 'require', 'requires',
            'outside', 'their', 'with', 'is', 'be', 'own', 'remains',
        ];
        $lower = ' ' . mb_strtolower($text) . ' ';
        $hits = 0;
        foreach ($words as $word) {
            if (str_contains($lower, " {$word} ")) {
                $hits++;
            }
        }

        return $hits >= 2;
    }
}
