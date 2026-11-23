<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentTermsBulkEditAction;
use MyInvoice\Service\Payroll\PayrollEmploymentTermsBulkEdit;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Hromadná úprava podmínek vybraných vztahů po přijaté přihlášce A1:
 * změna CZ-ISCO nebo pracoviště je údaj, který ČSSZ vede, takže detekce
 * změn musí navrhnout dohlášení. Neplatný kód číselníku je 4xx.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eRegTermsChangeTest extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;

    private const START_ON = '2026-10-12';

    private PayrollEmploymentTermsBulkEdit $bulk;

    protected function setUp(): void
    {
        $this->bootRegistrationFlow('2026-10-06');
        $bulk = $this->container->get(PayrollEmploymentTermsBulkEdit::class);
        self::assertInstanceOf(PayrollEmploymentTermsBulkEdit::class, $bulk);
        $this->bulk = $bulk;
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Přihláška A1 s profesí 24111 přijatá, vztah běží. V listopadu účetní
     * hromadně přepíše CZ-ISCO na 43111 (nic není zúčtované, takže se opraví
     * platná verze podmínek). Detekce musí změnu profese ukázat.
     */
    public function testBulkCzIscoChangeAfterAcceptedA1IsProposedForReporting(): void
    {
        [, $employmentId] = $this->registered();

        $this->regToday('2026-11-20');
        $preview = $this->bulk->preview($this->supplierId, '2026-11-01', 'employment', [$employmentId]);
        self::assertSame([$employmentId], $preview['editable_employment_ids'], CanonicalJson::encode($preview));
        $result = $this->bulk->apply(
            $this->supplierId,
            '2026-11-01',
            PayrollEmploymentTermsBulkEdit::MODE_OVERWRITE,
            ['cz_isco_code' => '43111'],
            [$employmentId],
            $this->actors[0],
            null,
            null,
        );
        self::assertSame(1, $result['counts']['applied'], CanonicalJson::encode($result));

        $detected = $this->detect($employmentId);
        $paths = [];
        foreach ($detected['proposals'] as $proposal) {
            foreach ($proposal['findings'] ?? [] as $finding) {
                $paths[] = $finding['path'];
            }
        }
        self::assertContains('employment.profession_code', $paths, CanonicalJson::encode($detected));

        // Návrh jde podat jako A3 a věta nese novou profesi.
        $proposal = null;
        foreach ($detected['proposals'] as $candidate) {
            if ($candidate['duty_kind'] === 'regzec_change') {
                $proposal = $candidate;
            }
        }
        self::assertNotNull($proposal, CanonicalJson::encode($detected));
        self::assertTrue($proposal['fileable'], CanonicalJson::encode($proposal));
        $filed = $this->json(($this->registration)->fileChange(
            $this->request('POST', "/api/payroll/submissions/registration/{$employmentId}/changes/{$proposal['id']}/file")
                ->withParsedBody(['environment' => 'test']),
            new Response(),
            ['employmentId' => (string) $employmentId, 'proposalId' => (string) $proposal['id']],
        ));
        $a3 = $this->regPrepare($employmentId, ['event_id' => $filed['event']['id']]);
        $xml = $this->regArtifactXml((int) $a3['submission_id']);
        $this->regAssertXsdValid($xml);
        self::assertStringContainsString('act="3"', $xml);
        self::assertStringContainsString('clas="43111"', $xml);
        self::assertStringContainsString('oid="200000000000000000065"', $xml);
    }

    /** Neplatný kód CZ-ISCO v hromadné úpravě je 422, nic se neuloží. */
    public function testBulkEditRejectsUnknownCzIscoWith422(): void
    {
        [, $employmentId] = $this->regPerson('Hromadná', 'Neplatná', 63, 'employment', self::START_ON);
        $action = $this->container->get(PayrollEmploymentTermsBulkEditAction::class);
        self::assertInstanceOf(PayrollEmploymentTermsBulkEditAction::class, $action);
        $before = (string) $this->scalar(
            'SELECT cz_isco_code FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        );
        $response = $action->apply(
            $this->request('POST', '/api/payroll/employments/terms-bulk/apply')->withParsedBody([
                'period_start' => '2026-10-01',
                'mode' => 'overwrite',
                'changes' => ['cz_isco_code' => '99999'],
                'employment_ids' => [$employmentId],
            ]),
            new Response(),
        );
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($before, (string) $this->scalar(
            'SELECT cz_isco_code FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        ));
    }

    /** @return array{0:int,1:int} */
    private function registered(): array
    {
        [$employeeId, $employmentId] = $this->regPerson('Hromadná', 'Profese', 65, 'employment', self::START_ON);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET cz_isco_code = "24111" WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $employmentId]);
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON));
        $this->regReceipt(
            $this->regPrepare($employmentId, ['registration_mode' => 'full']),
            'accepted',
            '1000000012',
            '200000000000000000065',
        );
        $this->regToday(self::START_ON);
        $this->regTransition($employmentId, 'active', self::START_ON);

        return [$employeeId, $employmentId];
    }

    /** @return array<string,mixed> */
    private function detect(int $employmentId): array
    {
        $response = ($this->registration)->changeDetection(
            $this->request('POST', "/api/payroll/submissions/registration/{$employmentId}/changes")
                ->withParsedBody(['environment' => 'test']),
            new Response(),
            ['employmentId' => (string) $employmentId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }
}
