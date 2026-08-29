<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentExitRevisionRepository;
use PDO;

final class EmploymentExitDocumentService
{
    private const SAVEPOINT = 'employment_exit_document';

    public function __construct(
        private readonly Connection $db,
        private readonly EmploymentExitSnapshotBuilder $builder,
        private readonly EmploymentCertificatePdfRenderer $renderer,
        private readonly PayrollDocumentService $documents,
        private readonly PayrollEmploymentExitRevisionRepository $revisions,
        private readonly AverageEarningsSnapshotBuilder $averageBuilder,
        private readonly AverageEarningsCertificatePdfRenderer $averageRenderer,
        private readonly AverageEarningsStatementPdfRenderer $statementRenderer,
    ) {}

    /**
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    public function generateEmploymentCertificate(
        int $supplierId,
        int $employmentId,
        array $evidence,
        string $idempotencyKey,
        int $actorUserId,
    ): array {
        return $this->generateArchived(
            $supplierId,
            $employmentId,
            $idempotencyKey,
            $actorUserId,
            function () use (
                $supplierId,
                $employmentId,
                $evidence,
                $actorUserId,
            ): array {
                $prepared = $this->builder->build(
                    $supplierId,
                    $employmentId,
                    $evidence,
                    $actorUserId,
                );

                return [
                    'revision' => $prepared['revision'],
                    'artifact' => $this->renderer->render(
                        $prepared['document'],
                    ),
                ];
            },
        );
    }

    /**
     * Oddělené potvrzení podle § 313 odst. 2 zákoníku práce (čistý měsíční
     * průměr pro Úřad práce) i samostatné potvrzení o hrubém průměrném výdělku
     * podle § 356 odst. 1 a 2. Liší se jen účelem revize a rendererem, zdroj
     * i fail-closed brány jsou společné.
     *
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    public function generateAverageEarningsDocument(
        int $supplierId,
        int $employmentId,
        string $purpose,
        array $evidence,
        string $idempotencyKey,
        int $actorUserId,
    ): array {
        return $this->generateArchived(
            $supplierId,
            $employmentId,
            $idempotencyKey,
            $actorUserId,
            function () use (
                $supplierId,
                $employmentId,
                $purpose,
                $evidence,
                $actorUserId,
            ): array {
                $prepared = $this->averageBuilder->build(
                    $supplierId,
                    $employmentId,
                    $purpose,
                    $evidence,
                    $actorUserId,
                );
                $document = $prepared['document'];

                return [
                    'revision' => $prepared['revision'],
                    'artifact' => $document
                        instanceof AverageEarningsCertificateDocumentData
                        ? $this->averageRenderer->render($document)
                        : $this->statementRenderer->render($document),
                ];
            },
        );
    }

    /**
     * @param \Closure():array{revision:array<string,mixed>,artifact:PayrollArtifact} $prepare
     * @return array<string,mixed>
     */
    private function generateArchived(
        int $supplierId,
        int $employmentId,
        string $idempotencyKey,
        int $actorUserId,
        \Closure $prepare,
    ): array {
        $this->validateRequest(
            $supplierId,
            $employmentId,
            $idempotencyKey,
            $actorUserId,
        );
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        $scope = $this->documents->beginStorageScope();
        try {
            $prepared = $prepare();
            $revision = $prepared['revision'];
            $document = $this->documents->archiveEmploymentExitPdf(
                $supplierId,
                self::positiveInt($revision, 'id'),
                self::positiveInt($revision, 'employee_id'),
                $prepared['artifact'],
                $idempotencyKey,
                $actorUserId,
                $scope,
            );
            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            $this->documents->commitStorageScope($scope);

            return $document;
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $ownsTransaction);
            try {
                $this->documents->cleanupStorageScope($supplierId, $scope);
            } catch (\Throwable $cleanupException) {
                throw new \RuntimeException(
                    'Výstupní dokument selhal a osiřelé soubory se '
                        . 'nepodařilo uklidit.',
                    previous: $cleanupException,
                );
            }
            throw $exception;
        }
    }

    /**
     * Dostupnost všech tří výstupních dokumentů se zjišťuje stejnou cestou,
     * jakou jde generování — suchý běh stavitele snapshotu. Obrazovka tak
     * nikdy netvrdí „lze vydat" tam, kde by generování spadlo na fail-closed
     * bráně, a naopak.
     *
     * ── Proč se dokumenty posuzují každý zvlášť ────────────────────────────
     * Dřív se všechny tři zjišťovaly v JEDNOM try/catch a zápočtový list se
     * navíc vůbec nesondoval (vracel natvrdo `available: true`). Mělo to dvě
     * důsledky, oba na úkor člověka, který právě skončil:
     *
     * - Zápočtový list se tvářil vždycky dostupný a fail-closed brána se
     *   ozvala až 422 po vyplnění celého formuláře.
     * - Vada v EXEKUČNÍM LEDGERU (podklad jen pro zápočtový list, § 313
     *   odst. 1 ZP) zablokovala i potvrzení o průměrném výdělku pro Úřad
     *   práce (§ 313 odst. 2 ZP). Člověk tak kvůli nesouvisející vadě
     *   nedostal podklad pro dávku v nezaměstnanosti.
     *
     * Proto se sonduje každý dokument vlastní branou. Společná zůstává jen
     * ta část, bez které nemá smysl žádný z nich — vztah se musí dát načíst
     * a jeho druh musí být pro výstupní dokumenty přípustný.
     *
     * @return array{
     *   employment_certificate:array{
     *     available:bool,
     *     readiness_code:?string,
     *     deduction_claim_ids:list<int>
     *   },
     *   average_earnings_certificate:array{
     *     available:bool,
     *     readiness_code:?string,
     *     decisive_year:?int,
     *     decisive_quarter:?int
     *   },
     *   average_earnings_statement:array{
     *     available:bool,
     *     readiness_code:?string,
     *     decisive_year:?int,
     *     decisive_quarter:?int
     *   }
     * }
     */
    public function readiness(int $supplierId, int $employmentId): array
    {
        if ($supplierId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException(
                'Identita pracovního vztahu není platná.',
            );
        }
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        try {
            $sources = $this->revisions->lockCertificateSources(
                $supplierId,
                $employmentId,
            );
            EmploymentExitRelationshipPolicy::documentKind(
                self::text($sources['employment'], 'relation_type'),
            );
            // Sonda zápočtového listu je oddělená: její selhání (typicky vada
            // v exekučním ledgeru) nesmí zhasnout potvrzení pro Úřad práce.
            try {
                $certificate = [
                    'available' => true,
                    'readiness_code' => null,
                    'deduction_claim_ids' => $this->builder->probe(
                        $supplierId,
                        $employmentId,
                    ),
                ];
            } catch (EmploymentExitReadinessException $exception) {
                $certificate = [
                    'available' => false,
                    'readiness_code' => $exception->readinessCode,
                    'deduction_claim_ids' => [],
                ];
            }
            $end = new \DateTimeImmutable(
                self::text($sources['employment'], 'end_date'),
            );
            $decisive = [
                'decisive_year' => (int) $end->format('Y'),
                'decisive_quarter' =>
                    intdiv(((int) $end->format('n')) - 1, 3) + 1,
            ];
            $average = $this->averageReadiness(
                $supplierId,
                $employmentId,
                AverageEarningsSnapshotBuilder::CERTIFICATE_PURPOSE,
                $decisive,
            );
            $statement = $this->averageReadiness(
                $supplierId,
                $employmentId,
                AverageEarningsSnapshotBuilder::STATEMENT_PURPOSE,
                $decisive,
            );
            $this->finishReadTransaction($pdo, $ownsTransaction);
        } catch (EmploymentExitReadinessException $exception) {
            $this->rollback($pdo, $ownsTransaction);
            $blocked = [
                'available' => false,
                'readiness_code' => $exception->readinessCode,
                'decisive_year' => null,
                'decisive_quarter' => null,
            ];
            $certificate = [
                'available' => false,
                'readiness_code' => $exception->readinessCode,
                'deduction_claim_ids' => [],
            ];
            $average = $blocked;
            $statement = $blocked;
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $ownsTransaction);
            throw $exception;
        }

        return [
            'employment_certificate' => $certificate,
            'average_earnings_certificate' => $average,
            'average_earnings_statement' => $statement,
        ];
    }

    /**
     * @param array{decisive_year:int,decisive_quarter:int} $decisive
     * @return array{
     *   available:bool,
     *   readiness_code:?string,
     *   decisive_year:int,
     *   decisive_quarter:int
     * }
     */
    private function averageReadiness(
        int $supplierId,
        int $employmentId,
        string $purpose,
        array $decisive,
    ): array {
        $code = null;
        try {
            $this->averageBuilder->probe(
                $supplierId,
                $employmentId,
                $purpose,
            );
        } catch (EmploymentExitReadinessException $exception) {
            $code = $exception->readinessCode;
        }

        return [
            'available' => $code === null,
            'readiness_code' => $code,
            'decisive_year' => $decisive['decisive_year'],
            'decisive_quarter' => $decisive['decisive_quarter'],
        ];
    }

    private function validateRequest(
        int $supplierId,
        int $employmentId,
        string $idempotencyKey,
        int $actorUserId,
    ): void {
        if ($supplierId <= 0 || $employmentId <= 0 || $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Identita požadavku na výstupní dokument není platná.',
            );
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 200) {
            throw new \InvalidArgumentException(
                'Idempotency-Key výstupního dokumentu není platný.',
            );
        }
    }

    private function finishReadTransaction(PDO $pdo, bool $owns): void
    {
        if ($owns) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        }
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

    /** @param array<string,mixed> $row */
    private static function positiveInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) || $value <= 0) {
            throw new \RuntimeException("Pole {$field} není kladné číslo.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException("Pole {$field} není text.");
        }

        return $value;
    }
}
