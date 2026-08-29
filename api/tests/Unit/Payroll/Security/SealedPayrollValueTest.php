<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Security;

use MyInvoice\Service\Payroll\Security\SealedPayrollValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Zapečetěná mzdová hodnota (rodné číslo, číslo účtu) je jediný tvar, ve kterém
 * citlivý údaj smí opustit šifrovací vrstvu. Konstruktor je proto poslední místo,
 * kde se dá zachytit, že do úložiště míří nešifrovaný text nebo useknutý
 * vyhledávací otisk — potom už je to jen sloupec v tabulce, který nikdo nekontroluje.
 */
final class SealedPayrollValueTest extends TestCase
{
    private const LOOKUP_HASH_LENGTH = 32;

    public function testAcceptsSealedValueAndExposesStorageShape(): void
    {
        $value = new SealedPayrollValue(
            'enc:v2:' . base64_encode('šifrovaný text'),
            str_repeat("\x11", self::LOOKUP_HASH_LENGTH),
            '******1234',
        );

        self::assertSame(
            ['ciphertext', 'lookup_hash', 'masked'],
            array_keys($value->toStorage()),
        );
        self::assertSame($value->ciphertext, $value->toStorage()['ciphertext']);
        self::assertSame($value->lookupHash, $value->toStorage()['lookup_hash']);
        self::assertSame('******1234', $value->toStorage()['masked']);
    }

    /**
     * Verze prefixu je součástí kontraktu: `enc:v1:` je starší schéma, které už
     * dešifrovací cesta neumí. Kdyby konstruktor bral cokoliv, migrace na v2 by
     * se poznala až při čtení dat zpět.
     */
    public function testRejectsCiphertextWithoutCurrentVersionPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Neplatná zapečetěná mzdová hodnota.');
        new SealedPayrollValue(
            'enc:v1:' . base64_encode('starý tvar'),
            str_repeat("\x11", self::LOOKUP_HASH_LENGTH),
            '******1234',
        );
    }

    /**
     * Vyhledávací otisk je surových 32 bajtů SHA-256, ne hex. Kratší i delší
     * hodnota znamená, že někdo poslal hex podobu nebo useknutý otisk —
     * v obou případech by přestalo fungovat vyhledávání podle rodného čísla.
     *
     * @param non-empty-string $lookupHash
     */
    #[DataProvider('invalidLookupHashes')]
    public function testRejectsLookupHashOfWrongLength(string $lookupHash): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SealedPayrollValue(
            'enc:v2:' . base64_encode('šifrovaný text'),
            $lookupHash,
            '******1234',
        );
    }

    /** @return array<string,array{string}> */
    public static function invalidLookupHashes(): array
    {
        return [
            'hex místo binárky' => [str_repeat('a', 64)],
            'useknutý otisk' => [str_repeat("\x11", 31)],
            'o bajt delší' => [str_repeat("\x11", 33)],
            'prázdný' => [''],
        ];
    }

    /**
     * Maskovaný tvar je to jediné, co se smí ukázat v UI a v logu. Prázdná maska
     * není „nic k zobrazení", ale chybějící údaj — a ten do zapečetěné hodnoty
     * nepatří vůbec.
     */
    public function testRejectsEmptyMask(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SealedPayrollValue(
            'enc:v2:' . base64_encode('šifrovaný text'),
            str_repeat("\x11", self::LOOKUP_HASH_LENGTH),
            '',
        );
    }
}
