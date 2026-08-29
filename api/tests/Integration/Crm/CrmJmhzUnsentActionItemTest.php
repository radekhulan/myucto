<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Crm;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Připravené měsíční hlášení, které nikdo neodeslal, patří mezi úkoly.
 *
 * Odeslání JMHZ zůstává ručním potvrzením záměrně — je to právní úkon
 * přičitatelný zaměstnavateli a poslední okamžik, kdy si člověk může všimnout
 * chyby. Zapomenout na něj ale znamená propásnout lhůtu do 20. dne
 * následujícího měsíce, a to je přesně ten druh chyby, který uživatel sám
 * neodhalí.
 */
#[Group('integration')]
final class CrmJmhzUnsentActionItemTest extends TestCase
{
    private const ITEM_TYPE = 'jmhz_unsent';

    private Connection $db;
    private PDO $pdo;
    private CrmAggregationService $crm;
    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->crm = $c->get(CrmAggregationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->pdo = $this->db->pdo();
        if (!$this->db->hasTable('payroll_submissions')) {
            $this->markTestSkipped('Mzdové migrace neproběhly.');
        }

        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí firma nebo uživatel.');
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;
        $this->pdo->prepare('DELETE FROM crm_action_item_dismissals WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testReadySubmissionShowsUpAsAnActionItem(): void
    {
        self::assertNull($this->item(), 'předpoklad: nic nevisí');

        $this->submission('JMHZ', 'ready', 'vrep_apep', 'production');

        $item = $this->item();
        self::assertNotNull($item);
        self::assertSame('high', $item['severity']);
        self::assertSame('/payroll/submissions', $item['link']);
        self::assertSame(1, $item['count']);
    }

    public function testSubmittedOneDisappears(): void
    {
        $id = $this->submission('JMHZ', 'ready', 'vrep_apep', 'production');
        self::assertNotNull($this->item());

        // `chk_payroll_submissions_dates` vyžaduje u odeslaného podání datum
        // odeslání — a je to správná invarianta, ne překážka.
        $this->pdo->prepare(
            'UPDATE payroll_submissions SET status = ?, submitted_at = ? WHERE id = ?'
        )->execute(['submitted', '2026-08-15 10:00:00', $id]);

        self::assertNull($this->item(), 'odeslané hlášení už úkol není');
    }

    /**
     * Podání na portál zdravotní pojišťovny aplikace odeslat NEUMÍ — žádná ze
     * sedmi nemá zveřejněné strojové rozhraní. Vyzývat k úkonu, který se odsud
     * udělat nedá, je horší než mlčet.
     */
    public function testHealthPortalSubmissionIsNotOffered(): void
    {
        $this->submission('PPZ_2026', 'ready', 'health_portal', 'production');

        self::assertNull($this->item());
    }

    /** Testovací podání nikdo podávat nemusí. */
    public function testTestEnvironmentIsNotOffered(): void
    {
        $this->submission('JMHZ', 'ready', 'vrep_apep', 'test');

        self::assertNull($this->item());
    }

    private function submission(
        string $agendaCode,
        string $status,
        string $channel,
        string $environment,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type, subject_reference,
                 period_start, period_end, obligation_kind, preferred_channel, status,
                 source_event_type, source_event_reference, source_event_hash,
                 request_fingerprint, idempotency_key_hash, row_version, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute([
            $this->supplierId,
            $environment,
            $agendaCode,
            'employer',
            'test-' . bin2hex(random_bytes(4)),
            '2026-07-01',
            '2026-07-31',
            'regular',
            $channel,
            'open',
            'payroll_run_approved',
            'test-event-' . bin2hex(random_bytes(4)),
            str_repeat('c', 64),
            str_repeat('a', 64),
            random_bytes(32),
            $this->userId,
        ]);
        $obligationId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO payroll_submissions
                (supplier_id, environment, obligation_id, submission_kind, channel,
                 status, source_snapshot_hash, request_fingerprint,
                 idempotency_key_hash, row_version, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute([
            $this->supplierId,
            $environment,
            $obligationId,
            'regular',
            $channel,
            $status,
            str_repeat('d', 64),
            str_repeat('b', 64),
            random_bytes(32),
            $this->userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    private function item(): ?array
    {
        $result = $this->crm->actionItems($this->supplierId, $this->userId);
        foreach ((array) ($result['items'] ?? $result) as $item) {
            if (is_array($item) && ($item['type'] ?? null) === self::ITEM_TYPE) {
                return $item;
            }
        }

        return null;
    }
}
