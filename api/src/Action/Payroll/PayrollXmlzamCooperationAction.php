<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\Garnishment\Xmlzam\XmlzamCooperationFlowService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollXmlzamCooperationAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly XmlzamCooperationFlowService $flow,
        private readonly PayrollModuleAccess $access,
    ) {}

    public function import(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = self::body($request);
            $result = $this->flow->import(
                $this->currentSupplierId($request),
                self::string($body, 'environment'),
                self::positiveInt($body, 'inbox_message_id'),
                self::positiveInt($body, 'document_file_id'),
                $this->requiredUserId($request),
            );
            return Json::ok($response, ['request' => $result], $result['created'] ? 201 : 200);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'xmlzam_blocked', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
    }

    public function candidates(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            $query = $request->getQueryParams();
            $environment = $query['environment'] ?? null;
            if (!is_string($environment) || trim($environment) === '') {
                throw new \InvalidArgumentException('environment musí být neprázdný řetězec.');
            }
            return Json::ok($response, [
                'candidates' => $this->flow->candidates(
                    $this->currentSupplierId($request),
                    trim($environment),
                ),
            ]);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
    }

    /** @param array{id:string} $args */
    public function detail(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            $query = $request->getQueryParams();
            $environment = $query['environment'] ?? null;
            if (!is_string($environment) || trim($environment) === '') {
                throw new \InvalidArgumentException('environment musí být neprázdný řetězec.');
            }
            return Json::ok($response, [
                'request' => $this->flow->detail(
                    $this->currentSupplierId($request),
                    trim($environment),
                    (int) $args['id'],
                ),
            ]);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'xmlzam_blocked', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
    }

    /** @param array{id:string} $args */
    public function preview(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = self::body($request);
            $preview = $this->flow->preview(
                $this->currentSupplierId($request),
                self::string($body, 'environment'),
                (int) $args['id'],
                self::positiveInt($body, 'case_id'),
                self::strings($body, 'periods'),
            );
            return Json::ok($response, ['preview' => $preview]);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'xmlzam_blocked', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
    }

    /** @param array{id:string} $args */
    public function freeze(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = self::body($request);
            $result = $this->flow->freeze(
                $this->currentSupplierId($request),
                self::string($body, 'environment'),
                (int) $args['id'],
                self::positiveInt($body, 'case_id'),
                self::strings($body, 'periods'),
                self::string($body, 'idempotency_key'),
                $this->requiredUserId($request),
            );
            return Json::ok($response, ['response' => $result], $result['created'] ? 201 : 200);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'xmlzam_blocked', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
    }

    /** @param array{id:string} $args */
    public function enqueue(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = self::body($request);
            $result = $this->flow->enqueue(
                $this->currentSupplierId($request),
                self::string($body, 'environment'),
                (int) $args['id'],
                self::positiveInt($body, 'recipient_id'),
                $this->requiredUserId($request),
            );
            return Json::ok($response, ['dispatch' => $result], $result['created'] ? 201 : 200);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'xmlzam_blocked', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $minimum = AccessLevel::WRITE,
    ): ?Response
    {
        if (($request->getAttribute(AuthMiddleware::ATTR_METHOD) ?? '') !== 'session') {
            return Json::error($response, 'session_required', 'Součinnost XMLZAM vyžaduje přihlášenou uživatelskou relaci.', 403);
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.enforcement.cooperation',
            $minimum,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }
        return null;
    }

    private function requiredUserId(Request $request): int
    {
        $id = $this->userId($request);
        if ($id === null) {
            throw new \InvalidArgumentException('XMLZAM vyžaduje konkrétního přihlášeného uživatele.');
        }
        return $id;
    }

    /** @return array<string,mixed> */
    private static function body(Request $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
        }
        return $body;
    }

    /** @param array<string,mixed> $body */
    private static function positiveInt(array $body, string $key): int
    {
        $value = filter_var($body[$key] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($value) || $value <= 0) {
            throw new \InvalidArgumentException("{$key} musí být kladné celé číslo.");
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function string(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("{$key} musí být neprázdný řetězec.");
        }
        return trim($value);
    }

    /**
     * @param array<string,mixed> $body
     * @return list<string>
     */
    private static function strings(array $body, string $key): array
    {
        $value = $body[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("{$key} musí být seznam.");
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException("{$key} smí obsahovat jen řetězce.");
            }
        }
        return $value;
    }
}
