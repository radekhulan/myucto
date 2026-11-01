<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\WorkReport;

use MyInvoice\Action\WorkReport\DeleteWorkReportAction;
use MyInvoice\Action\WorkReport\SaveWorkReportAction;
use MyInvoice\Action\WorkReport\SaveWorkReportMaterialsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * PUT /api/invoices/{id}/work-report a /work-report/materials s částečným tělem (#113):
 * vynechaný klíč drží uloženou hodnotu (řádky, sazbu DPH, název), explicitní null / []
 * maže. Data jsou syntetická, úklid v tearDown.
 */
#[Group('integration')]
final class PartialWorkReportUpdateTest extends TestCase
{
    private Connection $db;
    private SaveWorkReportAction $work;
    private SaveWorkReportMaterialsAction $materials;
    private DeleteWorkReportAction $delete;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vat21 = 0;
    private int $vat12 = 0;
    private int $invoiceId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db        = $c->get(Connection::class);
            $this->work      = $c->get(SaveWorkReportAction::class);
            $this->materials = $c->get(SaveWorkReportMaterialsAction::class);
            $this->delete    = $c->get(DeleteWorkReportAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vat21      = (int) ($pdo->query("SELECT id FROM vat_rates WHERE code='CZ-21' LIMIT 1")->fetchColumn() ?: 0);
        $this->vat12      = (int) ($pdo->query("SELECT id FROM vat_rates WHERE code='CZ-12' LIMIT 1")->fetchColumn() ?: 0);
        $stmt = $pdo->prepare("SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' ORDER BY is_default DESC, id LIMIT 1");
        $stmt->execute([$this->supplierId]);
        $this->currencyId = (int) $stmt->fetchColumn();
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        if (!$this->supplierId || !$this->userId || !$this->vat21 || !$this->vat12 || !$this->currencyId || !$czId) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'TEST partial work report (PHPUnit)', 'Ulice 1', 'Praha', '11000', $czId, 'partial-wr@example.test', $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, client_id, project_id, issue_date, due_date, currency_id, created_by, status, invoice_type)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $this->clientId, '2096-06-01', '2096-06-15', $this->currencyId, $this->userId, 'draft', 'invoice']);
        $this->invoiceId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->invoiceId > 0) {
            $wrId = (int) ($pdo->query('SELECT id FROM work_reports WHERE invoice_id = ' . $this->invoiceId)->fetchColumn() ?: 0);
            if ($wrId > 0) {
                $pdo->prepare('DELETE FROM work_report_materials WHERE work_report_id = ?')->execute([$wrId]);
                $pdo->prepare('DELETE FROM work_report_items WHERE work_report_id = ?')->execute([$wrId]);
            }
            $pdo->prepare('DELETE FROM work_reports WHERE invoice_id = ?')->execute([$this->invoiceId]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$this->invoiceId]);
        }
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->db->close();
    }

    /** BEZ OPRAVY PADÁ: tělo jen s názvem smazalo řádky výkazu a sazbu DPH. */
    public function testWorkReportOmittedKeysKeepStoredValues(): void
    {
        $this->saveFullWorkReport();

        $res = $this->call($this->work, ['title' => 'Nový název']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('Nový název', $res['body']['title']);
        self::assertCount(2, $res['body']['items'], 'Vynechané items zůstávají.');
        self::assertEqualsWithDelta(15.0, (float) $res['body']['total_hours'], 0.001);
        self::assertEqualsWithDelta(19500.0, (float) $res['body']['total_amount'], 0.01);
        self::assertSame($this->vat21, $res['body']['vat_rate_id']);

        // Bez title u existujícího výkazu: uložený název zůstává.
        $res = $this->call($this->work, ['vat_rate_id' => $this->vat12]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('Nový název', $res['body']['title']);
        self::assertSame($this->vat12, $res['body']['vat_rate_id']);
        self::assertCount(2, $res['body']['items']);
    }

    public function testWorkReportExplicitEmptyClears(): void
    {
        $this->saveFullWorkReport();

        $res = $this->call($this->work, ['vat_rate_id' => null, 'items' => []]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertNull($res['body']['vat_rate_id']);
        self::assertCount(0, $res['body']['items']);
        self::assertSame('Výkaz 6/2096', $res['body']['title']);
    }

    public function testWorkReportCreateStillRequiresTitle(): void
    {
        $res = $this->call($this->work, ['items' => []]);
        self::assertSame(400, $res['status']);
    }

    /** BEZ OPRAVY PADÁ: tělo jen s názvem smazalo řádky materiálu, součet i sazbu DPH. */
    public function testMaterialsOmittedKeysKeepStoredValues(): void
    {
        $this->saveFullMaterials();

        $res = $this->call($this->materials, ['material_title' => 'Materiál B']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('Materiál B', $res['body']['material_title']);
        self::assertCount(1, $res['body']['materials']);
        self::assertEqualsWithDelta(250.0, (float) $res['body']['material_total'], 0.01);
        self::assertSame($this->vat12, $res['body']['material_vat_rate_id']);

        $res = $this->call($this->materials, ['material_vat_rate_id' => $this->vat21]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('Materiál B', $res['body']['material_title'], 'Vynechaný název zůstává.');
        self::assertSame($this->vat21, $res['body']['material_vat_rate_id']);
        self::assertCount(1, $res['body']['materials']);
    }

    public function testMaterialsExplicitNullRateWithStoredRowsIsRejected(): void
    {
        $this->saveFullMaterials();

        $res = $this->call($this->materials, ['material_vat_rate_id' => null]);
        self::assertSame(400, $res['status'], 'Uložené řádky sazbu potřebují.');

        $res = $this->call($this->materials, ['materials' => [], 'material_vat_rate_id' => null]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertCount(0, $res['body']['materials']);
        self::assertNull($res['body']['material_vat_rate_id']);
        self::assertEqualsWithDelta(0.0, (float) $res['body']['material_total'], 0.001);
    }

    /**
     * Výkaz vystavené faktury je součást úpravy vystavené faktury: s ?force=1 ho upraví
     * a smaže i interní role s právem `invoices.edit_issued`, bez něj ani s force ne.
     */
    public function testIssuedInvoiceWorkReportFollowsEditIssuedPermission(): void
    {
        $this->saveFullWorkReport();
        $this->saveFullMaterials();
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued', varsymbol = 'P13-WR-1' WHERE id = ?")
            ->execute([$this->invoiceId]);
        $editor = new EffectiveRole(90, 'Úprava vystavených', 'staff', true, [
            'invoices' => AccessLevel::WRITE->value,
            'invoices.edit_issued' => AccessLevel::WRITE->value,
        ]);
        $withoutRight = new EffectiveRole(91, 'Fakturace', 'staff', true, ['invoices' => AccessLevel::WRITE->value]);

        $res = $this->call($this->work, ['title' => 'Bez práva'], $withoutRight, true);
        self::assertSame(409, $res['status']);
        $res = $this->call($this->materials, ['material_title' => 'Bez práva'], $withoutRight, true);
        self::assertSame(409, $res['status']);
        $res = $this->call($this->delete, [], $withoutRight, true);
        self::assertSame(409, $res['status']);
        $res = $this->call($this->work, ['title' => 'Bez force'], $editor);
        self::assertSame(409, $res['status'], 'Bez ?force=1 zůstává výkaz vystavené faktury zamčený.');

        // Vynucená úprava výkazu vystavené faktury je účetní úkon jen z relace, ne tokenem.
        foreach ([[$this->work, ['title' => 'Tokenem']], [$this->materials, ['material_title' => 'Tokenem']], [$this->delete, []]] as [$action, $body]) {
            $res = $this->call($action, $body, $editor, true, 'bearer');
            self::assertSame(403, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
            self::assertSame('token_write_forbidden', $res['body']['error']['code'] ?? null);
        }

        $res = $this->call($this->work, ['title' => 'Opravený výkaz'], $editor, true);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $res = $this->call($this->materials, ['material_title' => 'Opravený materiál'], $editor, true);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $res = $this->call($this->delete, [], $editor, true);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
    }

    public function testDisplayInheritanceOverrideAndResetKeepRowsAndAmounts(): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT work_report_display_options FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);
        $original = $stmt->fetchColumn();
        try {
            $pdo->prepare('UPDATE supplier SET work_report_display_options = ? WHERE id = ?')
                ->execute(['{"rate":false,"amount":false}', $this->supplierId]);
            $this->saveFullWorkReport();
            $repo = new \MyInvoice\Repository\WorkReportRepository($this->db);
            $before = $repo->findByInvoice($this->invoiceId, $this->supplierId);
            self::assertNull($before['display_options']);
            self::assertFalse($before['effective_display_options']['rate']);
            self::assertFalse($before['effective_display_options']['amount']);

            $clientRole = new EffectiveRole(99, 'Klient výkaz', 'client', true, ['invoices' => AccessLevel::WRITE->value]);
            $result = $this->call($this->work, ['display_options' => ['compact' => true, 'rate' => true]], $clientRole);
            self::assertSame(200, $result['status']);
            self::assertTrue($result['body']['effective_display_options']['compact']);
            self::assertTrue($result['body']['effective_display_options']['rate']);
            self::assertFalse($result['body']['effective_display_options']['amount']);
            self::assertEquals($before['items'], $result['body']['items']);
            self::assertEquals($before['total_amount'], $result['body']['total_amount']);

            $this->call($this->work, ['title' => 'Nový titulek']);
            $this->saveFullMaterials();
            $single = $repo->findByInvoice($this->invoiceId, $this->supplierId);
            $batch = $repo->findByInvoiceIds([$this->invoiceId], $this->supplierId)[$this->invoiceId];
            self::assertEquals($single, $batch);
            self::assertSame($single['effective_display_options'], $batch['effective_display_options']);
            self::assertSame(['compact' => true, 'rate' => true], $single['display_options']);

            $reset = $this->call($this->work, ['display_options' => null]);
            self::assertSame(200, $reset['status']);
            self::assertNull($reset['body']['display_options']);
            self::assertFalse($reset['body']['effective_display_options']['rate']);
            self::assertFalse($reset['body']['effective_display_options']['compact']);
            self::assertEquals($before['total_amount'], $reset['body']['total_amount']);
        } finally {
            $pdo->prepare('UPDATE supplier SET work_report_display_options = ? WHERE id = ?')
                ->execute([$original, $this->supplierId]);
        }
    }

    public function testApprovalPreviewDoesNotExposeHiddenColumns(): void
    {
        $this->saveFullWorkReport();
        $repo = new \MyInvoice\Repository\WorkReportRepository($this->db);
        $repo->saveDisplayOptions($this->invoiceId, ['date' => false, 'hours' => false, 'rate' => false, 'amount' => false], $this->supplierId);
        $token = bin2hex(random_bytes(24));
        $this->db->pdo()->prepare("UPDATE invoices SET approval_status = 'requested', approval_token = ?, approval_token_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?")
            ->execute([$token, $this->invoiceId]);
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Approval\PublicApprovalGetAction::class);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/public/approval/' . $token);
        $response = $action($request, new Psr7Response(), ['token' => $token]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $report = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['work_report'];
        self::assertNull($report['total_hours']);
        self::assertNull($report['total_amount']);
        self::assertNull($report['items'][0]['rate']);
        self::assertNull($report['items'][0]['hours']);
        self::assertFalse($report['effective_display_options']['amount']);
        self::assertSame(19500.0, $repo->findByInvoice($this->invoiceId, $this->supplierId)['total_amount']);
    }

    public function testReadAndDisplayUpdateAreRestrictedToTheSelectedCompany(): void
    {
        $this->saveFullWorkReport();
        $repo = new \MyInvoice\Repository\WorkReportRepository($this->db);
        $otherSupplier = (int) $this->db->pdo()->query('SELECT id FROM supplier WHERE id <> ' . $this->supplierId . ' ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $otherSupplier, 'CI fixture musí obsahovat druhou firmu.');
        $before = $repo->findByInvoice($this->invoiceId, $this->supplierId);
        self::assertNull($repo->findByInvoice($this->invoiceId, $otherSupplier));
        self::assertSame([], $repo->findByInvoiceIds([$this->invoiceId], $otherSupplier));
        $repo->saveDisplayOptions($this->invoiceId, ['amount' => false], $otherSupplier);
        $repo->deleteByInvoice($this->invoiceId, $otherSupplier);
        self::assertSame($before, $repo->findByInvoice($this->invoiceId, $this->supplierId));
    }

    public function testWorkAndMaterialCreationRejectsAnInvoiceFromAnotherCompany(): void
    {
        $repo = new \MyInvoice\Repository\WorkReportRepository($this->db);
        $otherSupplier = (int) $this->db->pdo()->query('SELECT id FROM supplier WHERE id <> ' . $this->supplierId . ' ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $otherSupplier);
        foreach (['work', 'material'] as $kind) {
            try {
                if ($kind === 'work') {
                    $repo->save($this->invoiceId, $otherSupplier, null, 'Cizí práce', [['description' => 'Práce', 'hours' => 1, 'rate' => 100]]);
                } else {
                    $repo->saveMaterials($this->invoiceId, $otherSupplier, null, 'Cizí materiál', $this->vat21, [['description' => 'Materiál', 'quantity' => 1, 'unit_price' => 100]]);
                }
                self::fail('Cizí faktura musí být odmítnuta.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Faktura nepatří zadané firmě.', $e->getMessage());
            }
            self::assertNull($repo->findByInvoice($this->invoiceId, $this->supplierId));
        }
    }

    public function testInvalidDisplayOptionsDoNotModifyTheReport(): void
    {
        $this->saveFullWorkReport();
        $repo = new \MyInvoice\Repository\WorkReportRepository($this->db);
        $before = $repo->findByInvoice($this->invoiceId, $this->supplierId);
        foreach ([['rate' => 'false'], ['unknown' => true], 'compact'] as $options) {
            $response = $this->call($this->work, ['title' => 'Nesmí se uložit', 'items' => [], 'display_options' => $options]);
            self::assertSame(400, $response['status']);
            self::assertSame($before, $repo->findByInvoice($this->invoiceId, $this->supplierId));
        }
    }

    private function saveFullWorkReport(): void
    {
        $res = $this->call($this->work, [
            'project_id'  => null,
            'title'       => 'Výkaz 6/2096',
            'vat_rate_id' => $this->vat21,
            'items'       => [
                ['description' => 'Programování', 'hours' => 10, 'rate' => 1500],
                ['description' => 'Konzultace', 'hours' => 5, 'rate' => 900],
            ],
        ]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
    }

    private function saveFullMaterials(): void
    {
        $res = $this->call($this->materials, [
            'project_id'           => null,
            'material_title'       => 'Materiál A',
            'material_vat_rate_id' => $this->vat12,
            'materials'            => [
                ['description' => 'Kabel', 'quantity' => 10, 'unit' => 'm', 'unit_price' => 25],
            ],
        ]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(
        callable $action,
        array $body,
        ?EffectiveRole $role = null,
        bool $force = false,
        string $authMethod = 'session',
    ): array {
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/invoices/' . $this->invoiceId . '/work-report')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withParsedBody($body);
        if ($role !== null) {
            $request = $request->withAttribute('auth.effective_role', $role);
        }
        if ($force) {
            $request = $request->withQueryParams(['force' => '1']);
        }
        $response = $action($request, new Psr7Response(), ['id' => (string) $this->invoiceId]);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
