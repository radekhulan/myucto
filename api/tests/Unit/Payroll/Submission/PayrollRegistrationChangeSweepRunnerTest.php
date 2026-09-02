<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeSweeper;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeSweepRunner;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationSweepTargets;
use PHPUnit\Framework\TestCase;

/**
 * Noční průchod detekcí registračních změn.
 *
 * Testuje se to, co u jednotlivé firmy nikdo neuvidí a co přitom rozhoduje
 * o zákonné lhůtě: že se instalace bez mezd vůbec nedotkne, že opakovaný běh
 * nezaloží povinnost podruhé a že jedna rozbitá firma nepřipraví o lhůtu
 * všechny ostatní.
 */
final class PayrollRegistrationChangeSweepRunnerTest extends TestCase
{
    /**
     * Mzdy jsou opt-in per firma. Firma, která je nemá, se do seznamu cílů
     * nedostane — a runner na ni nesmí sáhnout ani tehdy, když ji někdo
     * vypíše ručně přes `--supplier`.
     */
    public function testInstallationWithoutPayrollDoesNothing(): void
    {
        $sweeper = new SweepRunnerRecordingSweeper();
        $runner = new PayrollRegistrationChangeSweepRunner(
            new SweepRunnerTargets([]),
            $sweeper,
        );

        $report = $runner->run('production');

        self::assertSame(0, $report['suppliers']);
        self::assertSame(0, $report['scanned']);
        self::assertSame(0, $report['created']);
        self::assertSame(0, $report['errors']);
        self::assertSame([], $sweeper->calls);
    }

    public function testSupplierWithoutPayrollIsSkippedEvenWhenRequestedByHand(): void
    {
        $sweeper = new SweepRunnerRecordingSweeper();
        $runner = new PayrollRegistrationChangeSweepRunner(
            new SweepRunnerTargets([7]),
            $sweeper,
        );

        $report = $runner->run('production', [42]);

        self::assertSame(0, $report['suppliers']);
        self::assertSame([], $sweeper->calls);
    }

    /**
     * Idempotence. Fake modeluje přesně to, co v ostrém provozu drží vodoznak
     * `payroll_registration_change_scans.source_watermark`: kandidátem je jen
     * vztah, jehož zdroj se od posledního porovnání pohnul. Druhý běh nad
     * nezměněnými daty tedy neporovná nic a žádnou lhůtu nezaloží podruhé.
     */
    public function testSecondRunOverUnchangedDataCreatesNothing(): void
    {
        $sweeper = new SweepRunnerWatermarkSweeper([1 => 3]);
        $runner = new PayrollRegistrationChangeSweepRunner(
            new SweepRunnerTargets([1]),
            $sweeper,
        );

        $first = $runner->run('production');
        $second = $runner->run('production');

        self::assertSame(3, $first['scanned']);
        self::assertSame(3, $first['created']);
        self::assertSame(0, $second['scanned']);
        self::assertSame(0, $second['created']);
        self::assertSame(0, $second['changed']);
    }

    /** Pohne-li se zdroj znovu, vznikne nová povinnost — jinak by lhůta zmizela. */
    public function testChangedSourceProducesANewProposal(): void
    {
        $sweeper = new SweepRunnerWatermarkSweeper([1 => 2]);
        $runner = new PayrollRegistrationChangeSweepRunner(
            new SweepRunnerTargets([1]),
            $sweeper,
        );

        $runner->run('production');
        $sweeper->touch(1, 1);
        $report = $runner->run('production');

        self::assertSame(1, $report['scanned']);
        self::assertSame(1, $report['created']);
    }

    /**
     * Selhání jedné firmy nesmí zastavit ostatní: mzdy jsou napříč firmami
     * nezávislé a lhůta běží každé zvlášť.
     */
    public function testOneFailingSupplierDoesNotStopTheOthers(): void
    {
        $sweeper = new SweepRunnerRecordingSweeper(failing: [2]);
        $runner = new PayrollRegistrationChangeSweepRunner(
            new SweepRunnerTargets([1, 2, 3]),
            $sweeper,
        );

        $report = $runner->run('production');

        self::assertSame([1, 2, 3], $sweeper->calls);
        self::assertSame(3, $report['suppliers']);
        self::assertSame(1, $report['errors']);
        self::assertCount(1, $report['failures']);
        self::assertSame(2, $report['failures'][0]['supplier_id']);
        // Dvě zdravé firmy se prošly, přestože prostřední spadla.
        self::assertSame(2, $report['scanned']);
    }

    /** Velká firma se bere po porcích, dokud porce chodí plné. */
    public function testLargeSupplierIsSweptInBatchesUntilTheQueueRunsOut(): void
    {
        $sweeper = new SweepRunnerWatermarkSweeper([1 => 25]);
        $runner = new PayrollRegistrationChangeSweepRunner(
            new SweepRunnerTargets([1]),
            $sweeper,
        );

        $report = $runner->run('production', null, batch: 10);

        self::assertSame(25, $report['scanned']);
        self::assertSame(3, $sweeper->rounds);
        self::assertSame([], $report['truncated']);
    }

