<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use PDO;

/**
 * Drahý základ testů (firma SVJ, předpisy, úhrady, uzávěrka roku) se v jedné třídě
 * staví jednou a další testy začínají z téhož stavu databáze.
 *
 * Celá třída běží v jedné transakci sdíleného testovacího spojení. Hned po jejím
 * začátku vznikne savepoint čistého stavu, po postavení základu savepoint základu.
 * Každý test začíná návratem k savepointu: ke stavu po postavení základu, nebo k čistému
 * stavu, když test chce jiný základ nebo žádný. Zápisy testu tak nikdy nepřežijí do
 * dalšího testu. Návrat k savepointu zvedá čítače LoggingPdo, paměti aktuálních čtení
 * se proto zahodí. Po poslední metodě třídy se transakce odvolá.
 *
 * Test musí začít voláním {@see sharedFixture()} nebo {@see sharedClean()}; mezi nimi
 * nic nezapisuje. Hodnota základu (id, požadavky) je pro všechny testy stejná.
 *
 * Když instanci třídy používá jiná třída jako fixturu (reflexí volá setUp a tearDown),
 * běží postaru: transakce na test, základ se staví pokaždé.
 */
trait SharedFixtureSavepointTrait
{
    /** @var array{key:string,value:mixed}|null */
    private static ?array $sharedFixtureState = null;
    private static ?PDO $sharedFixturePdo = null;
    private bool $sharedFixtureLegacy = true;

    /** Volá setUp místo beginTransaction(). */
    protected function beginSharedFixtureTest(PDO $pdo): void
    {
        $this->sharedFixtureLegacy = SharedTestConnectionGuard::runningClass() !== static::class;
        if ($this->sharedFixtureLegacy) {
            $pdo->beginTransaction();
            return;
        }
        if (self::$sharedFixturePdo !== $pdo || !$pdo->inTransaction()) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->beginTransaction();
            $pdo->exec('SAVEPOINT shared_fixture_clean');
            self::$sharedFixturePdo = $pdo;
            self::$sharedFixtureState = null;
            SharedTestConnectionGuard::setFixtureHolder(static::class);
            return;
        }
        try {
            $pdo->exec(self::$sharedFixtureState !== null ? 'ROLLBACK TO SAVEPOINT shared_fixture_ready' : 'ROLLBACK TO SAVEPOINT shared_fixture_clean');
        } catch (\PDOException) {
            // Předchozí test transakci sám ukončil a začal novou: savepointy zmizely s ní.
            $pdo->rollBack();
            $pdo->beginTransaction();
            $pdo->exec('SAVEPOINT shared_fixture_clean');
            self::$sharedFixtureState = null;
        }
    }

    /** Volá tearDown místo rollBack(); sdílenou transakci nechá dalšímu testu třídy. */
    protected function endSharedFixtureTest(PDO $pdo): void
    {
        if ($this->sharedFixtureLegacy && $pdo->inTransaction()) $pdo->rollBack();
    }

    /**
     * Základ podle klíče: poprvé ho postaví nad čistým stavem, potom vrací uložený.
     *
     * @template T
     * @param callable():T $build
     * @return T
     */
    protected function sharedFixture(string $key, callable $build): mixed
    {
        if ($this->sharedFixtureLegacy) return $build();
        $pdo = self::$sharedFixturePdo ?? throw new \LogicException('Shared fixture test did not begin.');
        if (self::$sharedFixtureState !== null && self::$sharedFixtureState['key'] === $key) {
            return self::$sharedFixtureState['value'];
        }
        $this->sharedClean();
        $value = $build();
        $pdo->exec('SAVEPOINT shared_fixture_ready');
        self::$sharedFixtureState = ['key' => $key, 'value' => $value];
        return $value;
    }

    /** Test bez sdíleného základu začíná z čistého stavu třídy. */
    protected function sharedClean(): void
    {
        if ($this->sharedFixtureLegacy) return;
        $pdo = self::$sharedFixturePdo ?? throw new \LogicException('Shared fixture test did not begin.');
        if (self::$sharedFixtureState !== null) {
            $pdo->exec('ROLLBACK TO SAVEPOINT shared_fixture_clean');
            self::$sharedFixtureState = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$sharedFixturePdo?->inTransaction()) self::$sharedFixturePdo->rollBack();
        self::$sharedFixturePdo = null;
        self::$sharedFixtureState = null;
        if (SharedTestConnectionGuard::fixtureHolder() === static::class) SharedTestConnectionGuard::setFixtureHolder(null);
    }
}
