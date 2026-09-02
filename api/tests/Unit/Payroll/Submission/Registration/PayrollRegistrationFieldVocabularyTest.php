<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationFieldVocabulary as Vocabulary;
use PHPUnit\Framework\TestCase;

/**
 * Slovník je jediné místo, kde se technický název pole mění na to, co účetní
 * hledá na obrazovce. Když se rozejde, hlášky celého registračního řetězce
 * začnou zase mluvit názvy sloupců.
 */
final class PayrollRegistrationFieldVocabularyTest extends TestCase
{
    /** Identifikátory, kvůli kterým slovník vznikl, musí mít jméno i místo. */
    public function testIdentifiersHaveHumanNamesAndAPlaceToEnterThem(): void
    {
        foreach ([
            'birth_number' => 'rodné číslo',
            'ecp' => 'evidenční číslo pojištěnce (EČP)',
            'vcp' => 'variabilní číslo pojištěnce (VČP)',
            'foreign_tax_identifier' => 'zahraniční daňový identifikátor',
        ] as $path => $name) {
            self::assertSame($name, Vocabulary::name($path), $path);
            self::assertSame(
                'kartě osoby → Kontakty a identifikátory',
                Vocabulary::where($path),
                $path,
            );
            self::assertStringContainsString(
                'Kontakty a identifikátory',
                Vocabulary::describe($path),
                $path,
            );
        }
    }

    /**
     * Snapshot nese `identifiers.ecp`, formulář `ecp` a katalog změn
     * `identity.first_name` — pro účetní je to pořád tentýž údaj.
     */
    public function testTechnicalContainersResolveToTheSameField(): void
    {
        self::assertSame(Vocabulary::name('ecp'), Vocabulary::name('identifiers.ecp'));
        self::assertSame(Vocabulary::name('sex'), Vocabulary::name('identity.sex'));
        self::assertSame(
            Vocabulary::name('employment.activity_code'),
            Vocabulary::name('data.activity_code'),
        );
    }

    /** Adresní listy se opakují ve čtyřech sekcích a musí mít název všude. */
    public function testAddressLeavesFallBackToTheGenericAddressLabel(): void
    {
        self::assertSame('obec', Vocabulary::name('czech_residence_address.city'));
        self::assertSame(
            'obec trvalého pobytu',
            Vocabulary::name('permanent_address.city'),
        );
        // Trvalý pobyt se bere z evidence osoby, kontaktní adresa se píše tady.
        self::assertNotNull(Vocabulary::where('permanent_address.city'));
        self::assertNull(Vocabulary::where('contact_address.city'));
    }

    /** Neúplný slovník nesmí zamlčet, že něco chybí. */
    public function testUnknownPathStillProducesAUsableSentence(): void
    {
        self::assertNull(Vocabulary::name('naprosto.neznama.cesta'));
        self::assertSame(
            'Údaj naprosto.neznama.cesta',
            Vocabulary::label('naprosto.neznama.cesta'),
        );
        self::assertStringContainsString(
            'kartě osoby',
            Vocabulary::describe('naprosto.neznama.cesta'),
        );
    }

    /**
     * Názvy stojí na začátku věty, takže musí být v 1. pádě a s malým
     * počátečním písmenem v `name()` (kapitalizuje se až `capitalName()`).
     */
    public function testNamesAreUsableAtTheStartOfASentence(): void
    {
        self::assertSame('Rodné číslo', Vocabulary::capitalName('birth_number'));
        self::assertSame(
            'PSČ trvalého pobytu',
            Vocabulary::capitalName('permanent_address.postal_code'),
        );
        // Žádný název nesmí být sloveso ani 4. pád („doplňte adresu").
        foreach (['czech_residence_address', 'contact_address', 'attachments'] as $path) {
            $name = Vocabulary::name($path);
            self::assertIsString($name);
            self::assertStringEndsNotWith('u', explode(' ', $name)[0], $path);
        }
    }

    /** Kód akce sám o sobě účetní nic neříká; věta musí začít tím, co se hlásí. */
    public function testActionCodesTranslateToWhatIsActuallyBeingFiled(): void
    {
        self::assertSame(
            'Oznámení o skončení pracovního vztahu (REGZEC A2)',
            Vocabulary::action('REGZEC25', 2),
        );
        self::assertSame(
            'Částečné přihlášení před nástupem (PREZEC P1)',
            Vocabulary::action('PREZEC26', 9),
        );
        self::assertStringContainsString(
            '42',
            Vocabulary::action('REGZEC25', 42),
        );
    }

    /** Technický název patří do závorky na konec, ne na začátek věty. */
    public function testReferenceWrapsThePathAndSkipsEmptyOnes(): void
    {
        self::assertSame(' (birth_number)', Vocabulary::reference('birth_number'));
        self::assertSame('', Vocabulary::reference(null));
        self::assertSame('', Vocabulary::reference(''));
    }
}
