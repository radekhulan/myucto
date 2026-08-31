<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Bootstrap;
use PHPUnit\Framework\Attributes\DataProvider;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\CachedHealthOfficialFormProvider;
use PHPUnit\Framework\TestCase;

/**
 * Úřední tiskopisy jsou připnuté v repozitáři, ne stahované.
 *
 * Dokud se tahaly z webu pojišťovny, závisela na cizím serveru i testovací
 * sada. Test drží obojí: že soubory v instalaci jsou, a že sedí s otiskem,
 * na který se aplikace odkazuje — nesoulad je brzda, ne varování.
 */
final class HealthOfficialFormTemplateTest extends TestCase
{
    /** @return list<array{string}> */
    public static function forms(): array
    {
        return array_map(
            static fn (string $id): array => [$id],
            CachedHealthOfficialFormProvider::formIds(),
        );
    }

    #[DataProvider('forms')]
    public function testPinnedFormIsShippedAndMatchesItsHash(string $formId): void
    {
        $form = (new CachedHealthOfficialFormProvider())->form($formId);

        self::assertStringStartsWith('%PDF-', $form->bytes);
        self::assertSame(
            CachedHealthOfficialFormProvider::sha256($formId),
            hash('sha256', $form->bytes),
        );
        self::assertSame($form->sha256, hash('sha256', $form->bytes));
        self::assertNotSame(
            $form->sourceSha256,
            $form->sha256,
            'Připnutá kopie je zveřejněný soubor bez šifrovacího obalu, '
            . 'takže se otisky liší — kdyby byly shodné, jeden z nich lže.',
        );
    }

    #[DataProvider('forms')]
    public function testShippedFileIsTheOneTheCodePinsTo(string $formId): void
    {
        $path = Bootstrap::rootDir() . DIRECTORY_SEPARATOR . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            CachedHealthOfficialFormProvider::relativePath($formId),
        );

        self::assertFileExists($path, 'Úřední tiskopis musí být součástí instalace.');
        self::assertSame(
            CachedHealthOfficialFormProvider::sha256($formId),
            hash_file('sha256', $path),
        );
    }

    /**
     * V připnutém tiskopisu nesmí zůstat vyplněná data. Jediné, co v něm autor
     * nechal, je datum vydání a přednastavený přepínač — na výstup se to
     * nedostane, protože se ze šablony bere jen obsah stránky.
     */
    public function testPinnedFormsCarryNoPersonalData(): void
    {
        foreach (CachedHealthOfficialFormProvider::formIds() as $formId) {
            $bytes = (new CachedHealthOfficialFormProvider())->form($formId)->bytes;
            self::assertStringNotContainsString('IdentityInfo', $bytes);
            self::assertDoesNotMatchRegularExpression(
                '/\b\d{6}\/\d{3,4}\b/',
                $bytes,
                'Tiskopis nesmí obsahovat rodné číslo.',
            );
        }
    }

    /** Nesmí zůstat cesta, která by se pokusila tiskopis stáhnout. */
    public function testProviderDoesNotReachOutToTheNetwork(): void
    {
        $source = (string) file_get_contents(
            Bootstrap::rootDir()
            . '/api/src/Service/Payroll/Submission/HealthInsurance/CachedHealthOfficialFormProvider.php',
        );

        self::assertStringNotContainsString('GuzzleHttp', $source);
        self::assertStringNotContainsString('->request(', $source);
        self::assertStringNotContainsString('file_get_contents(\'http', $source);
    }
}
