<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Security\ClientTaxSubmissionPolicy;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\License\TaxSubmissionAccess;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Přehled daňových podání: filtr stavu a stránkování patří na SERVER.
 *
 * Dřív se seznam vracel natvrdo `LIMIT 100` a stav se filtroval až v prohlížeči,
 * takže volba „zamítnuté" ukázala prázdno, i když zamítnutá podání existovala —
 * jen byla mimo prvních sto řádků. Stejnou past má licenční omezení: kdyby se
 * bezplatné výkazy vybíraly až v PHP nad načtenou stránkou, vyšly by krátké
 * stránky a špatný celkový počet.
 *
 * Izolace: vše v transakci s rollbackem.
 */
#[Group('integration')]
final class TaxSubmissionListFilterTest extends TestCase
{
    private Connection $db;
    private TaxSubmissionRepository $repo;
    private \DI\Container|\Psr\Container\ContainerInterface $container;

    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    /** Placený výkaz — v bezplatné části {@see TaxSubmissionAccess} není. */
    private const PAID_FORM = 'dpfdp7';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db   = $this->container->get(Connection::class);
            $this->repo = $this->container->get(TaxSubmissionRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    private function seed(string $formCode, int $month, string $status): int
    {
        return $this->repo->archive(
            $this->supplierId,
            $formCode,
            2011,
            $month,
            null,
            '<?xml version="1.0"?><Pisemnost m="' . $month . $formCode . $status . '"/>',
            ['t' => 1],
            'passed',
            [],
            $this->userId,
            'B',
            $status,
        );
    }

    // ── Filtr stavu ────────────────────────────────────────────────────────────

    public function testStatusFilterRunsInSqlOverWholeArchive(): void
    {
        $rejectedId = $this->seed('dphdp3', 1, 'rejected');
        for ($month = 2; $month <= 9; $month++) {
            $this->seed('dphdp3', $month, 'downloaded');
        }

        $rows = $this->repo->list($this->supplierId, ['status' => 'rejected'], 200, 0);
        $statuses = array_values(array_unique(array_column($rows, 'status')));
        self::assertSame(['rejected'], $statuses, 'Filtr stavu musí vrátit jen zvolený stav.');
        self::assertContains($rejectedId, array_column($rows, 'id'));

        self::assertSame(
            count($rows),
            $this->repo->countList($this->supplierId, ['status' => 'rejected']),
            'Součet filtru musí sedět na počet vrácených řádků, když se vejdou na stránku.',
        );
    }

    /**
     * Jádro původní chyby: zamítnuté podání starší než sto řádků. Se serverovým
     * filtrem se najde bez ohledu na to, kolik novějších záznamů je nad ním.
     */
    public function testOldStatusIsFoundEvenBehindManyNewerRows(): void
    {
        $oldId = $this->seed('dphdp3', 1, 'rejected');
        $this->db->pdo()->prepare(
            'UPDATE tax_submissions SET generated_at = ? WHERE id = ?'
        )->execute(['2011-01-01 00:00:00', $oldId]);
        for ($month = 2; $month <= 12; $month++) {
            $this->seed('dphdp3', $month, 'downloaded');
        }

        $rows = $this->repo->list($this->supplierId, ['status' => 'rejected'], 5, 0);
        self::assertContains($oldId, array_column($rows, 'id'));
    }

    // ── Stránkování ────────────────────────────────────────────────────────────

    public function testPaginationSplitsArchiveWithoutOverlapOrGaps(): void
    {
        for ($month = 1; $month <= 7; $month++) {
            $this->seed('dphkh1', $month, 'downloaded');
        }
        $filters = ['form_code' => 'dphkh1', 'status' => 'downloaded'];
        $total = $this->repo->countList($this->supplierId, $filters);
        self::assertGreaterThanOrEqual(7, $total);

        $seen = [];
        for ($offset = 0; $offset < $total; $offset += 3) {
            $page = $this->repo->list($this->supplierId, $filters, 3, $offset);
            self::assertLessThanOrEqual(3, count($page));
            foreach ($page as $row) {
                self::assertArrayNotHasKey($row['id'], $seen, 'Řádek se nesmí objevit na dvou stránkách.');
                $seen[$row['id']] = true;
            }
        }
        self::assertCount($total, $seen, 'Součet stránek musí dát přesně celkový počet.');
    }

    // ── Licence ────────────────────────────────────────────────────────────────

    /**
     * Bez licence se placené výkazy nesmí dostat ani do stránky, ani do součtu.
     * Kdyby se odfiltrovaly až v PHP, vyšly by krátké stránky a součet by sliboval
     * řádky, které uživatel nikdy neuvidí.
     */
    public function testFreeTierFiltersFormsInSqlSoPagesAndTotalStayConsistent(): void
    {
        $free = ['allowed_form_codes' => TaxSubmissionAccess::freeFormCodes()];
        $freeBefore = $this->repo->countList($this->supplierId, $free);
        $allBefore  = $this->repo->countList($this->supplierId, []);

        for ($month = 1; $month <= 4; $month++) {
            $this->seed(self::PAID_FORM, $month, 'downloaded');
        }
        for ($month = 1; $month <= 3; $month++) {
            $this->seed('dphdp3', $month, 'downloaded');
        }

        self::assertSame($allBefore + 7, $this->repo->countList($this->supplierId, []));
        self::assertSame(
            $freeBefore + 3,
            $this->repo->countList($this->supplierId, $free),
            'Součet bez licence musí ignorovat placené výkazy.',
        );

        $page = $this->repo->list($this->supplierId, $free, 100, 0);
        foreach ($page as $row) {
            self::assertTrue(
                TaxSubmissionAccess::isFreeForm($row['form_code']),
                'Bez licence se placený výkaz nesmí dostat ani do jedné stránky.',
            );
        }
        self::assertNotContains(self::PAID_FORM, $this->repo->listFormCodes($this->supplierId, $free));
        self::assertContains(self::PAID_FORM, $this->repo->listFormCodes($this->supplierId, []));

        // Plná stránka i bez licence: limit se nesmí „projíst" placenými řádky.
        self::assertCount(2, $this->repo->list($this->supplierId, $free, 2, 0));
    }

    /** Souhrnné dlaždice popisují celý viditelný archiv, ne stránku ani filtr. */
    public function testStatsCoverWholeArchiveNotThePage(): void
    {
        $before = $this->repo->listStats($this->supplierId, []);
        for ($month = 1; $month <= 5; $month++) {
            $this->seed('dphdp3', $month, 'submitted');
        }
        $after = $this->repo->listStats($this->supplierId, []);

        self::assertSame($before['total'] + 5, $after['total']);
        self::assertSame($before['submitted'] + 5, $after['submitted']);
        // Stránka o dvou řádcích na souhrn nemá vliv — kdyby ho počítal frontend
        // z načtených položek, vyšlo by tu 2.
        self::assertSame(
            $after['total'],
            $this->repo->listStats($this->supplierId, ['status' => 'rejected'])['total'],
            'Souhrn se nesmí zužovat filtrem stavu.',
        );
        self::assertCount(2, $this->repo->list($this->supplierId, [], 2, 0));
    }

    // ── Akce ───────────────────────────────────────────────────────────────────

    /** @return array{0:int,1:array<string,mixed>} */
    private function callList(array $query, bool $client = false): array
    {
        $action = $this->container->get(\MyInvoice\Action\Report\TaxSubmissionAction::class);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/reports/submissions')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        if ($client) {
            $request = $request->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Klient podání', 'client', true, ['reports.submissions' => 1]));
        }
        $response = $action->list($request, (new ResponseFactory())->createResponse());
        return [
            $response->getStatusCode(),
            (array) json_decode((string) $response->getBody(), true),
        ];
    }

