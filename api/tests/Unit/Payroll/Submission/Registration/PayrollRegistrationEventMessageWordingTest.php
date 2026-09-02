<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEventService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Hlášky registračních událostí A2–A8 jdou beze změny přes akci až do
 * formuláře. Účetní z nich musí poznat, CO je špatně a KAM jít — ne přečíst
 * název sloupce („identifiers.vcp musí být neprázdný text.").
 *
 * Test se drží tří pravidel, na kterých se hlášky lámaly:
 * 1. věta začíná lidským názvem údaje, ne technickou cestou,
 * 2. technická cesta smí být jen v závorce na konci,
 * 3. v textu není žargon („neměnný zdroj", „matice", „objekt", „snapshot").
 *
 * Validátory jsou čisté (nesahají na závislosti), proto se instance staví bez
 * konstruktoru — jinak by test tahal osm repozitářů kvůli formátování věty.
 */
final class PayrollRegistrationEventMessageWordingTest extends TestCase
{
    /** Slova, která účetní z hlášky nepřečte. */
    private const BANNED = [
        'snapshot', 'payload', 'facet', 'nullable', 'row_version',
        'neměnn', 'Neměnn', 'matice', 'artefakt', 'musí být objekt',
        'není platný text', 'není platný kód', 'zakázané pole',
    ];

    /**
     * @return iterable<string,array{0:string,1:list<mixed>,2:string,3:list<string>}>
     */
    public static function badInput(): iterable
    {
        yield 'chybějící datum účinnosti' => [
            'date',
            [null, 'effective_on'],
            'Den, ke kterému se změna hlásí',
            ['RRRR-MM-DD', '(effective_on)'],
        ];
        yield 'datum v českém tvaru' => [
            'date',
            ['31. 3. 2026', 'end_on'],
            'Datum skončení pracovního vztahu',
            ['RRRR-MM-DD', 'kartě pracovního vztahu', '(end_on)'],
        ];
        yield 'variabilní symbol zaměstnavatele s lomítkem' => [
            'requiredDigits',
            ['123/456', 'employer_variable_symbol', 8, 10],
            'Variabilní symbol zaměstnavatele u ČSSZ',
            ['8 až 10 číslic', 'Nastavení mezd', '(employer_variable_symbol)'],
        ];
        yield 'kód pracoviště o pevné délce' => [
            'requiredDigits',
            ['12', 'cssz_workplace_code', 3, 3],
            'Kód pracoviště ČSSZ',
            ['přesně 3 číslice', '(cssz_workplace_code)'],
        ];
        yield 'chybějící reference podkladu' => [
            'requiredText',
            ['   ', 'source_reference', 191],
            'Reference ověřeného podkladu',
            ['chybí', 'v tomhle formuláři', '(source_reference)'],
        ];
        yield 'příliš dlouhý název zaměstnavatele' => [
            'requiredText',
            [str_repeat('a', 200), 'employer_name', 150],
            'Název zaměstnavatele',
            ['nejvýš 150 znaků', '(employer_name)'],
        ];
        yield 'částka s haléři' => [
            'requiredAmount',
            ['12345,50', 'unemployment.average_net_earnings'],
            'Průměrný čistý měsíční výdělek',
            ['v celých korunách', '(unemployment.average_net_earnings)'],
        ];
        yield 'nevyplněné ukončení úmrtím' => [
            'bool',
            [null, 'ended_by_death'],
            'Ukončení pracovního vztahu úmrtím',
            ['vyberte ano, nebo ne', '(ended_by_death)'],
        ];
        yield 'špatný kód státu' => [
            'country',
            ['Česko', 'foreign_insurance.country_code'],
            'Stát zahraničního nositele pojištění',
            ['CZ', '(foreign_insurance.country_code)'],
        ];
        yield 'nečíselné číslo původního podání' => [
            'positive',
            ['loni', 'source_submission_id'],
            'Číslo původního přijatého podání',
            ['kladné celé číslo', '(source_submission_id)'],
        ];
        yield 'nevyplněná skupina údajů' => [
            'object',
            ['CZ', 'contact_address'],
            'Kontaktní adresa',
            ['chybí', 'celou skupinu údajů', '(contact_address)'],
        ];
        yield 'skupina údajů s množným názvem' => [
            'object',
            [null, 'unemployment'],
            'Podklady pro podporu v nezaměstnanosti chybí.',
            ['(unemployment)'],
        ];
        yield 'jednomístný kód' => [
            'requiredDigits',
            ['ab', 'relationship_detail_code', 1, 1],
            'Bližší určení pracovněprávního vztahu',
            ['přesně 1 číslici', '(relationship_detail_code)'],
        ];
        yield 'kód s diakritikou' => [
            'requiredCodeValue',
            ['Ž', 'highest_education_code', 1],
            'Nejvyšší dosažené vzdělání',
            ['(highest_education_code)'],
        ];
    }

    /**
     * @param list<mixed> $arguments
     * @param list<string> $contains
     */
    #[DataProvider('badInput')]
    public function testMessageStartsWithHumanFieldName(
        string $method,
        array $arguments,
        string $startsWith,
        array $contains,
    ): void {
        $message = $this->messageFrom($method, $arguments);

        self::assertStringStartsWith(
            $startsWith,
            $message,
            'Věta musí začínat lidským názvem údaje, ne názvem sloupce.',
        );
        foreach ($contains as $needle) {
            self::assertStringContainsString($needle, $message);
        }
    }

    /**
     * @param list<mixed> $arguments
     * @param list<string> $contains
     */
    #[DataProvider('badInput')]
    public function testTechnicalNameStaysInTrailingParentheses(
        string $method,
        array $arguments,
        string $startsWith,
        array $contains,
    ): void {
        $message = $this->messageFrom($method, $arguments);
        $path = (string) $arguments[1];

        self::assertStringEndsWith(
            "({$path})",
            $message,
            'Technický název pole patří jen do závorky na konci věty.',
        );
        self::assertSame(
            1,
            substr_count($message, $path),
            'Technický název se nesmí v hlášce objevit dvakrát.',
        );
    }

    /**
     * @param list<mixed> $arguments
     * @param list<string> $contains
     */
    #[DataProvider('badInput')]
    public function testMessageAvoidsJargon(
        string $method,
        array $arguments,
        string $startsWith,
        array $contains,
    ): void {
        $message = $this->messageFrom($method, $arguments);

        foreach (self::BANNED as $word) {
            self::assertStringNotContainsString(
                $word,
                $message,
                "Hláška nesmí obsahovat žargon „{$word}“.",
            );
        }
    }

    /**
     * Zakázaná pole musí účetní poznat podle názvu, ne podle klíče v JSON,
     * a věta jí musí říct, co s tím.
     */
    public function testForbiddenFieldsAreNamedInCzech(): void
    {
        $message = $this->messageFrom('onlyKeys', [
            ['mode' => 'provided', 'employment_type' => '1', 'entitlement' => true],
            ['mode'],
            'unemployment.',
            'u podkladů s důvodem neposkytnutí 2',
        ]);

        self::assertStringStartsWith('Druh zaměstnání v podkladech pro podporu', $message);
        self::assertStringContainsString('nárok na odstupné', $message);
        self::assertStringContainsString('u podkladů s důvodem neposkytnutí 2', $message);
        self::assertStringContainsString('neposílají', $message);
        self::assertStringEndsWith(
            '(unemployment.employment_type, unemployment.entitlement)',
            $message,
        );
    }

    /**
     * Rozpor u A2 musí ukázat obě data, jinak účetní neví, které opravit.
     */
    public function testEndSourceMismatchShowsBothDates(): void
    {
        $message = $this->call('endSourceMismatchMessage', [
            '2026-03-31',
            ['status' => 'active', 'end_date' => '2026-04-30'],
        ]);

        self::assertStringStartsWith(
            'Oznámení o skončení pracovního vztahu (REGZEC A2)',
            $message,
        );
        self::assertStringContainsString('2026-03-31', $message);
        self::assertStringContainsString('2026-04-30', $message);
        self::assertStringContainsString('kartě pracovního vztahu', $message);
        self::assertStringEndsWith('(end_on)', $message);
    }

    public function testEndSourceMismatchNamesMissingEndDate(): void
    {
        $message = $this->call('endSourceMismatchMessage', [
            '2026-03-31',
            ['status' => 'active', 'end_date' => null],
        ]);

        self::assertStringContainsString(
            'v evidenci vztah zatím žádné datum skončení nemá',
            $message,
        );
    }

    /** Kódy akcí se překládají do lidské řeči, ne na „A5". */
    public function testActionCodesAreTranslated(): void
    {
        self::assertSame(
            'Převod zaměstnance pod jiný variabilní symbol zaměstnavatele'
                . ' (REGZEC A5)',
            $this->call('actionName', [5]),
        );
        self::assertSame(
            'Storno přihlášení — zaměstnanec nenastoupil (REGZEC A8)',
            $this->call('actionName', [8]),
        );
    }

    /** @param list<mixed> $arguments */
    private function messageFrom(string $method, array $arguments): string
    {
        try {
            $this->call($method, $arguments);
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }
        self::fail("Metoda {$method} měla neplatný vstup odmítnout.");
    }

    /** @param list<mixed> $arguments */
    private function call(string $method, array $arguments): string
    {
        $service = (new ReflectionClass(PayrollRegistrationEventService::class))
            ->newInstanceWithoutConstructor();
        $reflection = new \ReflectionMethod(
            PayrollRegistrationEventService::class,
            $method,
        );

        return (string) $reflection->invokeArgs($service, $arguments);
    }
}
