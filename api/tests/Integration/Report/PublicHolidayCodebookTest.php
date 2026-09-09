<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PublicHolidayRepository;
use MyInvoice\Service\Report\CzechWorkingDays;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P-7 — celý řetěz od tabulky `public_holidays` (migrace 1780) až k lhůtě podání.
 *
 * Unit testy ({@see \MyInvoice\Tests\Unit\Service\Report\CzechWorkingDaysCodebookTest})
 * ověřují chování helperu proti podstrčenému číselníku. Tenhle test ověřuje to,
 * co unit test ověřit nemůže: že Bootstrap číselník helperu skutečně předá,
 * repozitář ho umí přečíst a seed migrace odpovídá zákonu.
 *
 * Vše běží v transakci, kterou tearDown rollbackne.
 */
#[Group('integration')]
final class PublicHolidayCodebookTest extends TestCase
{
    private Connection $db;
    private PublicHolidayRepository $repo;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->repo = $container->get(PublicHolidayRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $seeded = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM public_holidays')->fetchColumn();
        if ($seeded === 0) {
            $this->markTestSkipped('Číselník svátků není naseedovaný (migrace 1780).');
        }

        $this->db->pdo()->beginTransaction();
        $this->inTx = true;
        $this->rebind();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        CzechWorkingDays::reset();
    }

    /**
     * Číselník se čte přes cache (v rámci requestu se na svátky ptáme stokrát),
     * takže test po zápisu musí helperu i repozitáři cache zahodit — jinak by
     * ověřoval jen to, co si helper zapamatoval při prvním dotazu.
     */
    private function rebind(): void
    {
        $repo = $this->repo;
        CzechWorkingDays::configure(static fn (): PublicHolidayRepository => $repo);
    }

    /**
     * Seed migrace 1780 dává TÝŽ výsledek jako dosavadní konstanta: 25. 7. 2026
     * je sobota, lhůta KH za 06/2026 tedy padá na pondělí 27. 7. (§ 33 odst. 4 DŘ).
     */
    public function testSeededCodebookReproducesStatutoryDeadlines(): void
    {
        self::assertFalse(CzechWorkingDays::usingFallback(), 'Odpověď musí přijít z databáze.');
        self::assertSame('2026-07-27', CzechWorkingDays::deadline(2026, 7), 'KH 06/2026: 25. 7. je sobota.');
        self::assertSame('2026-08-25', CzechWorkingDays::deadline(2026, 8), 'Všední termín zůstává na 25.');
        self::assertCount(13, CzechWorkingDays::holidaysForYear(2026), '11 pevných + 2 velikonoční.');
        self::assertTrue(CzechWorkingDays::isPublicHoliday(new \DateTimeImmutable('2026-04-03')), 'Velký pátek 2026.');
    }

    /**
     * Jádro P-7: nový svátek se přidá ŘÁDKEM V ČÍSELNÍKU a lhůta se posune — bez
     * zásahu do kódu a bez nového releasu. Kdyby to nešlo, byl by číselník jen
     * kulisa a novela zákona by dál znamenala novou verzi aplikace.
     */
    public function testHolidayAddedToCodebookShiftsDeadline(): void
    {
        self::assertSame('2026-08-25', CzechWorkingDays::deadline(2026, 8), 'Výchozí stav — úterý.');

        $this->repo->create([
            'code'          => 'test_codebook_holiday',
            'name'          => 'Zkušební svátek (test)',
            'rule_type'     => 'fixed',
            'month_day'     => '08-25',
            'easter_offset' => null,
            'valid_from'    => '2026-01-01',
            'valid_to'      => null,
            'note'          => 'syntetický řádek integračního testu',
        ]);
        $this->rebind();

        self::assertTrue(CzechWorkingDays::isPublicHoliday(new \DateTimeImmutable('2026-08-25')));
        self::assertSame('2026-08-26', CzechWorkingDays::deadline(2026, 8), 'Lhůta se posunula na středu.');
        self::assertSame('2025-08-25', CzechWorkingDays::deadline(2025, 8), 'Rok před platností zůstává beze změny.');
        self::assertFalse(CzechWorkingDays::usingFallback());
    }

    /** Prázdná tabulka = instalace bez seedu: pojistka v kódu, a hlasitě. */
    public function testEmptyTableFallsBackToCode(): void
    {
        $this->db->pdo()->exec('DELETE FROM public_holidays');
        $this->rebind();

        self::assertCount(13, CzechWorkingDays::holidaysForYear(2026));
        self::assertTrue(CzechWorkingDays::usingFallback(), 'Fallback se musí dát poznat.');
        self::assertSame('2026-07-27', CzechWorkingDays::deadline(2026, 7), 'Posun podle § 33/4 platí i z pojistky.');
    }
}
