<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollDeletionException;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollEmploymentDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollMealEntitlementBasisLockedException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzEvidenceCatalog;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollEmploymentAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $validator,
        private readonly PayrollEmploymentJmhzEvidenceCatalog $jmhzEvidence,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
        private readonly PayrollEmploymentDeletionRepository $deletion,
    ) {}

    public function jmhzEvidenceOptions(Request $request, Response $response): Response
    {
        if (($error = $this->authorizeRead($request, $response)) !== null) {
            return $error;
        }
        return Json::ok($response, ['options' => $this->jmhzEvidence->options()]);
    }

    public function jmhzMunicipalities(Request $request, Response $response): Response
    {
        if (($error = $this->authorizeRead($request, $response)) !== null) {
            return $error;
        }
        try {
            $query = $request->getQueryParams();
            $search = is_string($query['q'] ?? null) ? $query['q'] : '';
            $limitRaw = $query['limit'] ?? 20;
            $limit = filter_var($limitRaw, FILTER_VALIDATE_INT);
            if ($limit === false) {
                throw new \InvalidArgumentException('Limit vyhledávání obcí není celé číslo.');
            }
            return Json::ok($response, [
                'items' => $this->jmhzEvidence->municipalities($search, $limit),
                'external_codebooks' => $this->jmhzEvidence->externalCodebookProvenance(),
            ]);
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function create(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $employment = $this->employments->create(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $this->validator->create($this->body($request)),
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
        return Json::ok($response, ['employment' => $employment], 201);
    }

    /** @param array{id:string} $args */
    public function addTerms(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);
            $supplierId = $this->currentSupplierId($request);
            $employmentId = (int) $args['id'];
            $employment = $this->employments->addTerms(
                $supplierId,
                $employmentId,
                $this->validator->terms(
                    $body,
                    // Uložený kód CZ-ISCO smí projít, i když v číselníku není —
                    // hodnotu bere validátor odsud, nikdy z požadavku klienta.
                    $this->employments->currentCzIscoCode($supplierId, $employmentId),
                    // Zařazení pro srážkovou daň drží klient jen tehdy, když ho
                    // zná; jinak se přebírá to uložené, ať ho uložení podmínek
                    // nešoupne zpátky na „neurčeno".
                    $this->employments->currentOtherWithholdingEligibility(
                        $supplierId,
                        $employmentId,
                    ),
                    $this->employments->currentRelationType($supplierId, $employmentId),
                ),
                $this->validator->rowVersion($body),
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
        return Json::ok($response, ['employment' => $employment]);
    }

    /**
     * Označení vztahu pro import docházky.
     *
     * Kód se generuje sám a uživatel ho běžně nevidí — jenže je to párovací klíč
     * CSV importu, takže kdo importuje z docházkového systému, musí ho umět
     * srovnat s tím, co posílá druhá strana. Dřív byl po založení neměnný, což
     * u importního klíče znamenalo založit vztah znovu.
     *
     * @param array{id:string} $args
     */
    public function rename(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);
            $employment = $this->employments->rename(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $this->validator->code($body),
                $this->validator->rowVersion($body),
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
        return Json::ok($response, ['employment' => $employment]);
    }

    /** @param array{id:string} $args */
    public function setMealEntitlementBasis(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);
            $employment = $this->employments->setMealEntitlementBasis(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $this->validator->requiredMealEntitlementBasis($body),
                $this->validator->rowVersion($body),
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }

        return Json::ok($response, ['employment' => $employment]);
    }

    /** @param array{id:string,target:string} $args */
    public function transition(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $data = $this->validator->transition($this->body($request));
            $employment = $this->employments->transition(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $args['target'],
                $data['row_version'],
                $data['effective_on'],
                $data['note'],
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
        return Json::ok($response, ['employment' => $employment]);
    }

    /**
     * Smazání vztahu, který vůbec neměl vzniknout.
     *
     * Nenahrazuje `no_show` — „nenástup" je záznam o tom, že něco nastalo (člověk
     * byl přijat a nenastoupil). Tohle je pro případ, kdy se to nemělo stát vůbec,
     * a nemá po sobě nechat fiktivní nenástup v evidenci.
     *
     * Právo je `payroll.employment.write`, tedy TOTÉŽ, kterým se vztah zakládá:
     * mazání omylem založeného vztahu je opak jeho založení, ne přísnější úkon.
     * Před skutečnými záznamy chrání blokátory v repozitáři, ne zvláštní právo.
     *
     * @param array{id:string} $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);
            $cascade = $this->deletion->delete(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $this->validator->rowVersion($body),
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }

        return Json::ok($response, ['deleted' => true, 'cascade' => $cascade]);
    }

    /** @param array{id:string,item_key:string} $args */
    public function checklist(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $data = $this->validator->checklist($this->body($request));
            $employment = $this->employments->updateChecklist(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $args['item_key'],
                $data['row_version'],
                $data['status'],
                $data['note'],
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
        return Json::ok($response, ['employment' => $employment]);
    }

    private function authorize(Request $request, Response $response): ?Response
    {
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
            'payroll.employment.write',
            AccessLevel::WRITE,
            $error,
        )) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď oprávnění.');
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď modulu.');
        }
        return null;
    }

    private function authorizeRead(Request $request, Response $response): ?Response
    {
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
            'payroll',
            AccessLevel::READ,
            $error,
        )) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď oprávnění.');
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď modulu.');
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
        }
        $result = [];
        foreach ($body as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private function ip(Request $request): string
    {
        $params = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }
        return $this->ipMatcher->clientIpFromRequest($params);
    }

    private function domainError(Response $response, \Throwable $e): Response
    {
        return match (true) {
            $e instanceof PayrollEmploymentNotFoundException => Json::error(
                $response,
                'not_found',
                $e->getMessage(),
                404,
            ),
            // Blokace mazání není chyba uživatele — nese kód a větu, podle které
            // se dá jednat, takže je frontend ukazuje místo zašedlého tlačítka.
            $e instanceof PayrollDeletionException => Json::error(
                $response,
                $e->errorCode,
                $e->getMessage(),
                409,
                array_filter([
                    'employment_id' => $e->employmentId,
                    'employment_code' => $e->employmentCode,
                ], static fn ($value): bool => $value !== null),
            ),
            $e instanceof PayrollEmploymentConflictException => Json::error(
                $response,
                'row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            ),
            $e instanceof PayrollMealEntitlementBasisLockedException => Json::error(
                $response,
                'meal_entitlement_basis_locked',
                $e->getMessage(),
                409,
            ),
            $e instanceof \DomainException => Json::error(
                $response,
                'invalid_transition',
                $e->getMessage(),
                409,
            ),
            $e instanceof \InvalidArgumentException => Json::error(
                $response,
                'validation_failed',
                $e->getMessage(),
                422,
            ),
            $e instanceof \PDOException && $e->getCode() === '23000' => Json::error(
                $response,
                'employment_conflict',
                'Pracovní vztah koliduje s existujícím kódem, intervalem nebo primárním vztahem.',
                409,
            ),
            default => throw $e,
        };
    }
}