    public function testUnknownStatusIsRejectedInsteadOfSilentlyIgnored(): void
    {
        [$status, $body] = $this->callList(['status' => 'nesmysl']);
        self::assertSame(422, $status);
        self::assertSame('validation_failed', $body['error']['code'] ?? null);
        self::assertArrayNotHasKey('data', $body, 'Neplatný filtr nesmí vrátit plný seznam.');
    }

    public function testClientScopeAppliesToSqlPagesCountsStatsAndHostileFilters(): void
    {
        [$status, $before] = $this->callList([], true);
        self::assertSame(200, $status);
        foreach (ClientTaxSubmissionPolicy::FORM_CODES as $form) {
            $this->seed($form, 1, 'downloaded');
        }
        $this->seed(self::PAID_FORM, 1, 'downloaded');
        $this->seed('dpzvd6', 1, 'downloaded');

        [$status, $body] = $this->callList(['limit' => '2'], true);
        self::assertSame(200, $status);
        self::assertCount(2, $body['data'], 'Nepovolené výkazy nesmějí zkrátit stránku filtrováním až v PHP.');
        self::assertSame($before['meta']['total'] + 3, $body['meta']['total']);
        self::assertSame($before['meta']['stats']['total'] + 3, $body['meta']['stats']['total']);
        foreach ($body['data'] as $row) {
            self::assertContains($row['form_code'], ClientTaxSubmissionPolicy::FORM_CODES);
        }
        self::assertSame([], array_diff($body['meta']['form_codes'], ClientTaxSubmissionPolicy::FORM_CODES));

        foreach ([['scope' => 'payroll'], ['form_code' => self::PAID_FORM]] as $query) {
            [$status, $filtered] = $this->callList($query, true);
            self::assertSame(200, $status);
            self::assertSame([], $filtered['data']);
            self::assertSame(0, $filtered['meta']['total']);
            self::assertSame([], array_diff($filtered['meta']['form_codes'], ClientTaxSubmissionPolicy::FORM_CODES));
        }
    }

