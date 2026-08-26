<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollRegzelProfileConflictException;
use MyInvoice\Repository\Payroll\PayrollRegzelRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Regzel\EmployerRegistrationService;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollRegzelAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly EmployerRegistrationService $service,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function profile(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->guardFailure($error);
        }

        $supplierId = $this->currentSupplierId($request);
        $profile = $this->service->profile($supplierId);
        return Json::ok($response, [
            'profile' => $profile,
            'suggested_tax_office_workplace_code' =>
                ($profile['is_complete'] ?? false)
                    ? null
                    : $this->service->taxOfficeWorkplaceSuggestion($supplierId),
            'supported' => [
                'document_type' => 'REGZELDOPL25',
                'xsd_version' => '1.2',
                'interactions' => ['supplemental_information'],
            ],
        ]);
    }

    public function saveProfile(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->guardFailure($error);
        }

        try {
            $body = $this->body($request);
            $profile = $this->service->saveProfile(
                $this->currentSupplierId($request),
                $this->requiredUserId($request),
                $this->nonNegativeInt($body, 'row_version'),
                $this->bool($body, 'social_enterprise'),
                $this->bool($body, 'employment_agency'),
                $this->bool($body, 'protected_labor_market'),
                $body['tax_office_code'] ?? null,
                $body['tax_office_workplace_code'] ?? null,
                $body['payer_reference_number'] ?? null,
                $this->bool($body, 'evidence_confirmed'),
            );
        } catch (RegzelValidationException $exception) {
            return Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                422,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (PayrollRegzelProfileConflictException $exception) {
            return Json::error(
                $response,
                'row_version_conflict',
                $exception->getMessage(),
                409,
                ['current_row_version' => $exception->currentVersion],
            );
        }

        $this->audit(
            $request,
            'payroll.regzel.profile_confirmed',
            'payroll_regzel_employer_profiles',
            $this->currentSupplierId($request),
            [
                'row_version' => $profile['row_version'],
                'is_complete' => $profile['is_complete'],
            ],
        );

        return Json::ok($response, ['profile' => $profile]);
    }

    public function prepare(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->guardFailure($error);
        }

        try {
            $body = $this->body($request);
            if (!$this->bool($body, 'evidence_confirmed')) {
                throw new RegzelValidationException(
                    'regzel_evidence_confirmation_required',
                    'Před přípravou XML musíš potvrdit aktuálnost zdrojových údajů.',
                );
            }
            $result = $this->service->prepareSupplementalInformation(
                $this->currentSupplierId($request),
                $this->positiveInt($body, 'office_id'),
                $this->environment($body),
                $this->string($body, 'idempotency_key'),
                $this->requiredUserId($request),
            );
        } catch (RegzelValidationException $exception) {
            return Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                422,
            );
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        unset($result['xml']);
        $this->audit(
            $request,
            'payroll.regzel.snapshot_prepared',
            'payroll_regzel_payload_snapshots',
            $result['id'],
            [
                'environment' => $result['environment'],
                'office_id' => $result['office_id'],
                'created' => $result['created'],
            ],
        );

        return Json::ok(
            $response,
            ['snapshot' => $result],
            $result['created'] ? 201 : 200,
        );
    }

    public function snapshots(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->guardFailure($error);
        }

        // Strop je tvrdý, ne jen výchozí — z URL ho zvednout nejde.
        $query = $request->getQueryParams();
        $limit = max(1, min(
            PayrollRegzelRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollRegzelRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        try {
            $environment = $this->queryEnvironment($request);
            $page = $this->service->snapshots(
                $this->currentSupplierId($request),
                $environment,
                $limit,
                $offset,
            );
        } catch (RegzelValidationException $exception) {
            return Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                422,
            );
        }

        // Klíč `items` zůstává kvůli stávajícím volajícím.
        return Json::ok($response, [
            'environment' => $environment,
            'items' => $page['items'],
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** @param array{id:string} $args */
    public function download(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->guardFailure($error);
        }

        try {
            $result = $this->service->snapshotXml(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $this->queryEnvironment($request),
            );
        } catch (\OutOfBoundsException) {
            return Json::error(
                $response,
                'not_found',
                'REGZEL snapshot nebyl nalezen.',
                404,
            );
        } catch (RegzelValidationException $exception) {
            return Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                422,
            );
        }

        $response->getBody()->write($result['xml']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $result['filename'] . '"',
            )
            ->withHeader('Content-Length', (string) strlen($result['xml']))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function guard(
        Request $request,
        Response $response,
        AccessLevel $minimum,
        ?Response &$error,
    ): bool {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            $error = Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
            return false;
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            $minimum,
            $error,
        )) {
            return false;
        }
        return $this->requirePayrollEnabled(
            $request,
            $response,
            $this->access,
            $error,
        );
    }

    private function guardFailure(?Response $error): Response
    {
        return $error
            ?? throw new \LogicException('Payroll REGZEL guard selhal bez odpovědi.');
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
        }
        $normalized = [];
        foreach ($body as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException(
                    'Tělo požadavku musí být objekt.',
                );
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @param array<string,mixed> $body */
    private function bool(array $body, string $key): bool
    {
        if (!array_key_exists($key, $body) || !is_bool($body[$key])) {
            throw new \InvalidArgumentException($key . ' musí být pravdivostní hodnota.');
        }
        return $body[$key];
    }

    /** @param array<string,mixed> $body */
    private function nonNegativeInt(array $body, string $key): int
    {
        $value = filter_var(
            $body[$key] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );
        if ($value === false) {
            throw new \InvalidArgumentException($key . ' musí být nezáporné celé číslo.');
        }
        return (int) $value;
    }

    /** @param array<string,mixed> $body */
    private function positiveInt(array $body, string $key): int
    {
        $value = filter_var(
            $body[$key] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($value === false) {
            throw new \InvalidArgumentException($key . ' musí být kladné celé číslo.');
        }
        return (int) $value;
    }

    /** @param array<string,mixed> $body */
    private function string(array $body, string $key): string
    {
        if (!array_key_exists($key, $body) || !is_string($body[$key])) {
            throw new \InvalidArgumentException($key . ' musí být text.');
        }
        return $body[$key];
    }

    /** @param array<string,mixed> $body */
    private function environment(array $body): string
    {
        $environment = $this->string($body, 'environment');
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new RegzelValidationException(
                'regzel_environment_invalid',
                'Prostředí REGZEL není platné.',
            );
        }
        return $environment;
    }

    private function queryEnvironment(Request $request): string
    {
        $query = $request->getQueryParams();
        $environment = $query['environment'] ?? null;
        if (!is_string($environment)
            || !in_array($environment, ['production', 'test'], true)
        ) {
            throw new RegzelValidationException(
                'regzel_environment_invalid',
                'Prostředí REGZEL není platné.',
            );
        }
        return $environment;
    }

    private function requiredUserId(Request $request): int
    {
        return $this->userId($request)
            ?? throw new \InvalidArgumentException('Přihlášený uživatel není platný.');
    }

    /** @param array<string,mixed> $metadata */
    private function audit(
        Request $request,
        string $action,
        string $entityType,
        int $entityId,
        array $metadata,
    ): void {
        $serverParams = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $serverParams[$key] = $value;
            }
        }
        $this->logger->log(
            $action,
            $this->userId($request),
            $entityType,
            $entityId,
            $metadata,
            $this->ipMatcher->clientIpFromRequest($serverParams),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
