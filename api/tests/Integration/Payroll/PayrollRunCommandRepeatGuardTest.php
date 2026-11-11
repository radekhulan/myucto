<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRunsAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Výpočet a schválení běhu jsou jeden synchronní požadavek, u větší firmy na
 * desítky sekund. Účetní, která mezitím klikne znovu nebo obnoví stránku,
 * nesmí krok spustit podruhé:
 *
 *  - tentýž požadavek (stejný `Idempotency-Key`, typicky opakování po výpadku
 *    spojení) vrátí původní výsledek a nic nepřepočítá;
 *  - nový požadavek ze stránky, která ještě ukazuje starý stav (jiný klíč,
 *    stará `row_version`), skončí konfliktem verze a nic nezmění.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollRunCommandRepeatGuardTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const WORKED = ['2026-04-04', '2026-04-11', '2026-04-18'];

    private PayrollRunsAction $action;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->action = $this->container->get(PayrollRunsAction::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testRepeatedCalculationNeitherRecalculatesNorStartsASecondRevision(): void
    {
        $officeId = $this->createOffice('RPT', 'Syntetická účtárna opakování', '1100001237');
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $componentId = $this->createComponent('ODMENA_DPP_RPT', 'base_wage', 'regular');
        $this->container->get(PayrollComponentJmhzMappingRepository::class)
            ->put($this->supplierId, $componentId, '10329', null, $this->actors[0]);
        $person = $this->createEmployment($officeId, 'Opakovaná Syntetická', 1, 'dpp', 'dpp', 10, 2_500, true, self::PERIOD_START);
        $this->completeJmhzEmployment($person);
        $this->publishShifts($person['employment_id'], self::WORKED);
        self::assertSame(200, $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::WORKED, dailyMinutes: 240)->getStatusCode());
        $this->createApprovedInput($person, $componentId, 360_000, 'rpt-1', self::PERIOD_START);

        $run = $this->runs->createRun($this->supplierId, self::PERIOD_START, '2026-05-15', $officeId, $this->actors[0]);
        $runId = (int) $run['id'];
        $version = (int) $run['row_version'];

        $first = $this->command($runId, $version, 'rpt-first-click');
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        $firstBody = $this->json($first);
        self::assertFalse($firstBody['idempotent_replay']);
        self::assertSame('calculated', $firstBody['to_status']);
        $state = $this->state($runId);

        // Tentýž požadavek znovu (stejný klíč): původní výsledek, žádný přepočet.
        $replay = $this->command($runId, $version, 'rpt-first-click');
        self::assertSame(200, $replay->getStatusCode(), (string) $replay->getBody());
        $replayBody = $this->json($replay);
        self::assertTrue($replayBody['idempotent_replay']);
        self::assertSame($firstBody['revision']['id'], $replayBody['revision']['id']);
        self::assertSame($state, $this->state($runId));

        // Druhé kliknutí ze stránky se starým stavem (nový klíč, stará verze).
        $second = $this->command($runId, $version, 'rpt-second-click');
        self::assertSame(409, $second->getStatusCode(), (string) $second->getBody());
        $error = $this->json($second)['error'];
        self::assertSame('row_version_conflict', $error['code']);
        self::assertSame($firstBody['run']['row_version'], $error['current_row_version']);
        self::assertSame($state, $this->state($runId));
    }

    private function command(int $runId, int $rowVersion, string $key): ResponseInterface
    {
        return $this->action->command(
            $this->request('POST', "/api/payroll/runs/{$runId}/commands/lock_and_calculate")
                ->withParsedBody(['row_version' => $rowVersion])
                ->withHeader('Idempotency-Key', $key),
            new Response(),
            ['id' => (string) $runId, 'command' => 'lock_and_calculate'],
        );
    }

    /**
     * Otisk toho, co by druhé spuštění změnilo: verze běhu, počet revizí,
     * čas výpočtu a počet událostí auditu.
     *
     * @return array<string,mixed>
     */
    private function state(int $runId): array
    {
        return [
            'row_version' => (int) $this->scalar(
                'SELECT row_version FROM payroll_runs WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, $runId],
            ),
            'revisions' => (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_revisions WHERE supplier_id = ? AND run_id = ?',
                [$this->supplierId, $runId],
            ),
            'result_hash' => (string) $this->scalar(
                'SELECT result_snapshot_hash FROM payroll_run_revisions WHERE supplier_id = ? AND run_id = ?',
                [$this->supplierId, $runId],
            ),
            'events' => (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_events WHERE supplier_id = ? AND run_id = ?',
                [$this->supplierId, $runId],
            ),
        ];
    }
}
