<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use DateTimeImmutable;
use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollDependantConflictException;
use MyInvoice\Repository\Payroll\PayrollDependantNotFoundException;
use MyInvoice\Repository\Payroll\PayrollDependantRepository;
use MyInvoice\Repository\Payroll\PayrollPersonNotFoundException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollDependantValidator;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Rodinné (vyživované) osoby a jejich nárok na daňové zvýhodnění — MZ-04-W05.
 * Session-only, rodné číslo dítěte se vrací výhradně maskované.
 */
final class PayrollDependantAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollDependantRepository $dependants,
        private readonly PayrollDependantValidator $validator,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array{id:string} $args */
    public function list(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard($request, $response, 'payroll', AccessLevel::READ, $error)) {
            return $this->errorResponse($error);
        }

        $effectiveOn = $this->effectiveOn($request);
        if ($effectiveOn === null) {
            return Json::error(
                $response,
                'validation_failed',
                'effective_on musí být datum YYYY-MM-DD.',
                422,
            );
        }

        $view = $this->dependants->overview(
            $this->currentSupplierId($request),
            (int) $args['id'],
            $effectiveOn,
        );
        if ($view === null) {
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        }

        return Json::ok($response, $view);
    }

    /** @param array{id:string} $args */
    public function create(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard(
            $request,
            $response,
            'payroll.person.write',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $failure = null;
        $body = $this->body($request, $response, $failure);
        if ($body === null) {
            return $this->errorResponse($failure);
        }
        $effectiveOn = $this->effectiveOn($request);
        if ($effectiveOn === null) {
            return Json::error(
                $response,
                'validation_failed',
                'effective_on musí být datum YYYY-MM-DD.',
                422,
            );
        }

        return $this->run($request, $response, function () use (
            $request,
            $args,
            $body,
            $effectiveOn,
        ): array {
            return $this->dependants->createDependant(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $this->validator->validateDependant($body),
                $effectiveOn,
                $this->userId($request),
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
            );
        });
    }

    /** @param array{id:string,dependantId:string} $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->guard(
            $request,
            $response,
            'payroll.person.write',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $failure = null;
        $body = $this->body($request, $response, $failure);
        if ($body === null) {
            return $this->errorResponse($failure);
        }
        $version = $this->rowVersion($body);
        if ($version === null) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být kladné celé číslo.',
                422,
            );
        }
        $effectiveOn = $this->effectiveOn($request);
        if ($effectiveOn === null) {
            return Json::error(
                $response,
                'validation_failed',
                'effective_on musí být datum YYYY-MM-DD.',
                422,
            );
        }

        /*
         * `delete: true` je SMAZÁNÍ, ne uložení prázdné osoby.
         *
         * Evidence uměla osoby jen zakládat a měnit, takže vyživovaná osoba
         * zapsaná u špatného zaměstnance zůstala navždy. Příznak jde tudy,
         * a ne novou routou, aby cesta ven existovala hned.
         */
        $delete = $body['delete'] ?? false;
        if (!is_bool($delete)) {
            return Json::error($response, 'validation_failed', 'Pole delete musí být boolean.', 422);
        }

        return $this->run($request, $response, function () use (
            $request,
            $args,
            $body,
            $version,
            $effectiveOn,
            $delete,
        ): array {
            if ($delete) {
                return $this->dependants->deleteDependant(
                    $this->currentSupplierId($request),
                    (int) $args['id'],
                    (int) $args['dependantId'],
                    $version,
                    $effectiveOn,
                    $this->userId($request),
                    $this->clientIp($request),
                    $request->getHeaderLine('User-Agent'),
                );
            }

            return $this->dependants->updateDependant(
                $this->currentSupplierId($request),
                (int) $args['id'],
                (int) $args['dependantId'],
                $this->validator->validateDependant($body),
                $version,
                $effectiveOn,
                $this->userId($request),
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
            );
        });
    }

    /** @param array{id:string,dependantId:string} $args */
    public function createClaim(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $error = null;
        if (!$this->guard(
            $request,
            $response,
            'payroll.person.write',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $failure = null;
        $body = $this->body($request, $response, $failure);
        if ($body === null) {
            return $this->errorResponse($failure);
        }
        $effectiveOn = $this->effectiveOn($request);
        if ($effectiveOn === null) {
            return Json::error(
                $response,
                'validation_failed',
                'effective_on musí být datum YYYY-MM-DD.',
                422,
            );
        }

        return $this->run($request, $response, function () use (
            $request,
            $args,
            $body,
            $effectiveOn,
        ): array {
            return $this->dependants->createClaim(
                $this->currentSupplierId($request),
                (int) $args['id'],
                (int) $args['dependantId'],
                $this->validator->validateClaim($body),
                $effectiveOn,
                $this->userId($request),
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
            );
        });
    }

    /** @param array{id:string,dependantId:string,claimId:string} $args */
    public function saveClaim(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $error = null;
        if (!$this->guard(
            $request,
            $response,
            'payroll.person.write',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $failure = null;
        $body = $this->body($request, $response, $failure);
        if ($body === null) {
            return $this->errorResponse($failure);
        }
        $version = $this->rowVersion($body);
        if ($version === null) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být kladné celé číslo.',
                422,
            );
        }
        $effectiveOn = $this->effectiveOn($request);
        if ($effectiveOn === null) {
            return Json::error(
                $response,
                'validation_failed',
                'effective_on musí být datum YYYY-MM-DD.',
                422,
            );
        }

        // Viz update(): `delete: true` maže nárok, který nikdy neměl vzniknout.
        $delete = $body['delete'] ?? false;
        if (!is_bool($delete)) {
            return Json::error($response, 'validation_failed', 'Pole delete musí být boolean.', 422);
        }

        return $this->run($request, $response, function () use (
            $request,
            $args,
            $body,
            $version,
            $effectiveOn,
            $delete,
        ): array {
            if ($delete) {
                return $this->dependants->deleteClaim(
                    $this->currentSupplierId($request),
                    (int) $args['id'],
                    (int) $args['dependantId'],
                    (int) $args['claimId'],
                    $version,
                    $effectiveOn,
                    $this->userId($request),
                    $this->clientIp($request),
                    $request->getHeaderLine('User-Agent'),
                );
            }

            return $this->dependants->saveClaim(
                $this->currentSupplierId($request),
                (int) $args['id'],
                (int) $args['dependantId'],
                (int) $args['claimId'],
                $this->validator->validateClaim($body),
                $version,
                $effectiveOn,
                $this->userId($request),
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
            );
        });
    }

    /** @param callable():array<string,mixed> $work */
    private function run(Request $request, Response $response, callable $work): Response
    {
        try {
            $view = $work();
        } catch (PayrollPersonNotFoundException) {
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        } catch (PayrollDependantNotFoundException $exception) {
            return Json::error($response, 'not_found', $exception->getMessage(), 404);
        } catch (PayrollDependantConflictException $exception) {
            return Json::error(
                $response,
                'row_version_conflict',
                $exception->getMessage(),
                409,
                ['current_row_version' => $exception->currentVersion],
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, $view);
    }

    private function guard(
        Request $request,
        Response $response,
        string $permission,
        AccessLevel $level,
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
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
            return false;
        }

        return $this->requirePayrollEnabled($request, $response, $this->access, $error);
    }

    /** @return array<string,mixed>|null */
    private function body(
        Request $request,
        Response $response,
        ?Response &$failure,
    ): ?array {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $failure = Json::error(
                $response,
                'validation_failed',
                'Tělo požadavku musí být objekt.',
                422,
            );
            return null;
        }
        $result = [];
        foreach ($body as $key => $value) {
            if (!is_string($key)) {
                $failure = Json::error(
                    $response,
                    'validation_failed',
                    'Tělo požadavku musí být objekt.',
                    422,
                );
                return null;
            }
            $result[$key] = $value;
        }
        $failure = null;

        return $result;
    }

    /** @param array<string,mixed> $body */
    private function rowVersion(array $body): ?int
    {
        $version = filter_var($body['row_version'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return is_int($version) ? $version : null;
    }

    private function effectiveOn(Request $request): ?string
    {
        $params = $request->getQueryParams();
        $value = $params['effective_on'] ?? null;
        if ($value === null || $value === '') {
            return (new DateTimeImmutable('today'))->format('Y-m-d');
        }
        if (!is_string($value)) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value
            ? $value
            : null;
    }

    private function clientIp(Request $request): ?string
    {
        $params = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $this->ipMatcher->clientIpFromRequest($params);
    }

    private function errorResponse(?Response $error): Response
    {
        return $error ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
    }
}
