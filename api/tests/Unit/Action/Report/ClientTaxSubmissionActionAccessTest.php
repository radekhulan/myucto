<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Report;

use MyInvoice\Action\Report\EpoDirectSubmissionAction;
use MyInvoice\Action\Report\TaxSubmissionAction;
use MyInvoice\Action\Report\TaxSubmissionEpoAction;
use MyInvoice\Action\Report\TaxSubmissionIsdsAction;
use MyInvoice\Action\Submission\IsdsGatewayAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\IsdsGatewaySessionRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Security\ClientTaxSubmissionPolicy;
use MyInvoice\Service\License\CommercialFeatureAccess;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/** Klient smí pracovat jen s DPH/KH/SH; kontrola musí předcházet službám a step-upu. */
final class ClientTaxSubmissionActionAccessTest extends TestCase
{
    public static function scopedOperations(): iterable
    {
        foreach (['detail', 'payment', 'downloadXml', 'submit'] as $method) {
            yield 'archiv ' . $method => [TaxSubmissionAction::class, $method, 'repo'];
        }
        foreach (['handoff', 'uploadArtifacts', 'downloadArtifact'] as $method) {
            yield 'EPO ' . $method => [TaxSubmissionEpoAction::class, $method, 'submissions'];
        }
        foreach (['test', 'submit', 'refreshStatus', 'recoverConfirmation', 'revealStatePassword'] as $method) {
            yield 'přímé EPO ' . $method => [EpoDirectSubmissionAction::class, $method, 'submissionRepository'];
        }
        foreach (['enqueue', 'cancel', 'uploadReceipt'] as $method) {
            yield 'ISDS ' . $method => [TaxSubmissionIsdsAction::class, $method, 'submissions'];
        }
    }

