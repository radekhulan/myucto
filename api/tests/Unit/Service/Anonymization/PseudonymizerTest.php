<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Anonymization;

use MyInvoice\Service\Anonymization\Pseudonymizer;
use MyInvoice\Service\Payroll\Submission\CsszEmployerVariableSymbol;
use PHPUnit\Framework\TestCase;

final class PseudonymizerTest extends TestCase
{
    private const SECRET = 'unit-test-secret-0123456789';

    private static function ico(string $sevenDigits): string
    {
        return $sevenDigits . Pseudonymizer::icoCheckDigit($sevenDigits);
    }

    /** Syntetické platné rodné číslo (10 číslic) z data a pořadového čísla. */
    private static function birthNumber(string $head, int $serial): string
    {
        for (;; $serial++) {
            $nine = (int) ($head . str_pad((string) ($serial % 1000), 3, '0', STR_PAD_LEFT));
            $check = (11 - (($nine * 10) % 11)) % 11;
            if ($check < 10) {
                return $nine . $check;
            }
        }
    }

    public function testSameInputGivesSamePseudonymWithinRunAndDifferentAcrossKeys(): void
    {
        $a = new Pseudonymizer(self::SECRET);
        $b = new Pseudonymizer(self::SECRET);
        $c = new Pseudonymizer('another-secret-0123456789');
        $ico = self::ico('1234567');

        self::assertSame($a->ico($ico), $a->ico($ico));
        self::assertSame($a->ico($ico), $b->ico($ico));
        self::assertSame($a->partyName('Zkušební Obchod s.r.o.'), $b->partyName('Zkušební Obchod s.r.o.'));
        self::assertNotSame($a->ico($ico), $c->ico($ico));
    }

    public function testIcoIsValidDifferentAndInjective(): void
    {
        $p = new Pseudonymizer(self::SECRET);
        $seen = [];
        for ($i = 0; $i < 3000; $i++) {
            $original = self::ico(str_pad((string) (1000000 + $i * 37), 7, '0', STR_PAD_LEFT));
            $pseudo = $p->ico($original);
            self::assertTrue(Pseudonymizer::isValidIco($pseudo), "Neplatné IČO {$pseudo}");
            self::assertNotSame($original, $pseudo);
            self::assertArrayNotHasKey($pseudo, $seen, 'Dva originály dostaly stejné IČO.');
            $seen[$pseudo] = true;
        }
    }

    public function testDicFollowsIcoAndBirthNumber(): void
    {
        $p = new Pseudonymizer(self::SECRET);
        $ico = self::ico('7654321');

        self::assertSame('CZ' . $p->ico($ico), $p->dic('CZ' . $ico));
        $rc = self::birthNumber('855101', 123);
        self::assertTrue(Pseudonymizer::isValidBirthNumber($rc));
        self::assertSame('CZ' . $p->birthNumber($rc), $p->dic('CZ' . $rc));
        self::assertMatchesRegularExpression('/^DE\d{9}$/', $p->dic('DE123456789'));
    }

    public function testBirthNumberKeepsDateAndSexAndStaysValid(): void
    {
        $p = new Pseudonymizer(self::SECRET);
        $withSlash = static fn (string $rc): string => substr($rc, 0, 6) . '/' . substr($rc, 6);
        foreach ([$withSlash(self::birthNumber('855101', 123)), self::birthNumber('800101', 555), $withSlash(self::birthNumber('901231', 7)), '535101/123'] as $original) {
            $pseudo = $p->birthNumber($original);
            self::assertSame(substr($original, 0, 6), substr($pseudo, 0, 6));
            self::assertSame(str_contains($original, '/'), str_contains($pseudo, '/'));
            self::assertNotSame($original, $pseudo);
            self::assertTrue(Pseudonymizer::isValidBirthNumber($pseudo), "Neplatné RČ {$pseudo}");
        }
        $rc = self::birthNumber('855101', 123);
        self::assertSame(str_replace('/', '', $p->birthNumber($withSlash($rc))), $p->birthNumber($rc));
    }

    public function testBankAccountIsConsistentAcrossFormats(): void
    {
        $p = new Pseudonymizer(self::SECRET);
        $national = $p->bankAccount('19-1000000005/0100');
        self::assertMatchesRegularExpression('#^19-(\d+)/0100$#', $national);
        preg_match('#^19-(\d+)/0100$#', $national, $m);
        $base = $m[1];

        self::assertTrue(Pseudonymizer::isValidAccountPart($base));
        self::assertNotSame('1000000005', $base);
        self::assertSame('19-' . $base, $p->bankAccount('19-1000000005'));
        self::assertSame('000019' . str_pad($base, 10, '0', STR_PAD_LEFT), $p->bankAccount('0000191000000005'));
        self::assertSame('0100:000019' . str_pad($base, 10, '0', STR_PAD_LEFT), $p->bankAccount('0100:0000191000000005'));
        self::assertSame(ltrim('19' . str_pad($base, 10, '0', STR_PAD_LEFT), '0'), $p->accountKey('191000000005'));

        $iban = $p->iban('CZ' . Pseudonymizer::ibanCheckDigits('CZ', '01000000191000000005') . '01000000191000000005');
        self::assertTrue(Pseudonymizer::isValidIban($iban));
        self::assertSame('0100', substr($iban, 4, 4));
        self::assertSame(str_pad($base, 10, '0', STR_PAD_LEFT), substr($iban, 14));
    }

