<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollPensionRequestRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpValidationException;
use MyInvoice\Service\Payroll\Submission\Eldp\PensionInsuranceCertificateService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Výzvy a žádosti v důchodovém pojištění u osoby.
 *
 * Jeden POST pro založení, ruční vyřízení (`complete: true`), zápis předání
 * kopie (`copy_delivered: true`) i smazání omylem zapsané žádosti
 * (`delete: true`), stejně jako u žádostí o potvrzení o zdanitelných příjmech.
 */
final class PayrollPensionRequestAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollPensionRequestRepository $requests,
        private readonly PensionInsuranceCertificateService $certificates,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array{id:string} $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď.');
        }
        $list = $this->requests->list($this->currentSupplierId($request), (int) $args['id']);

        return ($list === null
            ? Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404)
            : Json::ok($response, ['requests' => $list]))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** @param array{id:string} $args */
    public function save(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď.');
        }
        $body = $request->getParsedBody();
        if (!is_array($body) || array_is_list($body)) {
            return Json::error($response, 'validation_failed', 'Tělo požadavku musí být objekt.', 422);
        }
        $supplierId = $this->currentSupplierId($request);
        $employeeId = (int) $args['id'];
        $actorId = $this->userId($request) ?? throw new \LogicException('Chybí uživatel relace.');
        $requestId = null;
        if (($body['id'] ?? null) !== null) {
            $requestId = filter_var($body['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($requestId)) {
                return Json::error($response, 'validation_failed', 'Pole id musí být kladné celé číslo.', 422);
            }
        }
        try {
            $list = match (true) {
                $requestId !== null && ($body['delete'] ?? false) === true
                    => $this->requests->remove($supplierId, $employeeId, $requestId),
                $requestId !== null && ($body['complete'] ?? false) === true
                    => $this->requests->complete(
                        $supplierId,
                        $employeeId,
                        $requestId,
                        $body['completed_on'] ?? null,
                        $body['completion_reference'] ?? null,
                        $actorId,
                    ),
                $requestId !== null && ($body['copy_delivered'] ?? false) === true
                    => $this->requests->recordCopyDelivered(
                        $supplierId,
                        $employeeId,
                        $requestId,
                        $body['copy_delivered_on'] ?? null,
                    ),
                $requestId === null => $this->requests->create($supplierId, $employeeId, $body, $actorId),
                default => throw new \InvalidArgumentException('U existující žádosti uveďte complete, copy_delivered, nebo delete.'),
            };
        } catch (\OutOfBoundsException $exception) {
            return Json::error($response, 'not_found', $exception->getMessage(), 404);
        } catch (EldpValidationException $exception) {
            return Json::error($response, $exception->validationCode, $exception->getMessage(), 422);
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }

        return Json::ok($response, ['requests' => $list])->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * Potvrzení o době důchodového pojištění v roce (§ 42 zákona č. 582/1991
     * Sb.) jako PDF. Sestavuje se ze zmrazených schválených revizí týmž
     * sestavovačem jako evidenční list.
     *
     * @param array{id:string,requestId:string} $args
     */
    public function certificate(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď.');
        }
        try {
            $artifact = $this->certificates->render(
                $this->currentSupplierId($request),
                (int) $args['id'],
                (int) $args['requestId'],
            );
        } catch (\OutOfBoundsException $exception) {
            return Json::error($response, 'not_found', $exception->getMessage(), 404);
        } catch (EldpValidationException $exception) {
            return Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                422,
                $exception->blockers === [] ? [] : ['blockers' => $exception->blockers],
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }
        $response->getBody()->write($artifact['pdf']);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $artifact['filename'] . '"')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    private function guard(Request $request, Response $response, AccessLevel $level, ?Response &$error): bool
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            $error = Json::sessionRequired($response);
            return false;
        }
        $allowed = $level === AccessLevel::READ
            ? $this->requirePermission($request, $response, 'payroll', AccessLevel::READ, $error)
            : $this->requirePermission($request, $response, 'payroll.person.write', AccessLevel::WRITE, $error);

        return $allowed && $this->requirePayrollEnabled($request, $response, $this->access, $error);
    }
}
