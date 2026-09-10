<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Cron\CronCatalog;
use MyInvoice\Service\Cron\CronDispatcher;
use MyInvoice\Service\Cron\CronHealth;
use MyInvoice\Service\Cron\CronJobGate;
use MyInvoice\Service\Cron\CronScheduleMode;
use MyInvoice\Service\System\EnvironmentCheckService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola prostředí, kontrola „Plánované úlohy".
 *
 * Falešný poplach tady stojí víc než chybějící nález: uživatel si na červenou
 * zvykne a přestane číst celou stránku. Proto se hlídá, že se mezi zaseklé
 * nepočítá ani úloha, kterou nikdo nenakonfiguroval, ani měsíční úloha, která
 * prostě ještě není na řadě.
 */
final class CronHealthCheckTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const HOUR = 3600;

    public function testConfiguredJobThatStoppedRunningIsStale(): void
    {
        $result = $this->classify([$this->heartbeat('cron-backup', 48 * self::HOUR)]);

        self::assertSame(['cron-backup'], $result['stale']);
        self::assertSame([], $result['idle']);
    }

    public function testUnconfiguredJobThatStoppedRunningIsNotStale(): void
    {
        $result = $this->classify(
            [$this->heartbeat('cron-bank-scan', 48 * self::HOUR)],
            ['cron-bank-scan' => CronJobGate::INACTIVE_NOT_CONFIGURED],
        );

        self::assertSame([], $result['stale']);
    }

    public function testFreshRunIsFine(): void
    {
        $result = $this->classify([$this->heartbeat('cron-backup', 600)]);

        self::assertSame([], $result['stale']);
        self::assertSame([], $result['idle']);
        self::assertSame(600, $result['oldest_ok_age_sec']);
    }

    /**
     * Měsíční úlohy se plochým limitem 26 hodin hlásily jako zaseklé pořád —
     * dvanáct dní po posledním běhu je u nich normální stav, ne porucha.
     */
    public function testMonthlyJobIsNotStaleTwelveDaysAfterItsRun(): void
    {
        $result = $this->classify([
            $this->heartbeat('cron-payroll-post', 12 * 24 * self::HOUR),
            $this->heartbeat('cron-vat-clearing', 12 * 24 * self::HOUR),
        ]);

        self::assertSame([], $result['stale']);
    }

    public function testMonthlyJobIsStaleOnceItMissesWholePeriod(): void
    {
        $result = $this->classify([$this->heartbeat('cron-payroll-post', 40 * 24 * self::HOUR)]);

        self::assertSame(['cron-payroll-post'], $result['stale']);
    }

    /**
     * V režimu dispatcheru se gatovaná úloha vůbec nespustí, když pro ni není
     * práce — její heartbeat proto legitimně stárne. Za důkaz života se bere
     * dispatcher, viz {@see \MyInvoice\Service\Cron\CronHealth}.
     */
    public function testDispatcherGatedJobWithoutWorkIsIdleNotStale(): void
    {
        $result = $this->classify(
            [$this->heartbeat('cron-epo-status', 12 * 24 * self::HOUR)],
            [],
            ['cron-epo-status'],
            true,
        );

        self::assertSame([], $result['stale']);
        self::assertSame(['cron-epo-status'], $result['idle']);
    }

    /** Mrtvý dispatcher už ticho podřízené úlohy nevysvětluje. */
    public function testDispatcherGatedJobIsStaleWhenDispatcherIsDead(): void
    {
        $result = $this->classify(
            [$this->heartbeat('cron-epo-status', 12 * 24 * self::HOUR)],
            [],
            ['cron-epo-status'],
            false,
        );

        self::assertSame(['cron-epo-status'], $result['stale']);
    }

    /** Skript mimo katalog neumíme posoudit — platí původní plochý limit. */
    public function testUnknownScriptFallsBackToFlatLimit(): void
    {
        self::assertSame(['cron-neznama'], $this->classify([$this->heartbeat('cron-neznama', 48 * self::HOUR)])['stale']);
        self::assertSame([], $this->classify([$this->heartbeat('cron-neznama', 2 * self::HOUR)])['stale']);
    }

    /** Heartbeat bez jediného běhu se neposuzuje — od toho je stránka úloh. */
    public function testHeartbeatWithoutAnyRunIsIgnored(): void
    {
        $result = $this->classify([['script' => 'cron-backup', 'last_ok_at' => null, 'last_tick_at' => null, 'last_status' => null]]);

        self::assertSame([], $result['stale']);
        self::assertNull($result['oldest_ok_age_sec']);
    }

    /**
     * Stojí-li dispatcher, stojí všechno, co spouští. Osm „zaseklých" úloh
     * vedlo k hledání chyby v každé z nich, přitom stačí rozběhnout jedinou
     * položku plánovače. Úlohy zůstávají jen jako podrobnost.
     */
    public function testDeadDispatcherIsOneSchedulerProblemNotManyStaleJobs(): void
    {
        $result = $this->classify(
            [
                $this->heartbeat(CronCatalog::DISPATCHER_SCRIPT, 3 * self::HOUR),
                $this->heartbeat('cron-epo-status', 3 * self::HOUR),
                $this->heartbeat('cron-bank-connections', 5 * self::HOUR),
                $this->heartbeat('cron-backup', 2 * self::HOUR),
            ],
            [],
            CronDispatcher::gatedScripts(),
            false,
            CronScheduleMode::DISPATCHER,
        );

        self::assertSame(CronHealth::SCHEDULER_DISPATCHER_DOWN, $result['scheduler_down']);

        $check = EnvironmentCheckService::cronHealthCheck(['available' => true, 'inactive' => []] + $result);
        self::assertSame(EnvironmentCheckService::STATUS_FAIL, $check['status']);
        self::assertSame(CronHealth::SCHEDULER_DISPATCHER_DOWN, $check['variant']);
        self::assertSame(CronHealth::SCHEDULER_DISPATCHER_DOWN, $check['actual']);
        self::assertSame(
            [CronCatalog::DISPATCHER_SCRIPT, 'cron-epo-status', 'cron-bank-connections'],
            $check['meta']['stale'],
        );
    }

    /** V režimu jednotlivých úloh prozradí mrtvý cron to, že neběží vůbec nic. */
    public function testNothingRunningInIndividualModeIsOneSchedulerProblem(): void
    {
        $result = $this->classify([
            $this->heartbeat('cron-backup', 48 * self::HOUR),
            $this->heartbeat('cron-cleanup', 48 * self::HOUR),
            $this->heartbeat('cron-cnb-rates', 48 * self::HOUR),
        ]);

        self::assertSame(CronHealth::SCHEDULER_NOTHING_RUNS, $result['scheduler_down']);
        self::assertSame(
            CronHealth::SCHEDULER_NOTHING_RUNS,
            EnvironmentCheckService::cronHealthCheck(['available' => true, 'inactive' => []] + $result)['variant'],
        );
    }

    /** Jedna zaseklá úloha vedle běžících je nález o té úloze, ne o plánovači. */
    public function testOneStaleJobBesideRunningOnesIsNotSchedulerProblem(): void
    {
        $result = $this->classify([
            $this->heartbeat('cron-backup', 48 * self::HOUR),
            $this->heartbeat('cron-cleanup', self::HOUR),
        ]);

        self::assertNull($result['scheduler_down']);
        $check = EnvironmentCheckService::cronHealthCheck(['available' => true, 'inactive' => []] + $result);
        self::assertSame('cron-backup', $check['actual']);
        self::assertArrayNotHasKey('variant', $check);
    }

    public function testLiveDispatcherKeepsStaleJobAsItsOwnProblem(): void
    {
        $result = $this->classify(
            [
                $this->heartbeat(CronCatalog::DISPATCHER_SCRIPT, 60),
                $this->heartbeat('cron-backup', 48 * self::HOUR),
            ],
            [],
            CronDispatcher::gatedScripts(),
            true,
            CronScheduleMode::DISPATCHER,
        );

        self::assertNull($result['scheduler_down']);
        self::assertSame(['cron-backup'], $result['stale']);
    }

    /**
     * Neaktivita volitelných úloh nesmí spolknout povinnou úlohu: kurzy ČNB
     * po limitu jsou pořád zaseklé, i když banka, EPO ani katalog nic nemají.
     */
    public function testMandatoryJobPastLimitStaysStaleWhileOptionalJobsAreNotInUse(): void
    {
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(false);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn($stmt);
        $gate = new CronJobGate(new Config([]), $pdo);

        $inactive = [];
        foreach (CronCatalog::all() as $job) {
            $reason = $gate->inactiveReason($job);
            if ($reason !== null) {
                $inactive[(string) $job['script']] = $reason;
            }
        }

        $result = $this->classify(
            [
                $this->heartbeat('cron-cnb-rates', 48 * self::HOUR),
                $this->heartbeat('cron-bank-connections', 48 * self::HOUR),
                $this->heartbeat('cron-epo-status', 48 * self::HOUR),
                $this->heartbeat('cron-catalog-worker', 48 * self::HOUR),
                $this->heartbeat('cron-cleanup', self::HOUR),
            ],
            $inactive,
        );

        self::assertSame(['cron-cnb-rates'], $result['stale']);
        self::assertNull($result['scheduler_down']);
        self::assertSame(CronJobGate::INACTIVE_NOT_IN_USE, $inactive['cron-bank-connections']);
    }

    /** Katalog musí zůstat zdrojem intervalů — jinak test výš nic nehlídá. */
    public function testMonthlyJobsKeepMonthlyToleranceInCatalog(): void
    {
        self::assertGreaterThan(24 * 30, CronCatalog::maxAgeHours('cron-payroll-post'));
        self::assertGreaterThan(24 * 30, CronCatalog::maxAgeHours('cron-vat-clearing'));
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,string> $inactive
     * @param list<string> $gatedScripts
     * @return array{oldest_ok_age_sec:?int,stale:list<string>,idle:list<string>,scheduler_down:?string}
     */
    private function classify(
        array $rows,
        array $inactive = [],
        array $gatedScripts = [],
        bool $dispatcherAlive = false,
        string $mode = CronScheduleMode::INDIVIDUAL,
    ): array {
        return EnvironmentCheckService::classifyCronHeartbeats($rows, $inactive, $gatedScripts, $dispatcherAlive, self::NOW, $mode);
    }

    /** @return array<string,mixed> */
    private function heartbeat(string $script, int $ageSec, string $status = 'ok'): array
    {
        return [
            'script'       => $script,
            'last_ok_at'   => date('Y-m-d H:i:s', self::NOW - $ageSec),
            'last_tick_at' => date('Y-m-d H:i:s', self::NOW - $ageSec),
            'last_status'  => $status,
        ];
    }
}
