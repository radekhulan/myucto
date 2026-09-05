<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollPersonNotFoundException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\PermissionDenied;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Security\PayrollPersonSensitiveRevealService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollPersonSensitiveRevealAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollPersonSensitiveRevealService $reveal,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array{id:string} $args */
    public function post(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $error = null;
        if (!$this->requireSession($request, $response, $error)) {
            return $this->errorResponse($error);
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.person.read_sensitive',
            AccessLevel::READ,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        if (!$this->requirePayrollEnabled(
            $request,
            $response,
            $this->access,
            $error,
        )) {
            return $this->errorResponse($error);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return Json::error(
                $response,
                'validation_failed',
                'Tělo požadavku musí být objekt.',
                422,
            );
        }
        try {
            $body = $this->stringKeyedArray($body);
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }
        $reason = $body['reason'] ?? null;
        if (!is_string($reason)) {
            return Json::error(
                $response,
                'validation_failed',
                'Důvod odhalení musí být text.',
                422,
            );
        }
        $actorUserId = $this->userId($request);
        if ($actorUserId === null) {
            return Json::error(
                $response,
                'unauthenticated',
                'Nepřihlášený uživatel.',
                401,
            );
        }

        try {
            $result = $this->reveal->reveal(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $actorUserId,
                RequestAuthorization::effectiveRole($request),
                $reason,
                $this->ipMatcher->clientIpFromRequest(
                    $this->stringKeyedArray($request->getServerParams()),
                ),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (PayrollPersonNotFoundException) {
            return Json::error(
                $response,
                'not_found',
                'Zaměstnanec nenalezen.',
                404,
            );
        } catch (PermissionDenied) {
            return Json::error(
                $response,
                'forbidden',
                'Pro tuto akci nemáš oprávnění.',
                403,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException) {
            return Json::error(
                $response,
                'sensitive_data_integrity_failed',
                'Citlivé údaje nelze bezpečně odhalit.',
                409,
            );
        }

        $response = Json::ok($response, ['sensitive' => $result]);
        foreach ($result->responseHeaders() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private function requireSession(
        Request $request,
        Response $response,
        ?Response &$error,
    ): bool {
        if (!RequestAuthorization::isSessionAuth($request)) {
            $error = Json::sessionRequired($response);
            return false;
        }
        $error = null;

        return true;
    }

    private function errorResponse(?Response $error): Response
    {
        return $error
            ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
    }

    /**
     * @param array<mixed> $value
     * @return array<string,mixed>
     */
    private function stringKeyedArray(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException(
                    'Tělo požadavku musí být objekt.',
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
