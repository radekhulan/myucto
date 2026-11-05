<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollWageStatementRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

/**
 * Mzdový výměr (§ 136 ZP) trvajícího pracovního poměru.
 *
 * Výměr se sestaví z podkladů platných k datu účinnosti: podmínky vztahu
 * (sjednaná mzda, úvazek, pracoviště), opakující se mzdové složky
 * a zaměstnavatelská mzdová politika (termín výplaty). Místo a způsob výplaty
 * zadá účetní ve formuláři, předvyplněné podle pravidel výplaty zaměstnance.
 *
 * Revize je neměnná. Stejné podklady (bez data vystavení) vrátí tutéž revizi
 * i tentýž dokument; změna mzdy, složek, pracoviště nebo termínu výplaty
 * založí další verzi, která předchozí dokument nahradí.
 */
final class WageStatementService
{
    public const SCHEMA_VERSION = 'wage-statement-snapshot.v1';

    private const SAVEPOINT = 'wage_statement_document';

    /** Druhy vztahů, u kterých se mzda sjednává a výměr vydává (§ 136 ZP). */
    private const SUPPORTED_RELATIONS = ['employment', 'small_scale_employment'];

    private const CLOSED_STATUSES = ['ended', 'archived', 'no_show'];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollWageStatementRepository $repository,
        private readonly PayrollEmployerPolicyRepository $employerPolicies,
        private readonly PayrollDocumentEmployerSnapshotProvider $employers,
        private readonly WageStatementPdfRenderer $renderer,
        private readonly PayrollDocumentService $documents,
    ) {}

    /**
     * Zda lze výměr vydat, a s čím se formulář předvyplní.
     *
     * @return array{available:bool,readiness_code:?string,message:?string,effective_from:?string,payment_place:string}
     */
    public function readiness(int $supplierId, int $employmentId): array
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        $effectiveFrom = null;
        $paymentPlace = '';
        try {
            $employment = $this->employment($supplierId, $employmentId);
            $effectiveFrom = $this->defaultEffectiveFrom($supplierId, $employment);
            $paymentPlace = $this->suggestedPaymentPlace($supplierId, (int) $employment['employee_id']);
            $this->sources($supplierId, $employment, $effectiveFrom);
            $result = [
                'available' => true,
                'readiness_code' => null,
                'message' => null,
                'effective_from' => $effectiveFrom,
                'payment_place' => $paymentPlace,
            ];
        } catch (WageStatementReadinessException $exception) {
            $result = [
                'available' => false,
                'readiness_code' => $exception->readinessCode,
                'message' => $exception->getMessage(),
                'effective_from' => $effectiveFrom,
                'payment_place' => $paymentPlace,
            ];
        } finally {
            $this->rollback($pdo, $owns);
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $evidence effective_from, payment_place, note
     * @return array<string,mixed>
     */
    public function generate(
        int $supplierId,
        int $employmentId,
        array $evidence,
        string $idempotencyKey,
        int $actorUserId,
    ): array {
        if ($supplierId <= 0 || $employmentId <= 0 || $actorUserId <= 0) {
            throw new \InvalidArgumentException('Identita požadavku na mzdový výměr není platná.');
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 200) {
            throw new \InvalidArgumentException('Idempotency-Key mzdového výměru není platný.');
        }
        $effectiveFrom = self::date($evidence['effective_from'] ?? null);
        $paymentPlace = self::text($evidence['payment_place'] ?? null, 255, 'Místo a způsob výplaty');
        $note = ($evidence['note'] ?? null) === null || trim((string) $evidence['note']) === ''
            ? null
            : self::text($evidence['note'], 500, 'Poznámka');

        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        $scope = $this->documents->beginStorageScope();
        try {
            $employment = $this->employment($supplierId, $employmentId);
            $sources = $this->sources($supplierId, $employment, $effectiveFrom);
            $snapshot = $sources + [
                'payment_place' => $paymentPlace,
                'note' => $note,
            ];
            ksort($snapshot);
            $manifestHash = hash('sha256', CanonicalJson::encode($snapshot));
            $revision = $this->repository->findBySourceManifest($supplierId, $employmentId, $manifestHash);
            if ($revision === null) {
                $latest = $this->repository->latest($supplierId, $employmentId);
                $snapshot['issued_at'] = $this->today();
                $json = CanonicalJson::encode($snapshot);
                $revision = $this->repository->insertApproved([
                    'supplier_id' => $supplierId,
                    'employee_id' => (int) $employment['employee_id'],
                    'employment_id' => $employmentId,
                    'effective_from' => $effectiveFrom,
                    'revision_no' => $latest === null ? 1 : (int) $latest['revision_no'] + 1,
                    'previous_revision_id' => $latest['id'] ?? null,
                    'snapshot_json' => $json,
                    'snapshot_hash' => hash('sha256', $json),
                    'source_manifest_hash' => $manifestHash,
                    'approved_by' => $actorUserId,
                ]);
            }
            $stored = json_decode((string) $revision['snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($stored)
                || !hash_equals((string) $revision['snapshot_hash'], hash('sha256', (string) $revision['snapshot_json']))
            ) {
                throw new \DomainException('Otisk revize mzdového výměru nesouhlasí.');
            }
            $artifact = $this->renderer->render(new WageStatementDocumentData(
                (string) $revision['snapshot_hash'],
                (int) $revision['revision_no'],
                $stored,
            ));
            $document = $this->documents->archiveWageStatementPdf(
                $supplierId,
                (int) $revision['id'],
                (int) $revision['employee_id'],
                $artifact,
                $idempotencyKey,
                $actorUserId,
                $scope,
            );
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            $this->documents->commitStorageScope($scope);

            return $document + [
                'employment_id' => $employmentId,
                'effective_from' => $revision['effective_from'],
                'wage_statement_revision_no' => (int) $revision['revision_no'],
            ];
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $owns);
            try {
                $this->documents->cleanupStorageScope($supplierId, $scope);
            } catch (\Throwable $cleanup) {
                throw new \RuntimeException(
                    'Mzdový výměr selhal a osiřelé soubory se nepodařilo uklidit.',
                    previous: $cleanup,
                );
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function employment(int $supplierId, int $employmentId): array
    {
        $employment = $this->repository->employment($supplierId, $employmentId);
        if ($employment === null) {
            throw new \InvalidArgumentException('Pracovní vztah nepatří této firmě.');
        }
        if (!in_array($employment['relation_type'], self::SUPPORTED_RELATIONS, true)) {
            throw new WageStatementReadinessException(
                'wage_statement_relation_unsupported',
                'Mzdový výměr se vydává jen u pracovního poměru. U dohod o pracích konaných '
                . 'mimo pracovní poměr se odměna sjednává přímo v dohodě.',
            );
        }
        // Ukončení zapsané dopředu (dohoda k poslednímu dni příštího měsíce)
        // přepne stav na `ended` hned, vztah ale do dne skončení trvá.
        $endsLater = $employment['status'] === 'ended'
            && is_string($employment['end_date'] ?? null)
            && $employment['end_date'] >= (new \DateTimeImmutable('today'))->format('Y-m-d');
        if (!$endsLater && in_array($employment['status'], self::CLOSED_STATUSES, true)) {
            throw new WageStatementReadinessException(
                'wage_statement_employment_closed',
                'Pracovní vztah už skončil nebo nenastoupil; mzdový výměr se vydává jen trvajícímu vztahu.',
            );
        }

        return $employment;
    }

    /**
     * Podklady výměru k datu účinnosti. Fail-closed: bez mzdy nebo termínu
     * výplaty výměr nevznikne.
     *
     * @param array<string,mixed> $employment
     * @return array<string,mixed>
     */
    private function sources(int $supplierId, array $employment, string $effectiveFrom): array
    {
        $employmentId = (int) $employment['id'];
        $start = (string) ($employment['actual_start_date'] ?? $employment['start_date'] ?? '');
        $terms = $this->repository->termsOn($supplierId, $employmentId, $effectiveFrom);
        $components = array_map(
            static fn (array $row): array => [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'calculation_kind' => (string) $row['calculation_kind'],
                'amount_minor' => $row['amount_minor'] === null ? null : (int) $row['amount_minor'],
                'rate_basis_points' => $row['rate_basis_points'] === null ? null : (int) $row['rate_basis_points'],
            ],
            $this->repository->recurringComponentsOn($supplierId, $employmentId, $effectiveFrom),
        );
        $hourly = ($terms['remuneration_type'] ?? 'monthly') === 'hourly';
        $hourlyRate = $hourly ? ($terms['hourly_rate_minor'] ?? null) : null;
        $monthly = $hourly ? null : ($terms['monthly_gross_minor'] ?? null);
        if ($terms === null || ($hourly ? (int) $hourlyRate <= 0 : (((int) $monthly) <= 0 && $components === []))) {
            throw new WageStatementReadinessException(
                'wage_statement_wage_missing',
                sprintf(
                    'K %s nemá pracovní vztah sjednanou mzdu: doplňte mzdu v podmínkách '
                    . 'vztahu nebo opakující se mzdovou složku na kartě pracovního vztahu.',
                    $effectiveFrom,
                ),
            );
        }
        try {
            $policy = $this->employerPolicies->findEffective($supplierId, $effectiveFrom);
        } catch (\InvalidArgumentException $exception) {
            throw new WageStatementReadinessException('wage_statement_payday_missing', $exception->getMessage());
        }
        if ($policy === null) {
            throw new WageStatementReadinessException(
                'wage_statement_payday_missing',
                sprintf(
                    'K %s chybí zaměstnavatelská mzdová politika s termínem výplaty. Doplňte ji '
                    . 'v Mzdy → Nastavení zaměstnavatele → Mzdové politiky.',
                    $effectiveFrom,
                ),
            );
        }
        try {
            $employer = ($this->employers)($supplierId)->toArray();
        } catch (\DomainException $exception) {
            throw new WageStatementReadinessException(
                'wage_statement_employer_missing',
                $exception->getMessage() . ' Doplňte údaje firmy a kontakt mzdové účetní '
                . 'v Mzdy → Nastavení zaměstnavatele.',
            );
        }
        $weekly = $terms['weekly_hours'] ?? null;
        $workPlace = trim((string) ($terms['work_place'] ?? ''));
        if ($workPlace === '') {
            $workPlace = trim((string) ($terms['regular_workplace'] ?? ''));
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'employer' => $employer,
            'employee' => ['name' => (string) $employment['full_name']],
            'relationship_kind' => (string) $employment['relation_type'],
            'employment_from' => $start !== '' ? $start : $effectiveFrom,
            'effective_from' => $effectiveFrom,
            'wage' => [
                'monthly_gross_minor' => ((int) $monthly) > 0 ? (int) $monthly : null,
                ...($hourly ? [
                    'remuneration_type' => 'hourly',
                    'hourly_rate_minor' => (int) $hourlyRate,
                    'remuneration_basis_kind' => $terms['remuneration_basis_kind'],
                    'remuneration_basis_kind_label' => match ($terms['remuneration_basis_kind']) {
                        'wage_statement' => 'Mzdový výměr',
                        'contract' => 'Smlouva',
                        'agreement' => 'Dohoda',
                        'internal_regulation' => 'Vnitřní předpis',
                        'collective_agreement' => 'Kolektivní smlouva',
                        default => '',
                    },
                    'remuneration_basis' => $terms['remuneration_basis'],
                ] : []),
                'weekly_hours' => $weekly === null ? null : rtrim(rtrim((string) $weekly, '0'), '.'),
                'workload_basis_points' => (int) ($terms['workload_basis_points'] ?? 10_000),
            ],
            'components' => $components,
            'work_place' => $workPlace === '' ? null : $workPlace,
            'payday' => [
                'day' => (int) $policy['payday_day'],
                'month_offset' => (int) $policy['payday_month_offset'],
                'business_day_rule' => (string) $policy['payday_business_day_rule'],
            ],
        ];
    }

    /** @param array<string,mixed> $employment */
    private function defaultEffectiveFrom(int $supplierId, array $employment): string
    {
        $today = $this->today();
        $termsStart = $this->repository->latestTermsStart($supplierId, (int) $employment['id'], $today);
        if ($termsStart !== null) {
            return $termsStart;
        }
        $start = (string) ($employment['actual_start_date'] ?? $employment['start_date'] ?? '');

        return $start !== '' ? $start : $today;
    }

    private function suggestedPaymentPlace(int $supplierId, int $employeeId): string
    {
        $destinations = $this->repository->payoutDestinations($supplierId, $employeeId);
        if (in_array('bank', $destinations, true)) {
            return 'Bezhotovostně na platební účet zaměstnance';
        }
        if (in_array('cash', $destinations, true)) {
            return 'V hotovosti v sídle zaměstnavatele';
        }

        return '';
    }

    private function today(): string
    {
        $value = $this->db->pdo()->query('SELECT CURRENT_DATE')->fetchColumn();

        return is_string($value) ? $value : (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    private function rollback(PDO $pdo, bool $owns): void
    {
        if (!$pdo->inTransaction()) {
            return;
        }
        if ($owns) {
            $pdo->rollBack();
            return;
        }
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
        $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
    }

    private static function date(mixed $value): string
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Datum účinnosti výměru musí být ve tvaru RRRR-MM-DD.');
        }

        return $value;
    }

    private static function text(mixed $value, int $maximum, string $label): string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '' || mb_strlen($text) > $maximum || preg_match('/[\x00-\x1F\x7F]/u', $text) === 1) {
            throw new \InvalidArgumentException("{$label}: vyplňte text do {$maximum} znaků.");
        }

        return $text;
    }
}