    /**
     * Strop porcí je pojistka proti tomu, aby jedna obří firma sežrala celé
     * noční okno. Zbytek dojede další běh — vodoznak drží pozici.
     */
    public function testBatchCeilingReportsTruncationInsteadOfRunningForever(): void
    {
        $sweeper = new SweepRunnerWatermarkSweeper([1 => 100]);
        $runner = new PayrollRegistrationChangeSweepRunner(
            new SweepRunnerTargets([1]),
            $sweeper,
        );

        $report = $runner->run('production', null, batch: 10, maxBatches: 2);

        self::assertSame(20, $report['scanned']);
        self::assertSame([1], $report['truncated']);
    }

    /**
     * Porce, ve které se nepodařilo porovnat ani jeden vztah, se příště vrátí
     * ve stejném složení (vodoznak se u selhání neukládá). Runner se o ni
     * nesmí pokoušet donekonečna.
     */
    public function testFullyUnreadableBatchStopsTheSupplierInsteadOfLooping(): void
    {
        $sweeper = new SweepRunnerUnreadableSweeper();
        $runner = new PayrollRegistrationChangeSweepRunner(
            new SweepRunnerTargets([1]),
            $sweeper,
        );

        $report = $runner->run('production', null, batch: 5, maxBatches: 50);

        self::assertSame(1, $sweeper->rounds);
        self::assertSame(5, $report['unreadable']);
        self::assertSame(0, $report['created']);
        self::assertSame([], $report['truncated']);
    }

    public function testEnvironmentIsValidated(): void
    {
        $runner = new PayrollRegistrationChangeSweepRunner(
            new SweepRunnerTargets([1]),
            new SweepRunnerRecordingSweeper(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $runner->run('sandbox');
    }
}

final class SweepRunnerTargets implements PayrollRegistrationSweepTargets
{
    /** @param list<int> $ids */
    public function __construct(private readonly array $ids) {}

    /** @return list<int> */
    public function payrollEnabledSupplierIds(): array
    {
        return $this->ids;
    }
}

/** Prosté zaznamenání volání; jedna firma smí selhat. */
final class SweepRunnerRecordingSweeper implements PayrollRegistrationChangeSweeper
{
    /** @var list<int> */
    public array $calls = [];

    /** @param list<int> $failing */
    public function __construct(private readonly array $failing = []) {}

    /** @return array{scanned:int,changed:int,skipped:int,created:int} */
    public function sweep(int $supplierId, string $environment, int $limit): array
    {
        $this->calls[] = $supplierId;
        if (in_array($supplierId, $this->failing, true)) {
            throw new \RuntimeException('rozbitý snapshot');
        }

        return ['scanned' => 1, 'changed' => 0, 'skipped' => 0, 'created' => 0];
    }
}

/**
 * Fake s vodoznakem: každý vztah má verzi zdroje, porovná se jen tehdy, když
 * se od posledního porovnání změnila, a návrh na tentýž stav vznikne jednou.
 * Tím se v jednotkovém testu drží stejná pravidla jako v databázi.
 */
final class SweepRunnerWatermarkSweeper implements PayrollRegistrationChangeSweeper
{
    public int $rounds = 0;

    /** @var array<int,array<int,int>> supplier => employment => verze zdroje */
    private array $sources = [];

    /** @var array<string,true> otisk „firma:vztah:verze", pro který návrh už existuje */
    private array $proposals = [];

    /** @var array<string,int> poslední porovnaná verze */
    private array $watermarks = [];

    /** @param array<int,int> $employmentsPerSupplier */
    public function __construct(array $employmentsPerSupplier)
    {
        foreach ($employmentsPerSupplier as $supplierId => $count) {
            for ($i = 1; $i <= $count; ++$i) {
                $this->sources[$supplierId][$i] = 1;
            }
        }
    }

    /** Změna hlásitelného údaje = posun verze zdroje. */
    public function touch(int $supplierId, int $employmentId): void
    {
        ++$this->sources[$supplierId][$employmentId];
    }

    /** @return array{scanned:int,changed:int,skipped:int,created:int} */
    public function sweep(int $supplierId, string $environment, int $limit): array
    {
        ++$this->rounds;
        $scanned = 0;
        $changed = 0;
        $created = 0;
        foreach ($this->sources[$supplierId] ?? [] as $employmentId => $version) {
            $key = $supplierId . ':' . $employmentId;
            if (($this->watermarks[$key] ?? null) === $version) {
                continue; // zdroj se nepohnul — kandidátem není
            }
            if ($scanned >= $limit) {
                break;
            }
            ++$scanned;
            $this->watermarks[$key] = $version;
            $stateKey = $key . ':' . $version;
            ++$changed;
            if (!isset($this->proposals[$stateKey])) {
                $this->proposals[$stateKey] = true;
                ++$created;
            }
        }

        return [
            'scanned' => $scanned,
            'changed' => $changed,
            'skipped' => 0,
            'created' => $created,
        ];
    }
}

/** Firma, u které se nedaří porovnat vůbec nic (nečitelné snapshoty). */
final class SweepRunnerUnreadableSweeper implements PayrollRegistrationChangeSweeper
{
    public int $rounds = 0;

    /** @return array{scanned:int,changed:int,skipped:int,created:int} */
    public function sweep(int $supplierId, string $environment, int $limit): array
    {
        ++$this->rounds;

        return [
            'scanned' => $limit,
            'changed' => 0,
            'skipped' => $limit,
            'created' => 0,
        ];
    }
}
