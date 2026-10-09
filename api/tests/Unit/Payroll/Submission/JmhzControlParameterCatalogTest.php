<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlParameterCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use PHPUnit\Framework\TestCase;

final class JmhzControlParameterCatalogTest extends TestCase
{
    /**
     * Sazby podle písmene § 5a musí být právě ty, které katalog ČSSZ přiřazuje
     * kontrole 315 — jinak by hlášení spočítalo 10481 jinak než ČSSZ.
     */
    public function testParagraph5RatesAreTheParametersOfControl315(): void
    {
        $keys = JmhzControlSourceCatalog::load()->parameters()->keysForControl(315);
        $letterKeys = array_values(JmhzControlParameterCatalog::EMPLOYER_SOCIAL_RATE_BY_PARAGRAPH5_LETTER);
        sort($keys);
        sort($letterKeys);

        self::assertSame($keys, $letterKeys);
    }

    public function testEmployerSocialInsuranceIsRoundedUpToWholeCrowns(): void
    {
        $parameters = JmhzControlSourceCatalog::load()->parameters();

        self::assertSame(249, $parameters->employerSocialInsuranceCzk(1_001, 'a', '2026-06-01'));
        self::assertSame(248, $parameters->employerSocialInsuranceCzk(1_000, 'a', '2026-06-01'));
        self::assertSame(298, $parameters->employerSocialInsuranceCzk(1_000, 'b', '2026-06-01'));
        self::assertSame(278, $parameters->employerSocialInsuranceCzk(1_000, 'c', '2026-06-01'));
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function parametricConstants(): iterable
    {
        foreach (['2025-01-15', '2026-01-15'] as $date) {
            yield "74 bonus {$date}" => [74, $date, '50'];
            yield "3 sleva v procentech {$date}" => [3, $date, '5'];
            yield "168 sazba zaměstnance {$date}" => [168, $date, '0.07171'];
            yield "170 sleva § 7a delta {$date}" => [170, $date, '0.06565'];
            yield "170 sleva § 7a {$date}" => [170, $date, '0.065'];
            yield "270 sazba zaměstnance {$date}" => [270, $date, '0.07171'];
        }
        yield '271 průměrná mzda 2025' => [271, '2025-01-15', '46500'];
        yield '271 průměrná mzda 2026' => [271, '2026-01-15', '48500'];
    }

    /**
     * Parametrické konstanty kontrol podle listu Parametrické konstanty
     * katalogu kontrol MH 1.4.2.10, platné od ledna 2025 a ledna 2026.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('parametricConstants')]
    public function testParametricConstantOfControlMatchesTheCatalogue(int $controlId, string $date, string $expected): void
    {
        $parameters = JmhzControlSourceCatalog::load()->parameters();
        $values = array_map(
            static fn (string $key): string => $parameters->value($key, $date),
            $parameters->keysForControl($controlId),
        );

        self::assertContains($expected, $values, "Kontrola {$controlId} k {$date}");
    }

    public function testUnknownLetterIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        JmhzControlSourceCatalog::load()->parameters()->employerSocialInsuranceCzk(1_000, 'd', '2026-06-01');
    }
}
