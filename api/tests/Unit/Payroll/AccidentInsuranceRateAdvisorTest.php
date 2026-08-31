<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\AccidentInsuranceRateAdvisor;
use MyInvoice\Service\Payroll\AccidentInsuranceRateSchedule;
use PDO;
use PHPUnit\Framework\TestCase;

final class AccidentInsuranceRateAdvisorTest extends TestCase
{
    private function advisor(?string $naceCode): AccidentInsuranceRateAdvisor
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, cz_nace_code TEXT NULL)');
        $insert = $pdo->prepare('INSERT INTO supplier (id, cz_nace_code) VALUES (1, ?)');
        $insert->execute([$naceCode]);

        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        return new AccidentInsuranceRateAdvisor($db, new AccidentInsuranceRateSchedule());
    }

    public function testSchedulePartCarriesTheWholeAnnexAndItsProvenance(): void
    {
        $advice = $this->advisor(null)->advise(1);

        self::assertCount(8, $advice['schedule']['groups']);
        self::assertSame('125/1993 Sb.', $advice['schedule']['legal']['decree']);
        self::assertSame(98, $advice['schedule']['codebook']['activity_count']);
    }

    public function testSuggestionsAreAlwaysDeclaredNonBinding(): void
    {
        foreach ([null, '361000', '620200'] as $nace) {
            self::assertFalse($this->advisor($nace)->advise(1)['suggestions_binding']);
        }
    }

    public function testSuggestsByTheNameOfTheCompanyNaceActivity(): void
    {
        // CZ-NACE 31.00.0 „Výroba nábytku" — v příloze je stejná činnost pod
        // OKEČ 36.1 se sazbou 8,4 ‰. Číslo se přitom vůbec neshoduje.
        $advice = $this->advisor('310000')->advise(1);

        self::assertSame('31.00.00', $advice['nace']['display']);
        self::assertNotSame([], $advice['suggestions']);
        self::assertSame('36.1', $advice['suggestions'][0]['okec_code']);
        self::assertSame('8.40', $advice['suggestions'][0]['rate_per_mille']);
    }

    /**
     * Past, kvůli které se návrh hledá podle názvu: CZ-NACE 62 jsou činnosti
     * v oblasti IT, OKEČ 62 je letecká doprava (4,2 ‰). Párování čísel by
     * softwarové firmě podstrčilo sazbu letecké dopravy.
     */
    public function testNeverProposesTheAnnexRowWithTheSameCodeNumber(): void
    {
        $advice = $this->advisor('620200')->advise(1);

        self::assertNotNull($advice['nace']);
        self::assertNotContains('62', array_column($advice['suggestions'], 'okec_code'));
    }

    public function testCompanyWithoutNaceGetsScheduleButNoSuggestion(): void
    {
        $advice = $this->advisor(null)->advise(1);

        self::assertNull($advice['nace']);
        self::assertSame([], $advice['suggestions']);
        self::assertCount(8, $advice['schedule']['groups']);
    }

    public function testRejectsInvalidSupplier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->advisor(null)->advise(0);
    }
}
