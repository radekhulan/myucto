<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Ručně doložené OIČ / IK MPSV a ID PPV pro měsíční hlášení JMHZ. */
final class PayrollJmhzIdentityAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollRegistrationIdentityService $identities,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array{employmentId:string} $args */
    public function show(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, false);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $query = $request->getQueryParams();
            $result = $this->identities->jmhzIdentityStatusAt(
                $this->currentSupplierId($request),
                $this->employmentId($args),
                $this->environment($query['environment'] ?? null),
                $this->requiredString($query['on_date'] ?? null, 'on_date'),
            );
        } catch (\OutOfBoundsException $exception) {
            return $this->error($response, 'not_found', $exception, 404);
        } catch (\InvalidArgumentException|\UnexpectedValueException $exception) {
            return $this->error($response, 'validation_failed', $exception, 422);
        } catch (\DomainException $exception) {
            return $this->error($response, 'conflict', $exception, 409);
        }

        return $this->noStore(Json::ok($response, ['identity' => $result]));
    }

    /** @param array{employmentId:string} $args */
    public function put(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $denied = $this->authorize($request, $response, true);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $body = $request->getParsedBody();
            if (!is_array($body) || array_is_list($body)) {
                throw new \InvalidArgumentException(
                    'Tělo požadavku musí být objekt.',
                );
            }
            $employmentId = $this->employmentId($args);
            $environment = $this->environment($body['environment'] ?? null);
            $validFrom = $this->requiredString(
                $body['valid_from'] ?? null,
                'valid_from',
            );
            $actor = $this->userId($request);
            if ($actor === null) {
                return $this->noStore(Json::error(
                    $response,
                    'actor_required',
                    'Potvrzující uživatel chybí.',
                    422,
                ));
            }
            $assigned = $this->identities->assignManualJmhzIdentity(
                $this->currentSupplierId($request),
                $employmentId,
                $environment,
                $this->optionalString(
                    $body['person_external_identifier'] ?? null,
                    'person_external_identifier',
                ),
                $this->optionalString(
                    $body['employment_external_identifier'] ?? null,
                    'employment_external_identifier',
                ),
                $validFrom,
                $this->optionalString(
                    $body['source_reference'] ?? null,
                    'source_reference',
                ),
                $this->requiredBoolean(
                    $body['evidence_confirmed'] ?? null,
                    'evidence_confirmed',
                ),
                $actor,
            );
        } catch (\OutOfBoundsException $exception) {
            return $this->error($response, 'not_found', $exception, 404);
        } catch (\InvalidArgumentException|\UnexpectedValueException $exception) {
            return $this->error($response, 'validation_failed', $exception, 422);
        } catch (\DomainException $exception) {
            return $this->error($response, 'conflict', $exception, 409);
        }

        return $this->noStore(Json::ok($response, [
            'assigned' => $assigned,
        ]));
    }

    private function authorize(
        Request $request,
        Response $response,
        bool $write,
    ): ?Response {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            $write ? 'payroll.person.write' : 'payroll',
            $write ? AccessLevel::WRITE : AccessLevel::READ,
            $error,
        )) {
            return $error;
        }
        if ($write && !$this->requirePermission(
            $request,
            $response,
            'payroll.employment.write',
            AccessLevel::WRITE,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled(
            $request,
            $response,
            $this->access,
            $error,
        )) {
            return $error;
        }

        return null;
    }

    /** @param array{employmentId:string} $args */
    private function employmentId(array $args): int
    {
        $value = $args['employmentId'];
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException(
                'employmentId musí být kladné celé číslo.',
            );
        }

        return (int) $value;
    }

    private function environment(mixed $value): string
    {
        if (!is_string($value)
            || !in_array($value, ['test', 'production'], true)
        ) {
            throw new \InvalidArgumentException(
                'Prostředí musí být test nebo production.',
            );
        }

        return $value;
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("{$field} musí být neprázdný text.");
        }

        return trim($value);
    }

    private function optionalString(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$field} musí být text.");
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function requiredBoolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("{$field} musí být ano/ne.");
        }

        return $value;
    }

    private function error(
        Response $response,
        string $code,
        \Throwable $exception,
        int $status,
    ): Response {
        return $this->noStore(Json::error(
            $response,
            $code,
            $exception->getMessage(),
            $status,
        ));
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}
