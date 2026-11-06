<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzContentCorrectionSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCorrectiveSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzZeroAmendmentSubmissionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Storno a opravné podání měsíčního hlášení.
 *
 * Endpoint podání jen ZMRAZÍ; odesílá se pak stejnou cestou jako řádné hlášení
 * (`POST /submissions/{id}/jmhz-transport`), takže mu patří tentýž ledger
 * pokusů, totéž dotažení protokolu i uzavření transakce. Dvě sloučené akce
 * („zmraz a rovnou pošli") by znamenaly, že se při chybě odeslání nedá poznat,
 * jestli storno vzniklo — a druhý pokus by ho založil znovu.
 *
 * Rozdíl mezi oběma akcemi je zásadní a musí být vidět i v adrese:
 * `cancel` ruší za období VŠECHNO, `cancel-components` jen vyjmenované
 * pracovněprávní vztahy.
 */
final class PayrollJmhzCorrectionAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly JmhzCorrectiveSubmissionService $corrections,
        private readonly JmhzContentCorrectionSubmissionService $contentCorrections,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly PayrollRegistrationIdentityRepository $identities,
        private readonly PayrollPeopleRepository $people,
        private readonly JmhzZeroAmendmentSubmissionService $zeroAmendments,
    ) {
    }

    /**
     * Součásti přijatého řádného hlášení a zda jde jejich měsíc anulovat
     * nulovým opravným hlášením.
     *
     * @param array{submissionId:string} $args
     */
    public function zeroAmendmentCandidates(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment === null || preg_match('/^[1-9][0-9]*$/D', $args['submissionId']) !== 1) {
            return $this->invalid($response, 'Podání nebo prostředí nulového opravného hlášení nejsou platné.');
        }
        try {
            $result = $this->zeroAmendments->candidates(
                $this->currentSupplierId($request),
                $environment,
                (int) $args['submissionId'],
            );
        } catch (JmhzXmlException|\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return Json::error($response, 'conflict', $exception->getMessage(), 409);
        }

        return Json::ok($response, $result)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /**
     * Zmrazí nulové opravné hlášení, které anuluje měsíc vybraných vztahů.
     * Situaci musí klient poslat výslovně; z dat se neodvozuje.
     *
     * @param array{submissionId:string} $args
     */
    public function freezeZeroAmendment(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        $body = $request->getParsedBody();
        $selected = is_array($body) ? ($body['form_guids'] ?? null) : null;
        $situation = is_array($body) ? ($body['situation'] ?? null) : null;
        if ($environment === null || !is_array($selected) || $selected === [] || !is_string($situation)
            || preg_match('/^[1-9][0-9]*$/D', $args['submissionId']) !== 1
        ) {
            return $this->invalid($response, 'Podání, výběr vztahů nebo situace nulového opravného hlášení nejsou platné.');
        }
        try {
            $result = $this->zeroAmendments->freeze(
                $this->currentSupplierId($request),
                $environment,
                (int) $args['submissionId'],
                array_values($selected),
                $situation,
                $this->userId($request),
            );
        } catch (JmhzXmlException|\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return Json::error($response, 'conflict', $exception->getMessage(), 409);
        }

        return Json::ok($response, $result, $result['created'] ? 201 : 200)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array{submissionId:string} $args */
    public function contentCorrectionPreparations(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment === null
            || preg_match('/^[1-9][0-9]*$/D', $args['submissionId']) !== 1
        ) {
            return $this->invalid($response, 'Podání nebo prostředí obsahové opravy nejsou platné.');
        }
        try {
            $result = $this->contentCorrections->preparationCandidates(
                $this->currentSupplierId($request),
                $environment,
                (int) $args['submissionId'],
                $this->optionalPositiveInput($request, 'office_id'),
            );
        } catch (JmhzXmlException|\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return Json::error($response, 'conflict', $exception->getMessage(), 409);
        }

        return Json::ok($response, $result)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array{submissionId:string} $args */
    public function contentCorrection(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        $preparationId = $this->positiveInput($request, 'preparation_id');
        if ($environment === null || $preparationId === null
            || preg_match('/^[1-9][0-9]*$/D', $args['submissionId']) !== 1
        ) {
            return $this->invalid($response, 'Podání, příprava nebo prostředí obsahové opravy nejsou platné.');
        }
        try {
            $officeId = $this->optionalPositiveInput($request, 'office_id');
            $result = $this->contentCorrections->candidates(
                $this->currentSupplierId($request),
                $environment,
                (int) $args['submissionId'],
                $preparationId,
                $officeId,
            );
        } catch (JmhzXmlException|\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return Json::error($response, 'conflict', $exception->getMessage(), 409);
        }

        return Json::ok($response, $result)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array{submissionId:string} $args */
    public function freezeContentCorrection(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        $preparationId = $this->positiveInput($request, 'preparation_id');
        $body = $request->getParsedBody();
        $selected = is_array($body) ? ($body['employment_external_identifiers'] ?? null) : null;
        if ($environment === null || $preparationId === null || !is_array($selected)
            || preg_match('/^[1-9][0-9]*$/D', $args['submissionId']) !== 1
        ) {
            return $this->invalid($response, 'Podání, příprava nebo výběr obsahové opravy nejsou platné.');
        }
        try {
            $officeId = $this->optionalPositiveInput($request, 'office_id');
            $result = $this->contentCorrections->freeze(
                $this->currentSupplierId($request),
                $environment,
                (int) $args['submissionId'],
                $preparationId,
                array_values($selected),
                $this->userId($request),
                $officeId,
            );
        } catch (JmhzXmlException|\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return Json::error($response, 'conflict', $exception->getMessage(), 409);
        }

        return Json::ok($response, $result, $result['created'] ? 201 : 200)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array{submissionId:string} $args */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, $args, null);
    }

    /** @param array{submissionId:string} $args */
    public function components(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }
        if (preg_match('/^[1-9][0-9]*$/D', $args['submissionId']) !== 1) {
            return $this->invalid($response, 'submissionId musí být kladné celé číslo.');
        }
        $submissionId = (int) $args['submissionId'];
        try {
            $components = $this->corrections->correctableComponents(
                $this->currentSupplierId($request),
                $environment,
                $submissionId,
            );
        } catch (JmhzXmlException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return Json::error($response, 'conflict', $exception->getMessage(), 409);
        }

        return Json::ok($response, [
            'environment' => $environment,
            'submission_id' => $submissionId,
            'components' => $this->withEmployeeNames(
                $this->currentSupplierId($request),
                $environment,
                $components,
            ),
        ])->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /**
     * Jméno zaměstnance k formuláři, aby účetní při stornu vybírala lidi,
     * ne třináctimístná ID PPV. Vztah se dohledá podle ID PPV v evidenci
     * identifikátorů; nenajde-li se (převzatá data), jméno zůstane prázdné.
     *
     * @param list<array{form_guid:string,person_external_identifier:string,employment_external_identifier:string}> $components
     * @return list<array<string,mixed>>
     */
    private function withEmployeeNames(int $supplierId, string $environment, array $components): array
    {
        $employees = [];
        foreach ($components as $index => $component) {
            try {
                $hash = $this->sensitiveData->lookupHash(
                    $component['employment_external_identifier'],
                    PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                    $supplierId,
                );
            } catch (\InvalidArgumentException) {
                continue;
            }
            $link = $this->identities->employmentByExternalIdValueHash($supplierId, $environment, 'id_ppv', $hash);
            if ($link !== null) {
                $employees[$index] = $link['employee_id'];
            }
        }
        $names = $employees === []
            ? []
            : $this->people->namesForTenant($supplierId, array_values(array_unique($employees)));
        $rows = [];
        foreach ($components as $index => $component) {
            $rows[] = $component + [
                'employee_name' => isset($employees[$index]) ? ($names[$employees[$index]] ?? null) : null,
            ];
        }

        return $rows;
    }

    /** @param array{submissionId:string} $args */
    public function cancelComponents(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $rows = $body['form_guids'] ?? null;
        if (!is_array($rows) || $rows === []) {
            return $this->invalid(
                $response,
                'Vyberte alespoň jeden pracovněprávní vztah, který se má stornovat.',
            );
        }
        $formGuids = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_string($row) || trim($row) === '') {
                return $this->invalid(
                    $response,
                    'Položka č. ' . ($index + 1) . ' nemá platný identifikátor formuláře.',
                );
            }
            $formGuids[] = trim($row);
        }

        return $this->run($request, $response, $args, $formGuids);
    }

    /**
     * @param array{submissionId:string} $args
     * @param list<string>|null $formGuids
     */
    private function run(
        Request $request,
        Response $response,
        array $args,
        ?array $formGuids,
    ): Response {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }
        if (preg_match('/^[1-9][0-9]*$/D', $args['submissionId']) !== 1) {
            return $this->invalid($response, 'submissionId musí být kladné celé číslo.');
        }
        $supplierId = $this->currentSupplierId($request);
        $submissionId = (int) $args['submissionId'];

        try {
            $result = $formGuids === null
                ? $this->corrections->cancelSubmission(
                    $supplierId,
                    $environment,
                    $submissionId,
                    $this->userId($request),
                )
                : $this->corrections->cancelComponents(
                    $supplierId,
                    $environment,
                    $submissionId,
                    $formGuids,
                    $this->userId($request),
                );
        } catch (JmhzXmlException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return Json::error($response, 'conflict', $exception->getMessage(), 409);
        }

        return Json::ok($response, $result, $result['created'] ? 201 : 200)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function environment(Request $request): ?string
    {
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body['environment'] ?? null) : null;
        if (!is_string($value)) {
            $value = $request->getQueryParams()['environment'] ?? 'production';
        }

        return in_array($value, ['test', 'production'], true) ? $value : null;
    }

    private function invalid(Response $response, string $message): Response
    {
        return Json::error($response, 'validation_failed', $message, 422)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function positiveInput(Request $request, string $name): ?int
    {
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body[$name] ?? null) : null;
        $value ??= $request->getQueryParams()[$name] ?? null;

        return is_int($value) && $value > 0
            ? $value
            : (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1
                ? (int) $value
                : null);
    }

    private function optionalPositiveInput(Request $request, string $name): ?int
    {
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body[$name] ?? null) : null;
        $value ??= $request->getQueryParams()[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $positive = $this->positiveInput($request, $name);
        if ($positive === null) {
            throw new \InvalidArgumentException('Nepovinný identifikátor musí být kladné celé číslo.');
        }

        return $positive;
    }

    private function authorize(Request $request, Response $response): ?Response
    {
        // Storno i příprava opravy jednají jménem firmy. Token se dá odcizit
        // a nemá druhý faktor, takže sem se smí jen z přihlášené relace.
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            AccessLevel::WRITE,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }
}
