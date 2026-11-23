<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollHealthInsurerNoticeService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sdělení zdravotní pojišťovny zaměstnancem a písemné potvrzení zaměstnavatele
 * (§ 12 písm. b) zákona č. 48/1997 Sb.) u věty historie pojišťovny osoby.
 *
 * Čtení chrání modulové právo `payroll`, zápis `payroll.person.write` jako
 * zbytek zákonné evidence osoby. Session-only: odpověď nese jméno osoby.
 */
final class PayrollHealthInsurerNoticeAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollHealthInsurerNoticeService $notices,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array{id:string} $args */
    public function list(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->errorResponse($error);
        }

        return Json::ok($response, ['items' => $this->notices->list(
            $this->currentSupplierId($request),
            (int) $args['id'],
        )]);
    }

    /** @param array{id:string,coverageId:string} $args */
    public function record(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->errorResponse($error);
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        try {
            $notice = $this->notices->record(
                $this->currentSupplierId($request),
                (int) $args['id'],
                (int) $args['coverageId'],
                self::text($body['employee_notified_on'] ?? null),
                self::text($body['employer_confirmed_on'] ?? null),
                $this->userId($request),
            );
        } catch (\OutOfBoundsException $exception) {
            return Json::error($response, 'not_found', $exception->getMessage(), 404);
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }

        return Json::ok($response, $notice);
    }

    /** @param array{id:string,coverageId:string} $args */
    public function confirmation(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->errorResponse($error);
        }
        try {
            $pdf = $this->notices->confirmationPdf(
                $this->currentSupplierId($request),
                (int) $args['id'],
                (int) $args['coverageId'],
            );
        } catch (\OutOfBoundsException $exception) {
            return Json::error($response, 'not_found', $exception->getMessage(), 404);
        } catch (\DomainException $exception) {
            return Json::error($response, 'health_insurer_notice_missing', $exception->getMessage(), 409);
        }
        $response->getBody()->write($pdf['bytes']);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $pdf['filename'] . '"')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function guard(
        Request $request,
        Response $response,
        AccessLevel $level,
        ?Response &$error,
    ): bool {
        if (!RequestAuthorization::actsAsSession($request)) {
            $error = Json::sessionRequired($response);
            return false;
        }
        $permission = $level === AccessLevel::WRITE ? 'payroll.person.write' : 'payroll';
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
            return false;
        }

        return $this->requirePayrollEnabled($request, $response, $this->access, $error);
    }

    private function errorResponse(?Response $error): Response
    {
        return $error ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
    }
}
