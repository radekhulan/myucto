<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollInputConflictException;
use MyInvoice\Repository\Payroll\PayrollQuickInputRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Component\PayrollQuickInputValidator;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollQuickInputsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollQuickInputRepository $quickInputs,
        private readonly PayrollQuickInputValidator $validator,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $query = $request->getQueryParams();
        $cardsView = ($query['view'] ?? null) === 'cards';
        // Strop je tvrdý, ne jen výchozí — z URL ho zvednout nejde. Řádek je
        // pracovní vztah, takže seznam roste s velikostí firmy.
        $limit = $cardsView ? self::cardPageLimit($query) : self::pageLimit($query);
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $employmentId = self::narrowingId($query, 'employment_id');
        try {
            $period = $this->validator->period($query['period'] ?? null);
            $month = $cardsView
                ? $this->quickInputs->employeeCards(
                    $this->currentSupplierId($request),
                    $period,
                    $limit,
                    $offset,
                    self::cardSearch($query),
                    self::cardStatus($query),
                )
                : $this->quickInputs->month(
                    $this->currentSupplierId($request),
                    $period,
                    $limit,
                    $offset,
                    $employmentId,
                );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        // Klíč `month` a jeho `items` zůstávají, aby stávající volající
        // nespadli; `total`/`limit`/`offset` přibyly vedle něj ve stejném
        // tvaru jako u ostatních stránkovaných seznamů. `employment_id` hlásí
        // uplatněné zúžení, aby prohlížeč poznal zúžené prázdno od nezúženého.
        return Json::ok($response, [
            'month' => $month,
            'total' => $month['total'],
            'limit' => $limit,
            'offset' => $offset,
            'employment_id' => $employmentId,
            ...($cardsView ? ['view' => 'cards'] : []),
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        // Stránka i zúžení se berou z URL, aby uložení vrátilo TU stránku
        // a TO zúžení, na kterém uživatel byl. Vracet natvrdo první stránku
        // celého měsíce by ho odhodilo na začátek a do formuláře nasypalo lidi,
        // které při zúžení nevidí.
        $query = $request->getQueryParams();
        $limit = self::pageLimit($query);
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $employmentId = self::narrowingId($query, 'employment_id');
        // Kdo smí schvalovat, ať nemusí totéž odklikávat podruhé na jiné
        // obrazovce. Vstup se uloží rovnou jako schválený; kdo právo nemá,
        // ukládá dál koncept a ten pak schvaluje účtárna.
        $autoApprove = RequestAuthorization::allows(
            $request,
            'payroll.approve',
            AccessLevel::WRITE,
        );
        $failures = [];
        try {
            $body = $request->getParsedBody();
            $data = $this->validator->validate(
                is_array($body) ? PayrollTimeValue::row($body, 'request_body') : [],
            );
            $month = $this->quickInputs->save(
                $this->currentSupplierId($request),
                $data['period'],
                $data['rows'],
                $this->userId($request),
                $limit,
                $offset,
                $employmentId,
                $autoApprove,
                $failures,
            );
        } catch (PayrollEmploymentConflictException $e) {
            return Json::error(
                $response,
                'employment_row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            );
        } catch (PayrollInputConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (\DomainException $e) {
            return Json::error($response, 'input_state_conflict', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        // Neuložilo se NIC? Pak to není částečný výsledek, ale chyba, a musí
        // odejít se svým kódem — jinak by se z jediného konfliktu verzí stalo
        // „uloženo" s poznámkou pod čarou.
        $failedEmployments = array_unique(array_column($failures, 'employment_id'));
        if ($failures !== [] && count($failedEmployments) === count($data['rows'])) {
            $first = $failures[0];
            return Json::error(
                $response,
                $first['code'],
                $first['message'],
                $first['code'] === 'validation_failed' ? 422 : 409,
                array_filter(
                    [
                        'current_row_version' => $first['current_row_version'],
                        'failures' => $failures,
                    ],
                    static fn (mixed $value): bool => $value !== null,
                ),
            );
        }
        $this->logger->log(
            'payroll.quick_inputs.saved',
            $this->userId($request),
            'payroll_month',
            null,
            [
                'period' => $data['period'],
                'employment_count' => count($data['rows']),
                'auto_approved' => $autoApprove,
                'failed_count' => count($failures),
            ],
            $this->ipMatcher->clientIpFromRequest(
                PayrollTimeValue::row($request->getServerParams(), 'server_params'),
            ),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
        return Json::ok($response, [
            'month' => $month,
            'total' => $month['total'],
            'limit' => $limit,
            'offset' => $offset,
            'employment_id' => $employmentId,
            // Co se neuložilo a proč — pole po poli. Prohlížeč to musí umět
            // ukázat u konkrétního políčka; zredukovat to na jeden toast
            // „nepodařilo se" znamená poslat uživatele hádat.
            'failures' => $failures,
        ]);
    }

    /** @param array<array-key,mixed> $query */
    private static function pageLimit(array $query): int
    {
        return max(1, min(
            PayrollQuickInputRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollQuickInputRepository::LIST_DEFAULT_LIMIT),
        ));
    }

    /** @param array<array-key,mixed> $query */
    private static function cardPageLimit(array $query): int
    {
        return max(1, min(
            PayrollQuickInputRepository::CARD_PAGE_LIMIT,
            (int) ($query['limit'] ?? PayrollQuickInputRepository::CARD_PAGE_LIMIT),
        ));
    }

    /** @param array<array-key,mixed> $query */
    private static function cardSearch(array $query): string
    {
        $search = $query['search'] ?? '';
        if (!is_string($search)) {
            throw new \InvalidArgumentException('Hledaný text musí být řetězec.');
        }
        $search = trim($search);
        if (mb_strlen($search, 'UTF-8') > 120) {
            throw new \InvalidArgumentException('Hledaný text může mít nejvýše 120 znaků.');
        }

        return $search;
    }

    /** @param array<array-key,mixed> $query */
    private static function cardStatus(array $query): string
    {
        $status = $query['status'] ?? 'active';
        if (!is_string($status) || !in_array(
            $status,
            ['active', 'away', 'attention', 'all'],
            true,
        )) {
            throw new \InvalidArgumentException('Neplatný filtr stavu zaměstnanců.');
        }

        return $status;
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
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
        $permission = $level === AccessLevel::READ ? 'payroll' : 'payroll.inputs.write';
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }
        return null;
    }
}
