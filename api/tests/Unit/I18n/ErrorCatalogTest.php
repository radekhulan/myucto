<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\I18n;

use MyInvoice\I18n\ErrorCatalog;
use MyInvoice\I18n\Locale;
use PHPUnit\Framework\TestCase;

final class ErrorCatalogTest extends TestCase
{
    public function testCsLocaleReturnsInputUnchanged(): void
    {
        self::assertSame(
            'Vystavenou fakturu nelze editovat.',
            ErrorCatalog::lookup('Vystavenou fakturu nelze editovat.', 'cs'),
        );
    }

    public function testEnLocaleTranslatesKnownMessage(): void
    {
        self::assertSame(
            'An issued invoice cannot be edited.',
            ErrorCatalog::lookup('Vystavenou fakturu nelze editovat.', 'en'),
        );
    }

    public function testCanonicalHostnameConflictHasEnglishTranslation(): void
    {
        self::assertSame(
            'The hostname configured in app.url cannot be used as a company custom domain. Enter a different hostname.',
            ErrorCatalog::lookup(
                'Hostname nastavený v app.url nelze použít jako vlastní doménu firmy. Zadejte jiný hostname.',
                'en',
            ),
        );
    }

    public function testNewDomainBoundaryErrorsHaveEnglishTranslations(): void
    {
        $messages = [
            'Canonical hostname koliduje s vlastní doménou firmy.'
                => 'The canonical hostname conflicts with a company custom domain.',
            'Canonical přechod není pro tuto cestu povolený.'
                => 'Canonical handoff is not allowed for this path.',
            'DNS nebo HTTPS ověření domény selhalo.'
                => 'DNS or HTTPS domain verification failed.',
            'Doména musí mít čerstvě ověřené DNS a HTTPS.'
                => 'The domain must have freshly verified DNS and HTTPS.',
            'Doména se během ověření změnila; spusť kontrolu znovu.'
                => 'The domain changed during verification; run the check again.',
            'Doména se před ověřením změnila; spusť kontrolu znovu.'
                => 'The domain changed before verification; run the check again.',
        ];

        foreach ($messages as $cs => $en) {
            self::assertSame($en, ErrorCatalog::lookup($cs, 'en'), $cs);
        }
    }

    public function testSystemDimensionAndProvenanceErrorsHaveEnglishCatalogEntries(): void
    {
        $messages = [
            'Systémovou dimenzi ani význam použité hodnoty nelze změnit. Pro nový význam založte novou hodnotu.' => 'A system dimension or the meaning of a used value cannot be changed. Create a new value for a new meaning.',
            'Kód systémové dimenze již používá vlastní typ. Vlastní typ nelze automaticky převést.' => 'A custom type already uses the system dimension code. A custom type cannot be converted automatically.',
            'Kód systémové hodnoty již používá vlastní hodnota.' => 'A custom value already uses the system value code.',
            'Zápis nelze zaúčtovat bez povinných systémových dimenzí.' => 'An entry cannot be posted without the required system dimensions.',
            'Systémová dimenze nepatří firmě.' => 'The system dimension does not belong to this company.',
            'Rozdělení více systémových dimenzí musí mít společné alokační řádky.' => 'Splitting multiple system dimensions requires shared allocation rows.',
            'Hodnota systémové dimenze nepatří firmě nebo typu.' => 'The system dimension value does not belong to this company or type.',
            'Uzavřenou hodnotu systémové dimenze nelze nově přiřadit.' => 'A closed system dimension value cannot be assigned to a new row.',
            'Převod účetnictví je podporován pouze pro období vedená celé v rámci vyhlášky 500.' => 'Accounting migration is supported only for periods maintained entirely under Decree 500.',
            'Neplatný původ přiřazení dimenze.' => 'Invalid dimension assignment origin.',
            'Vlastní účet nemá ověřenou vazbu na účetní rámec.' => 'The custom account has no verified binding to the accounting framework.',
            'Protizápis neodpovídá živému zaúčtovanému zdroji.' => 'The reversal does not match a live posted source entry.',
            'Konec intervalu nesmí předcházet začátku.' => 'The interval end must not precede its start.',
        ];
        foreach ($messages as $cs => $en) {
            self::assertSame($en, ErrorCatalog::lookup($cs, 'en'), $cs);
            self::assertSame($cs, ErrorCatalog::lookup($cs, 'cs'));
        }
    }

