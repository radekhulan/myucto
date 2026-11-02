<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\WorkReport;

use MyInvoice\Action\WorkReport\PublicWorkReportGetAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\WorkReportLinkRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\WorkReport\WorkReportLinkService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Veřejný sledovací odkaz na výkaz práce (migrace 0112).
 *
 * Pokrývá: getOrCreate (idempotence), resolveAllowedEmails (client vs project
 * scope), code/verify/session mechaniku, revoke, a buildPreview (jen draft +
 * výkaz, scope filtr, součty).
 *
 * Izolováno pod existujícím supplierem, vše uklizeno v tearDown.
 * Soft-skip pokud chybí cfg.php / DB.
 */
#[Group('integration')]
final class WorkReportLinkServiceTest extends TestCase
{
    private Connection $db;
    private WorkReportLinkService $service;
    private WorkReportLinkRepository $links;
    private WorkReportRepository $workReports;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $czId = 0;
    private int $userId = 0;

    /** @var int[] */
    private array $clientIds = [];
    /** @var int[] */
    private array $projectIds = [];
    /** @var int[] */
    private array $invoiceIds = [];
    /** @var int[] */
    private array $linkIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(WorkReportLinkService::class);
            $this->links = $container->get(WorkReportLinkRepository::class);
            $this->workReports = $container->get(WorkReportRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->czId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data v DB (supplier/currency/country/user).');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->linkIds as $id) {
            $pdo->prepare('DELETE FROM work_report_link_sessions WHERE link_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM work_report_link_codes WHERE link_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM work_report_links WHERE id = ?')->execute([$id]);
        }
        foreach ($this->invoiceIds as $id) {
            $wrId = (int) ($pdo->query('SELECT id FROM work_reports WHERE invoice_id = ' . $id)->fetchColumn() ?: 0);
            if ($wrId > 0) {
                $pdo->prepare('DELETE FROM work_report_items WHERE work_report_id = ?')->execute([$wrId]);
            }
            $pdo->prepare('DELETE FROM work_reports WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->projectIds as $id) {
            $pdo->prepare('DELETE FROM project_billing_emails WHERE project_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
        }
        foreach ($this->clientIds as $id) {
            $pdo->prepare('DELETE FROM client_email_contacts WHERE client_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function client(string $mainEmail = 'klient@example.cz'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'WRL Test klient', 'Ulice 1', 'Praha', '11000', $this->czId, $mainEmail, $this->currencyId]);
        $id = (int) $pdo->lastInsertId();
        $this->clientIds[] = $id;
        return $id;
    }

    /** @param list<string> $billingEmails */
    private function project(int $clientId, array $billingEmails = []): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO projects (client_id, name, currency_id) VALUES (?, ?, ?)')
            ->execute([$clientId, 'WRL Test zakázka', $this->currencyId]);
        $id = (int) $pdo->lastInsertId();
        $this->projectIds[] = $id;
        foreach ($billingEmails as $i => $em) {
            $pdo->prepare('INSERT INTO project_billing_emails (project_id, email, position) VALUES (?, ?, ?)')
                ->execute([$id, $em, $i + 1]);
        }
        return $id;
    }

    /** @param list<array{description:string,hours:float,rate:float,work_date?:?string}> $items */
    private function draftInvoiceWithReport(int $clientId, ?int $projectId, string $title, array $items, string $status = 'draft'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, client_id, project_id, issue_date, due_date, currency_id, created_by, status, invoice_type)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $clientId, $projectId, '2026-06-01', '2026-06-15', $this->currencyId, $this->userId, $status, 'invoice']);
        $invId = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $invId;
        $this->workReports->save($invId, $this->supplierId, $projectId, $title, $items);
        return $invId;
    }

    private function trackLink(array $link): array
    {
        if (!in_array((int) $link['id'], $this->linkIds, true)) {
            $this->linkIds[] = (int) $link['id'];
        }
        return $link;
    }

    public function testPreviewRedactsHiddenColumnsAndSuppressesIncompleteGrandTotals(): void
    {
        $client = $this->client();
        $invoice = $this->draftInvoiceWithReport($client, null, 'Pouze čas', [
            ['description' => 'Analýza', 'work_date' => '2096-06-01', 'hours' => 2, 'rate' => 1234],
        ]);
        $this->workReports->saveDisplayOptions($invoice, ['rate' => false, 'amount' => false, 'date' => false], $this->supplierId);
        $this->draftInvoiceWithReport($client, null, 'S cenou', [['description' => 'Konzultace', 'hours' => 1, 'rate' => 500]]);
        $link = $this->trackLink($this->service->getOrCreate($this->supplierId, 'client', $client, null, $this->userId));
        $preview = $this->service->buildPreview($link);
        self::assertCount(2, $preview['reports']);
        self::assertSame([], $preview['totals_by_currency']);
        self::assertSame(3.0, $preview['total_hours']);
        $report = array_values(array_filter($preview['reports'], static fn (array $row): bool => $row['invoice_id'] === $invoice))[0];
        self::assertNull($report['items'][0]['work_date']);
        self::assertNull($report['items'][0]['rate']);
        self::assertNull($report['items'][0]['total_amount']);
        self::assertNull($report['total_amount']);
        self::assertSame(2.0, $report['items'][0]['hours']);
        self::assertSame(2468.0, $this->workReports->findByInvoice($invoice, $this->supplierId)['total_amount']);

        $this->workReports->saveDisplayOptions($invoice, ['hours' => false], $this->supplierId);
        $preview = $this->service->buildPreview($link);
        self::assertNull($preview['total_hours']);
        self::assertSame(2968.0, $preview['totals_by_currency'][0]['total_amount']);
    }

    // ── link lifecycle ───────────────────────────────────────────────────

    public function testGetOrCreateIsIdempotent(): void
    {
        $c = $this->client();
        $a = $this->trackLink($this->service->getOrCreate($this->supplierId, 'client', $c, null, $this->userId));
        $b = $this->service->getOrCreate($this->supplierId, 'client', $c, null, $this->userId);

        self::assertSame((int) $a['id'], (int) $b['id'], 'Druhé getOrCreate vrátí stejný odkaz');
        self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/', (string) $a['token']);
    }

    public function testRevokeInvalidatesLinkAndSession(): void
    {
        $c = $this->client();
        $link = $this->trackLink($this->service->getOrCreate($this->supplierId, 'client', $c, null, $this->userId));

        // Ručně vlož kód a ověř → vznikne session.
        $this->links->insertCode((int) $link['id'], 'klient@example.cz', hash('sha256', '123456'),
            (new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s'), null);
        $token = $this->service->verifyCode($link, 'klient@example.cz', '123456', '127.0.0.1');
        self::assertNotNull($token);
        self::assertTrue($this->service->validateSession($link, $token));

        $this->service->revoke((int) $link['id']);
        self::assertNull($this->service->findActiveLink((string) $link['token']), 'Revokovaný odkaz se nenajde');
        self::assertFalse($this->service->validateSession($link, $token), 'Revoke zneplatní i relaci');
    }

    // ── povolené e-maily ─────────────────────────────────────────────────

    public function testAllowedEmailsClientScope(): void
    {
        $c = $this->client('hlavni@example.cz');
        $p = $this->project($c, ['zakazka@example.cz']);
        $link = $this->trackLink($this->service->getOrCreate($this->supplierId, 'client', $c, null, $this->userId));

        $allowed = $this->service->resolveAllowedEmails($link);
        self::assertContains('hlavni@example.cz', $allowed);
        self::assertNotContains('zakazka@example.cz', $allowed, 'Client scope nepřebírá e-maily zakázky');
    }

    public function testAllowedEmailsProjectScopeIncludesProjectEmails(): void
    {
        $c = $this->client('hlavni@example.cz');
        $p = $this->project($c, ['zakazka@example.cz']);
        $link = $this->trackLink($this->service->getOrCreate($this->supplierId, 'project', $c, $p, $this->userId));

        $allowed = $this->service->resolveAllowedEmails($link);
        self::assertContains('hlavni@example.cz', $allowed);
        self::assertContains('zakazka@example.cz', $allowed, 'Project scope přidává e-maily zakázky');
    }

    // ── kód + relace ─────────────────────────────────────────────────────

    public function testIssueCodeDeniedForForeignEmail(): void
    {
        $c = $this->client('hlavni@example.cz');
        $link = $this->trackLink($this->service->getOrCreate($this->supplierId, 'client', $c, null, $this->userId));

        $r = $this->service->issueCode($link, 'cizi@nikdo.cz', '127.0.0.1');
        self::assertFalse($r['allowed']);
        self::assertFalse($r['sent']);
    }

    public function testVerifyCodeWrongThenRight(): void
    {
        $c = $this->client('hlavni@example.cz');
        $link = $this->trackLink($this->service->getOrCreate($this->supplierId, 'client', $c, null, $this->userId));

        $this->links->insertCode((int) $link['id'], 'hlavni@example.cz', hash('sha256', '654321'),
            (new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s'), null);

        self::assertNull($this->service->verifyCode($link, 'hlavni@example.cz', '000000', '127.0.0.1'), 'Špatný kód → null');
        $token = $this->service->verifyCode($link, 'hlavni@example.cz', '654321', '127.0.0.1');
        self::assertNotNull($token, 'Správný kód → session token');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $token);
        self::assertTrue($this->service->validateSession($link, (string) $token));
    }

    /** Ověří e-mail přes ručně vložený kód a vrátí session token. */
    private function verifiedSession(array $link, string $email): string
    {
        $this->links->insertCode((int) $link['id'], $email, hash('sha256', '246810'),
            (new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s'), null);
        $token = $this->service->verifyCode($link, $email, '246810', '127.0.0.1');
        self::assertNotNull($token);
        return (string) $token;
    }

    private function sessionRow(string $token): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM work_report_link_sessions WHERE session_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function testSessionGetsServerSideExpiryFromLifetime(): void
    {
        $c = $this->client('hlavni@example.cz');
        $link = $this->trackLink($this->service->getOrCreate($this->supplierId, 'client', $c, null, $this->userId));
        $token = $this->verifiedSession($link, 'hlavni@example.cz');

        $expires = new \DateTimeImmutable((string) $this->sessionRow($token)['expires_at']);
        $expected = new \DateTimeImmutable('+' . $this->service->sessionLifetimeDays() . ' days');
        self::assertLessThan(120, abs($expected->getTimestamp() - $expires->getTimestamp()), 'Platnost relace = ověření + session_lifetime_days');
        self::assertTrue($this->service->validateSession($link, $token));
    }

    public function testExpiredSessionIsRejected(): void
    {
        $c = $this->client('hlavni@example.cz');
        $link = $this->trackLink($this->service->getOrCreate($this->supplierId, 'client', $c, null, $this->userId));
        $token = $this->verifiedSession($link, 'hlavni@example.cz');

        $this->db->pdo()->prepare('UPDATE work_report_link_sessions SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE session_hash = ?')
            ->execute([hash('sha256', $token)]);
        self::assertFalse($this->service->validateSession($link, $token), 'Po expires_at relace na serveru neplatí');
    }

    public function testSessionRevokedWhenEmailRemovedFromClientContacts(): void
    {
        $c = $this->client('hlavni@example.cz');
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO client_email_contacts (client_id, email, is_active, usages) VALUES (?, ?, 1, '[]')")
            ->execute([$c, 'Kontakt@Example.cz']);
        $contactId = (int) $pdo->lastInsertId();
        $link = $this->trackLink($this->service->getOrCreate($this->supplierId, 'client', $c, null, $this->userId));
        $token = $this->verifiedSession($link, 'kontakt@example.cz');
        $mainToken = $this->verifiedSession($link, 'hlavni@example.cz');
        self::assertTrue($this->service->validateSession($link, $token));

        $pdo->prepare('UPDATE client_email_contacts SET is_active = 0 WHERE id = ?')->execute([$contactId]);
        self::assertFalse($this->service->validateSession($link, $token), 'Odebraný kontakt už náhled nevidí');
        self::assertNotNull($this->sessionRow($token)['revoked_at'], 'Relace se trvale zneplatní');
        self::assertTrue($this->service->validateSession($link, $mainToken), 'Relace jiné povolené adresy platí dál');

        // Ani opětovné přidání adresy starou relaci neobnoví, musí se ověřit znovu.
        $pdo->prepare('UPDATE client_email_contacts SET is_active = 1 WHERE id = ?')->execute([$contactId]);
        self::assertFalse($this->service->validateSession($link, $token));
    }

    public function testSessionRevokedWhenMainEmailChanged(): void
    {
        $c = $this->client('hlavni@example.cz');
        $link = $this->trackLink($this->service->getOrCreate($this->supplierId, 'client', $c, null, $this->userId));
        $token = $this->verifiedSession($link, 'hlavni@example.cz');

        $this->db->pdo()->prepare('UPDATE clients SET main_email = ? WHERE id = ?')->execute(['novy@example.cz', $c]);
        self::assertFalse($this->service->validateSession($link, $token));
    }

    public function testProjectScopeSessionWithProjectEmailStaysValid(): void
    {
        $c = $this->client('hlavni@example.cz');
        $p = $this->project($c, ['zakazka@example.cz']);
        $link = $this->trackLink($this->service->getOrCreate($this->supplierId, 'project', $c, $p, $this->userId));
        $token = $this->verifiedSession($link, 'zakazka@example.cz');
        self::assertTrue($this->service->validateSession($link, $token), 'E-mail zakázky platí u project scope');
        self::assertTrue($this->service->validateSession($link, $token), 'Opakované použití relace');

        $this->db->pdo()->prepare('DELETE FROM project_billing_emails WHERE project_id = ?')->execute([$p]);
        self::assertFalse($this->service->validateSession($link, $token), 'Odebraný e-mail zakázky relaci ukončí');
    }

    public function testSessionOfOtherLinkIsRejected(): void
    {
        $c = $this->client('hlavni@example.cz');
        $p = $this->project($c);
        $clientLink = $this->trackLink($this->service->getOrCreate($this->supplierId, 'client', $c, null, $this->userId));
        $projectLink = $this->trackLink($this->service->getOrCreate($this->supplierId, 'project', $c, $p, $this->userId));
        $token = $this->verifiedSession($clientLink, 'hlavni@example.cz');
        self::assertFalse($this->service->validateSession($projectLink, $token));
        self::assertTrue($this->service->validateSession($clientLink, $token));
    }

    public function testPublicGetActionHonoursCookieSessionLifecycle(): void
    {
        $c = $this->client('hlavni@example.cz');
        $p = $this->project($c, ['zakazka@example.cz']);
        $this->draftInvoiceWithReport($c, $p, 'Výkaz zakázka', [
            ['description' => 'Práce A', 'hours' => 2.0, 'rate' => 1000.0],
        ]);
        $link = $this->trackLink($this->service->getOrCreate($this->supplierId, 'project', $c, $p, $this->userId));
        $token = $this->verifiedSession($link, 'zakazka@example.cz');

        $action = Bootstrap::buildContainer()->get(PublicWorkReportGetAction::class);
        $call = function (?string $cookie) use ($action, $link): array {
            $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/public/work-report/' . $link['token']);
            if ($cookie !== null) {
                $request = $request->withCookieParams([WorkReportLinkService::COOKIE_NAME => $cookie]);
            }
            $response = $action($request, new Response(), ['token' => (string) $link['token']]);
            self::assertSame(200, $response->getStatusCode());
            return (array) json_decode((string) $response->getBody(), true);
        };

        $ok = $call($token);
        self::assertFalse($ok['requires_auth'], 'Ověřený klient vidí náhled');
        self::assertSame('Výkaz zakázka', $ok['preview']['reports'][0]['title']);

        self::assertTrue($call(null)['requires_auth'], 'Bez cookie je potřeba kód');

        $this->db->pdo()->prepare('DELETE FROM project_billing_emails WHERE project_id = ?')->execute([$p]);
        $denied = $call($token);
        self::assertTrue($denied['requires_auth'], 'Po odebrání e-mailu náhled vyžaduje nové ověření');
        self::assertArrayNotHasKey('preview', $denied);
    }

    public function testVerifyCodeRejectsForeignEmail(): void
    {
        $c = $this->client('hlavni@example.cz');
        $link = $this->trackLink($this->service->getOrCreate($this->supplierId, 'client', $c, null, $this->userId));
        // I kdyby útočník znal kód jiné adresy, jeho e-mail není povolený.
        self::assertNull($this->service->verifyCode($link, 'cizi@nikdo.cz', '123456', '127.0.0.1'));
    }

    // ── náhled ───────────────────────────────────────────────────────────

    public function testBuildPreviewClientScopeAggregates(): void
    {
        $c = $this->client('hlavni@example.cz');
        $p = $this->project($c);
        // 2 draft faktury s výkazem (1 na zakázce, 1 bez) + 1 issued (musí vypadnout).
        $this->draftInvoiceWithReport($c, $p, 'Výkaz zakázka', [
            ['description' => 'Práce A', 'hours' => 2.0, 'rate' => 1000.0, 'work_date' => '2026-06-02'],
            ['description' => 'Práce B', 'hours' => 1.0, 'rate' => 1000.0],
        ]);
        $this->draftInvoiceWithReport($c, null, 'Výkaz mimo zakázku', [
            ['description' => 'Práce C', 'hours' => 0.5, 'rate' => 1200.0],
        ]);
        $this->draftInvoiceWithReport($c, $p, 'Vystavený výkaz', [
            ['description' => 'Hotovo', 'hours' => 5.0, 'rate' => 1000.0],
        ], status: 'issued');

        $clientLink = $this->trackLink($this->service->getOrCreate($this->supplierId, 'client', $c, null, $this->userId));
        $preview = $this->service->buildPreview($clientLink);

        self::assertCount(2, $preview['reports'], 'Jen 2 draft výkazy (issued vyřazen)');
        self::assertEqualsWithDelta(3.5, $preview['total_hours'], 0.001);
        self::assertSame('WRL Test klient', $preview['client_company_name']);

        $totals = $preview['totals_by_currency'];
        self::assertCount(1, $totals);
        // 2*1000 + 1*1000 + 0.5*1200 = 3600
        self::assertEqualsWithDelta(3600.0, $totals[0]['total_amount'], 0.01);
        self::assertSame('CZK', $totals[0]['currency']);
    }

    public function testBuildPreviewProjectScopeFiltersToProject(): void
    {
        $c = $this->client('hlavni@example.cz');
        $p = $this->project($c);
        $this->draftInvoiceWithReport($c, $p, 'Výkaz zakázka', [
            ['description' => 'Práce A', 'hours' => 2.0, 'rate' => 1000.0],
        ]);
        $this->draftInvoiceWithReport($c, null, 'Výkaz mimo zakázku', [
            ['description' => 'Práce C', 'hours' => 9.0, 'rate' => 1000.0],
        ]);

        $projectLink = $this->trackLink($this->service->getOrCreate($this->supplierId, 'project', $c, $p, $this->userId));
        $preview = $this->service->buildPreview($projectLink);

        self::assertCount(1, $preview['reports'], 'Project scope ukáže jen výkazy té zakázky');
        self::assertSame('Výkaz zakázka', $preview['reports'][0]['title']);
        self::assertEqualsWithDelta(2.0, $preview['total_hours'], 0.001);
        self::assertSame('WRL Test zakázka', $preview['project_name']);
    }
}
