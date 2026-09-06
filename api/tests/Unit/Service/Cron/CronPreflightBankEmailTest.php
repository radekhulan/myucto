<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use MyInvoice\Service\Cron\CronPreflight;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Brána skenu bankovních e-mailových avíz.
 *
 * Vypnutá schránka se nesmí počítat jako práce — jinak by úloha každou půlhodinu
 * stavěla DI kontejner jen proto, aby ji skener sám přeskočil.
 */
final class CronPreflightBankEmailTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE bank_email_imap_settings (id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL, enabled INTEGER NOT NULL)');
    }

    public function testNoAccountAtAllMeansNoWork(): void
    {
        self::assertFalse(CronPreflight::hasBankEmailNoticeAccounts($this->pdo));
    }

    public function testDisabledAccountMeansNoWork(): void
    {
        $this->pdo->exec('INSERT INTO bank_email_imap_settings (supplier_id, enabled) VALUES (1, 0)');

        self::assertFalse(CronPreflight::hasBankEmailNoticeAccounts($this->pdo));
    }

    public function testEnabledAccountOpensTheGate(): void
    {
        $this->pdo->exec('INSERT INTO bank_email_imap_settings (supplier_id, enabled) VALUES (1, 0)');
        $this->pdo->exec('INSERT INTO bank_email_imap_settings (supplier_id, enabled) VALUES (2, 1)');

        self::assertTrue(CronPreflight::hasBankEmailNoticeAccounts($this->pdo));
    }

    public function testMissingTableFailsOpen(): void
    {
        $this->pdo->exec('DROP TABLE bank_email_imap_settings');

        self::assertTrue(CronPreflight::hasBankEmailNoticeAccounts($this->pdo));
    }
}
