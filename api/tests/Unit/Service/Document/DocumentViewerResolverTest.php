<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Document;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Document\DocumentViewerResolver;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DocumentViewerResolverTest extends TestCase
{
    public function testAdminContextDoesNotImplicitlyGrantSensitiveEvidence(): void
    {
        self::assertFalse(DocumentViewerContext::admin(7)->canViewPayrollEnforcementEvidence);
        self::assertFalse(DocumentViewerContext::fromRole('admin', 7)->canViewPayrollEnforcementEvidence);
        self::assertTrue(DocumentViewerContext::admin(7, true)->canViewPayrollEnforcementEvidence);
    }

    public function testBackgroundJobRoundTripPreservesBothVisibilityAxesFailClosed(): void
    {
        $authorizedStaff = DocumentViewerContext::forUser(7, true);
        $restoredStaff = DocumentViewerContext::fromJobParams($authorizedStaff->toJobParams(), 7);
        self::assertFalse($restoredStaff->isAdmin);
        self::assertTrue($restoredStaff->canViewPayrollEnforcementEvidence);

        $restrictedAdmin = DocumentViewerContext::admin(8, false);
        $restoredAdmin = DocumentViewerContext::fromJobParams($restrictedAdmin->toJobParams(), 8);
        self::assertTrue($restoredAdmin->isAdmin);
        self::assertFalse($restoredAdmin->canViewPayrollEnforcementEvidence);

        $legacyJob = DocumentViewerContext::fromJobParams(['viewer_is_admin' => true], 9);
        self::assertTrue($legacyJob->isAdmin);
        self::assertFalse($legacyJob->canViewPayrollEnforcementEvidence);
    }

    public function testSensitiveEvidenceRequiresSessionAndPayrollPermission(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/documents/1')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 7])
            ->withAttribute('auth.effective_role', new EffectiveRole(
                91,
                'Mzdová účetní',
                'staff',
                true,
                ['documents' => 1, 'payroll.enforcement' => 1],
            ));

        $session = DocumentViewerResolver::fromRequest(
            $request->withAttribute(AuthMiddleware::ATTR_METHOD, 'session'),
        );
        self::assertSame(7, $session->userId);
        self::assertTrue($session->canViewPayrollEnforcementEvidence);

        $bearer = DocumentViewerResolver::fromRequest(
            $request->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'),
        );
        self::assertFalse($bearer->canViewPayrollEnforcementEvidence);

        $documentsOnly = DocumentViewerResolver::fromRequest(
            $request
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withAttribute('auth.effective_role', new EffectiveRole(
                    92,
                    'Dokumenty bez mezd',
                    'staff',
                    true,
                    ['documents' => 1],
                )),
        );
        self::assertFalse($documentsOnly->canViewPayrollEnforcementEvidence);

        $numericString = DocumentViewerResolver::fromRequest(
            $request
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => '8'])
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session'),
        );
        self::assertSame(8, $numericString->userId);
    }
}
