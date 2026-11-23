<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollJmhzTransportAction;
use MyInvoice\Action\Payroll\PayrollRegzelAction;
use MyInvoice\Action\Payroll\PayrollSubmissionInboxAction;
use MyInvoice\Action\Payroll\PayrollSubmissionOverviewAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollRegzelRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionInboxRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Tři seznamy podání, které dřív MLČKY USEKLY.
 *
 * Inbox podání, historie přenosu JMHZ a snímky REGZEL vracely prvních 1000 /
 * 200 / 100 řádků bez jakéhokoli náznaku, že jich je víc — a ke starším se
 * nedalo dostat vůbec. Tichý strop je horší než chybějící stránkování: u toho
 * je aspoň vidět všechno. Testy proto hlídají obojí, co se dá pokazit: že
 * `total` hlásí VŠECHNY řádky a že se uživatel dostane i ZA původní strop.
 */
#[Group('integration')]
final class PayrollSubmissionListPaginationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const ENVIRONMENT = 'production';

    private const LIST_PAGE = 50;

    private Connection $db;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->db = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach ([
            'payroll_obligations',
            'payroll_submission_deadlines',
            'payroll_submission_inbox_items',
            'payroll_submissions',
            'payroll_submission_transport_attempts',
            'payroll_regzel_payload_snapshots',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /**
     * Inbox: `summary` se počítá nad CELÝM inboxem, ne nad stránkou.
     *
     * Kdyby se počítal ze stránky, číslo „kolik toho čeká" by viselo na tom,
     * kde se uživatel v seznamu zrovna nachází.
     */
    public function testInboxSummaryCountsTheWholeInboxNotThePage(): void
    {
        $this->seedInboxItems(7);
        $repository = $this->inboxRepository();

        $page = $repository->listItemsPage($this->supplierId, self::ENVIRONMENT, 3, 0);
        $summary = $repository->statusSummary($this->supplierId, self::ENVIRONMENT);

        self::assertCount(3, $page['items']);
        self::assertSame(7, $page['total']);
        self::assertSame(7, $summary['total'], 'Souhrn nesmí počítat jen stránku.');
        self::assertSame(7, $summary['open']);
    }

    /**
     * Inbox: vyřešené položky filtruje SERVER, ne klient.
     *
     * Panel je odjakživa neukazuje. Dokud je zahazoval až z přijaté stránky,
     * `total` je počítal — stránka měla míň řádků, než pager sliboval, a
     * poslední mohla vyjít prázdná. Vyřešená položka přitom nesmí zmizet
     * úplně: je to doklad, že problém byl a že se někdo postaral.
     */
    public function testInboxResolvedItemsAreFilteredOnTheServer(): void
    {
        $this->seedInboxItems(5);
        $this->resolveInboxItems(2);
        $repository = $this->inboxRepository();

        $unresolved = $repository->listItemsPage($this->supplierId, self::ENVIRONMENT);
        self::assertSame(3, $unresolved['total'], 'Total musí popisovat právě to, co stránka ukáže.');
        self::assertCount(3, $unresolved['items']);
        self::assertSame(
            [],
            array_intersect(['resolved'], array_column($unresolved['items'], 'status')),
        );

        // Vyřešené jdou dohledat, jen se o ně musí říct.
        $resolved = $repository->listItemsPage(
            $this->supplierId,
            self::ENVIRONMENT,
            self::LIST_PAGE,
            0,
            'resolved',
        );
        self::assertSame(2, $resolved['total']);
        self::assertCount(2, $resolved['items']);

        $all = $repository->listItemsPage(
            $this->supplierId,
            self::ENVIRONMENT,
            self::LIST_PAGE,
            0,
            'all',
        );
        self::assertSame(5, $all['total']);

        $response = $this->inboxAction()->list(
            $this->request('/api/payroll/submissions/inbox')->withQueryParams([
                'environment' => self::ENVIRONMENT,
                'status' => 'vymyslený',
            ]),
            new Response(),
        );
        self::assertSame(422, $response->getStatusCode(), 'Neznámý výběr stavu je vada vstupu.');
    }

    /** Inbox: strop stránky je tvrdý a offset se dostane za něj. */
    public function testInboxCapIsHardAndOffsetReachesBeyondIt(): void
    {
        $seeded = PayrollSubmissionInboxRepository::LIST_MAX_LIMIT + 5;
        $this->seedInboxItems($seeded);
        $repository = $this->inboxRepository();

        $greedy = $repository->listItemsPage($this->supplierId, self::ENVIRONMENT, 10_000, 0);
        self::assertCount(
            PayrollSubmissionInboxRepository::LIST_MAX_LIMIT,
            $greedy['items'],
            'Strop nejde obejít vyšším limitem.',
        );
        self::assertSame($seeded, $greedy['total']);

        $beyond = $repository->listItemsPage(
            $this->supplierId,
            self::ENVIRONMENT,
            10_000,
            PayrollSubmissionInboxRepository::LIST_MAX_LIMIT,
        );
        self::assertCount(5, $beyond['items'], 'Za původní strop se uživatel musí dostat.');
        self::assertSame(
            [],
            array_intersect($this->ids($greedy['items']), $this->ids($beyond['items'])),
        );
    }

    /** Inbox: odpověď akce si drží `items` i `summary` a přidává `total`. */
    public function testInboxResponseShapeIsPreserved(): void
    {
        $this->seedInboxItems(2);

        $payload = $this->json($this->inboxAction()->list(
            $this->request('/api/payroll/submissions/inbox')
                ->withQueryParams(['environment' => self::ENVIRONMENT]),
            new Response(),
        ));

        self::assertArrayHasKey('items', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertSame(self::ENVIRONMENT, $payload['environment']);
        self::assertSame(2, $payload['total']);
        self::assertSame(PayrollSubmissionInboxRepository::LIST_DEFAULT_LIMIT, $payload['limit']);
        self::assertSame(0, $payload['offset']);
    }

    /**
     * Derivace položek inboxu nesmí mlčky přeskočit povinnosti za tisícovkou.
     *
     * Tenhle strop byl nejhorší z celé skupiny: neusekával jen VÝPIS, ale
     * vznik položek. Povinnost za hranicí se nederivovala, takže by uživatel
     * v inboxu neviděl blížící se termín a NEMĚL BY JAK POZNAT, že o něm
     * aplikace mlčí. Čte se teď po dávkách, dokud něco chodí.
     */
    public function testSyncCandidatesAreNotSilentlyCutAtOneThousand(): void
    {
        $seeded = 1005;
        $this->seedInboxItems($seeded);

        $candidates = $this->inboxRepository()
            ->findSyncCandidates($this->supplierId, self::ENVIRONMENT);

        self::assertCount($seeded, $candidates, 'Kandidáti nesmí končit u tisícovky.');
        self::assertCount(
            $seeded,
            array_unique(array_column($candidates, 'obligation_id')),
            'Dávkování nesmí žádnou povinnost zopakovat.',
        );
    }

    /**
     * Historie přenosu: dřív se usekla na dvou stech pokusech.
     *
     * Ledger je append-only, takže se přes dvě stě pokusů firma, která podává
     * každý měsíc za víc pracovišť, dostane během pár let.
     */
    public function testTransportHistoryReachesBeyondTheOldSilentCap(): void
    {
        $oldCap = PayrollSubmissionTransportAttemptRepository::LIST_MAX_LIMIT;
        $seeded = $oldCap + 5;
        $this->seedTransportAttempts($seeded);
        $repository = $this->transportRepository();

        $greedy = $repository->listRecentPage($this->supplierId, self::ENVIRONMENT, 10_000, 0);
        self::assertCount($oldCap, $greedy['items'], 'Strop nejde obejít vyšším limitem.');
        self::assertSame($seeded, $greedy['total'], 'Total musí hlásit i pokusy za stropem.');

        $beyond = $repository->listRecentPage(
            $this->supplierId,
            self::ENVIRONMENT,
            10_000,
            $oldCap,
        );
        self::assertCount(5, $beyond['items'], 'Za původní strop se uživatel musí dostat.');
        self::assertSame(
            [],
            array_intersect($this->ids($greedy['items']), $this->ids($beyond['items'])),
        );
    }

    /** Historie přenosu: klíč `attempts` zůstává a odpověď nese `total`. */
    public function testTransportHistoryResponseShapeIsPreserved(): void
    {
        $this->seedTransportAttempts(3);

        $payload = $this->json($this->transportAction()->history(
            $this->request('/api/payroll/submissions/jmhz/history')
                ->withQueryParams(['environment' => self::ENVIRONMENT, 'limit' => '2']),
            new Response(),
        ));

        self::assertArrayHasKey('attempts', $payload);
        self::assertCount(2, (array) $payload['attempts']);
        self::assertSame(3, $payload['total']);
        self::assertSame(2, $payload['limit']);
        self::assertSame(0, $payload['offset']);
    }

    /**
     * Přehled podání: `summary.total` dřív hlásil počet PO tichém oříznutí.
     *
     * To je nejhorší varianta tichého stropu — číslo se tváří jako pravda.
     * Uživatel viděl „dvě stě povinností", ať jich bylo dvě stě nebo tisíc, a
     * neměl jak poznat rozdíl. Souhrny se proto počítají nad celým obdobím.
     */
    public function testSubmissionOverviewTotalIsNotTheTruncatedPageCount(): void
    {
        $oldCap = PayrollSubmissionRepository::LIST_MAX_LIMIT;
        $seeded = $oldCap + 6;
        $this->seedInboxItems($seeded);

        $payload = $this->json($this->overviewAction()(
            $this->request('/api/payroll/submissions/overview')->withQueryParams([
                'environment' => self::ENVIRONMENT,
                'period' => '2026-01',
                'limit' => '10000',
            ]),
            new Response(),
        ));

        self::assertCount($oldCap, (array) $payload['items'], 'Strop nejde obejít z URL.');
        self::assertSame($seeded, $payload['total'], 'Total nesmí být velikost stránky.');
        self::assertSame(
            $seeded,
            ((array) $payload['summary'])['total'],
            'Souhrn se počítá nad celým obdobím, ne nad stránkou.',
        );
        self::assertSame(
            $seeded,
            ((array) $payload['summary'])['open'],
            'Rozpad podle stavu musí počítat i povinnosti za stránkou.',
        );
        self::assertSame(
            $seeded,
            array_sum((array) $payload['deadline_summary']),
            'Rozpad podle fáze termínu se taky počítá nad celým obdobím.',
        );

        $beyond = $this->json($this->overviewAction()(
            $this->request('/api/payroll/submissions/overview')->withQueryParams([
                'environment' => self::ENVIRONMENT,
                'period' => '2026-01',
                'limit' => '10000',
                'offset' => (string) $oldCap,
            ]),
            new Response(),
        ));
        self::assertCount(6, (array) $beyond['items'], 'Za původní strop se uživatel musí dostat.');
        self::assertSame(
            [],
            array_intersect(
                $this->ids((array) $payload['items']),
                $this->ids((array) $beyond['items']),
            ),
        );
    }

    /**
     * Přehled podání: skupinu agend filtruje SERVER.
     *
     * Panel ukazuje vždy jen jednu agendu. Kdyby si ji odfiltroval až z přijaté
     * stránky, pager by počítal řádky obou a tabulka ukazovala jen některé —
     * čísla pod sebou by si odporovala. Filtr proto musí ubrat ze stránky,
     * z `total` i ze souhrnů zároveň.
     *
     * Kódy jsou ZÁMĚRNĚ ty skutečné, i s ročníkem. Test dřív seedoval `jmhz`
     * a `HOZ`, tedy tvary, které v provozu nevznikají — a proto přehlédl, že
     * klasifikace neuměla `JMHZ25` ani `HOZ_2026` a posílala je do `other`,
     * kam se žádný panel nedívá.
     */
    public function testSubmissionOverviewFiltersAgendaGroupOnTheServer(): void
    {
        $this->seedInboxItems(2, JmhzSubmissionBridgeService::AGENDA_CODE);
        $this->seedInboxItems(2, EldpStatementService::AGENDA_CODE);
        $this->seedInboxItems(
            3,
            HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION,
        );
        $this->seedInboxItems(1, 'VLASTNI_AGENDA');

        $all = $this->overview([]);
        self::assertSame(8, $all['total']);

        $jmhz = $this->overview(['agenda_group' => 'jmhz']);
        self::assertSame(4, $jmhz['total'], 'Filtr musí ubrat i z celkového počtu.');
        self::assertCount(4, (array) $jmhz['items']);
        self::assertSame(4, ((array) $jmhz['summary'])['total']);
        self::assertSame(
            ['jmhz'],
            array_values(array_unique(array_column((array) $jmhz['items'], 'agenda_group'))),
        );

        $health = $this->overview(['agenda_group' => 'health']);
        self::assertSame(3, $health['total']);
        self::assertSame(3, ((array) $health['summary'])['total']);

        // Neznámý kód zůstává v `other` — a `other` má vlastní panel, takže
        // se povinnost nikam neztratí.
        $other = $this->overview(['agenda_group' => 'other']);
        self::assertSame(1, $other['total']);
        self::assertSame(
            ['VLASTNI_AGENDA'],
            array_column((array) $other['items'], 'agenda_code'),
        );

        $response = $this->overviewAction()(
            $this->request('/api/payroll/submissions/overview')->withQueryParams([
                'environment' => self::ENVIRONMENT,
                'period' => '2026-01',
                'agenda_group' => 'vymyslena',
            ]),
            new Response(),
        );
        self::assertSame(422, $response->getStatusCode(), 'Neznámá skupina je vada vstupu.');
    }

    /** Snímky REGZEL: dřív se seznam usekl na stovce. */
    public function testRegzelSnapshotsReachBeyondTheOldSilentCap(): void
    {
        $oldCap = PayrollRegzelRepository::LIST_MAX_LIMIT;
        $seeded = $oldCap + 4;
        $this->seedRegzelSnapshots($seeded);

        $payload = $this->json($this->regzelAction()->snapshots(
            $this->request('/api/payroll/regzel/snapshots')
                ->withQueryParams(['environment' => self::ENVIRONMENT, 'limit' => '10000']),
            new Response(),
        ));
        self::assertCount($oldCap, (array) $payload['items'], 'Strop nejde obejít z URL.');
        self::assertSame($oldCap, $payload['limit']);
        self::assertSame($seeded, $payload['total'], 'Total musí hlásit i snímky za stropem.');

        $beyond = $this->json($this->regzelAction()->snapshots(
            $this->request('/api/payroll/regzel/snapshots')->withQueryParams([
                'environment' => self::ENVIRONMENT,
                'limit' => '10000',
                'offset' => (string) $oldCap,
            ]),
            new Response(),
        ));
        self::assertCount(4, (array) $beyond['items'], 'Za původní strop se uživatel musí dostat.');
        self::assertSame(
            [],
            array_intersect(
                $this->ids((array) $payload['items']),
                $this->ids((array) $beyond['items']),
            ),
        );
    }

    /**
     * @param array<array-key,mixed> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * Povinnosti, termíny a položky inboxu jedním příkazem přes sekvenční
     * engine MariaDB — seedovat stovky řádků po jednom by test jen zdržovalo.
     */
    private function seedInboxItems(int $count, string $agendaCode = 'jmhz'): void
    {
        $pdo = $this->db->pdo();
        // `subject_reference` i otisky nesou kód agendy, aby šlo seedovat víc
        // agend za sebou bez kolize na jedinečnosti.
        $pdo->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type,
                 subject_reference, period_start, period_end, obligation_kind,
                 preferred_channel, status, source_event_type,
                 source_event_reference, source_event_hash, request_fingerprint,
                 idempotency_key_hash)
             SELECT ?, ?, ?, "employer", CONCAT(?, "-syn-", seq), "2026-01-01",
                    "2026-01-31", "regular", "vrep_apep", "open", "test",
                    CONCAT(?, "-ref-", seq), SHA2(CONCAT(?, "-event-", seq), 256),
                    SHA2(CONCAT(?, "-finger-", seq), 256),
                    UNHEX(SHA2(CONCAT(?, "-idem-", seq), 256))
               FROM seq_1_to_' . $count
        )->execute([
            $this->supplierId,
            self::ENVIRONMENT,
            $agendaCode,
            $agendaCode,
            $agendaCode,
            $agendaCode,
            $agendaCode,
            $agendaCode,
        ]);

        $pdo->prepare(
            'INSERT INTO payroll_submission_deadlines
                (supplier_id, environment, obligation_id, deadline_kind,
                 earliest_submission_on, due_on, calendar_basis, ruleset_id,
                 ruleset_hash, trigger_event_hash)
             SELECT supplier_id, environment, id, "regular", "2026-02-01",
                    "2026-02-20", "calendar_days", "syn",
                    SHA2(CONCAT("ruleset-", id), 256),
                    SHA2(CONCAT("trigger-", id), 256)
               FROM payroll_obligations obligation
              WHERE supplier_id = ? AND environment = ?
                AND NOT EXISTS (
                    SELECT 1 FROM payroll_submission_deadlines deadline
                     WHERE deadline.supplier_id = obligation.supplier_id
                       AND deadline.environment = obligation.environment
                       AND deadline.obligation_id = obligation.id
                )'
        )->execute([$this->supplierId, self::ENVIRONMENT]);

        $pdo->prepare(
            'INSERT INTO payroll_submission_inbox_items
                (supplier_id, environment, obligation_id, source_key_hash,
                 problem_kind, escalation_level, status)
             SELECT supplier_id, environment, id,
                    SHA2(CONCAT("source-", id), 256), "due_soon", "due_soon",
                    "open"
               FROM payroll_obligations obligation
              WHERE supplier_id = ? AND environment = ?
                AND NOT EXISTS (
                    SELECT 1 FROM payroll_submission_inbox_items inbox
                     WHERE inbox.supplier_id = obligation.supplier_id
                       AND inbox.environment = obligation.environment
                       AND inbox.obligation_id = obligation.id
                )'
        )->execute([$this->supplierId, self::ENVIRONMENT]);
    }

    private function resolveInboxItems(int $count): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_submission_inbox_items
                SET status = "resolved", resolved_at = UTC_TIMESTAMP()
              WHERE supplier_id = ? AND environment = ?
              ORDER BY id
              LIMIT ' . $count
        )->execute([$this->supplierId, self::ENVIRONMENT]);
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function overview(array $query): array
    {
        return $this->json($this->overviewAction()(
            $this->request('/api/payroll/submissions/overview')->withQueryParams([
                'environment' => self::ENVIRONMENT,
                'period' => '2026-01',
                'limit' => '10000',
                ...$query,
            ]),
            new Response(),
        ));
    }

    private function seedTransportAttempts(int $count): void
    {
        $this->seedInboxItems(1);
        $pdo = $this->db->pdo();
        $obligationId = (int) $pdo->query(
            'SELECT MIN(id) FROM payroll_obligations WHERE supplier_id = '
                . $this->supplierId
        )->fetchColumn();

        $pdo->prepare(
            'INSERT INTO payroll_submissions
                (supplier_id, environment, obligation_id, submission_kind,
                 channel, status, source_snapshot_hash, request_fingerprint,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "regular", "vrep_apep", "draft", ?, ?, UNHEX(?))'
        )->execute([
            $this->supplierId,
            self::ENVIRONMENT,
            $obligationId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            hash('sha256', 'submission-idem'),
        ]);
        $submissionId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_submission_transport_attempts
                (supplier_id, environment, submission_id, channel, attempt_no,
                 status, idempotency_key_hash, request_sha256)
             SELECT ?, ?, ?, "vrep_apep", seq, "prepared",
                    UNHEX(SHA2(CONCAT("attempt-", seq), 256)),
                    SHA2(CONCAT("request-", seq), 256)
               FROM seq_1_to_' . $count
        )->execute([$this->supplierId, self::ENVIRONMENT, $submissionId]);
    }

    private function seedRegzelSnapshots(int $count): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, is_active)
             VALUES (?, "SYN-REG", "Syntetické pracoviště", 1)'
        )->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_regzel_payload_snapshots
                (supplier_id, environment, office_id, document_type,
                 interaction_code, mapping_version, xsd_version,
                 source_manifest_json, snapshot_ciphertext, source_snapshot_hash,
                 xml_sha256, xml_byte_size, request_fingerprint,
                 idempotency_key_hash)
             SELECT ?, ?, ?, "REGZELDOPL25", "supplemental_information", "v1",
                    "1.2", "{}", "", SHA2(CONCAT("source-", seq), 256),
                    SHA2(CONCAT("xml-", seq), 256), 1024,
                    SHA2(CONCAT("finger-", seq), 256),
                    SHA2(CONCAT("idem-", seq), 256)
               FROM seq_1_to_' . $count
        )->execute([$this->supplierId, self::ENVIRONMENT, $officeId]);
    }

    private function inboxRepository(): PayrollSubmissionInboxRepository
    {
        $repository = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ->get(PayrollSubmissionInboxRepository::class);
        self::assertInstanceOf(PayrollSubmissionInboxRepository::class, $repository);

        return $repository;
    }

    private function transportRepository(): PayrollSubmissionTransportAttemptRepository
    {
        $repository = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ->get(PayrollSubmissionTransportAttemptRepository::class);
        self::assertInstanceOf(
            PayrollSubmissionTransportAttemptRepository::class,
            $repository,
        );

        return $repository;
    }

    private function inboxAction(): PayrollSubmissionInboxAction
    {
        $action = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ->get(PayrollSubmissionInboxAction::class);
        self::assertInstanceOf(PayrollSubmissionInboxAction::class, $action);

        return $action;
    }

    private function transportAction(): PayrollJmhzTransportAction
    {
        $action = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ->get(PayrollJmhzTransportAction::class);
        self::assertInstanceOf(PayrollJmhzTransportAction::class, $action);

        return $action;
    }

    private function overviewAction(): PayrollSubmissionOverviewAction
    {
        $action = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ->get(PayrollSubmissionOverviewAction::class);
        self::assertInstanceOf(PayrollSubmissionOverviewAction::class, $action);

        return $action;
    }

    private function regzelAction(): PayrollRegzelAction
    {
        $action = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(PayrollRegzelAction::class);
        self::assertInstanceOf(PayrollRegzelAction::class, $action);

        return $action;
    }

    private function request(string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
