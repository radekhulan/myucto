<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PayrollDocumentsApiContractTest extends TestCase
{
    public function testRoutesExposeDocumentListBundleAndHeaderTokenDownload(): void
    {
        $routes = $this->read('api/src/Routes.php');

        self::assertStringContainsString(
            "\$g->get('/documents', [PayrollDocumentAction::class, 'list']);",
            $routes,
        );
        self::assertStringContainsString(
            "'/runs/{runId:[0-9]+}/revisions/{revisionId:[0-9]+}/documents/monthly-bundle'",
            $routes,
        );
        self::assertStringContainsString(
            "\$g->get('/documents/annual', [PayrollDocumentAction::class, 'listAnnual']);",
            $routes,
        );
        self::assertStringContainsString(
            "'/people/{employeeId:[0-9]+}/documents/payroll-sheet/{year:[0-9]{4}}'",
            $routes,
        );
        self::assertStringContainsString(
            "'/people/{employeeId:[0-9]+}/documents/tax-certificate/{kind:advance|withholding}/{year:[0-9]{4}}'",
            $routes,
        );
        self::assertStringContainsString(
            "[AnnualTaxCertificateAction::class, 'generate']",
            $routes,
        );
        self::assertStringContainsString(
            "'/documents/{documentId:[0-9]+}/download-grant'",
            $routes,
        );
        self::assertStringContainsString(
            "'/documents/{documentId:[0-9]+}/download'",
            $routes,
        );
        self::assertStringContainsString(
            "'/documents/{documentId:[0-9]+}/delivery-events'",
            $routes,
        );
    }

    public function testSessionOnlyPayrollDocumentsStayOutsideBearerOpenApi(): void
    {
        $openApi = $this->read('api/openapi.yaml');

        self::assertStringNotContainsString('/api/v1/payroll/', $openApi);
    }

    public function testTaxCertificateGenerationHasExactWritePermission(): void
    {
        $map = $this->read('api/src/Security/RoutePermissionMap.php');

        self::assertStringContainsString(
            "#^/api/payroll/people/[0-9]+/documents/tax-certificate/(advance|withholding)/[0-9]{4}$#",
            $map,
        );
        self::assertStringContainsString(
            "'payroll.documents', AccessLevel::WRITE",
            $map,
        );
    }

    public function testDownloadTokenNeverTravelsInQueryString(): void
    {
        $action = $this->read('api/src/Action/Payroll/PayrollDocumentAction.php');
        self::assertStringContainsString(
            "getHeaderLine('X-Payroll-Download-Token')",
            $action,
        );
        self::assertStringNotContainsString(
            "getQueryParams()['token']",
            $action,
        );
        self::assertStringContainsString(
            "'Cache-Control', 'private, no-store'",
            $action,
        );
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertIsString($contents);
        return $contents;
    }
}
