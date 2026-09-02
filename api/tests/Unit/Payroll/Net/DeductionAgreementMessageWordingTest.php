<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Net;

use MyInvoice\Service\Payroll\Net\DeductionAgreementTerms;
use PHPUnit\Framework\TestCase;

/**
 * Text výjimky projde beze změny přes akci až do formuláře dohody o srážkách.
 * Účetní tam musí najít NÁZEV POLE, do kterého má sáhnout, ne název sloupce
 * v databázi ("Pole basis_amount_minor musí být celé číslo").
 */
final class DeductionAgreementMessageWordingTest extends TestCase
{
    /** @return iterable<string,array{0:array<string,mixed>,1:string}> */
    public static function badInput(): iterable
    {
        yield 'chybí název dohody' => [['title' => ''], 'Název dohody'];
        yield 'nečíselný základ' => [
            ['basis_points' => 1_000, 'basis_amount_minor' => 'nevím'],
            'Základ pro procentní srážku',
        ];
        yield 'nečíselný limit' => [['total_limit_minor' => 'později'], 'Celkový limit dohody'];
        yield 'špatný tvar data' => [['valid_from' => '1. 1. 2026'], 'Účinnost od'];
        yield 'neexistující datum' => [['valid_to' => '2026-02-30'], 'Účinnost do'];
        yield 'dlouhá poznámka' => [['note' => str_repeat('a', 501)], 'Poznámka'];
    }

    /**
     * @param array<string,mixed> $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badInput')]
    public function testMessageNamesTheFieldNotTheColumn(array $overrides, string $label): void
    {
        try {
            DeductionAgreementTerms::fromRequest($this->body($overrides));
            self::fail('Neplatný vstup měl skončit výjimkou.');
        } catch (\InvalidArgumentException $exception) {
            $message = $exception->getMessage();
            self::assertStringContainsString($label, $message);
            foreach (array_keys($overrides) as $column) {
                self::assertStringNotContainsString(
                    (string) $column,
                    $message,
                    'Hláška nesmí uživateli podstrčit název databázového sloupce.',
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function body(array $overrides = []): array
    {
        return [
            'title' => 'Stravenky',
            'deduction_kind' => 'meal',
            'priority_no' => 100,
            'requested_minor' => 50_000,
            'valid_from' => '2026-01-01',
            ...$overrides,
        ];
    }
}