    public function testStatement504MessagesAreBilingual(): void
    {
        $messages = [
            'Vyhláška 504 nestanoví účelovou výsledovku.' => '504 does not define an income statement by function.',
            'Výkazy podle vyhlášky 504 před rokem 2024 zatím nejsou podporované.' => '504 statements before 2024 are not supported.',
            'Vlastní mapování účtu nesmí změnit předepsaný řádek výkazu 504.' => 'A 504 account override cannot change the prescribed statement row.',
            'Účetní rámec se mění uvnitř vykazovaného období.' => 'Accounting framework changes inside the reporting period.',
            'Účetní období neexistuje.' => 'Accounting period does not exist.',
            'Účet nemá jednoznačné přiřazení k řádku výkazu 504.' => 'An account is not uniquely mapped to the 504 statement.',
            'Neplatný rozsah účetního výkazu.' => 'Invalid statement scope.',
            'PDF a XLSX šablony výkazů 504 zatím nejsou k dispozici.' => 'PDF and XLSX templates for 504 statements are not available yet.',
            'Rozpad zaúčtované činnosti nesouhlasí s částkou řádku.' => 'Posted activity allocation does not reconcile.',
            'Rozpad zaúčtované činnosti je nejednoznačný nebo neúplný.' => 'Posted activity allocation is ambiguous or incomplete.',
            'Zařazení činnosti na zaúčtovaném řádku chybí nebo není platné.' => 'Posted activity classification is missing or invalid.',
            'Datum výkazu leží mimo účetní období.' => 'Statement date is outside the accounting period.',
            'Definice výkazu 504 není úplná.' => 'The 504 statement definition is incomplete.',
            'Definice výkazu 504 chybí nebo zatím není podporovaná.' => 'The 504 statement definition is missing or unsupported.',
            'Mapování účtu neodpovídá účtové skupině nebo řádku výkazu 504.' => 'The account mapping conflicts with the 504 account group or statement row.',
            'Účet výsledku běžného období nesouhlasí s výsledovkou.' => 'The current result account does not reconcile to profit and loss.',
            'Před sestavením oficiální rozvahy 504 je nutné vypořádat výsledek ve schvalovacím řízení.' => 'The pending result must be resolved before an official 504 balance sheet can be produced.',
            'Typ zaúčtovaného účtu neodpovídá účtové třídě vyhlášky 504.' => 'The posted account type conflicts with the 504 account class.',
            'Vybraná dimenze a činnost vyžadují společnou alokaci.' => 'The selected dimension and activity require a joint allocation.',
            'Editor mapování výkazů pro rámec 504 zatím není k dispozici.' => 'The statement mapping editor is not available for framework 504.',
        ];
        foreach ($messages as $cs => $en) {
            self::assertSame($cs, ErrorCatalog::lookup($cs, 'cs'));
            self::assertSame($en, ErrorCatalog::lookup($cs, 'en'));
        }
    }

    public function testStatement504WorkflowMessagesAreBilingual(): void
    {
        $messages = [
            'Přehled peněžních toků a změn vlastního kapitálu podle vyhlášky 500 není dostupný pro účetní rámec 504.' => 'Cash flow and equity statements under Decree 500 are not available for accounting framework 504.',
            'Podklady výkazu 504 pro export nejsou úplné nebo platné.' => 'The data for exporting the Decree 504 statement is incomplete or invalid.',
        ];
        foreach ($messages as $cs => $en) {
            self::assertSame($cs, ErrorCatalog::lookup($cs, 'cs'));
            self::assertSame($en, ErrorCatalog::lookup($cs, 'en'));
        }
    }

    public function testEnLocaleReturnsInputForUnknownMessage(): void
    {
        self::assertSame(
            'Tohle není v katalogu',
            ErrorCatalog::lookup('Tohle není v katalogu', 'en'),
        );
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        self::assertSame('', ErrorCatalog::lookup('', 'en'));
    }

    public function testPrefixMatchForExceptionMessages(): void
    {
        // V katalogu: "Email se nepodařilo odeslat: " => "Failed to send email: "
        // Volání s konkatenovanou příčinou musí prefix přeložit.
        self::assertSame(
            'Failed to send email: connection timeout',
            ErrorCatalog::lookup('Email se nepodařilo odeslat: connection timeout', 'en'),
        );
    }

    public function testLocaleStateGlobal(): void
    {
        Locale::set('en');
        self::assertSame('en', Locale::current());
        Locale::set('cs');
        self::assertSame('cs', Locale::current());
        // Reset na default po testu, aby neovlivnil další testy
    }

    public function testLocaleSetUnknownFallsBackToCs(): void
    {
        Locale::set('xx');
        self::assertSame('cs', Locale::current());
    }
}
