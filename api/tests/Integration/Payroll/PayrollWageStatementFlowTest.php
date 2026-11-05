<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollWageStatementAction;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Mzdový výměr (§ 136 ZP) z karty pracovního vztahu.
 *
 * Účetní otevře kartu vztahu, vidí, že výměr lze vydat (s předvyplněným dnem
 * účinnosti), zadá místo výplaty a vydá PDF. Opakované vydání beze změny vrátí
 * týž dokument; po změně mzdy vznikne nová verze, která předchozí nahradí.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollWageStatementFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private int $officeId;
    private PayrollWageStatementAction $action;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('JMHZ', 'Syntetická registrace JMHZ', '9990001234');
        $this->configureSocialInsuranceOutput($this->officeId);
        $action = $this->container->get(PayrollWageStatementAction::class);
        self::assertInstanceOf(PayrollWageStatementAction::class, $action);
        $this->action = $action;
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testHourlyWageStatementFreezesRateWithoutMonthlyFallback(): void
    {
        $person = $this->createEmployment($this->officeId, 'Hana Hodinová', 7, 'hpp', 'employment', 40, 10_000);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET remuneration_type = "hourly", hourly_rate_minor = 18750,
                    remuneration_basis_kind = "wage_statement", remuneration_basis = "Syntetický výměr", monthly_gross_minor = NULL
              WHERE supplier_id = ? AND employment_id = ?'
        )->execute([$this->supplierId, $person['employment_id']]);
        self::assertTrue($this->list($person['employment_id'])['readiness']['available']);
        $first = $this->generate($person['employment_id'], 'hourly-wage-statement');
        self::assertSame(201, $first['status'], json_encode($first['body']) ?: '');
        $snapshot = json_decode((string) $this->scalar(
            'SELECT snapshot_json FROM payroll_wage_statement_revisions WHERE supplier_id = ? AND employment_id = ? AND revision_no = 1',
            [$this->supplierId, $person['employment_id']],
        ), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('hourly', $snapshot['wage']['remuneration_type']);
        self::assertSame(18750, $snapshot['wage']['hourly_rate_minor']);
        self::assertNull($snapshot['wage']['monthly_gross_minor']);
        self::assertSame('wage_statement', $snapshot['wage']['remuneration_basis_kind']);
        self::assertSame('Mzdový výměr', $snapshot['wage']['remuneration_basis_kind_label']);
        self::assertSame('Syntetický výměr', $snapshot['wage']['remuneration_basis']);
        self::assertSame($first['body']['id'], $this->generate($person['employment_id'], 'hourly-wage-replay')['body']['id']);
    }

    public function testWageStatementIsIssuedVersionedAndReplaced(): void
    {
        $person = $this->createEmployment($this->officeId, 'Věra Výměrová', 1, 'hpp', 'employment', 40, 10_000);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET monthly_gross_minor = 4500000, work_place = "Praha"
              WHERE supplier_id = ? AND employment_id = ?'
        )->execute([$this->supplierId, $person['employment_id']]);

        $listed = $this->list($person['employment_id']);
        self::assertTrue($listed['readiness']['available'], json_encode($listed['readiness']) ?: '');
        self::assertSame('2026-01-01', $listed['readiness']['effective_from']);
        self::assertSame([], $listed['items']);

        $first = $this->generate($person['employment_id'], 'wage-1');
        self::assertSame(201, $first['status'], json_encode($first['body']) ?: '');
        self::assertSame('wage_statement', $first['body']['document_kind']);
        self::assertSame(1, $first['body']['wage_statement_revision_no']);
        self::assertStringStartsWith('mzdovy-vymer-', $first['body']['suggested_filename']);

        // Beze změny podkladů: tatáž revize i tentýž dokument.
        $again = $this->generate($person['employment_id'], 'wage-2');
        self::assertSame($first['body']['id'], $again['body']['id']);

        // Změna mzdy = nová verze výměru, která předchozí nahradí.
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET monthly_gross_minor = 5000000
              WHERE supplier_id = ? AND employment_id = ?'
        )->execute([$this->supplierId, $person['employment_id']]);
        $changed = $this->generate($person['employment_id'], 'wage-3');
        self::assertSame(201, $changed['status'], json_encode($changed['body']) ?: '');
        self::assertSame(2, $changed['body']['wage_statement_revision_no']);
        self::assertSame(2, $changed['body']['document_revision_no']);
        self::assertSame($first['body']['id'], $changed['body']['supersedes_document_id']);

        $items = $this->list($person['employment_id'])['items'];
        self::assertCount(2, $items);
        $snapshot = json_decode((string) $this->scalar(
            'SELECT snapshot_json FROM payroll_wage_statement_revisions
              WHERE supplier_id = ? AND employment_id = ? AND revision_no = 2',
            [$this->supplierId, $person['employment_id']],
        ), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(5_000_000, $snapshot['wage']['monthly_gross_minor']);
        self::assertSame('Praha', $snapshot['work_place']);
        self::assertSame('Bezhotovostně na účet', $snapshot['payment_place']);
        self::assertSame(10, $snapshot['payday']['day']);
    }

    public function testMissingWageAndAgreementsAreExplained(): void
    {
        $person = $this->createEmployment($this->officeId, 'Bořek Bezmzdý', 2, 'hpp', 'employment', 40, 10_000);
        $readiness = $this->list($person['employment_id'])['readiness'];
        self::assertFalse($readiness['available']);
        self::assertSame('wage_statement_wage_missing', $readiness['readiness_code']);
        self::assertStringContainsString('kartě pracovního vztahu', $readiness['message']);

        $refused = $this->generate($person['employment_id'], 'wage-missing');
        self::assertSame(422, $refused['status']);
        self::assertSame('wage_statement_wage_missing', $refused['body']['error']['code']);

        $agreement = $this->createEmployment($this->officeId, 'Dana Dohodová', 3, 'dpp', 'dpp', 10, 2_500);
        self::assertSame(
            'wage_statement_relation_unsupported',
            $this->list($agreement['employment_id'])['readiness']['readiness_code'],
        );
    }

    /**
     * Skončení zapsané dopředu přepne stav na `ended` hned. Do dne skončení
     * vztah trvá a výměr jde vydat; po skončení ne (C-17).
     */
    public function testEmploymentEndingInFutureStillGetsWageStatement(): void
    {
        $person = $this->createEmployment($this->officeId, 'Filip Budoucí', 5, 'hpp', 'employment', 40, 10_000);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET monthly_gross_minor = 3000000
              WHERE supplier_id = ? AND employment_id = ?'
        )->execute([$this->supplierId, $person['employment_id']]);
        $setEnd = $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "ended", end_date = ? WHERE supplier_id = ? AND id = ?'
        );
        $setEnd->execute([
            (new \DateTimeImmutable('today'))->modify('+1 month')->format('Y-m-d'),
            $this->supplierId,
            $person['employment_id'],
        ]);
        self::assertTrue($this->list($person['employment_id'])['readiness']['available']);

        $setEnd->execute([
            (new \DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d'),
            $this->supplierId,
            $person['employment_id'],
        ]);
        self::assertSame(
            'wage_statement_employment_closed',
            $this->list($person['employment_id'])['readiness']['readiness_code'],
        );
    }

    public function testIssuedRevisionIsImmutable(): void
    {
        $person = $this->createEmployment($this->officeId, 'Karel Kotva', 4, 'hpp', 'employment', 40, 10_000);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET monthly_gross_minor = 3000000
              WHERE supplier_id = ? AND employment_id = ?'
        )->execute([$this->supplierId, $person['employment_id']]);
        $document = $this->generate($person['employment_id'], 'wage-anchor');
        self::assertSame(201, $document['status'], json_encode($document['body']) ?: '');

        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_wage_statement_revisions SET effective_from = "2026-02-01"
              WHERE supplier_id = ? AND employment_id = ?'
        )->execute([$this->supplierId, $person['employment_id']]);
    }

    /** @return array<string,mixed> */
    private function list(int $employmentId): array
    {
        $response = $this->action->list(
            $this->request('GET', "/api/payroll/employments/{$employmentId}/documents/wage-statement"),
            new Response(),
            ['id' => (string) $employmentId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function generate(int $employmentId, string $key): array
    {
        $response = $this->action->generate(
            $this->request('POST', "/api/payroll/employments/{$employmentId}/documents/wage-statement")
                ->withHeader('Idempotency-Key', $key)
                ->withParsedBody([
                    'effective_from' => '2026-01-01',
                    'payment_place' => 'Bezhotovostně na účet',
                    'note' => null,
                ]),
            new Response(),
            ['id' => (string) $employmentId],
        );

        return ['status' => $response->getStatusCode(), 'body' => $this->json($response)];
    }
}
