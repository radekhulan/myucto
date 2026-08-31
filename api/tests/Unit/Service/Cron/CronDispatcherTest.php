<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Cron\CronCatalog;
use MyInvoice\Service\Cron\CronDispatcher;
use MyInvoice\Service\Cron\CronJobGate;
use MyInvoice\Service\Cron\CronProcessLauncher;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Rozhodovací logika dispatcheru nad SQLite in-memory + falešným launcherem.
 * Skutečné procesy se tu nespouští — testuje se, co by spustil a co ne.
 */
final class CronDispatcherTest extends TestCase
{
    private PDO $pdo;
    private RecordingLauncher $launcher;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE cron_dispatch_claims (
                script TEXT NOT NULL,
                minute_bucket TEXT NOT NULL,
                claimed_at TEXT NOT NULL,
                PRIMARY KEY (script, minute_bucket)
            )'
        );
        $this->launcher = new RecordingLauncher();
    }

    private function dispatcher(): CronDispatcher
    {
        // Prázdný config → úlohy s `requires_config` se přeskočí, což je pro
        // většinu testů žádoucí (typický tenant je nemá nastavené).
        return new CronDispatcher($this->pdo, new CronJobGate(new Config([]), null), $this->launcher);
    }

    public function testQuietMinuteLaunchesNothing(): void
    {
        // 13:37 — v katalogu na tuhle minutu nepadá nic kromě úloh běžících
        // každou minutu, a ty mají bránu na skutečnou práci (SQLite tabulky
        // neexistují → fail-open → stav EPO i fronta dokumentů se spustí).
        $report = $this->dispatcher()->tick(new DateTimeImmutable('2026-08-03 13:37:00'));

        self::assertSame(
            [
                'cron-epo-status',
                'cron-payroll-document-worker',
                'cron-payroll-period-export-worker',
            ],
            $report['due'],
        );
        self::assertSame([], $report['errors']);
    }

    public function testDailyJobFiresOnlyInItsMinute(): void
    {
        $at0200 = $this->dispatcher()->tick(new DateTimeImmutable('2026-08-03 02:00:00'));
        self::assertContains('cron-backup', $at0200['launched'], 'Záloha musí ve 2:00 běžet.');

        $at0201 = $this->dispatcher()->tick(new DateTimeImmutable('2026-08-03 02:01:00'));
        self::assertNotContains('cron-backup', $at0201['launched'], 'V 2:01 už zálohu spouštět nesmí.');
    }

    /**
     * Hodinová obnova licence patří JEN spravovanému provozu. Self-hosted instalace
     * má úlohu naplánovanou denně už dnes a admin si plán sám nepřenastaví — kdyby
     * katalog přepnul na hodinový rytmus plošně, začal by `max_age_hours` hlásit
     * opožděnou úlohu, přestože běží přesně tak, jak byla nastavená.
     */
    public function testLicenseRenewFiresHourlyOnlyOnManagedInstallations(): void
    {
        $managed = new CronDispatcher(
            $this->pdo,
            new CronJobGate(new Config(['app' => ['managed' => true]]), null),
            $this->launcher,
        );

        $at1015 = $managed->tick(new DateTimeImmutable('2026-08-03 10:15:00'));
        self::assertContains('cron-license-renew', $at1015['launched']);

        $at1016 = $managed->tick(new DateTimeImmutable('2026-08-03 10:16:00'));
        self::assertNotContains('cron-license-renew', $at1016['launched']);
    }

    public function testLicenseRenewStaysDailyOnSelfHostedInstallations(): void
    {
        // `dispatcher()` staví bránu s prázdnou konfigurací = self-hosted.
        $at1015 = $this->dispatcher()->tick(new DateTimeImmutable('2026-08-03 10:15:00'));
        self::assertNotContains('cron-license-renew', $at1015['launched']);

        $at0500 = $this->dispatcher()->tick(new DateTimeImmutable('2026-08-03 05:00:00'));
        self::assertContains('cron-license-renew', $at0500['launched']);
    }

    /**
     * Nejcitlivější vlastnost dispatcheru. Dvojí spuštění v téže minutě
     * (cron + ruční běh) nesmí pustit úlohu dvakrát — u pravidelné fakturace
     * by to znamenalo doklady navíc.
     */
    public function testSecondTickInSameMinuteLaunchesNothingAgain(): void
    {
        $when = new DateTimeImmutable('2026-08-03 06:30:00');

        $first = $this->dispatcher()->tick($when);
        self::assertContains('cron-generate-recurring-invoices', $first['launched']);

        $second = $this->dispatcher()->tick($when);
        self::assertNotContains('cron-generate-recurring-invoices', $second['launched']);
        self::assertSame('already_dispatched', $second['skipped']['cron-generate-recurring-invoices'] ?? null);

        self::assertSame(
            1,
            $this->launcher->countOf('cron-generate-recurring-invoices'),
            'Úloha se za jednu minutu smí spustit právě jednou.',
        );
    }

    public function testNextMinuteClaimsAgain(): void
    {
        $this->dispatcher()->tick(new DateTimeImmutable('2026-08-03 13:37:00'));
        $this->dispatcher()->tick(new DateTimeImmutable('2026-08-03 13:38:00'));

        self::assertSame(2, $this->launcher->countOf('cron-epo-status'));
        self::assertSame(2, $this->launcher->countOf('cron-payroll-document-worker'));
    }

    public function testUnconfiguredJobIsSkippedNotLaunched(): void
    {
        // cron-scan-purchase-inbox má requires_config; prázdný config → skip.
        $report = $this->dispatcher()->tick(new DateTimeImmutable('2026-08-03 09:10:00'));

        self::assertContains('cron-scan-purchase-inbox', $report['due']);
        self::assertSame('not_configured', $report['skipped']['cron-scan-purchase-inbox'] ?? null);
        self::assertNotContains('cron-scan-purchase-inbox', $report['launched']);
    }

    public function testDryRunDecidesButLaunchesNothing(): void
    {
        $report = $this->dispatcher()->tick(new DateTimeImmutable('2026-08-03 02:00:00'), true);

        self::assertContains('cron-backup', $report['launched']);
        self::assertSame(0, $this->launcher->total(), '--dry-run nesmí spustit ani jeden proces.');

        // A nesmí si ani nárokovat minutu, jinak by ostrý běh o minutu přišel.
        $real = $this->dispatcher()->tick(new DateTimeImmutable('2026-08-03 02:00:00'));
        self::assertContains('cron-backup', $real['launched']);
    }

    public function testDispatcherNeverDispatchesItself(): void
    {
        foreach (['00:00:00', '02:00:00', '09:00:00', '13:37:00'] as $time) {
            $report = $this->dispatcher()->tick(new DateTimeImmutable('2026-08-03 ' . $time));
            self::assertNotContains(CronCatalog::DISPATCHER_SCRIPT, $report['due']);
            self::assertNotContains(CronCatalog::DISPATCHER_SCRIPT, $report['launched']);
        }
    }

    public function testFailedLaunchIsReportedAsError(): void
    {
        $this->launcher->failEverything = true;
        $report = $this->dispatcher()->tick(new DateTimeImmutable('2026-08-03 02:00:00'));

        self::assertArrayHasKey('cron-backup', $report['errors']);
        self::assertStringContainsString('launch_failed', $report['errors']['cron-backup']);
        self::assertNotContains('cron-backup', $report['launched']);
    }

    /**
     * Nedostupná claim tabulka nesmí vést k tomu, že se úloha spustí bez ochrany
     * proti duplicitě — v takovém stavu je správné ji raději vynechat.
     */
    public function testMissingClaimTableBlocksLaunchRatherThanRiskingDuplicate(): void
    {
        $this->pdo->exec('DROP TABLE cron_dispatch_claims');
        $report = $this->dispatcher()->tick(new DateTimeImmutable('2026-08-03 02:00:00'));

        self::assertSame(0, $this->launcher->total());
        // Rozlišené od 'already_dispatched' — jinak by rozbitá tabulka vypadala
        // v logu jako normální provoz a nikdo by si nevšiml, že cron stojí.
        self::assertSame(CronDispatcher::CLAIM_UNAVAILABLE, $report['skipped']['cron-backup'] ?? null);
    }
}

final class RecordingLauncher implements CronProcessLauncher
{
    public bool $failEverything = false;

    /** @var list<string> */
    public array $launched = [];

    public function launch(string $script, ?string &$error = null): bool
    {
        if ($this->failEverything) {
            $error = 'test failure';
            return false;
        }
        $this->launched[] = $script;
        return true;
    }

    public function countOf(string $script): int
    {
        return count(array_filter($this->launched, static fn (string $s): bool => $s === $script));
    }

    public function total(): int
    {
        return count($this->launched);
    }
}
