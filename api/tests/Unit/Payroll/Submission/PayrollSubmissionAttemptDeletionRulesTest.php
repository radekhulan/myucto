<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionAttemptDeletionService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Co se z historie pokusů smí smazat.
 *
 * Ledger pokusů je záměrně append-only — běžná cesta ven je zahození, po kterém
 * řádek zůstane i s odpovědí úřadu. Trvalé smazání je výjimka pro záznam, který
 * NIC nedokládá; jakmile by se jím dal zahodit důkaz o odeslání, je z výjimky
 * díra do evidence. Proto se testuje především to, co projít NESMÍ.
 */
final class PayrollSubmissionAttemptDeletionRulesTest extends TestCase
{
    private const SUPPLIER = 11;
    private const ENVIRONMENT = 'production';

    /** Pokus s protokolem od úřadu je doklad o odeslání. */
    public function testCompletedAttemptCannotBeDeleted(): void
    {
        $service = $this->service($this->pdoThatMustNotBeQueried());

        $reason = $service->blockedReason(self::SUPPLIER, self::ENVIRONMENT, [
            'status' => 'completed',
            'submission_id' => 5,
            'correlation_reference' => 'ABC123',
        ]);

        self::assertNotNull($reason);
        self::assertStringContainsString('protokol', $reason);
    }

    /**
     * Uvízlý pokus bez identifikátoru u úřadu nemá co doložit — a zrovna ten
     * v přehledu straší nejvíc, protože vypadá jako otevřená transakce.
     */
    public function testAttemptWithoutCorrelationIsDeletable(): void
    {
        $service = $this->service($this->pdoThatMustNotBeQueried());

        self::assertNull($service->blockedReason(self::SUPPLIER, self::ENVIRONMENT, [
            'status' => 'awaiting_protocol',
            'submission_id' => 5,
            'correlation_reference' => null,
        ]));
    }

    /** Připnutá dodejka nebo protokol smazání zakazuje. */
    public function testAttemptWithReceiptCannotBeDeleted(): void
    {
        $service = $this->service($this->pdoAnswering([1, false]));

        $reason = $service->blockedReason(self::SUPPLIER, self::ENVIRONMENT, [
            'status' => 'awaiting_protocol',
            'submission_id' => 5,
            'correlation_reference' => 'ABC123',
        ]);

        self::assertNotNull($reason);
        self::assertStringContainsString('dodejka', $reason);
    }

    /** Identifikátor, pod kterým podání u úřadu běží, je jediná stopa čím se odeslalo. */
    public function testAttemptIdentifyingTheSubmissionCannotBeDeleted(): void
    {
        $service = $this->service($this->pdoAnswering([false, 1]));

        $reason = $service->blockedReason(self::SUPPLIER, self::ENVIRONMENT, [
            'status' => 'awaiting_protocol',
            'submission_id' => 5,
            'correlation_reference' => 'ABC123',
        ]);

        self::assertNotNull($reason);
        self::assertStringContainsString('identifikátorem', $reason);
    }

    /**
     * Uvízlý VREP pokus, ke kterému úřad nic nevydal a podání se odeslalo jinou
     * cestou — přesně ten případ, kvůli kterému mazání vzniklo.
     */
    public function testStuckAttemptWithNoEvidenceIsDeletable(): void
    {
        $service = $this->service($this->pdoAnswering([false, false]));

        self::assertNull($service->blockedReason(self::SUPPLIER, self::ENVIRONMENT, [
            'status' => 'awaiting_protocol',
            'submission_id' => 5,
            'correlation_reference' => 'ABC123',
        ]));
    }

    private function service(PDO $pdo): PayrollSubmissionAttemptDeletionService
    {
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        return new PayrollSubmissionAttemptDeletionService(
            $db,
            $this->createStub(PayrollSubmissionTransportAttemptRepository::class),
        );
    }

    /**
     * PDO, jehož `fetchColumn()` vrací postupně zadané odpovědi — první dotaz
     * je na dodejku, druhý na identifikátor podání.
     *
     * @param list<int|false> $answers
     */
    private function pdoAnswering(array $answers): PDO
    {
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetchColumn')->willReturn(...$answers);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return $pdo;
    }

    /**
     * PDO, které se nesmí zeptat vůbec. Hlídá, že se na databázi nesahá tam,
     * kde rozhodnutí padne už ze stavu pokusu — jinak by se guard dal obejít
     * prázdnou odpovědí databáze.
     */
    private function pdoThatMustNotBeQueried(): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('prepare');

        return $pdo;
    }
}
