<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Database;

use MyInvoice\Infrastructure\Database\DbErrorLogger;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Očekávaná kolize unikátního klíče nemá v produkčním logu svítit jako ERROR —
 * caller ji ošetřuje (idempotentní fronta, návrh k transakci, 409 uživateli).
 * Zúžení na JMENOVANÝ index je tu to podstatné: kdyby se ztišila každá
 * duplicita v rozsahu, neošetřená kolize by skončila jako 500 bez jediného
 * záznamu v logu.
 */
final class DbErrorLoggerExpectedDuplicateTest extends TestCase
{
    private function duplicate(string $index, int $driverCode = 1062): PDOException
    {
        $e = new PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: {$driverCode} Duplicate entry '7' for key '{$index}'",
        );
        $e->errorInfo = ['23000', $driverCode, 'Duplicate entry'];
        return $e;
    }

    public function testOcekavanaDuplicitaJdeDoDebugu(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $logger->expects(self::once())->method('debug')->with(self::stringContains('DB duplicate'));

        DbErrorLogger::expectingDuplicates(['uq_bps_pending'], function () use ($logger): void {
            DbErrorLogger::log($logger, $this->duplicate('uq_bps_pending'), 'INSERT INTO t VALUES (?)', [1]);
        });
    }

    public function testDuplicitaNaJinemIndexuZustavaChybou(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $logger->expects(self::never())->method('debug');

        DbErrorLogger::expectingDuplicates(['uq_bps_pending'], function () use ($logger): void {
            DbErrorLogger::log($logger, $this->duplicate('uq_something_else'), 'INSERT INTO t VALUES (?)', [1]);
        });
    }

    /** 1452 (cizí klíč) nese týž SQLSTATE 23000 — očekávaný výsledek to nikdy není. */
    public function testPoruseniCizihoKliceZustavaChybou(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $logger->expects(self::never())->method('debug');

        DbErrorLogger::expectingDuplicates(['uq_bps_pending'], function () use ($logger): void {
            DbErrorLogger::log($logger, $this->duplicate('uq_bps_pending', 1452), 'INSERT INTO t VALUES (?)', [1]);
        });
    }

    public function testMimoRozsahZustavaDuplicitaChybou(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $logger->expects(self::never())->method('debug');

        DbErrorLogger::log($logger, $this->duplicate('uq_bps_pending'), 'INSERT INTO t VALUES (?)', [1]);
    }

    /** Rozsah končí i při výjimce — jinak by potlačení přeteklo do dalších dotazů. */
    public function testRozsahKonciIPriVyjimce(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $logger->expects(self::never())->method('debug');

        try {
            DbErrorLogger::expectingDuplicates(['uq_bps_pending'], static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // očekávané
        }

        DbErrorLogger::log($logger, $this->duplicate('uq_bps_pending'), 'INSERT INTO t VALUES (?)', [1]);
    }
}