    #[DataProvider('scopedOperations')]
    public function testIncomeSubmissionIsDeniedBeforeAnySideEffect(string $class, string $method, string $property): void
    {
        $action = $this->action($class);
        $repo = $this->createMock(TaxSubmissionRepository::class);
        $repo->expects(self::once())->method('find')->with(17, 9)->willReturn([
            'id' => 17, 'supplier_id' => 9, 'form_code' => 'dpfdp7',
            'validation_status' => 'passed', 'status' => 'downloaded',
        ]);
        // Další závislosti schválně nejsou inicializované: žádná se nesmí zavolat.
        if ((new \ReflectionClass($class))->hasProperty($property)) {
            (new \ReflectionProperty($class, $property))->setValue($action, $repo);
        }
        $response = $action->$method($this->request(), new Response(), ['id' => '17', 'artifactId' => '2', 'attemptId' => '3']);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    #[DataProvider('scopedOperations')]
    public function testMissingSubmissionInCurrentSupplierReturns404(string $class, string $method, string $property): void
    {
        $action = $this->action($class);
        $repo = $this->createMock(TaxSubmissionRepository::class);
        $repo->expects(self::once())->method('find')->with(17, 9)->willReturn(null);
        (new \ReflectionProperty($class, $property))->setValue($action, $repo);
        $response = $action->$method($this->request(), new Response(), ['id' => '17', 'artifactId' => '2', 'attemptId' => '3']);
        self::assertSame(404, $response->getStatusCode());
    }

    #[DataProvider('payrollArchiveOperations')]
    public function testPayrollEvidenceRemainsHiddenFromClientAfterMerge(string $method): void
    {
        $action = $this->action(TaxSubmissionAction::class);
        $repo = $this->createMock(TaxSubmissionRepository::class);
        $repo->expects(self::once())->method('find')->with(17, 9)->willReturn([
            'id' => 17, 'supplier_id' => 9, 'form_code' => 'dpzvd6',
        ]);
        (new \ReflectionProperty(TaxSubmissionAction::class, 'repo'))->setValue($action, $repo);
        $response = $action->$method($this->request(), new Response(), ['id' => '17']);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public static function payrollArchiveOperations(): iterable
    {
        foreach (['detail', 'payment', 'downloadXml'] as $method) {
            yield $method => [$method];
        }
    }

    public function testExportWriteStaffKeepsArchiveDeletionWithoutSubmissionWrite(): void
    {
        $request = $this->request()->withAttribute('auth.effective_role', new EffectiveRole(6, 'Archivář', 'staff', true, [
            'reports' => 1, 'reports.export' => 2,
        ]));
        self::assertTrue(RequestAuthorization::canDeleteTaxSubmission($request));
        self::assertFalse(RequestAuthorization::canManageTaxSubmissions($request));
    }

    public function testClientSettingsOnlyExposeEnvironmentWithoutReadingSharedFolders(): void
    {
        $action = $this->action(TaxSubmissionEpoAction::class);
        $config = $this->createMock(Config::class);
        $config->expects(self::once())->method('get')->with('epo_test', false)->willReturn(false);
        (new \ReflectionProperty(TaxSubmissionEpoAction::class, 'config'))->setValue($action, $config);
        $response = $action->settings($this->request(false), new Response());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['epo_environment' => 'production'], json_decode((string) $response->getBody(), true));
    }

    public function testReadOnlyClientCanReachOwnVatArtifactWithoutExportPermission(): void
    {
        $action = $this->action(TaxSubmissionEpoAction::class);
        $repo = $this->createMock(TaxSubmissionRepository::class);
        $repo->expects(self::once())->method('find')->with(17, 9)->willReturn([
            'id' => 17, 'supplier_id' => 9, 'form_code' => 'dphkh1',
        ]);
        $epo = $this->createMock(TaxSubmissionEpoRepository::class);
        $epo->expects(self::once())->method('artifact')->with(2, 17, 9)->willReturn(null);
        (new \ReflectionProperty(TaxSubmissionEpoAction::class, 'submissions'))->setValue($action, $repo);
        (new \ReflectionProperty(TaxSubmissionEpoAction::class, 'epo'))->setValue($action, $epo);
        $response = $action->downloadArtifact($this->request(false), new Response(), ['id' => '17', 'artifactId' => '2']);
        self::assertSame(404, $response->getStatusCode());
    }

    public static function administrativeOperations(): iterable
    {
        yield 'smazat snapshot' => [TaxSubmissionAction::class, 'delete'];
        yield 'nastavit složky EPO' => [TaxSubmissionEpoAction::class, 'updateSettings'];
        yield 'globálně smazat certifikát' => [EpoDirectSubmissionAction::class, 'deleteCredential'];
        yield 'vynutit uvolnění EPO' => [EpoDirectSubmissionAction::class, 'resolveAsNotSubmitted'];
        yield 'nastavit směrování ISDS' => [TaxSubmissionIsdsAction::class, 'saveRoutes'];
        yield 'vypsat korespondenci FÚ' => [TaxSubmissionIsdsAction::class, 'inboxCandidates'];
        yield 'připojit korespondenci FÚ' => [TaxSubmissionIsdsAction::class, 'linkInbox'];
    }

    #[DataProvider('administrativeOperations')]
    public function testClientCannotManageSharedConfigurationOrUnrelatedCorrespondence(string $class, string $method): void
    {
        $response = $this->action($class)->$method($this->request(), new Response(), ['id' => '17', 'attemptId' => '3']);
        self::assertSame(403, $response->getStatusCode());
    }

    public static function evidenceOperations(): iterable
    {
        yield 'uvolnění předání' => [EpoDirectSubmissionAction::class, 'resolveAsNotSubmitted'];
        yield 'korespondence FÚ' => [TaxSubmissionIsdsAction::class, 'inboxCandidates'];
        yield 'připojení korespondence' => [TaxSubmissionIsdsAction::class, 'linkInbox'];
    }

    #[DataProvider('evidenceOperations')]
    public function testSettingsManagementDoesNotGrantStaffSubmissionEvidenceAccess(string $class, string $method): void
    {
        $request = $this->request()->withAttribute('auth.effective_role', new EffectiveRole(6, 'Správce nastavení', 'staff', true, [
            'reports' => 1, 'reports.submissions.manage' => 2,
        ]));
        self::assertTrue(RequestAuthorization::canManageTaxSubmissions($request));
        self::assertFalse(RequestAuthorization::canManageTaxSubmissionEvidence($request));
        $response = $this->action($class)->$method($request, new Response(), ['id' => '17', 'attemptId' => '3']);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testReadOnlyClientCanDownloadVatXmlWithoutReportExportPermission(): void
    {
        $action = $this->action(TaxSubmissionAction::class);
        $repo = $this->createMock(TaxSubmissionRepository::class);
        $repo->expects(self::once())->method('find')->with(17, 9)->willReturn([
            'id' => 17, 'supplier_id' => 9, 'form_code' => 'dphdp3',
            'period_year' => 2025, 'period_month' => 1, 'xml_content' => '<Pisemnost/>',
        ]);
        (new \ReflectionProperty(TaxSubmissionAction::class, 'repo'))->setValue($action, $repo);
        $response = $action->downloadXml($this->request(false), new Response(), ['id' => '17']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<Pisemnost/>', (string) $response->getBody());
    }

    public static function archiveFilters(): iterable
    {
        yield 'všechny formuláře' => [[]];
        yield 'podvržená daň z příjmů' => [['form_code' => 'dpfdp7']];
        yield 'podvržený rozsah mezd' => [['scope' => 'payroll']];
    }

    #[DataProvider('archiveFilters')]
    public function testClientFormRestrictionReachesEveryRepositoryProjection(array $query): void
    {
        $action = $this->action(TaxSubmissionAction::class);
        $repo = $this->createMock(TaxSubmissionRepository::class);
        $filters = self::callback(static function (array $filters) use ($query): bool {
            self::assertSame(ClientTaxSubmissionPolicy::FORM_CODES, $filters['allowed_form_codes']);
            self::assertSame($query['form_code'] ?? '', $filters['form_code']);
            self::assertSame(($query['scope'] ?? '') === 'payroll' ? ['dpzvd6', 'dpsvd2'] : null, $filters['scope_form_codes']);
            return true;
        });
        $repo->expects(self::once())->method('list')->with(9, $filters, 50, 0)->willReturn([]);
        $repo->expects(self::once())->method('countList')->with(9, $filters)->willReturn(0);
        $repo->expects(self::once())->method('listStats')->with(9, $filters)->willReturn(['total' => 0]);
        $repo->expects(self::once())->method('listFormCodes')->with(9, $filters)->willReturn([]);
        $epo = $this->createMock(TaxSubmissionEpoRepository::class);
        $epo->expects(self::once())->method('enrich')->with([], 9)->willReturn([]);
        (new \ReflectionProperty(TaxSubmissionAction::class, 'repo'))->setValue($action, $repo);
        (new \ReflectionProperty(TaxSubmissionAction::class, 'epo'))->setValue($action, $epo);
        // Bez inicializované licence je její stávající fail-open fallback dostupný;
        // klientský rozsah se přesto musí uplatnit nezávisle na komerční licenci.
        (new \ReflectionProperty(TaxSubmissionAction::class, 'commercial'))->setValue($action, $this->action(CommercialFeatureAccess::class));
        $response = $action->list($this->request(false)->withQueryParams($query), new Response());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], json_decode((string) $response->getBody(), true)['data']);
    }

    public static function gatewayOperations(): iterable
    {
        yield 'zahájení brány' => ['taxStart'];
        yield 'návrat z brány' => ['taxComplete'];
    }

    #[DataProvider('gatewayOperations')]
    public function testGatewayVerifiesUnderlyingSnapshotInsteadOfTrustingOutboxAgenda(string $method): void
    {
        $action = $this->action(IsdsGatewayAction::class);
        $outboxRow = [
            'id' => 25, 'supplier_id' => 9, 'artifact_id' => 17, 'row_version' => 1,
            'artifact_kind' => 'tax_submission', 'agenda_code' => 'dphdp3',
            'recipient_id' => null, 'confirmed_by' => null, 'created_by' => null,
            'receipt_document_id' => null, 'receipt_inbox_message_id' => null,
        ];
        $outbox = new SubmissionOutboxRepository($this->repositoryConnection($outboxRow, [9, 25]));
        (new \ReflectionProperty(IsdsGatewayAction::class, 'outbox'))->setValue($action, $outbox);
        $repo = $this->createMock(TaxSubmissionRepository::class);
        $repo->expects(self::once())->method('find')->with(17, 9)->willReturn([
            'id' => 17, 'supplier_id' => 9, 'form_code' => 'dpfdp7',
        ]);
        (new \ReflectionProperty(IsdsGatewayAction::class, 'taxSubmissions'))->setValue($action, $repo);
        $request = $this->request();
        if ($method === 'taxComplete') {
            $sessions = new IsdsGatewaySessionRepository($this->repositoryConnection([
                'id' => 11, 'supplier_id' => 9, 'outbox_id' => 25, 'user_id' => 4, 'row_version' => 1,
            ], ['synthetic-app-token']));
            (new \ReflectionProperty(IsdsGatewayAction::class, 'sessions'))->setValue($action, $sessions);
            $request = $request->withParsedBody(['app_token' => 'synthetic-app-token', 'session_id' => 'synthetic-session']);
        }
        // Dispatch není inicializovaný: nesmí nastat ani první vzdálené volání.
        $response = $action->$method($request, new Response(), ['id' => '25']);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('tax_gateway_outbox_forbidden', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    private function repositoryConnection(array $row, array $arguments): Connection
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with($arguments)->willReturn(true);
        $statement->expects(self::once())->method('fetch')->with(\PDO::FETCH_ASSOC)->willReturn($row);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $db = $this->createStub(Connection::class);
        $db->method('hasTable')->willReturn(true);
        $db->method('pdo')->willReturn($pdo);
        return $db;
    }

    private function action(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function request(bool $write = true): ServerRequestInterface
    {
        // I podvržená historická široká práva nesmí klienta pustit mimo klientský rozsah.
        $permissions = $write ? ['reports' => 2, 'reports.export' => 2, 'reports.submit' => 2] : [];
        $permissions['reports.submissions'] = 1;
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/reports/submissions/17')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 4])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 9)
            ->withAttribute('auth.effective_role', new EffectiveRole(8, 'Klient podání', 'client', true, $permissions));
    }
}
