<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Mazání a slučování vztahů s registrací: profil A1 ani návrh změny mazání
 * nebrání a po smazání nezůstane nic osiřelého; připravená, odeslaná nebo
 * přijatá přihláška a ID PPV mazání drží se srozumitelnou hláškou.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eRegDeletionTest extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;

    private const START_ON = '2026-11-16';

    private PayrollEmploymentAction $employmentAction;

    protected function setUp(): void
    {
        $this->bootRegistrationFlow('2026-11-11');
        $action = $this->container->get(PayrollEmploymentAction::class);
        self::assertInstanceOf(PayrollEmploymentAction::class, $action);
        $this->employmentAction = $action;
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /** Vztah jen s profilem A1 (bez podání) jde smazat a nic po něm nezůstane. */
    public function testEmploymentWithA1ProfileOnlyIsDeletedWithoutOrphans(): void
    {
        [, $employmentId] = $this->regPerson('Profil', 'Jen', 51, 'employment', self::START_ON);
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON));

        $response = $this->delete($employmentId);

        self::assertSame(200, $response['status'], CanonicalJson::encode($response['body']));
        foreach ([
            'payroll_registration_a1_profiles',
            'payroll_registration_identity_snapshots',
            'payroll_registration_change_proposals',
            'payroll_employment_external_ids',
            'payroll_employment_terms',
        ] as $table) {
            self::assertSame(0, (int) $this->scalar(
                "SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND employment_id = ?",
                [$this->supplierId, $employmentId],
            ), $table);
        }
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_submission_parts WHERE supplier_id = ? AND subject_reference = ?',
            [$this->supplierId, 'payroll_employment:' . $employmentId],
        ));
    }

    /**
     * Připravená (zmrazená, neodeslaná), odeslaná i přijatá přihláška mazání
     * drží. Hláška nesmí tvrdit, že úkon „proběhl navenek", když přihláška
     * jen čeká ve frontě, a musí říct, co dělat.
     */
    public function testPreparedSubmittedAndAcceptedA1BlockDeletionWithAccurateMessage(): void
    {
        [, $ready] = $this->regPerson('Fronta', 'Čeká', 53, 'employment', self::START_ON);
        $this->regSaveA1Profile($ready, $this->regA1Profile(self::START_ON));
        $readyPrepared = $this->regPrepare($ready, ['registration_mode' => 'full']);

        $blocked = $this->delete($ready);
        self::assertSame(409, $blocked['status'], CanonicalJson::encode($blocked['body']));
        $message = (string) ($blocked['body']['error']['message'] ?? '');
        self::assertStringNotContainsString('proběhl navenek', $message, 'Neodeslaná přihláška u ČSSZ není.');
        self::assertStringContainsString((string) $readyPrepared['submission_id'], $message, $message);
        self::assertStringContainsString('zrušte', $message);
        self::assertSame('ready', $this->regSubmissionStatus((int) $readyPrepared['submission_id']));

        // Odeslaná přihláška (čeká na protokol) mazání drží také.
        [, $sent] = $this->regPerson('Odeslaná', 'Čeká', 59, 'employment', self::START_ON);
        $this->regSaveA1Profile($sent, $this->regA1Profile(self::START_ON));
        $sentPrepared = $this->regPrepare($sent, ['registration_mode' => 'full']);
        $submissions = $this->container->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        $submissions->transition(
            $this->supplierId,
            (int) $sentPrepared['submission_id'],
            (int) $sentPrepared['row_version'],
            'submitted',
            'synthetic-e2e-delete-submitted:' . $sentPrepared['submission_id'],
        );
        $blockedSent = $this->delete($sent);
        self::assertSame(409, $blockedSent['status'], CanonicalJson::encode($blockedSent['body']));
        self::assertStringContainsString('nenástup', (string) $blockedSent['body']['error']['message']);

        [, $accepted] = $this->regPerson('Přijatá', 'Přihláška', 55, 'employment', self::START_ON);
        $this->regSaveA1Profile($accepted, $this->regA1Profile(self::START_ON));
        $this->regReceipt(
            $this->regPrepare($accepted, ['registration_mode' => 'full']),
            'accepted',
            '1000000012',
            '200000000000000000055',
        );
        $blocked = $this->delete($accepted);
        self::assertSame(409, $blocked['status']);
        self::assertSame('payroll_employment_registered', $blocked['body']['error']['code'] ?? null, CanonicalJson::encode($blocked['body']));
        self::assertStringContainsString('nenástup', (string) $blocked['body']['error']['message']);
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_employments WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $accepted],
        ));
    }

    /**
     * Přihláška připravená omylem a včas zrušená (nikdy neodešla): vztah, který
     * vznikl chybou, jde smazat; zrušené podání ve frontě zůstane jako záznam
     * a odeslat už nejde.
     */
    public function testEmploymentWithCancelledUnsentA1CanBeDeleted(): void
    {
        [, $employmentId] = $this->regPerson('Zrušená', 'Omylem', 57, 'employment', self::START_ON);
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON));
        $prepared = $this->regPrepare($employmentId, ['registration_mode' => 'full']);
        $submissions = $this->container->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        $submissions->transition(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $prepared['row_version'],
            'cancelled_in_time',
        );

        $response = $this->delete($employmentId);

        self::assertSame(200, $response['status'], CanonicalJson::encode($response['body']));
        self::assertSame('cancelled_in_time', $this->regSubmissionStatus((int) $prepared['submission_id']));
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_registration_a1_profiles WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        ));
    }

    /**
     * Sloučení: zdroj s připravenou přihláškou sloučit nejde (fronta by
     * zůstala bez vztahu), opačný směr projde a cíl si OIČ a ID PPV ponechá.
     */
    public function testMergeKeepsRegisteredTargetAndRefusesSourceWithLiveA1(): void
    {
        [$employeeId, $registered] = $this->regPerson('Sloučení', 'Dvojí', 61, 'dpp', self::START_ON);
        $this->regSaveA1Profile($registered, $this->regA1Profile(self::START_ON, 'T'));
        $this->regReceipt(
            $this->regPrepare($registered, ['registration_mode' => 'full']),
            'accepted',
            '1000000012',
            '200000000000000000061',
        );
        $duplicate = $this->regAdditionalEmployment($employeeId, 'DPP-DUP', 'dpp', self::START_ON);

        $wrongWay = $this->merge($registered, $duplicate);
        self::assertSame(409, $wrongWay['status'], CanonicalJson::encode($wrongWay['body']));
        self::assertSame('payroll_merge_source_registered', $wrongWay['body']['error']['code'] ?? null);

        $merged = $this->merge($duplicate, $registered);
        self::assertSame(200, $merged['status'], CanonicalJson::encode($merged['body']));
        self::assertSame(self::START_ON, $this->regIdentifier($registered, 'id_ppv'));
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_employments WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $duplicate],
        ));

        // Zdroj s přihláškou připravenou ve frontě (bez snímku a bez ID PPV).
        $queued = $this->regAdditionalEmployment($employeeId, 'DPP-Q', 'dpp', '2026-11-18');
        $this->regSaveA1Profile($queued, $this->regA1Profile('2026-11-18', 'T'));
        $this->regPrepare($queued, ['registration_mode' => 'full']);
        $refused = $this->merge($queued, $registered);
        self::assertSame(409, $refused['status'], CanonicalJson::encode($refused['body']));
        self::assertSame('payroll_merge_source_registered', $refused['body']['error']['code'] ?? null);
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function merge(int $sourceId, int $targetId): array
    {
        $version = fn (int $id): int => (int) $this->scalar(
            'SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $id],
        );
        $response = $this->employmentAction->mergeInto(
            $this->request('POST', "/api/payroll/employments/{$sourceId}/merge-into")
                ->withParsedBody([
                    'row_version' => $version($sourceId),
                    'target_employment_id' => $targetId,
                    'target_row_version' => $version($targetId),
                    'adopt_terms' => false,
                ]),
            new Response(),
            ['id' => (string) $sourceId],
        );

        return ['status' => $response->getStatusCode(), 'body' => $this->json($response)];
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function delete(int $employmentId): array
    {
        $rowVersion = (int) $this->scalar(
            'SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $employmentId],
        );
        $response = $this->employmentAction->delete(
            $this->request('DELETE', "/api/payroll/employments/{$employmentId}")
                ->withParsedBody(['row_version' => $rowVersion]),
            new Response(),
            ['id' => (string) $employmentId],
        );

        return ['status' => $response->getStatusCode(), 'body' => $this->json($response)];
    }
}
