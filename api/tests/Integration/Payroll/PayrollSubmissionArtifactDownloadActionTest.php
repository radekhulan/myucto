<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollSubmissionArtifactDownloadAction;
use MyInvoice\Action\Payroll\PayrollSubmissionDetailAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionArtifactDownloadService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollSubmissionArtifactDownloadActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollSubmissionArtifactDownloadAction $action;
    private PayrollSubmissionDetailAction $detailAction;
    private PayrollSubmissionArtifactDownloadService $downloads;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        if (!$this->db->hasTable(
            'payroll_submission_artifact_download_grants',
        )) {
            self::fail('Migrace 1287 neproběhla.');
        }
        $action = $container->get(
            PayrollSubmissionArtifactDownloadAction::class,
        );
        self::assertInstanceOf(
            PayrollSubmissionArtifactDownloadAction::class,
            $action,
        );
        $this->action = $action;
        $detailAction = $container->get(
            PayrollSubmissionDetailAction::class,
        );
        self::assertInstanceOf(
            PayrollSubmissionDetailAction::class,
            $detailAction,
        );
        $this->detailAction = $detailAction;
        $downloads = $container->get(
            PayrollSubmissionArtifactDownloadService::class,
        );
        self::assertInstanceOf(
            PayrollSubmissionArtifactDownloadService::class,
            $downloads,
        );
        $this->downloads = $downloads;
        $obligations = $container->get(
            PayrollObligationService::class,
        );
        self::assertInstanceOf(PayrollObligationService::class, $obligations);
        $this->obligations = $obligations;
        $submissions = $container->get(
            PayrollSubmissionService::class,
        );
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        $this->submissions = $submissions;

        $pdo = $this->db->pdo();
        $sourceSupplierId = $this->firstInteger(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        );
        $this->userId = $this->firstInteger(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        );
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)',
        )->execute([$this->supplierId, $this->otherSupplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testOneTimeTenantAndUserBoundGrantReturnsExactStoredBytes(): void
    {
        $storedBytes = '<synthetic-download>presne-bajty</synthetic-download>';
        $aggregate = $this->artifact($this->supplierId, $storedBytes);
        $args = [
            'submissionId' => (string) $aggregate['submission_id'],
            'artifactId' => (string) $aggregate['artifact_id'],
        ];

        $foreignGrant = $this->action->grant(
            $this->request(
                $this->otherSupplierId,
                'session',
                'POST',
            )->withParsedBody(['ttl_seconds' => 60]),
            new Response(),
            $args,
        );
        self::assertSame(404, $foreignGrant->getStatusCode());

        $grantResponse = $this->action->grant(
            $this->request(
                $this->supplierId,
                'session',
                'POST',
            )->withParsedBody(['ttl_seconds' => 60]),
            new Response(),
            $args,
        );
        self::assertSame(201, $grantResponse->getStatusCode());
        self::assertSame(
            'private, no-store',
            $grantResponse->getHeaderLine('Cache-Control'),
        );
        self::assertSame(
            'no-cache',
            $grantResponse->getHeaderLine('Pragma'),
        );
        $grant = $this->json($grantResponse);
        self::assertSame(
            $aggregate['submission_id'],
            $grant['submission_id'],
        );
        self::assertSame($aggregate['artifact_id'], $grant['artifact_id']);
        self::assertIsString($grant['token']);
        self::assertMatchesRegularExpression(
            '/^[A-Za-z0-9_-]{43}$/D',
            $grant['token'],
        );
        $grantJson = json_encode($grant, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('sha256', $grantJson);
        self::assertStringNotContainsString('ciphertext', $grantJson);
        self::assertStringNotContainsString($storedBytes, $grantJson);

        $hashStatement = $this->db->pdo()->prepare(
            'SELECT HEX(token_hash)
               FROM payroll_submission_artifact_download_grants
              WHERE id = ?',
        );
        $hashStatement->execute([$grant['grant_id']]);
        $storedTokenHash = $hashStatement->fetchColumn();
        self::assertIsString($storedTokenHash);
        self::assertNotSame($grant['token'], $storedTokenHash);

        foreach ([
            [$this->otherSupplierId, $this->userId],
            [$this->supplierId, $this->userId + 1_000_000],
        ] as [$supplierId, $userId]) {
            $denied = $this->action->download(
                $this->request(
                    $supplierId,
                    'session',
                    'GET',
                    $userId,
                )->withHeader(
                    'X-Payroll-Download-Token',
                    $grant['token'],
                ),
                new Response(),
                $args,
            );
            self::assertSame(404, $denied->getStatusCode());
        }
        $wrongSubmission = $this->action->download(
            $this->request(
                $this->supplierId,
                'session',
                'GET',
            )->withHeader(
                'X-Payroll-Download-Token',
                $grant['token'],
            ),
            new Response(),
            [
                'submissionId' => (string) (
                    $aggregate['submission_id'] + 1
                ),
                'artifactId' => (string) $aggregate['artifact_id'],
            ],
        );
        self::assertSame(404, $wrongSubmission->getStatusCode());

        $download = $this->action->download(
            $this->request(
                $this->supplierId,
                'session',
                'GET',
            )->withHeader(
                'X-Payroll-Download-Token',
                $grant['token'],
            ),
            new Response(),
            $args,
        );
        self::assertSame(200, $download->getStatusCode());
        self::assertSame($storedBytes, (string) $download->getBody());
        self::assertSame(
            (string) strlen($storedBytes),
            $download->getHeaderLine('Content-Length'),
        );
        self::assertSame(
            'application/xml',
            $download->getHeaderLine('Content-Type'),
        );
        self::assertStringStartsWith(
            'attachment; filename="JMHZ_2026-08_CSSZ',
            $download->getHeaderLine('Content-Disposition'),
        );
        self::assertSame(
            'private, no-store',
            $download->getHeaderLine('Cache-Control'),
        );
        self::assertSame('no-cache', $download->getHeaderLine('Pragma'));
        self::assertSame(
            'nosniff',
            $download->getHeaderLine('X-Content-Type-Options'),
        );
        self::assertSame(
            "default-src 'none'; sandbox",
            $download->getHeaderLine('Content-Security-Policy'),
        );

        $reused = $this->action->download(
            $this->request($this->supplierId, 'session', 'GET')
                ->withHeader(
                    'X-Payroll-Download-Token',
                    $grant['token'],
                ),
            new Response(),
            $args,
        );
        self::assertSame(404, $reused->getStatusCode());

        $audit = $this->auditRows($aggregate['artifact_id']);
        self::assertSame([
            'payroll.submission_artifact_download_grant_issued',
            'payroll.submission_artifact_downloaded',
        ], array_column($audit, 'action'));
        $auditJson = json_encode($audit, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($grant['token'], $auditJson);
        self::assertStringNotContainsString('artifact_sha256', $auditJson);
        self::assertStringNotContainsString('content_ciphertext', $auditJson);
        self::assertStringNotContainsString($storedBytes, $auditJson);
    }

    public function testBearerIsRejectedAndMetadataNeverLeaksArchivedContent(): void
    {
        $storedBytes = '<synthetic-secret>never-list-this</synthetic-secret>';
        $aggregate = $this->artifact($this->supplierId, $storedBytes);
        $args = [
            'submissionId' => (string) $aggregate['submission_id'],
            'artifactId' => (string) $aggregate['artifact_id'],
        ];
        $bearer = $this->action->grant(
            $this->request($this->supplierId, 'bearer', 'POST'),
            new Response(),
            $args,
        );
        self::assertSame(403, $bearer->getStatusCode());
        $bearerBody = $this->json($bearer);
        $bearerError = $bearerBody['error'] ?? null;
        self::assertIsArray($bearerError);
        self::assertSame(
            'session_required',
            $bearerError['code'] ?? null,
        );

        $detail = ($this->detailAction)(
            $this->request($this->supplierId, 'session', 'GET'),
            new Response(),
            ['submissionId' => (string) $aggregate['submission_id']],
        );
        self::assertSame(200, $detail->getStatusCode());
        $metadata = $this->json($detail);
        $artifacts = $metadata['artifacts'] ?? null;
        self::assertIsArray($artifacts);
        self::assertCount(1, $artifacts);
        $artifact = $artifacts[0] ?? null;
        self::assertIsArray($artifact);
        self::assertArrayNotHasKey(
            'artifact_sha256',
            $artifact,
        );
        self::assertArrayNotHasKey(
            'content_ciphertext',
            $artifact,
        );
        self::assertStringNotContainsString(
            $storedBytes,
            json_encode($metadata, JSON_THROW_ON_ERROR),
        );
    }

    public function testAuditFailureRollsBackGrantAndConsumption(): void
    {
        $aggregate = $this->artifact(
            $this->supplierId,
            '<synthetic-audit-rollback/>',
        );
        try {
            $this->downloads->issue(
                $this->supplierId,
                $aggregate['submission_id'],
                $aggregate['artifact_id'],
                $this->userId,
                60,
                static function (): void {
                    throw new \RuntimeException('synthetic audit failure');
                },
            );
            self::fail('Selhání auditu musí vrátit vydání grantu.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'synthetic audit failure',
                $exception->getMessage(),
            );
        }
        self::assertSame(0, $this->grantCount($aggregate['artifact_id']));

        $grant = $this->downloads->issue(
            $this->supplierId,
            $aggregate['submission_id'],
            $aggregate['artifact_id'],
            $this->userId,
            60,
        );
        try {
            $this->downloads->consume(
                $this->supplierId,
                $aggregate['submission_id'],
                $aggregate['artifact_id'],
                $this->userId,
                $grant['token'],
                static function (): void {
                    throw new \RuntimeException('synthetic audit failure');
                },
            );
            self::fail('Selhání auditu musí vrátit spotřebu grantu.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'synthetic audit failure',
                $exception->getMessage(),
            );
        }

        $download = $this->downloads->consume(
            $this->supplierId,
            $aggregate['submission_id'],
            $aggregate['artifact_id'],
            $this->userId,
            $grant['token'],
        );
        self::assertSame(
            '<synthetic-audit-rollback/>',
            $download['bytes'],
        );
    }

    /** @return array{submission_id:int,artifact_id:int} */
    private function artifact(int $supplierId, string $bytes): array
    {
        $suffix = bin2hex(random_bytes(6));
        $obligation = $this->obligations->register(
            $supplierId,
            'JMHZ',
            'office',
            'office:synthetic-' . $suffix,
            '2026-08-01',
            '2026-08-31',
            'regular',
            'manual_upload',
            'payroll_run_approved',
            'run:synthetic-' . $suffix,
            hash('sha256', 'source-' . $suffix),
            '2026-09-01',
            '2026-09-20',
            'calendar_days',
            'artifact-download-ruleset',
            hash('sha256', 'ruleset-' . $suffix),
            'artifact-download-obligation-' . $suffix,
            environment: 'production',
        );
        $submission = $this->submissions->prepare(
            $supplierId,
            $obligation['id'],
            'regular',
            'manual_upload',
            hash('sha256', 'submission-' . $suffix),
            'artifact-download-submission-' . $suffix,
            createdBy: $this->userId,
        );
        $artifact = $this->submissions->storeArtifact(
            $supplierId,
            $submission['id'],
            $submission['row_version'],
            null,
            'outbound_xml',
            'outbound',
            'application/xml',
            $bytes,
            'synthetic-xsd',
            'synthetic-catalog',
            'manual_upload',
            'artifact-download-artifact-' . $suffix,
            $this->userId,
        );

        return [
            'submission_id' => $submission['id'],
            'artifact_id' => $artifact['id'],
        ];
    }

    private function request(
        int $supplierId,
        string $authMethod,
        string $method,
        ?int $userId = null,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest(
                $method,
                '/api/payroll/submissions/1/artifacts/1/download',
                ['REMOTE_ADDR' => '127.0.0.1'],
            )
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                [
                    'id' => $userId ?? $this->userId,
                    'role' => 'accountant',
                ],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode(
            (string) $response->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        $result = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** @return list<array{action:string,payload:?string}> */
    private function auditRows(int $artifactId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT action, payload
               FROM activity_log
              WHERE supplier_id = ?
                AND entity_type = "payroll_submission_artifact"
                AND entity_id = ?
              ORDER BY id',
        );
        $statement->execute([$this->supplierId, $artifactId]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                self::fail('Auditní řádek nemá asociativní strukturu.');
            }
            $normalized = [];
            foreach ($row as $key => $value) {
                if (is_string($key)) {
                    $normalized[$key] = $value;
                }
            }
            $action = $normalized['action'] ?? null;
            $payload = $normalized['payload'] ?? null;
            self::assertIsString($action);
            self::assertTrue($payload === null || is_string($payload));
            $result[] = [
                'action' => $action,
                'payload' => $payload,
            ];
        }

        return $result;
    }

    private function grantCount(int $artifactId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_submission_artifact_download_grants
              WHERE supplier_id = ? AND artifact_id = ?',
        );
        $statement->execute([$this->supplierId, $artifactId]);

        return (int) $statement->fetchColumn();
    }

    private function firstInteger(string $sql): int
    {
        $statement = $this->db->pdo()->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $value = $statement->fetchColumn();
        if (!is_int($value) && !is_string($value)) {
            return 0;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) ? $integer : 0;
    }
}
