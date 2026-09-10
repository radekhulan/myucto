<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Cron\CronCatalog;
use MyInvoice\Service\Cron\CronJobGate;
use MyInvoice\Service\Cron\CronScheduleMode;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Relevance plánované úlohy — jediná definice „tahle úloha u téhle instalace
 * nemá co dělat". Čte ji přehled úloh i kontrola prostředí, takže chyba tady
 * buď schová skutečný výpadek (falešné „neaktivní"), nebo hlásí poplach
 * o funkci, kterou nikdo nezapnul.
 */
final class CronJobGateRelevanceTest extends TestCase
{
    public function testUnconfiguredDirectoryMakesJobInactive(): void
    {
        $gate = new CronJobGate(new Config([]), null);

        self::assertSame(
            CronJobGate::INACTIVE_NOT_CONFIGURED,
            $gate->inactiveReason($this->job('cron-bank-scan')),
        );
    }

    public function testConfiguredDirectoryMakesJobRelevant(): void
    {
        $gate = new CronJobGate(new Config(['bank_import' => ['scan_root' => sys_get_temp_dir()]]), null);

        self::assertNull($gate->inactiveReason($this->job('cron-bank-scan')));
    }

    /**
     * Měření spotřeby místa existuje kvůli kvótě spravovaného provozu.
     * Na self-hostu je to jediná úloha, která by procházela celý datový strom
     * každou hodinu — a její výsledek by tam neměl jediného konzumenta.
     */
    public function testStorageUsageIsInactiveWithoutManagedMode(): void
    {
        $gate = new CronJobGate(new Config([]), null);

        self::assertSame(
            CronJobGate::INACTIVE_MANAGED_ONLY,
            $gate->inactiveReason($this->job('cron-storage-usage')),
        );
        self::assertFalse($gate->isSchedulable($this->job('cron-storage-usage')));
    }

    public function testStorageUsageIsRelevantInManagedMode(): void
    {
        $gate = new CronJobGate(new Config(['app' => ['managed' => true]]), null);

        self::assertNull($gate->inactiveReason($this->job('cron-storage-usage')));
        self::assertTrue($gate->isSchedulable($this->job('cron-storage-usage')));
    }

    /** Ostatní úlohy se zámkem spravovaného režimu minout nesmí. */
    public function testManagedOnlyFlagDoesNotLeakToOtherJobs(): void
    {
        $gate = new CronJobGate(new Config([]), null);

        self::assertNull($gate->inactiveReason($this->job('cron-backup')));
        self::assertNull($gate->inactiveReason($this->job('cron-cnb-rates')));
    }

    public function testJobWithoutAnyConditionIsAlwaysRelevant(): void
    {
        $gate = new CronJobGate(new Config([]), null);

        self::assertNull($gate->inactiveReason($this->job('cron-backup')));
    }

    public function testMonthlyPostingJobsAreInactiveWithoutDoubleEntry(): void
    {
        $gate = new CronJobGate(new Config([]), $this->pdoReturning(false));

        self::assertSame(CronJobGate::INACTIVE_FEATURE_OFF, $gate->inactiveReason($this->job('cron-payroll-post')));
        self::assertSame(CronJobGate::INACTIVE_FEATURE_OFF, $gate->inactiveReason($this->job('cron-vat-clearing')));
    }

    public function testMonthlyPostingJobsAreRelevantWithDoubleEntry(): void
    {
        $gate = new CronJobGate(new Config([]), $this->pdoReturning('1'));

        self::assertNull($gate->inactiveReason($this->job('cron-payroll-post')));
        self::assertNull($gate->inactiveReason($this->job('cron-vat-clearing')));
    }

    /**
     * Instalace bez mezd nemá co hlásit do registru pojištěnců — noční detekce
     * registračních změn by tam jen stárla a vyráběla falešný poplach.
     */
    public function testRegistrationChangeDetectionIsInactiveWithoutPayroll(): void
    {
        $gate = new CronJobGate(new Config([]), $this->pdoReturning(false));

        self::assertSame(
            CronJobGate::INACTIVE_FEATURE_OFF,
            $gate->inactiveReason($this->job('cron-payroll-registration-changes')),
        );
    }

    public function testRegistrationChangeDetectionIsRelevantWithPayroll(): void
    {
        $gate = new CronJobGate(new Config([]), $this->pdoReturning('1'));

        self::assertNull(
            $gate->inactiveReason($this->job('cron-payroll-registration-changes')),
        );
    }

    /**
     * Fail-open: nečitelná databáze nesmí úlohu umlčet. Falešné „neaktivní"
     * schová výpadek natrvalo, kdežto falešný poplach si někdo přečte.
     */
    public function testUnreadableDatabaseKeepsJobRelevant(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willThrowException(new \PDOException('no such table'));

        $gate = new CronJobGate(new Config([]), $pdo);

        self::assertNull($gate->inactiveReason($this->job('cron-payroll-post')));
    }

    /** Překlep v katalogu se nesmí projevit jako vypnutá funkce. */
    public function testUnknownFeatureNameKeepsJobRelevant(): void
    {
        $gate = new CronJobGate(new Config([]), $this->pdoReturning(false));

        self::assertNull($gate->inactiveReason(['script' => 'cron-x', 'requires_feature' => 'neexistuje']));
    }

    public function testDispatcherBelongsOnlyToDispatcherMode(): void
    {
        $gate = new CronJobGate(new Config([]), null);
        $job  = $this->job(CronCatalog::DISPATCHER_SCRIPT);

        self::assertNull($gate->inactiveReason($job, CronScheduleMode::DISPATCHER));
        self::assertSame(CronJobGate::INACTIVE_OTHER_MODE, $gate->inactiveReason($job, CronScheduleMode::INDIVIDUAL));
    }

    /**
     * Volitelné úlohy, které na instalaci nemají co obsluhovat. Bez vlastní
     * brány svítily v diagnostice jako zaseklé, přestože jen neměly práci.
     *
     * @return iterable<string,array{string}>
     */
    public static function optionalJobProvider(): iterable
    {
        yield 'bankovní napojení' => ['cron-bank-connections'];
        yield 'e-mailová avíza' => ['cron-bank-email-notices'];
        yield 'katalogový worker' => ['cron-catalog-worker'];
        yield 'stav podání EPO' => ['cron-epo-status'];
    }

    #[DataProvider('optionalJobProvider')]
    public function testOptionalJobWithNothingToServeIsNotInUse(string $script): void
    {
        $gate = new CronJobGate(new Config([]), $this->pdoReturning(false));

        self::assertSame(CronJobGate::INACTIVE_NOT_IN_USE, $gate->inactiveReason($this->job($script)));
    }

    #[DataProvider('optionalJobProvider')]
    public function testOptionalJobWithSomethingToServeIsRelevant(string $script): void
    {
        $gate = new CronJobGate(new Config([]), $this->pdoReturning('1'));

        self::assertNull($gate->inactiveReason($this->job($script)));
    }

    /** Hlášení JMHZ i hlídání jeho dokumentace existují jen kvůli mzdám. */
    public function testJmhzJobsAreInactiveWithoutPayroll(): void
    {
        $gate = new CronJobGate(new Config([]), $this->pdoReturning(false));

        self::assertSame(CronJobGate::INACTIVE_FEATURE_OFF, $gate->inactiveReason($this->job('cron-jmhz-poll')));
        self::assertSame(CronJobGate::INACTIVE_FEATURE_OFF, $gate->inactiveReason($this->job('cron-jmhz-source-monitor')));
    }

    /** Se mzdami, ale bez otevřeného podání nemá dotazování ČSSZ na co čekat. */
    public function testJmhzPollWithPayrollButWithoutOpenSubmissionIsNotInUse(): void
    {
        $gate = new CronJobGate(
            new Config([]),
            $this->pdoAnswering(static fn (string $sql): bool => str_contains($sql, 'payroll_enabled')),
        );

        self::assertSame(CronJobGate::INACTIVE_NOT_IN_USE, $gate->inactiveReason($this->job('cron-jmhz-poll')));
        self::assertNull($gate->inactiveReason($this->job('cron-jmhz-source-monitor')));
    }

    /** Povinné úlohy na tom, co je na instalaci zapnuté, nezávisí nikdy. */
    public function testMandatoryJobsNeverDependOnUsage(): void
    {
        $gate = new CronJobGate(new Config([]), $this->pdoReturning(false));

        foreach ([
            'cron-backup', 'cron-cleanup', 'cron-cnb-rates', 'cron-send-reminders',
            'cron-generate-recurring-invoices', 'cron-journal-integrity-check', 'cron-license-renew',
        ] as $script) {
            self::assertNull($gate->inactiveReason($this->job($script)), $script);
        }
    }

    /** Fail-open platí i pro nové brány: nečitelná databáze úlohu neumlčí. */
    public function testUnreadableDatabaseKeepsOptionalJobsRelevant(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willThrowException(new \PDOException('no such table'));
        $gate = new CronJobGate(new Config([]), $pdo);

        foreach (['cron-bank-connections', 'cron-bank-email-notices', 'cron-catalog-worker', 'cron-epo-status', 'cron-jmhz-poll'] as $script) {
            self::assertNull($gate->inactiveReason($this->job($script)), $script);
        }
    }

    /** `isVisibleInUi()` nesmí být druhá definice relevance, jen její predikát. */
    public function testVisibilityMirrorsInactiveReason(): void
    {
        $gate = new CronJobGate(new Config([]), $this->pdoReturning(false));

        foreach (CronCatalog::all() as $job) {
            foreach (CronScheduleMode::all() as $mode) {
                self::assertSame(
                    $gate->inactiveReason($job, $mode) === null,
                    $gate->isVisibleInUi($job, $mode),
                    "Úloha {$job['script']} v režimu {$mode}",
                );
            }
        }
    }

    /** @return array<string,mixed> */
    private function job(string $script): array
    {
        foreach (CronCatalog::all() as $job) {
            if ($job['script'] === $script) {
                return $job;
            }
        }
        self::fail("Katalog neobsahuje úlohu {$script}.");
    }

    private function pdoReturning(mixed $column): PDO
    {
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn($column);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn($stmt);

        return $pdo;
    }

    /** @param callable(string):bool $answer Najde dotaz řádek? */
    private function pdoAnswering(callable $answer): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturnCallback(function (string $sql) use ($answer): PDOStatement {
            $stmt = $this->createStub(PDOStatement::class);
            $stmt->method('fetchColumn')->willReturn($answer($sql) ? '1' : false);
            return $stmt;
        });

        return $pdo;
    }
}