    public function testPreservedAccountStaysAndPlainBaseMapsToKey(): void
    {
        $p = new Pseudonymizer(self::SECRET);
        $p->preserveAccount('1000000005/0710');

        self::assertSame('1000000005/0710', $p->bankAccount('1000000005/0710'));
        $mapped = $p->bankAccount('2000000002/0800');
        self::assertSame(explode('/', $mapped)[0], $p->accountKey('2000000002'));
    }

    public function testCompanyKeepsLegalFormAndPersonGetsPersonName(): void
    {
        $p = new Pseudonymizer(self::SECRET);

        $company = $p->partyName('Zkušební Montáže, s.r.o.');
        self::assertStringEndsWith(', s.r.o.', $company);
        self::assertStringNotContainsString('Zkušební', $company);
        self::assertSame($company, $p->partyName('Zkušební Montáže, s.r.o.'));

        $woman = $p->personName('Ing. Jana Zkušebná');
        self::assertStringStartsWith('Ing. ', $woman);
        self::assertMatchesRegularExpression('/á$/u', $woman, 'Ženské příjmení má dostat ženskou podobu.');
        self::assertSame($p->lastName('Zkušebná'), explode(' ', $woman)[2]);
        self::assertSame($woman, $p->partyName('Ing. Jana Zkušebná'));
    }

    public function testEmailPhoneAndIdentifiers(): void
    {
        $p = new Pseudonymizer(self::SECRET);

        $email = $p->email('Jana.Testova@example.org');
        self::assertStringEndsWith('@' . Pseudonymizer::EMAIL_DOMAIN, $email);
        self::assertSame($email, $p->email('jana.testova@example.org'));

        $phone = $p->phone('+420 601 234 567');
        self::assertMatchesRegularExpression('/^\+420 6\d\d \d{3} \d{3}$/', $phone);
        self::assertNotSame('+420 601 234 567', $phone);

        self::assertMatchesRegularExpression('/^\d{4}$/', $p->cardLast4('1234'));
        self::assertMatchesRegularExpression('/^[a-z0-9]{7}$/', $p->dataBox('abc1234'));
        $plate = $p->shape('1AB 2345');
        self::assertMatchesRegularExpression('/^\d[A-Z]{2} \d{4}$/', $plate);
        self::assertNotSame('1AB 2345', $plate);
    }

    public function testShapeSurvivesTinyValueSpaceAndPunctuation(): void
    {
        $p = new Pseudonymizer(self::SECRET);
        self::assertSame('-', $p->shape('-'));
        for ($i = 0; $i < 10; $i++) {
            self::assertNotSame((string) $i, $p->shape((string) $i));
        }
        self::assertMatchesRegularExpression('/^\d$/', $p->shape('0'));
    }

    public function testFileNamesKeepExtensionAndMachineSegments(): void
    {
        $p = new Pseudonymizer(self::SECRET);

        self::assertMatchesRegularExpression('/^soubor-[a-z0-9]{10}\.pdf$/', $p->fileName('Faktura Zkušební.pdf'));
        self::assertSame('sup-3/ab/' . $p->fileName('Smlouva Zkušební.pdf'), $p->filePath('sup-3/ab/Smlouva Zkušební.pdf'));
        self::assertSame('sup-3/2024/3f9a0c1d2e4b5a6c.pdf', $p->filePath('sup-3/2024/3f9a0c1d2e4b5a6c.pdf'));
    }

    public function testSymbolChangesOnlyWhenWholeValueIsKnownIdentifier(): void
    {
        $p = new Pseudonymizer(self::SECRET);
        $ico = self::ico('2345678');
        $pseudo = $p->ico($ico);

        self::assertSame($pseudo, $p->symbol($ico));
        self::assertSame('2024001', $p->symbol('2024001'));
    }

    public function testCsszVariableSymbolKeepsDistrictAndPassesLuhn(): void
    {
        $p = new Pseudonymizer(self::SECRET);
        $original = '1101234560';
        $body = substr($original, 0, 9);
        $original = $body . Pseudonymizer::luhnCheckDigit($body);
        self::assertNull(CsszEmployerVariableSymbol::invalidReason($original));

        $pseudo = $p->csszVariableSymbol($original);

        self::assertNotSame($original, $pseudo);
        self::assertSame('110', substr($pseudo, 0, 3));
        self::assertNull(CsszEmployerVariableSymbol::invalidReason($pseudo));
        self::assertSame($pseudo, $p->symbol($original), 'Týž VS jinde (platby ČSSZ) dostane týž pseudonym.');
        self::assertSame('1868', substr($p->csszVariableSymbol('1868' . substr($body, 4) . Pseudonymizer::luhnCheckDigit('1868' . substr($body, 4))), 0, 4));
    }
}
