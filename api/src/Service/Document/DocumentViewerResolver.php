<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DocumentViewerResolver
{
    public static function fromRequest(Request $request): DocumentViewerContext
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = self::userId($user['id'] ?? null);
        $isSession = $request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'session';
        $canViewPayrollEnforcementEvidence =
            $isSession
            && RequestAuthorization::allows(
                $request,
                'payroll.enforcement',
                AccessLevel::READ,
            );
        $canViewPayrollInsolvencyEvidence =
            $canViewPayrollEnforcementEvidence
            && RequestAuthorization::allows(
                $request,
                'payroll.insolvency',
                AccessLevel::READ,
            );
        $canViewPayrollSubmissionEvidence =
            $isSession
            && RequestAuthorization::allows(
                $request,
                'payroll.submissions',
                AccessLevel::READ,
            );
        $canViewPayrollForeignPermitEvidence =
            $isSession
            && RequestAuthorization::allows(
                $request,
                'payroll',
                AccessLevel::READ,
            );
        $canViewPayrollHealthEvidence =
            $isSession
            && RequestAuthorization::allows(
                $request,
                'payroll.health_evidence',
                AccessLevel::READ,
            );
        $canViewPayrollDocuments =
            $isSession
            && RequestAuthorization::allows(
                $request,
                'payroll.documents',
                AccessLevel::READ,
            );

        return DocumentViewerContext::fromAuthorization(
            RequestAuthorization::isSuperadmin($request),
            $userId,
            $canViewPayrollEnforcementEvidence,
            $canViewPayrollInsolvencyEvidence,
            $canViewPayrollSubmissionEvidence,
            $canViewPayrollForeignPermitEvidence,
            $canViewPayrollHealthEvidence,
            $canViewPayrollDocuments,
        );
    }

    private static function userId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($validated) ? $validated : null;
    }
}
