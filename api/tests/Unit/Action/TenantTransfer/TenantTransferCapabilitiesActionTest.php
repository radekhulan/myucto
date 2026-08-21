<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\TenantTransfer;

use MyInvoice\Action\TenantTransfer\TenantTransferCapabilitiesAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\TenantTransferGrantMiddleware;
use MyInvoice\Service\TenantTransfer\Capabilities\TenantTransferCapabilitiesService;
use MyInvoice\Service\TenantTransfer\Compatibility\ApplicationVersionProvider;
use MyInvoice\Service\TenantTransfer\Compatibility\BuildRevisionProvider;
use MyInvoice\Service\TenantTransfer\Compatibility\CompatibilityFingerprintFactory;
use MyInvoice\Service\TenantTransfer\Compatibility\CompatibilityProfileRegistry;
use MyInvoice\Service\TenantTransfer\Compatibility\InstanceFingerprintProvider;
use MyInvoice\Service\TenantTransfer\Fingerprint\MigrationSetFingerprint;
use MyInvoice\Service\TenantTransfer\Fingerprint\MigrationSetStateProvider;
use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaFingerprintProvider;
use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaMetadataSource;
use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class TenantTransferCapabilitiesActionTest extends TestCase
{
    public function testOpenApiPublishesDedicatedGrantOnlyContractOnce(): void
    {
        $yaml = file_get_contents(dirname(__DIR__, 4) . '/openapi.yaml');
        self::assertIsString($yaml);

        self::assertSame(
            1,
            substr_count($yaml, '  /api/tenant-transfer/v1/capabilities:'),
        );
        self::assertSame(1, substr_count($yaml, '    TenantTransferCapabilities:'));
        self::assertSame(1, substr_count($yaml, '    TenantTransferGrantAuth:'));
        self::assertStringContainsString('name: X-MyUcto-Transfer-Grant', $yaml);
        self::assertStringContainsString('- TenantTransferGrantAuth: []', $yaml);
    }

    public function testRequiresMiddlewareValidatedGrantEvenWhenFeatureIsEnabled(): void
    {
        $action = $this->action(enabled: true);

        $response = $action(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                '/api/tenant-transfer/v1/capabilities',
            ),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('transfer_authorization_required', self::errorCode($response));
    }

    public function testDraftRegistryReturnsStableFailClosedReadinessReason(): void
    {
        $action = $this->action(enabled: true);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/tenant-transfer/v1/capabilities')
            ->withAttribute(TenantTransferGrantMiddleware::ATTR_GRANT, [
                'supplier_id' => 3,
            ]);

        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = self::decode($response);
        $error = $body['error'] ?? null;
        self::assertIsArray($error);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('source_not_ready', $error['code'] ?? null);
        self::assertSame('tenant_registry_incomplete', $error['reason'] ?? null);
    }

    public function testDisabledFeatureIsHiddenBeforeGrantValidation(): void
    {
        $response = ($this->action(enabled: false))(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                '/api/tenant-transfer/v1/capabilities',
            ),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('transfer_api_disabled', self::errorCode($response));
    }

    private function action(bool $enabled): TenantTransferCapabilitiesAction
    {
        $config = new Config(['tenant_transfer' => ['enabled' => $enabled]]);
        $connection = new Connection($config);
        $missingRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'myucto-tenant-transfer-missing';
        $schemaSource = new class implements TenantSchemaMetadataSource {
            public function inventory(): array
            {
                return [new TenantSchemaTableInventory(
                    'supplier',
                    'BASE TABLE',
                    ['id'],
                    ['id'],
                    [],
                )];
            }

            public function describe(array $tableNames): array
            {
                throw new \LogicException('Neúplný registr nesmí načítat schema metadata.');
            }
        };
        $service = new TenantTransferCapabilitiesService(
            new ApplicationVersionProvider($missingRoot . DIRECTORY_SEPARATOR . 'VERSION'),
            new BuildRevisionProvider(
                $config,
                $missingRoot . DIRECTORY_SEPARATOR . 'BUILD_REVISION',
            ),
            new MigrationSetStateProvider($connection, new MigrationSetFingerprint()),
            TenantDataRegistryFactory::draftV1(),
            new TenantSchemaFingerprintProvider($schemaSource),
            new CompatibilityFingerprintFactory(),
            new InstanceFingerprintProvider($config),
            CompatibilityProfileRegistry::v1(),
        );
        return new TenantTransferCapabilitiesAction($config, $service);
    }

    private static function errorCode(ResponseInterface $response): string
    {
        $body = self::decode($response);
        $error = $body['error'] ?? null;
        self::assertIsArray($error);
        $code = $error['code'] ?? null;
        self::assertIsString($code);
        return $code;
    }

    /** @return array<string,mixed> */
    private static function decode(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $result = [];
        foreach ($body as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }
        return $result;
    }
}
