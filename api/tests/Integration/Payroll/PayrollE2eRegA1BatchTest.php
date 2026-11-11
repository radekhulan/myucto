<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\PayrollEmploymentBulkClosure;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1BatchService;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E2E hromadné přípravy přihlášek A1 (Mimořádná podání → Přihlášky
 * k podání): nabídka nikdy neukáže vztah, který už A1 má nebo je
 * přihlášený, odmítnutá A1 se nabídne znovu, lhůty a navazující DPP
 * (každá nová dohoda = nový pojistný vztah = nová A1).
 *
 * Síť se nevolá: příprava končí zmrazeným XML, protokol ČSSZ je syntetický.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eRegA1BatchTest extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;

    private const TODAY = '2026-11-11';
    private const START_ON = '2026-11-16';

    private PayrollRegistrationA1BatchService $batch;

    protected function setUp(): void
    {
        $this->bootRegistrationFlow(self::TODAY);
        $batch = $this->container->get(PayrollRegistrationA1BatchService::class);
        self::assertInstanceOf(PayrollRegistrationA1BatchService::class, $batch);
        $this->batch = $batch;
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Každý stav první A1: připravená (zmrazená, ve frontě), odeslaná,
     * přijatá s OIČ a ID PPV → nenabídne se a hromadná příprava ji přeskočí.
     * Odmítnutá → nabídne se znovu a připraví se nová přihláška.
     */
    public function testBatchNeverOffersSecondA1AndReoffersRejectedOne(): void
    {
        $ready = $this->prepared('Připravená', 21);
        $submitted = $this->prepared('Odeslaná', 23);
        $this->submit($submitted['prepared']);
        $accepted = $this->prepared('Přijatá', 25);
        $this->regReceipt($accepted['prepared'], 'accepted', '1000000012', '200000000000000000025');
        $rejected = $this->prepared('Odmítnutá', 27);
        $this->regReceipt($rejected['prepared'], 'rejected');
        self::assertSame('rejected', $this->regSubmissionStatus((int) $rejected['prepared']['submission_id']));
        self::assertSame(self::START_ON, $this->regIdentifier($accepted['employment_id'], 'id_ppv'));

        $offered = $this->offered();
        foreach ([$ready, $submitted, $accepted] as $case) {
            self::assertArrayNotHasKey($case['employment_id'], $offered, CanonicalJson::encode($offered));
        }
        self::assertArrayHasKey($rejected['employment_id'], $offered, 'Odmítnutou A1 je třeba podat znovu.');

        $before = $this->submissionCount();
        $ids = [
            $ready['employment_id'],
            $submitted['employment_id'],
            $accepted['employment_id'],
            $rejected['employment_id'],
            $rejected['employment_id'],
        ];
        $results = array_column(
            $this->batch->prepare($this->supplierId, 'test', $ids, $this->actors[0])['results'],
            null,
            'employment_id',
        );
        self::assertCount(4, $results, 'Duplicitní id v jednom požadavku se zpracuje jednou.');
        self::assertSame('live_registration', $results[$ready['employment_id']]['code']);
        self::assertSame((int) $ready['prepared']['submission_id'], $results[$ready['employment_id']]['submission_id']);
        self::assertSame('live_registration', $results[$submitted['employment_id']]['code']);
        self::assertSame('id_ppv', $results[$accepted['employment_id']]['code']);
        // Po odmítnutí přichází nové řádné podání (nová povinnost i klíč),
        // i když účetní údaje nezměnila: dřív se vrátilo odmítnuté podání,
        // po opravě údajů příprava spadla na jediném řádném podání povinnosti.
        self::assertSame('prepared', $results[$rejected['employment_id']]['status'], CanonicalJson::encode($results));
        self::assertTrue($results[$rejected['employment_id']]['created']);
        $retryId = (int) $results[$rejected['employment_id']]['submission_id'];
        self::assertNotSame((int) $rejected['prepared']['submission_id'], $retryId);
        self::assertSame('ready', $this->regSubmissionStatus($retryId));
        self::assertSame('rejected', $this->regSubmissionStatus((int) $rejected['prepared']['submission_id']));
        self::assertSame($before + 1, $this->submissionCount());
        $this->regAssertXsdValid($this->regArtifactXml($retryId));

        // Druhé spuštění téže hromadné přípravy nic dalšího nezaloží.
        $again = $this->batch->prepare($this->supplierId, 'test', $ids, $this->actors[0]);
        self::assertSame(['skipped'], array_values(array_unique(array_column($again['results'], 'status'))));
        self::assertSame($before + 1, $this->submissionCount());
    }

    /**
     * Lhůta A1: nejdřív 8 dnů před nástupem, nejpozději v den nástupu.
     * Nástup za 19 dní se nabídne s nejbližším dnem podání a příprava ho
     * s vysvětlením přeskočí; zpožděná A1 je označená jako po lhůtě a jde
     * připravit.
     */
    public function testDeadlineWindowEarlyAndOverdue(): void
    {
        [, $early] = $this->regPerson('Brzký', 'Nástup', 31, 'employment', '2026-11-30');
        $this->regSaveA1Profile($early, $this->regA1Profile('2026-11-30'));
        [, $late] = $this->regPerson('Pozdní', 'Přihláška', 33, 'employment', '2026-11-02');
        $this->regSaveA1Profile($late, $this->regA1Profile('2026-11-02'));

        $offered = $this->offered();
        self::assertSame('2026-11-22', $offered[$early]['deadline']['earliest_registration_on']);
        self::assertSame('2026-11-30', $offered[$early]['deadline']['due_on']);
        self::assertFalse($offered[$early]['deadline']['overdue']);
        self::assertSame('2026-11-02', $offered[$late]['deadline']['due_on']);
        self::assertTrue($offered[$late]['deadline']['overdue']);

        $results = array_column(
            $this->batch->prepare($this->supplierId, 'test', [$early, $late], $this->actors[0])['results'],
            null,
            'employment_id',
        );
        self::assertSame('skipped', $results[$early]['status'], CanonicalJson::encode($results));
        self::assertStringContainsString('22. 11. 2026', (string) $results[$early]['message']);
        self::assertSame('prepared', $results[$late]['status'], CanonicalJson::encode($results));
        $xml = $this->regArtifactXml((int) $results[$late]['submission_id']);
        self::assertSame('http://schemas.cssz.cz/REGZEC/2025', $this->regAssertXsdValid($xml));
        self::assertStringContainsString('act="1"', $xml);
    }

    /**
     * Agentura: brigádník na DPP po měsících. Dohoda za říjen je přihlášená
     * (ID PPV) a skončila; listopadová dohoda je nový pojistný vztah a musí
     * mít vlastní A1. Nabídka ji ukáže bez varování o duplicitě a příprava
     * vytvoří A1 s druhem činnosti DPP.
     */
    public function testNextMonthlyDppOfTheSamePersonNeedsItsOwnA1(): void
    {
        [$employeeId, $october] = $this->regPerson('Brigádník', 'Měsíční', 35, 'dpp', '2026-10-01');
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "ended", end_date = "2026-10-31" WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $october]);
        $this->assignIdPpv($employeeId, $october, '2026-10-01');
        $november = $this->regAdditionalEmployment($employeeId, 'DPP-11', 'dpp', self::START_ON, '2026-11-30');
        $this->regSaveA1Profile($november, $this->regA1Profile(self::START_ON, 'T'));

        $offered = $this->offered();
        self::assertArrayNotHasKey($october, $offered);
        self::assertArrayHasKey($november, $offered, CanonicalJson::encode($offered));
        self::assertSame([], $offered[$november]['warnings']);
        self::assertTrue($offered[$november]['complete'], CanonicalJson::encode($offered[$november]['problems']));

        $result = $this->batch->prepare($this->supplierId, 'test', [$november], $this->actors[0])['results'][0];
        self::assertSame('prepared', $result['status'], CanonicalJson::encode($result));
        $xml = $this->regArtifactXml((int) $result['submission_id']);
        $this->regAssertXsdValid($xml);
        self::assertMatchesRegularExpression('/<job [^>]*rel="T"/', $xml);
        self::assertStringContainsString(' fro="' . self::START_ON . '"', $xml);
    }

    /** HPP a souběžná DPP téže osoby jsou dva pojistné vztahy: obě potřebují A1. */
    public function testEmploymentAndConcurrentDppOfOnePersonAreBothOffered(): void
    {
        [$employeeId, $hpp] = $this->regPerson('Souběh', 'Dvou', 37, 'employment', self::START_ON);
        $dpp = $this->regAdditionalEmployment($employeeId, 'DPP-S', 'dpp', self::START_ON, '2026-12-31');

        $offered = $this->offered();
        self::assertArrayHasKey($hpp, $offered);
        self::assertArrayHasKey($dpp, $offered);
        self::assertSame([], $offered[$dpp]['warnings'], 'HPP s ID PPV není duplicitní DPP.');
    }

    /**
     * Dohoda, za kterou se A1 nestihla podat a kterou noční úloha po
     * sjednaném konci ukončila, se z nabídky nesmí ztratit: přihlášení
     * zůstává povinné i po skončení. Přihlásí se dodatečnou A1 (bez data
     * skončení) a po přijetí (ID PPV) se skončení ohlásí odhláškou A2.
     */
    public function testExpiredDppEndedByNightlyJobWithoutA1IsRegisteredThenDeregistered(): void
    {
        [, $gig] = $this->regPerson('Krátká', 'Dohoda', 39, 'dpp', '2026-11-02');
        // Sjednaný konec dohody jde při založení do `end_date` (fixed_term_end_on).
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "active", end_date = "2026-11-06" WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $gig]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET fixed_term_end_on = "2026-11-06" WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $gig]);
        $this->regSaveA1Profile($gig, $this->regA1Profile('2026-11-02', 'T'));

        $closure = $this->container->get(PayrollEmploymentBulkClosure::class);
        self::assertInstanceOf(PayrollEmploymentBulkClosure::class, $closure);
        $ended = $closure->endExpiredAgreements($this->supplierId, self::TODAY);
        self::assertContains($gig, $ended['ended'], CanonicalJson::encode($ended));
        self::assertSame('ended', (string) $this->scalar(
            'SELECT status FROM payroll_employments WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $gig],
        ));

        $offered = $this->offered();
        self::assertArrayHasKey($gig, $offered, 'Skončená dohoda bez A1 zmizela z přihlášek k podání.');
        self::assertTrue($offered[$gig]['deadline']['overdue']);

        $result = $this->batch->prepare($this->supplierId, 'test', [$gig], $this->actors[0])['results'][0];
        self::assertSame('prepared', $result['status'], CanonicalJson::encode($result));
        $xml = $this->regArtifactXml((int) $result['submission_id']);
        self::assertSame('http://schemas.cssz.cz/REGZEC/2025', $this->regAssertXsdValid($xml));
        self::assertStringContainsString('act="1"', $xml);
        self::assertMatchesRegularExpression('/<job [^>]*fro="2026-11-02"/', $xml);
        self::assertDoesNotMatchRegularExpression('/<job [^>]*\sto="/', $xml);
        self::assertArrayNotHasKey($gig, $this->offered(), 'Připravená A1 se znovu nenabízí.');

        // Přijetí A1 přidělí OIČ a ID PPV; pak jde odhláška A2 ke dni skončení.
        $this->regReceipt(
            ['submission_id' => $result['submission_id'], 'part_id' => $this->partId((int) $result['submission_id'])],
            'accepted',
            '1000000012',
            '200000000000000000039',
        );
        self::assertSame('2026-11-02', $this->regIdentifier($gig, 'id_ppv'));
        $this->regToday('2026-11-12');
        $event = ($this->registration)->approveEvent(
            $this->request('POST', "/api/payroll/submissions/registration/{$gig}/events")
                ->withParsedBody([
                    'environment' => 'test',
                    'interaction' => 'termination',
                    'effective_on' => '2026-11-06',
                    'ended_by_death' => false,
                    'unemployment' => ['mode' => 'not_provided_2'],
                ]),
            new \Slim\Psr7\Response(),
            ['employmentId' => (string) $gig],
        );
        self::assertSame(201, $event->getStatusCode(), 'Zaseknutí: schválení A2. ' . (string) $event->getBody());
        $a2 = $this->regPrepare($gig, ['event_id' => $this->json($event)['id']]);
        self::assertSame('termination', $a2['interaction']);
        $a2Xml = $this->regArtifactXml((int) $a2['submission_id']);
        $this->regAssertXsdValid($a2Xml);
        self::assertStringContainsString('act="2"', $a2Xml);
        self::assertStringContainsString(' to="2026-11-06"', $a2Xml);
        self::assertStringContainsString('oid="200000000000000000039"', $a2Xml);
    }

    private function partId(int $submissionId): int
    {
        return (int) $this->scalar(
            'SELECT id FROM payroll_submission_parts WHERE supplier_id = ? AND submission_id = ? ORDER BY id LIMIT 1',
            [$this->supplierId, $submissionId],
        );
    }

    /** @return array{employment_id:int,prepared:array<string,mixed>} */
    private function prepared(string $last, int $sequence): array
    {
        [, $employmentId] = $this->regPerson('Stav', $last, $sequence, 'employment', self::START_ON);
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON));

        return [
            'employment_id' => $employmentId,
            'prepared' => $this->regPrepare($employmentId, ['registration_mode' => 'full']),
        ];
    }

    /** @param array<string,mixed> $prepared */
    private function submit(array $prepared): void
    {
        $submissions = $this->container->get(\MyInvoice\Service\Payroll\Submission\PayrollSubmissionService::class);
        self::assertInstanceOf(\MyInvoice\Service\Payroll\Submission\PayrollSubmissionService::class, $submissions);
        $submissions->transition(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $prepared['row_version'],
            'submitted',
            'synthetic-e2e-submitted:' . $prepared['submission_id'],
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function offered(): array
    {
        return array_column(
            $this->batch->candidates($this->supplierId, 'test', '2026-10-01', '2026-12-31')['items'],
            null,
            'employment_id',
        );
    }

    private function assignIdPpv(int $employeeId, int $employmentId, string $validFrom): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_employment_external_ids
                (supplier_id, employee_id, employment_id, environment,
                 identifier_type, value_ciphertext, value_hash, value_masked,
                 valid_from, source_kind, source_reference_hash)
             VALUES (?, ?, ?, 'test', 'id_ppv', 'enc:v2:test', UNHEX(SHA2(?, 256)),
                     '***123', ?, 'verified_manual_import', ?)",
        )->execute([$this->supplierId, $employeeId, $employmentId, 'ppv-' . $employmentId, $validFrom, str_repeat('b', 64)]);
    }

    private function submissionCount(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM payroll_submissions WHERE supplier_id = ?', [$this->supplierId]);
    }
}
