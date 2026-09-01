<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollDeletionException;
use MyInvoice\Repository\Payroll\PayrollEmployeeDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollPeopleAction
{
    use PayrollActionSupport;

    /** Delší hledaný řetězec už není jméno; ořízne se, aby se nedostal do dotazu. */
    private const SEARCH_MAX_LENGTH = 100;

    public function __construct(
        private readonly PayrollPeopleRepository $people,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollPersonCreateService $createService,
        private readonly IpMatcher $ipMatcher,
        private readonly PayrollEmployeeDeletionRepository $deletion,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll', AccessLevel::READ, $error)) {
            return $this->guardFailure($error);
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $this->guardFailure($error);
        }

        $supplierId = $this->currentSupplierId($request);
        $query = $request->getQueryParams();
        $view = $query['view'] ?? '';
        if ($view === 'options') {
            // Číselníkové čtení pro rozbalovátka: jediný dotaz, tedy kompletní
            // seznam bez stránkování.
            return Json::ok($response, [
                'items' => $this->people->listOptionsForTenant($supplierId),
            ]);
        }
        if ($view !== '') {
            return Json::error(
                $response,
                'validation_failed',
                'Neznámý pohled seznamu osob.',
                422,
            );
        }

        $filter = $query['filter'] ?? '';
        if (!is_string($filter)) {
            $filter = '';
        }
        if ($filter === '') {
            $filter = PayrollPeopleRepository::LIST_DEFAULT_FILTER;
        }
        if (!in_array($filter, PayrollPeopleRepository::LIST_FILTERS, true)) {
            return Json::error(
                $response,
                'validation_failed',
                'Neznámý filtr seznamu osob.',
                422,
            );
        }

        // Hledání se zkracuje, ne odmítá: delší řetězec než jméno nikoho nenajde,
        // ale prohlížeč ani odkaz kvůli tomu nemá dostat chybu.
        $search = $query['q'] ?? '';
        $search = is_string($search)
            ? mb_substr(trim($search), 0, self::SEARCH_MAX_LENGTH)
            : '';

        // Strop je tvrdý, ne jen výchozí — parametrem z URL ho zvednout nejde.
        $limit = max(1, min(
            PayrollPeopleRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollPeopleRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $page = $this->people->listForTenant($supplierId, $limit, $offset, $filter, $search);

        // Klíč `items` zůstává, aby stávající volající nespadli; `total`/`limit`/
        // `offset` přibyly vedle něj, protože seznam už nemusí být úplný.
        return Json::ok($response, [
            'items' => $page['items'],
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** @param array{id:string} $args */
    public function detail(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll', AccessLevel::READ, $error)) {
            return $this->guardFailure($error);
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $this->guardFailure($error);
        }

        $person = $this->people->findForTenant(
            $this->currentSupplierId($request),
            (int) $args['id'],
        );
        if ($person === null) {
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        }

        return Json::ok($response, ['person' => $person]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireSession($request, $response, $error)) {
            return $this->guardFailure($error);
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.person.write',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->guardFailure($error);
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $this->guardFailure($error);
        }

        try {
            $body = $request->getParsedBody();
            if (!is_array($body)) {
                throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
            }
            $input = [];
            foreach ($body as $key => $value) {
                if (!is_string($key)) {
                    throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
                }
                $input[$key] = $value;
            }
            $serverParams = [];
            foreach ($request->getServerParams() as $key => $value) {
                if (is_string($key)) {
                    $serverParams[$key] = $value;
                }
            }
            $person = $this->createService->create(
                $this->currentSupplierId($request),
                $input,
                $this->userId($request),
                $this->ipMatcher->clientIpFromRequest($serverParams),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            return Json::error(
                $response,
                'person_conflict',
                'Zaměstnance se nepodařilo založit kvůli kolizi vstupních údajů.',
                409,
            );
        }

        return Json::ok($response, ['person' => $person], 201);
    }

    /**
     * Smazání zaměstnance, kterého uživatel založil omylem.
     *
     * Právo je `payroll.person.write`, tedy TOTÉŽ, kterým se osoba zakládá — mazání
     * omylem založené osoby je opak jejího založení. Před skutečnými pohyby chrání
     * blokátory v repozitáři, ne zvláštní právo.
     *
     * Kaskáda odklidí i pracovní vztahy osoby, ale jen ty, které jdou samy smazat;
     * jinak rozhodnutí JMENUJE vztah, který to blokuje.
     *
     * @param array{id:string} $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireSession($request, $response, $error)) {
            return $this->guardFailure($error);
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.person.write',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->guardFailure($error);
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $this->guardFailure($error);
        }

        $supplierId = $this->currentSupplierId($request);
        $employeeId = (int) $args['id'];
        // 404 dřív, než se cokoli dozví o stavu — cizí tenant nesmí poznat ani to,
        // jestli id existuje.
        if ($this->people->findForTenant($supplierId, $employeeId) === null) {
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        }

        $serverParams = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $serverParams[$key] = $value;
            }
        }

        try {
            $cascade = $this->deletion->delete(
                $supplierId,
                $employeeId,
                $this->userId($request),
                $this->ipMatcher->clientIpFromRequest($serverParams),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (PayrollDeletionException $e) {
            return Json::error(
                $response,
                $e->errorCode,
                $e->getMessage(),
                409,
                array_filter([
                    'employment_id' => $e->employmentId,
                    'employment_code' => $e->employmentCode,
                ], static fn ($value): bool => $value !== null),
            );
        } catch (PayrollEmploymentNotFoundException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            return Json::error(
                $response,
                'payroll_employee_delete_conflict',
                'Na zaměstnance mezitím vznikla vazba, takže ho už smazat nejde. '
                . 'Načtěte seznam znovu.',
                409,
            );
        }

        return Json::ok($response, ['deleted' => true, 'cascade' => $cascade]);
    }

    private function requireSession(
        Request $request,
        Response $response,
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
        $error = null;
        return true;
    }

    private function guardFailure(?Response $error): Response
    {
        if ($error === null) {
            throw new \LogicException('Payroll guard selhal bez chybové odpovědi.');
        }

        return $error;
    }
}
