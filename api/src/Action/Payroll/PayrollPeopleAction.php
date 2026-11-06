<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollDeletionException;
use MyInvoice\Repository\Payroll\PayrollEmployeeDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Repository\Payroll\PayrollPersonIdentifierCollisionException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\License\LicensePayrollLimitExceeded;
use MyInvoice\Service\License\LicenseState;
use MyInvoice\Service\Payroll\CzIscoCodebook;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\People\PayrollPeopleContactColumns;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use MyInvoice\Service\Payroll\Security\PayrollEnforcementDisclosure;
use MyInvoice\Service\Payroll\Security\PayrollIdentifierProbeGuard;
use MyInvoice\Service\Payroll\Time\PayrollAgreementAnnualHours;
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
        private readonly LicenseCapacityGate $licenseCapacity,
        private readonly IpMatcher $ipMatcher,
        private readonly PayrollEmployeeDeletionRepository $deletion,
        private readonly PayrollIdentifierProbeGuard $identifierProbes,
        private readonly PayrollAgreementAnnualHours $agreementHours,
        private readonly CzIscoCodebook $czIsco,
        private readonly PayrollPeopleContactColumns $contactColumns,
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

        $sortKey = $query['sort_key'] ?? '';
        $sortDir = $query['sort_dir'] ?? '';
        if (!is_string($sortKey) || !is_string($sortDir)) {
            return Json::error($response, 'validation_failed', 'Neplatné řazení seznamu osob.', 422);
        }
        if ($sortKey === '') {
            $sortKey = PayrollPeopleRepository::LIST_DEFAULT_SORT;
        }
        if (!array_key_exists($sortKey, PayrollPeopleRepository::LIST_SORTS)
            || !in_array($sortDir, ['', 'asc', 'desc'], true)
        ) {
            return Json::error($response, 'validation_failed', 'Neplatné řazení seznamu osob.', 422);
        }

        $year = (int) date('Y');
        $page = $this->people->listForTenant(
            $supplierId,
            $limit,
            $offset,
            $filter,
            $search,
            $filter === PayrollPeopleRepository::FILTER_DPP_HOURS_LIMIT
                ? $this->agreementHours->employeesNearLimit($supplierId, $year)
                : [],
            $sortKey,
            $sortDir === 'desc',
        );
        $items = $this->withAgreementHours($supplierId, $page['items'], $year);
        $items = $this->withPositionLabels($items);
        // Kontakty se dešifrují jen na výslovnou žádost (sloupec je zapnutý)
        // a jen oprávněnému; jinak by každé otevření seznamu psalo do auditu.
        if (($query['include_contacts'] ?? '') === '1') {
            $serverParams = $request->getServerParams();
            $contacts = $this->contactColumns->forPage(
                $request,
                $supplierId,
                array_map(static fn (array $person): int => (int) $person['id'], $items),
                $this->ipMatcher->clientIpFromRequest(array_filter($serverParams, 'is_string', ARRAY_FILTER_USE_KEY)),
                $request->getHeaderLine('User-Agent'),
            );
            if (PayrollPeopleContactColumns::allowed($request)) {
                $items = array_map(static function (array $person) use ($contacts): array {
                    $person['contacts'] = $contacts[(int) $person['id']] ?? ['email' => null, 'phone' => null];

                    return $person;
                }, $items);
            }
        }

        // Klíč `items` zůstává, aby stávající volající nespadli; `total`/`limit`/
        // `offset` přibyly vedle něj, protože seznam už nemusí být úplný.
        $canSeeEnforcement = PayrollEnforcementDisclosure::canSeeEnforcement($request);

        return Json::ok($response, [
            'items' => array_map(
                static fn (array $person): array => self::disclosedPerson($person, $canSeeEnforcement),
                $items,
            ),
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

        [$person] = $this->withAgreementHours($this->currentSupplierId($request), [$person], (int) date('Y'));

        return Json::ok($response, ['person' => self::disclosedPerson(
            $person,
            PayrollEnforcementDisclosure::canSeeEnforcement($request),
        )]);
    }

    /**
     * Název pracovní pozice z číselníku CZ-ISCO. Číselník je soubor načtený
     * jednou za požadavek, takže dohledání na řádek nestojí dotaz.
     *
     * @param list<array<string,mixed>> $people
     * @return list<array<string,mixed>>
     */
    private function withPositionLabels(array $people): array
    {
        return array_map(function (array $person): array {
            $code = $person['cz_isco_code'] ?? null;
            $person['position_label'] = is_string($code) ? ($this->czIsco->find($code)['label'] ?? null) : null;

            return $person;
        }, $people);
    }

    /**
     * Roční hodiny na DPP (§ 75 ZP) k osobám, které dohodu o provedení práce
     * mají; ostatní dostanou `null`. Jeden dávkový výpočet za celou stránku.
     *
     * @param list<array<string,mixed>> $people
     * @return list<array<string,mixed>>
     */
    private function withAgreementHours(int $supplierId, array $people, int $year): array
    {
        $employeeIds = [];
        foreach ($people as $person) {
            if (in_array('dpp', (array) ($person['relation_types'] ?? []), true)) {
                $employeeIds[] = (int) $person['id'];
            }
        }
        $summaries = $this->agreementHours->yearSummaries($supplierId, $employeeIds, $year);

        return array_map(static function (array $person) use ($summaries): array {
            $person['dpp_annual_hours'] = $summaries[(int) $person['id']] ?? null;

            return $person;
        }, $people);
    }

    /**
     * ⚠️ Založení osoby je ZÁMĚRNĚ dostupné i přes API token.
     *
     * Onboarding z jiné agendy — z personálního systému, z docházky, z vlastního
     * skriptu — je přesně ta automatizace, kvůli které se integrace staví, a nic
     * na ní není nevratné: nová osoba bez pracovního vztahu do mzdy nevstoupí.
     * Zbytek mzdové agendy (výpočet, schválení, podání) session-only zůstává
     * a {@see delete()} taky — mazání kaskáduje na mzdovou historii.
     */
    public function create(Request $request, Response $response): Response
    {
        $error = null;
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

        $serverParams = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $serverParams[$key] = $value;
            }
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
            $person = $this->licenseCapacity->mutatePayrollEmployees(
                fn (): array => $this->createService->create(
                    $this->currentSupplierId($request),
                    $input,
                    $this->userId($request),
                    $this->ipMatcher->clientIpFromRequest($serverParams),
                    $request->getHeaderLine('User-Agent'),
                ),
            );
        } catch (LicensePayrollLimitExceeded $e) {
            return Json::error(
                $response,
                $e->reason === LicenseState::BLOCK_NO_LICENSE
                    ? 'license_payroll_feature_unavailable'
                    : 'license_payroll_employee_limit',
                'Dalšího aktivního zaměstnance lze přidat až po rozšíření mzdového doplňku.',
                403,
                ['buy_url' => '/activation/purchase#payroll-addon'],
            );
        } catch (PayrollPersonIdentifierCollisionException $e) {
            // Založení s rodným číslem je táž otázka „patří tahle hodnota
            // někomu?" jako uložení karty, proto stejný audit shody.
            $this->identifierProbes->recordCollision(
                $request,
                $this->currentSupplierId($request),
                null,
                $e->identifierType,
                'create',
                false,
                $this->userId($request),
                $this->ipMatcher->clientIpFromRequest($serverParams),
            );
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
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
            if (PayrollEnforcementDisclosure::isRestrictedDeleteBlockerCode(
                $e->errorCode,
                PayrollEnforcementDisclosure::canSeeEnforcement($request),
            )) {
                return Json::error(
                    $response,
                    PayrollEnforcementDisclosure::RESTRICTED_DELETE_BLOCKER,
                    PayrollEnforcementDisclosure::RESTRICTED_DELETE_MESSAGE,
                    409,
                );
            }
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
        if (!RequestAuthorization::isSessionAuth($request)) {
            $error = Json::sessionRequired($response);
            return false;
        }
        $error = null;
        return true;
    }

    /**
     * Blokátor smazání jmenuje exekuci; bez práva na agendu se nahradí obecnou
     * větou, aby seznam zaměstnanců neprozradil, na koho exekuce dorazila.
     *
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    private static function disclosedPerson(array $person, bool $canSeeEnforcement): array
    {
        if (!array_key_exists('delete_blocker', $person)) {
            return $person;
        }
        $blocker = $person['delete_blocker'];
        $person['delete_blocker'] = PayrollEnforcementDisclosure::redactDeleteBlocker(
            is_array($blocker) ? $blocker : null,
            $canSeeEnforcement,
        );

        return $person;
    }

    private function guardFailure(?Response $error): Response
    {
        if ($error === null) {
            throw new \LogicException('Payroll guard selhal bez chybové odpovědi.');
        }

        return $error;
    }
}
