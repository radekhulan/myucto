<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\Insurance\PayrollInsuranceBreakdownQueryService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * MZ-10-W07 / MZ-11-W07 — rozklad sociálního a zdravotního pojistného jedné osoby.
 *
 * Vrací vždy právě jednu osobu; cizí lidé se do odpovědi nedostanou. Chybějící
 * vysvětlení není chyba, ale stav: odpověď je 200 s `available:false` a důvodem,
 * aby obrazovka řekla větu místo prázdné karty.
 */
final class PayrollInsuranceBreakdownAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollInsuranceBreakdownQueryService $breakdowns,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array{revisionId:string,employeeId:string} $args */
    public function detail(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll', AccessLevel::READ, $error)) {
            return $error ?? Json::error(
                $response,
                'forbidden',
                'Pro tuto akci nemáš oprávnění.',
                403,
            );
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        try {
            $breakdown = $this->breakdowns->breakdown(
                $this->currentSupplierId($request),
                (int) $args['revisionId'],
                (int) $args['employeeId'],
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'insurance_breakdown_unavailable', $e->getMessage(), 409);
        }

        return Json::ok($response, ['insurance_breakdown' => $breakdown]);
    }
}
