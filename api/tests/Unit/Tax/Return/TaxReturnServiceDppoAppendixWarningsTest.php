<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * `TaxReturnService::buildDppoAppendix()` — dřív při chybějícím účetním období nebo
 * chybě čtení výkazů jen tiše vrátila `[]`, takže se DPPO XML vygenerovalo bez přílohy
 * (VetaUA/UB/UD/UZ) a nikdo se to nedozvěděl, dokud to EPO při skutečném podání
 * 30. 8. 2026 nevytklo jako chybu 2602 „Není vložena příloha účetní závěrky". Test
 * ověřuje, že důvod vynechání teď jde ven přes `warnings`.
 *
 * Instance se skládá reflexí (bez volání konstruktoru — ten má 22 závislostí, z nichž
 * `buildDppoAppendix()` používá jen tři); zbylé (nepoužité) `readonly` vlastnosti
 * zůstávají neinicializované, což private metodě, kterou test volá, nevadí.
 *
 * `FinancialStatementService`/`EntityCategoryService`/`AccountingSupplierSettingsRepository`
 * jsou `final` — PHPUnit 13 mockování `final` tříd zakazuje (`ClassIsFinalException`), takže
 * druhá větev (chyba při čtení výkazů) se netestuje mockem, ale skutečnou instancí bez
 * konstruktoru: sáhne-li produkční kód na její (taky neinicializovanou) `readonly`
 * vlastnost, PHP samo vyhodí `Error` — což je `\Throwable`, přesně to, co produkční
 * `catch (\Throwable)` má zachytit.
 */
final class TaxReturnServiceDppoAppendixWarningsTest extends TestCase
{
    /** @return array<string,mixed> */
    private function invoke(TaxReturnService $service, int $supplierId, array $period): array
    {
        $method = new ReflectionMethod($service, 'buildDppoAppendix');
        return $method->invoke($service, $supplierId, $period);
    }

    public function testMissingPeriodIdWarnsAboutError2602(): void
    {
        $service = (new ReflectionClass(TaxReturnService::class))->newInstanceWithoutConstructor();

        $result = $this->invoke($service, 1, []);

        self::assertArrayNotHasKey('balance_sheet', $result);
        self::assertNotEmpty($result['warnings']);
        self::assertStringContainsString('2602', $result['warnings'][0]);
    }

    public function testStatementErrorWarnsInsteadOfSilentlyDroppingAppendix(): void
    {
        $ref = new ReflectionClass(TaxReturnService::class);
        $service = $ref->newInstanceWithoutConstructor();
        // `statements` zůstává neinicializované schválně — první sáhnutí produkčního
        // kódu na jeho vnitřní závislosti (uvnitř balanceSheet()) vyhodí typovanou
        // Error, kterou má `catch (\Throwable)` proměnit ve warning, ne polknout.
        $prop = $ref->getProperty('statements');
        $prop->setValue($service, (new ReflectionClass(FinancialStatementService::class))->newInstanceWithoutConstructor());

        $result = $this->invoke($service, 1, ['id' => 42]);

        self::assertArrayNotHasKey('balance_sheet', $result);
        self::assertNotEmpty($result['warnings']);
        self::assertStringContainsString('2602', $result['warnings'][0]);
    }

    /**
     * `buildStatementNotesAttachment()` — chyba 2602 se (ověřeno proti zkušebnímu EPO,
     * DANE-PLAN.md §9.4c) neváže na VetaUA/UB/UD/UZ výše, ale na SKUTEČNĚ přiložený
     * soubor. Test ověřuje, že selhání `StatementNotesService::build()` (final třída,
     * nejde mockovat — stejný trik jako testStatementErrorWarnsInsteadOfSilentlyDroppingAppendix:
     * neinicializovaná `readonly` vlastnost vyhodí Error při prvním sáhnutí) skončí jako
     * warning s cestou k doplnění, ne jako tiché vynechání přílohy.
     */
    public function testStatementNotesAttachmentErrorWarnsInsteadOfSilentlyDropping(): void
    {
        $ref = new ReflectionClass(TaxReturnService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $method = new ReflectionMethod($service, 'buildStatementNotesAttachment');
        $result = $method->invoke($service, 1, 42, ['starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);

        self::assertNull($result['file']);
        self::assertNotNull($result['warning']);
        self::assertStringContainsString('Příloha v účetní závěrce', $result['warning']);
        self::assertStringContainsString('/accounting/periods/42/statement-notes', $result['warning']);
    }
}