    public function testListReturnsPageWithTotalAndStats(): void
    {
        for ($month = 1; $month <= 4; $month++) {
            $this->seed('dphdp3', $month, 'downloaded');
        }

        [$status, $body] = $this->callList(['status' => 'downloaded', 'form_code' => 'dphdp3', 'limit' => '2']);
        self::assertSame(200, $status);
        self::assertCount(2, $body['data']);
        self::assertGreaterThanOrEqual(4, $body['meta']['total']);
        self::assertSame(2, $body['meta']['limit']);
        self::assertSame(0, $body['meta']['offset']);
        self::assertArrayHasKey('problems', $body['meta']['stats']);
        self::assertContains('dphdp3', $body['meta']['form_codes']);

        // Souhrn nad celým archivem, ne nad dvouřádkovou stránkou.
        self::assertGreaterThanOrEqual(4, $body['meta']['stats']['total']);

        [, $second] = $this->callList([
            'status' => 'downloaded', 'form_code' => 'dphdp3', 'limit' => '2', 'offset' => '2',
        ]);
        self::assertSame(
            [],
            array_intersect(array_column($body['data'], 'id'), array_column($second['data'], 'id')),
            'Druhá stránka nesmí opakovat řádky první.',
        );
    }

    /**
     * Mzdová vyúčtování mají vlastní záložku pod Mzdami a mezi daněmi se
     * nepletou — včetně dlaždic a nabídky výkazů, které na rozsah obrazovky
     * musí slyšet stejně jako na licenci.
     */
    public function testScopeSeparatesPayrollStatementsFromTaxReturns(): void
    {
        $this->seed('dpzvd6', 1, 'downloaded');
        $this->seed('dphdp3', 1, 'downloaded');

        [$status, $payroll] = $this->callList(['scope' => 'payroll', 'limit' => '200']);
        self::assertSame(200, $status);
        self::assertNotEmpty($payroll['data']);
        foreach ($payroll['data'] as $row) {
            self::assertContains($row['form_code'], ['dpzvd6', 'dpsvd2']);
        }
        self::assertNotContains('dphdp3', $payroll['meta']['form_codes']);
        self::assertSame($payroll['meta']['total'], $payroll['meta']['stats']['total']);

        [, $tax] = $this->callList(['scope' => 'tax', 'limit' => '200']);
        self::assertNotContains('dpzvd6', array_column($tax['data'], 'form_code'));
        self::assertNotContains('dpzvd6', $tax['meta']['form_codes']);
        self::assertContains('dphdp3', $tax['meta']['form_codes']);

        [, $all] = $this->callList(['limit' => '200']);
        self::assertContains('dpzvd6', $all['meta']['form_codes'], 'Bez rozsahu zůstává archiv celý.');

        [$invalid] = $this->callList(['scope' => 'mzdy']);
        self::assertSame(422, $invalid);
    }

    /** Obohacení o pokusy a artefakty běží jen nad stránkou, ne nad celým archivem. */
    public function testEnrichmentIsBoundToThePage(): void
    {
        for ($month = 1; $month <= 6; $month++) {
            $this->seed('dphdp3', $month, 'downloaded');
        }
        [, $body] = $this->callList(['limit' => '3']);
        self::assertCount(3, $body['data']);
        foreach ($body['data'] as $row) {
            self::assertArrayHasKey('attempts', $row);
            self::assertArrayHasKey('artifacts', $row);
        }
    }
    public function testExactSubmissionFilterFindsOlderRowAndPreservesTenantLicenseAndPaging(): void
    {
        $target = $this->seed('dphdp3', 1, 'submitted');
        $this->db->pdo()->prepare('UPDATE tax_submissions SET generated_at = ? WHERE id = ?')
            ->execute(['2001-01-01 00:00:00', $target]);
        $this->seed('dphdp3', 2, 'submitted');
        $paid = $this->seed(self::PAID_FORM, 1, 'downloaded');
        $filters = ['submission_id' => $target];
        $rows = $this->repo->list($this->supplierId, $filters, 1, 0);
        self::assertSame([$target], array_column($rows, 'id'));
        self::assertSame(1, $this->repo->countList($this->supplierId, $filters));
        self::assertSame([], $this->repo->list($this->supplierId, $filters, 1, 1));
        self::assertSame([], $this->repo->list($this->supplierId + 1000, $filters));
        self::assertSame(0, $this->repo->countList($this->supplierId + 1000, $filters));
        $restricted = ['submission_id' => $paid, 'allowed_form_codes' => TaxSubmissionAccess::freeFormCodes()];
        self::assertSame([], $this->repo->list($this->supplierId, $restricted));
        self::assertSame(0, $this->repo->countList($this->supplierId, $restricted));
        [$status, $body] = $this->callList(['submission_id' => (string) $target]);
        self::assertSame(200, $status);
        self::assertSame([$target], array_column($body['data'], 'id'));
        self::assertSame(1, $body['meta']['total']);
        self::assertSame($this->repo->listStats($this->supplierId)['total'], $this->repo->listStats($this->supplierId, $filters)['total']);
    }

    public function testInvalidSubmissionIdCannotSilentlyShowEntireArchive(): void
    {
        foreach (['0', '-1', '1abc', '1.5', '', '999999999999999999999999', ['1']] as $id) {
            [$status, $body] = $this->callList(['submission_id' => $id]);
            self::assertSame(422, $status);
            self::assertSame('validation_failed', $body['error']['code'] ?? null);
            self::assertArrayNotHasKey('data', $body);
        }
    }

}
