<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Closing\CheckFindingNormalizer;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Selektivní výpočet uzávěrkových kontrol nesmí změnit ANI JEDEN výsledek.
 *
 * Proč to existuje: `checkFindings()` vrací detail JEDNÉ kontroly, ale spouštěl kvůli
 * tomu všech 39. Nad milionem řádků deníku to je 13 s za každé rozbalení nálezu
 * (naměřeno), takže `buildChecks()` umí spočítat jen vyžádané klíče. Jakmile se ale
 * kontroly začnou přeskakovat, vzniká riziko, že nějaká závisí na mezivýsledku bloku,
 * který se přeskočil — a projeví se to tím, že detail nálezu tvrdí něco jiného než
 * precheck. To je přesně ta třída vad, kterou uživatel pozná až u finančního úřadu.
 *
 * Test proto porovnává plný běh se selektivním pro KAŽDÝ klíč, který plný běh vydal.
 * Read-only, nad daty, která v databázi zrovna jsou — nezakládá nic.
 */
#[Group('integration')]
final class ClosingChecksSelectiveTest extends TestCase
{
    private ClosingService $closing;
    private Connection $db;

    /** @var array<string,mixed> */
    private array $period = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->closing = $container->get(ClosingService::class);
            $this->db = $container->get(Connection::class);
            $periods = $container->get(AccountingPeriodRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $row = $this->db->pdo()
            ->query('SELECT supplier_id, id FROM accounting_periods ORDER BY supplier_id, fiscal_year DESC LIMIT 1')
            ->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            $this->markTestSkipped('V databázi není žádné účetní období — není co kontrolovat.');
        }
        $period = $periods->findById((int) $row['supplier_id'], (int) $row['id']);
        if ($period === null) {
            $this->markTestSkipped('Účetní období se nepodařilo načíst.');
        }
        $this->period = $period;
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /**
     * Pro každý klíč z plného běhu musí selektivní běh vydat BITOVĚ TOTOŽNÝ nález.
     *
     * Kdyby některá kontrola stála na mezivýsledku jiné, projeví se to tady — a to je
     * jediný způsob, jak se to pozná, protože samotné přeskočení bloku nic nerozsvítí.
     */
    public function testSelectiveRunMatchesFullRunForEveryKey(): void
    {
        $supplierId = (int) $this->period['supplier_id'];
        $full = $this->closing->buildChecks(
            $supplierId,
            $this->period,
            null,
            null,
            CheckFindingNormalizer::DETAIL_CAP,
        );
        self::assertNotSame([], $full, 'Plný běh nevydal žádnou kontrolu — test by pak neověřoval nic.');

        foreach ($full as $expected) {
            $key = (string) $expected['key'];
            $selective = $this->closing->buildChecks(
                $supplierId,
                $this->period,
                null,
                null,
                CheckFindingNormalizer::DETAIL_CAP,
                [$key],
            );

            self::assertCount(
                1,
                $selective,
                "Selektivní běh pro „{$key}\" má vrátit právě jednu kontrolu, ne " . count($selective) . '.',
            );
            self::assertSame(
                $expected,
                $selective[0],
                "Kontrola „{$key}\" vyšla selektivně jinak než v plném běhu.",
            );
        }
    }

    /** Nesmyslný klíč vrátí prázdno, ne celou sadu — jinak by filtr byl k ničemu. */
    public function testUnknownKeyReturnsNothing(): void
    {
        $selective = $this->closing->buildChecks(
            (int) $this->period['supplier_id'],
            $this->period,
            null,
            null,
            CheckFindingNormalizer::DETAIL_CAP,
            ['tohle_neexistuje'],
        );

        self::assertSame([], $selective);
    }

    /**
     * Bez `$onlyKeys` se chování nesmí změnit vůbec — precheck, měsíční kontrola
     * a CSV export jdou touhle cestou a musí dostat celou sadu.
     */
    public function testFullRunStillReturnsEveryCheck(): void
    {
        $supplierId = (int) $this->period['supplier_id'];
        $full = $this->closing->buildChecks($supplierId, $this->period);
        $keys = array_map(static fn (array $c): string => (string) $c['key'], $full);

        self::assertSame($keys, array_unique($keys), 'Kontroly se v plném běhu nesmí duplikovat.');
        self::assertGreaterThan(20, count($keys), 'Plný běh má vydat celou sadu kontrol, ne podmnožinu.');
    }
}
