<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyConflictException;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyOverlapException;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollModuleActivationService;
use MyInvoice\Service\Payroll\Settings\PayrollEmployerPolicyService;
use MyInvoice\Service\Payroll\Settings\PayrollSetupCheckService;
use MyInvoice\Service\Payroll\Settings\PayrollSetupFeaturesResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollEmployerPolicyAction
{
    use PayrollActionSupport;
    use PayrollDeletionResponse;

    public function __construct(
        private readonly PayrollEmployerPolicyRepository $policies,
        private readonly PayrollEmployerPolicyDeletionRepository $deletion,
        private readonly PayrollEmployerPolicyService $service,
        private readonly PayrollSetupFeaturesResolver $featureResolver,
        private readonly PayrollSetupCheckService $setupCheckService,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PayrollModuleActivationService $activation,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            AccessLevel::READ,
        )) !== null) {
            return $error;
        }
        try {
            $effectiveOn = $this->dateQuery(
                $request->getQueryParams()['effective_on'] ?? null,
                false,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error(
                $response,
                'validation_failed',
                $e->getMessage(),
                422,
            );
        }

        $supplierId = $this->currentSupplierId($request);
        $query = $request->getQueryParams();
        $limit = max(1, min(
            PayrollEmployerPolicyRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollEmployerPolicyRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        if ($effectiveOn !== null) {
            // Dotaz „co platí k datu" vrací nejvýš jednu revizi, takže se
            // nestránkuje — celkový počet je ale v odpovědi taky, aby si
            // volající nemusel tvar odpovědi hlídat podle parametru.
            $effective = $this->policies->listEffective(
                $supplierId,
                $effectiveOn,
            );

            return Json::ok($response, [
                'policies' => $effective,
                'total' => count($effective),
                'limit' => $limit,
                'offset' => 0,
            ]);
        }

        $page = $this->policies->list($supplierId, $limit, $offset);

        return Json::ok($response, [
            'policies' => $page['items'],
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** @param array<string,string> $args */
    public function detail(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            AccessLevel::READ,
        )) !== null) {
            return $error;
        }

        $policy = $this->policies->find(
            $this->currentSupplierId($request),
            (int) ($args['id'] ?? 0),
        );

        return $policy === null
            ? Json::error(
                $response,
                'not_found',
                'Zaměstnavatelská politika nebyla nalezena.',
                404,
            )
            : Json::ok($response, ['policy' => $policy]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        $body = $this->input($request);
        // Zakládaná politika ještě žádnou verzi nemá, takže se na ni nedá ptát:
        // chybějící `row_version` znamená nulu, ne chybu. Vyžadovat ho po
        // volajícím bylo pole, které nikdo nepožaduje — a hláška navíc jen
        // opakovala číslo, které si aplikace umí doplnit sama.
        $rawVersion = $body['row_version'] ?? null;
        if ($rawVersion === null || $rawVersion === '') {
            $rawVersion = 0;
        }
        $version = filter_var(
            $rawVersion,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 0]],
        );
        if ($version === false) {
            return Json::error(
                $response,
                'validation_failed',
                'Zakládaná mzdová politika ještě žádnou verzi nemá — pole row_version buď vynechte, nebo pošlete 0.',
                422,
            );
        }
        unset($body['row_version']);

        $supplierId = $this->currentSupplierId($request);
        try {
            $policy = $this->service->save(
                $supplierId,
                null,
                $body,
                0,
                $this->userId($request),
            );
        } catch (PayrollEmployerPolicyOverlapException $e) {
            return Json::error(
                $response,
                'employer_policy_interval_overlap',
                $e->getMessage(),
                409,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error(
                $response,
                'validation_failed',
                $e->getMessage(),
                422,
            );
        }

        $this->audit(
            $request,
            'payroll.employer_policy.created',
            $policy,
        );

        return Json::ok($response, ['policy' => $policy], 201);
    }

    /** @param array<string,string> $args */
    public function update(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $version = filter_var(
            $body['row_version'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($version === false) {
            return Json::error(
                $response,
                'validation_failed',
                'Upravovaná politika musí mít kladné row_version.',
                422,
            );
        }
        unset($body['row_version']);

        $supplierId = $this->currentSupplierId($request);
        try {
            $policy = $this->service->save(
                $supplierId,
                (int) ($args['id'] ?? 0),
                $body,
                (int) $version,
                $this->userId($request),
            );
        } catch (PayrollEmployerPolicyOverlapException $e) {
            return Json::error(
                $response,
                'employer_policy_interval_overlap',
                $e->getMessage(),
                409,
            );
        } catch (PayrollEmployerPolicyConflictException $e) {
            return Json::error(
                $response,
                'row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error(
                $response,
                'validation_failed',
                $e->getMessage(),
                422,
            );
        } catch (\RuntimeException $e) {
            if ($e->getMessage()
                !== 'Zaměstnavatelská politika nebyla nalezena.'
            ) {
                throw $e;
            }
            return Json::error(
                $response,
                'not_found',
                $e->getMessage(),
                404,
            );
        }

        $this->audit(
            $request,
            'payroll.employer_policy.updated',
            $policy,
        );

        return Json::ok($response, ['policy' => $policy]);
    }

    /**
     * Smaže verzi pravidla, podle které se ještě nic nespočítalo.
     *
     * Právo je `payroll.settings`, tedy TOTÉŽ, kterým se verze zakládá a upravuje:
     * smazání omylem založené budoucí verze je opak jejího založení. Před verzí,
     * podle které už běžela mzda, chrání blokátor v repozitáři i trigger
     * v databázi (migrace 1388).
     *
     * @param array<string,string> $args
     */
    public function delete(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        try {
            $cascade = $this->deletion->delete(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $this->optionalRowVersion($this->input($request)['row_version'] ?? null),
                $this->userId($request),
                $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->deletionError($response, $e);
        }

        return Json::ok($response, ['deleted' => true, 'cascade' => $cascade]);
    }

    public function setupCheck(
        Request $request,
        Response $response,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            AccessLevel::READ,
        )) !== null) {
            return $error;
        }
        try {
            $effectiveOn = $this->dateQuery(
                $request->getQueryParams()['effective_on'] ?? null,
                true,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error(
                $response,
                'validation_failed',
                $e->getMessage(),
                422,
            );
        }
        if ($effectiveOn === null) {
            throw new \LogicException('Povinné datum setup checku chybí.');
        }

        $supplierId = $this->currentSupplierId($request);
        $features = $this->featureResolver->resolve(
            $supplierId,
            $effectiveOn,
        );
        $setup = $this->setupCheckService->check(
            $supplierId,
            $effectiveOn,
            $features,
        );
        // Dokončený setup je první ze dvou spouští překlopení modulu do
        // `active`. Vyhodnocuje se tam, kde se výsledek kontroly poprvé
        // objeví, aby uživatel nemusel nic dalšího odklikávat. Přechod je
        // jednosměrný a idempotentní, takže opakované čtení nic nemění.
        $this->activation->activateWhenSetupComplete(
            $supplierId,
            $this->userId($request),
        );

        return Json::ok($response, ['setup' => $setup]);
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
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
            'payroll.settings',
            $level,
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

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $parsed = $request->getParsedBody();
        if (!is_array($parsed)) {
            return [];
        }
        $result = [];
        foreach ($parsed as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function dateQuery(mixed $raw, bool $required): ?string
    {
        if ($raw === null || $raw === '') {
            if ($required) {
                throw new \InvalidArgumentException(
                    'effective_on je povinné datum YYYY-MM-DD.',
                );
            }
            return null;
        }
        if (!is_string($raw)) {
            throw new \InvalidArgumentException(
                'effective_on musí být platné datum YYYY-MM-DD.',
            );
        }
        $value = trim($raw);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new \InvalidArgumentException(
                'effective_on musí být platné datum YYYY-MM-DD.',
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $policy */
    private function audit(
        Request $request,
        string $action,
        array $policy,
    ): void {
        $supplierId = $this->currentSupplierId($request);
        $this->logger->log(
            $action,
            $this->userId($request),
            'payroll_employer_policy',
            $this->int($policy, 'id'),
            [
                'valid_from' => $this->string($policy, 'valid_from'),
                'valid_to' => $this->nullableString($policy, 'valid_to'),
                'row_version' => $this->int($policy, 'row_version'),
                'source_kind' => $this->string($policy, 'source_kind'),
            ],
            $this->ipMatcher->clientIpFromRequest(
                $this->serverParams($request),
            ),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }

    /** @return array<string,mixed> */
    private function serverParams(Request $request): array
    {
        $result = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private function int(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException(
                "DTO politiky nemá celé pole {$field}.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "DTO politiky nemá textové pole {$field}.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "DTO politiky nemá textové pole {$field}.",
            );
        }

        return $value;
    }
}
