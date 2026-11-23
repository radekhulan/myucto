<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotException;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * OIČ převzaté z protokolu ČSSZ u brigádníka s dohodami po měsících: totéž
 * číslo opsané k dřívější dohodě posune jen začátek platnosti (hodnota, původ
 * i protokol zůstanou), jiné číslo se odmítne.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eReg2IdentityTest extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;

    private const AUDIT_ACTION = 'payroll.jmhz_identity.validity_backdated';
    private const OIC = '1000000012';
    private const OTHER_OIC = '1000000023';

    private PayrollRegistrationIdentityService $identities;

    protected function setUp(): void
    {
        $this->bootRegistrationFlow('2026-11-11');
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        self::assertInstanceOf(PayrollRegistrationIdentityService::class, $identities);
        $this->identities = $identities;
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Přihláška k listopadové dohodě byla přijata a OIČ přišlo z protokolu.
     * K červnové dohodě účetní opíše totéž OIČ a její ID PPV: OIČ osoby
     * začne platit od června, řádek zůstane doložený protokolem a zápis má
     * auditní stopu bez hodnoty čísla.
     */
    public function testReceiptOicCopiedToEarlierAgreementMovesValidityBack(): void
    {
        [$employeeId, $later, $earlier] = $this->personWithReceiptOicAndEarlierAgreement(91);
        $before = $this->personRow($employeeId);
        self::assertSame('trusted_receipt', $before['source_kind']);
        self::assertSame('2026-11-16', $before['valid_from']);
        // Hlášení JMHZ za červen číslo k rozhodnému dni nevidí.
        self::assertSame('jmhz_identity_oic_missing', $this->jmhzIdentityCode($employeeId, $earlier, '2026-06-30'));

        $assigned = $this->identities->assignManualJmhzIdentity(
            $this->supplierId,
            $earlier,
            'test',
            self::OIC,
            '200000000000000000092',
            '2026-06-01',
            null,
            true,
            $this->actors[0],
        );

        $after = $this->personRow($employeeId);
        self::assertSame($before['id'], $after['id'], 'Posun nesmí založit nový řádek OIČ.');
        self::assertSame('2026-06-01', $after['valid_from']);
        self::assertSame('trusted_receipt', $after['source_kind']);
        self::assertSame($before['source_receipt_id'], $after['source_receipt_id']);
        self::assertSame($before['value_hash'], $after['value_hash']);
        self::assertSame($before['value_ciphertext'], $after['value_ciphertext']);
        self::assertSame($before['source_reference_hash'], $after['source_reference_hash']);
        self::assertSame((int) $before['row_version'] + 1, (int) $after['row_version']);
        self::assertSame($this->actors[0], (int) $after['updated_by']);
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_person_external_ids WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $employeeId],
        ));
        self::assertFalse($assigned['person_external_identifier']['created']);
        self::assertSame('2026-06-01', $assigned['person_external_identifier']['valid_from']);
        self::assertSame('2026-06-01', $this->regIdentifier($earlier, 'id_ppv'));
        self::assertSame('2026-11-16', $this->regIdentifier($later, 'id_ppv'));

        // Nová příprava hlášení za červen OIČ najde, listopadová dohoda ho
        // má dál doložené protokolem (odhláška A2 ruční potvrzení nechce).
        $june = $this->identities->sensitiveJmhzIdentityAt($this->supplierId, $employeeId, $earlier, 'test', '2026-06-30');
        self::assertSame(self::OIC, $june['person_external_identifier']['value']);
        $november = $this->identities->sensitiveJmhzIdentityAt(
            $this->supplierId,
            $employeeId,
            $later,
            'test',
            '2026-11-30',
            true,
        );
        self::assertSame(self::OIC, $november['person_external_identifier']['value']);
        self::assertSame('trusted_receipt', $november['provenance']['person']);

        $audit = $this->audit($earlier);
        self::assertCount(1, $audit);
        self::assertSame($this->actors[0], (int) $audit[0]['user_id']);
        $payload = json_decode((string) $audit[0]['payload'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ik_mpsv', $payload['identifier']);
        self::assertSame((int) $before['id'], $payload['external_id']);
        self::assertSame($employeeId, $payload['employee_id']);
        self::assertSame('trusted_receipt', $payload['source_kind']);
        self::assertSame((int) $before['source_receipt_id'], $payload['source_receipt_id']);
        self::assertSame('2026-11-16', $payload['valid_from_before']);
        self::assertSame('2026-06-01', $payload['valid_from_after']);
        self::assertStringNotContainsString(self::OIC, (string) $audit[0]['payload'], 'Auditní stopa nesmí nést hodnotu OIČ.');
    }

    /** Opakované uložení téhož opisu už nic neposouvá a druhý auditní záznam nevznikne. */
    public function testRepeatedCopyToEarlierAgreementIsIdempotent(): void
    {
        [$employeeId, , $earlier] = $this->personWithReceiptOicAndEarlierAgreement(93);
        $this->assign($earlier, self::OIC, '200000000000000000094', '2026-06-01');
        $first = $this->personRow($employeeId);

        $this->assign($earlier, self::OIC, null, '2026-06-01');

        self::assertSame($first, $this->personRow($employeeId));
        self::assertCount(1, $this->audit($earlier));
    }

    /**
     * Jiné číslo, než jaké přidělil protokol ČSSZ, se k dřívější dohodě
     * nezapíše: hláška říká proč a co dělat, v evidenci se nic nezmění.
     */
    public function testDifferentOicThanReceiptIsRefusedWithGuidance(): void
    {
        [$employeeId, , $earlier] = $this->personWithReceiptOicAndEarlierAgreement(95);
        $before = $this->personRow($employeeId);

        try {
            $this->assign($earlier, self::OTHER_OIC, '200000000000000000096', '2026-06-01');
            self::fail('Jiné OIČ než z protokolu ČSSZ se zapsalo.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('OIČ', $exception->getMessage());
            self::assertStringContainsString('v protokolu o přijetí přihlášky', $exception->getMessage());
            self::assertStringContainsString('Porovnejte opis s protokolem', $exception->getMessage());
            self::assertStringNotContainsString('ukončete platnost', $exception->getMessage());
        }

        self::assertSame($before, $this->personRow($employeeId));
        self::assertNull($this->regIdentifier($earlier, 'id_ppv'));
        self::assertSame([], $this->audit($earlier));
    }

    /**
     * Oprava („Opravit") s jiným číslem převzaté OIČ nepřepíše ani nesmaže.
     */
    public function testCorrectionCannotReplaceReceiptOic(): void
    {
        [$employeeId, $later] = $this->personWithReceiptOicAndEarlierAgreement(97);
        $before = $this->personRow($employeeId);

        try {
            $this->identities->assignManualJmhzIdentity(
                $this->supplierId,
                $later,
                'test',
                self::OTHER_OIC,
                null,
                '2026-11-16',
                null,
                true,
                $this->actors[0],
                true,
            );
            self::fail('Oprava přepsala OIČ převzaté z protokolu ČSSZ.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('z protokolu ČSSZ', $exception->getMessage());
        }

        self::assertSame($before, $this->personRow($employeeId));
    }

    /**
     * @return array{0:int,1:int,2:int} [employee_id, pozdější vztah s přijatou A1, dřívější vztah]
     */
    private function personWithReceiptOicAndEarlierAgreement(int $sequence): array
    {
        [$employeeId, $later] = $this->regPerson('Protokol', 'Dřívější', $sequence, 'dpp', '2026-11-16');
        $this->regSaveA1Profile($later, $this->regA1Profile('2026-11-16', 'T'));
        $this->regReceipt(
            $this->regPrepare($later, ['registration_mode' => 'full']),
            'accepted',
            self::OIC,
            sprintf('2%020d', $sequence),
        );
        $earlier = $this->regAdditionalEmployment($employeeId, 'DPP-E', 'dpp', '2026-06-01', '2026-06-30', 'ended');

        return [$employeeId, $later, $earlier];
    }

    private function assign(int $employmentId, string $oic, ?string $idPpv, string $validFrom): void
    {
        $this->identities->assignManualJmhzIdentity(
            $this->supplierId,
            $employmentId,
            'test',
            $oic,
            $idPpv,
            $validFrom,
            null,
            true,
            $this->actors[0],
        );
    }

    /** @return array<string,mixed> */
    private function personRow(int $employeeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, valid_from, valid_to, source_kind, source_receipt_id, source_reference_hash,
                    HEX(value_hash) AS value_hash, value_ciphertext, row_version, updated_by
               FROM payroll_person_external_ids
              WHERE supplier_id = ? AND employee_id = ? AND environment = "test" AND valid_to IS NULL',
        );
        $statement->execute([$this->supplierId, $employeeId]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function jmhzIdentityCode(int $employeeId, int $employmentId, string $onDate): ?string
    {
        try {
            $this->identities->sensitiveJmhzIdentityAt($this->supplierId, $employeeId, $employmentId, 'test', $onDate);
        } catch (PayrollRegistrationIdentitySnapshotException $exception) {
            return $exception->validationCode;
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private function audit(int $employmentId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT user_id, payload FROM activity_log
              WHERE supplier_id = ? AND action = ? AND entity_type = "payroll_employment" AND entity_id = ?
              ORDER BY id',
        );
        $statement->execute([$this->supplierId, self::AUDIT_ACTION, $employmentId]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
