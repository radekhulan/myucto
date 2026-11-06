<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Drahá výchozí data (osnova, uzavřený rok, konverze rámce) postavená JEDNOU za třídu.
 *
 * Testy běží na sdíleném testovacím spojení, které po každém testu uklidí rozdělanou
 * transakci ({@see SharedTestConnectionGuard}). Sdílená data proto nemohou žít v otevřené
 * transakci: {@see sharedFixture()} je postaví ve vlastní transakci a commitne, každý test
 * nad nimi pak pracuje ve své transakci, kterou tearDown vrátí. Test, který by sdílená data
 * trvale změnil (commit, druhá session s autocommitem), si musí postavit vlastní.
 *
 * Každá varianta dostane vlastního dodavatele; po poslední metodě třídy se smažou.
 */
trait SharedClassFixture
{
    /** @var array<string,array{supplier:int,data:array<string,mixed>}> */
    private static array $sharedFixtures = [];

    /**
     * @param callable():array{0:int,1:array<string,mixed>} $build vrátí [id dodavatele, data]
     * @return array<string,mixed>
     */
    private function sharedFixture(PDO $pdo, string $key, callable $build): array
    {
        if (!isset(self::$sharedFixtures[$key])) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->beginTransaction();
            try {
                [$supplier, $data] = $build();
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            self::$sharedFixtures[$key] = ['supplier' => $supplier, 'data' => $data];
        }
        if (!$pdo->inTransaction()) $pdo->beginTransaction();
        return self::$sharedFixtures[$key]['data'];
    }

    /**
     * Volá setUp() PHPUnit pro tento test? Jiné testy si třídu berou přes reflexi jako
     * fixturu (vlastní transakce, vlastní dodavatel) a musí dostat původní chování.
     */
    private function preparedByPhpunit(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $frame) {
            if (($frame['function'] ?? '') === 'prepareTest' && ($frame['object'] ?? null) === $this) return true;
        }
        return false;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$sharedFixtures === []) return;
        $pdo = Bootstrap::buildContainer()->get(Connection::class)->pdo();
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach (self::$sharedFixtures as $fixture) self::deleteFixtureSupplier($pdo, $fixture['supplier']);
        self::$sharedFixtures = [];
    }

    /** Smaže dodavatele; tabulky, které mazání blokují cizím klíčem, uklidí podle chyby 1451. */
    private static function deleteFixtureSupplier(PDO $pdo, int $supplier): void
    {
        self::deleteFixtureRows($pdo, 'supplier', $supplier, 0);
    }

    private static function deleteFixtureRows(PDO $pdo, string $table, int $supplier, int $depth): void
    {
        for ($attempt = 0; $attempt < 64; $attempt++) {
            try {
                $pdo->prepare('DELETE FROM `' . $table . '` WHERE ' . ($table === 'supplier' ? 'id' : 'supplier_id') . '=?')->execute([$supplier]);
                return;
            } catch (\PDOException $e) {
                if ($depth > 16 || (int) ($e->errorInfo[1] ?? 0) !== 1451 || preg_match('/`[^`]+`\.`([^`]+)`, CONSTRAINT/', (string) $e->getMessage(), $m) !== 1) throw $e;
                self::deleteFixtureRows($pdo, $m[1], $supplier, $depth + 1);
            }
        }
        throw new \RuntimeException('Sdílenou fixture dodavatele ' . $supplier . ' se nepodařilo smazat.');
    }
}
