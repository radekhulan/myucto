<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PayrollSubmissionArtifactDownloadContractTest extends TestCase
{
    public function testArtifactDownloadUsesScopedOneTimeGrant(): void
    {
        $migration = $this->read(
            'db/migrations/1287_payroll_submission_artifact_download_grants.sql',
        );

        self::assertStringContainsString(
            'payroll_submission_artifact_download_grants',
            $migration,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, environment, submission_id, artifact_id)',
            $migration,
        );
        self::assertStringContainsString('token_hash', $migration);
        self::assertStringContainsString('used_at', $migration);
    }

    public function testTokenStaysInHeaderAndEndpointsAreSessionOnly(): void
    {
        $routes = $this->read('api/src/Routes.php');
        $permissionMap = $this->read(
            'api/src/Security/RoutePermissionMap.php',
        );
        $action = $this->read(
            'api/src/Action/Payroll/PayrollSubmissionArtifactDownloadAction.php',
        );

        self::assertStringContainsString(
            '/artifacts/{artifactId:[0-9]+}/download-grant',
            $routes,
        );
        self::assertStringContainsString(
            '/artifacts/{artifactId:[0-9]+}/download',
            $routes,
        );
        self::assertStringContainsString(
            "getHeaderLine('X-Payroll-Download-Token')",
            $action,
        );
        self::assertStringNotContainsString(
            "getQueryParams()['token']",
            $action,
        );
        self::assertStringContainsString(
            // Brána se čte přes sdílený helper; doslovné ATTR_METHOD už v akcích není.
            '!RequestAuthorization::isSessionAuth($request)',
            $action,
        );
        self::assertStringContainsString(
            "#^/api/payroll/submissions/[0-9]+/artifacts/[0-9]+/download-grant$#', 'payroll.submissions', AccessLevel::READ",
            $permissionMap,
        );
        self::assertStringContainsString(
            "#^/api/payroll/submissions/[0-9]+/artifacts/[0-9]+/download$#', 'payroll.submissions', AccessLevel::READ",
            $permissionMap,
        );
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertIsString($contents);

        return $contents;
    }
}
