<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollInputApprovalException;
use MyInvoice\Repository\Payroll\PayrollInputCancellationException;
use MyInvoice\Repository\Payroll\PayrollInputConflictException;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Component\PayrollInputPreviewService;
use MyInvoice\Service\Payroll\Component\PayrollInputValidator;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollInputsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollInputRepository $inputs,
        private readonly PayrollInputValidator $validator,
        private readonly PayrollInputPreviewService $preview,
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
        $limit = max(1, min(
            PayrollInputRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollInputRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $employmentId = self::narrowingId($query, 'employment_id');
        try {
            $period = $this->period($query['period'] ?? null);
            $page = $this->inputs->list(
                $this->currentSupplierId($request),
                $period,
                $limit,
                $offset,
                $employmentId,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        // `employment_id` se vrací zpátky, aby prohlížeč poznal zúžený prázdný
        // seznam od nezúženého — bez toho vypadá obojí stejně.
        return Json::ok($response, [
            'inputs' => $page['items'],
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
            'employment_id' => $employmentId,
        ]);
    }

    public function preview(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $data = $this->validator->validate($this->input($request));
            $preview = $this->preview->preview(
                $this->currentSupplierId($request),
                $data,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        return Json::ok($response, ['preview' => $preview]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        // ── Proč se TADY neschvaluje automaticky ───────────────────────────────
        // Jednotlivý mzdový vstup má celý životní cyklus postavený na konceptu:
        // upravit (`update()`) i zrušit (`cancel()`) jde jen koncept a teprve
        // schválení ho zmrazí. Kdyby řádek vznikal rovnou schválený, ztratil by
        // uživatel obojí hned po založení — a chybové hlášky by se zhoršily:
        // vstup navázaný na cestovní příkaz by místo „je navázaný na vyúčtování
        // cesty" hlásil obecné „špatný stav", protože by na kontrolu vazby
        // vůbec nedošlo. U benefitů by se navíc roční koš § 6 odst. 9 ZDP čerpal
        // už při zadání, tedy dřív, než si to kdokoli stihl rozmyslet.
        //
        // Klikání to nepřidává: hromadné zadávání jde přes rychlé zadání, které
        // schvaluje rovnou a umí i opravu, a na všechno ostatní je
        // {@see approveBatch()}.
        try {
            $input = $this->inputs->create(
                $this->currentSupplierId($request),
                $this->validator->validate($this->input($request)),
                $this->userId($request),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->audit($request, 'payroll.input.created', $input);
        return Json::ok($response, ['input' => $input], 201);
    }

    /**
     * Hromadné schválení mzdových vstupů.
     *
     * Bez něj se 500 zaměstnanců schvaluje po jednom řádku — tisíc kliků na
     * obrazovce, kam uživatel přišel jen proto, že mzdový běh drží blokátor
     * `draft_inputs_present`.
     *
     * Přijímá buď výčet `ids`, nebo `period` (volitelně zúžené na jeden vztah),
     * kdy si dávku poskládá server ze všech konceptů měsíce. Idempotentní: už
     * schválený vstup se hlásí jako přeskočený, ne jako chyba.
     */
    public function approveBatch(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            AccessLevel::WRITE,
            'payroll.approve',
        )) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $supplierId = $this->currentSupplierId($request);
        try {
            $ids = $this->batchIds($body);
            if ($ids === null) {
                $ids = $this->inputs->draftInputIds(
                    $supplierId,
                    $this->period($body['period'] ?? null),
                    self::narrowingId($body, 'employment_id'),
                );
            }
            $result = $this->inputs->approveBatch($supplierId, $ids, $this->userId($request));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->logger->log(
            'payroll.inputs.approved_batch',
            $this->userId($request),
            'payroll_input',
            null,
            [
                'approved_count' => count($result['approved']),
                'skipped_count' => count($result['skipped']),
                'failed_count' => count($result['failed']),
            ],
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, $result);
    }

    /**
     * @param array<string,mixed> $body
     * @return ?list<int> null = výčet nebyl poslán, dávku určí období
     */
    private function batchIds(array $body): ?array
    {
        $ids = $body['ids'] ?? null;
        if ($ids === null) {
            return null;
        }
        if (!is_array($ids) || !array_is_list($ids)) {
            throw new \InvalidArgumentException('ids musí být seznam identifikátorů.');
        }
        return array_map(
            static function (mixed $id): int {
                $value = filter_var($id, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                if ($value === false) {
                    throw new \InvalidArgumentException(
                        'ids musí obsahovat jen kladná celá čísla.',
                    );
                }
                return (int) $value;
            },
            $ids,
        );
    }

    /** @param array<string,string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $version = $this->rowVersion($body['row_version'] ?? null);
        if ($version === null) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být kladné celé číslo.',
                422,
            );
        }
        unset($body['row_version']);
        try {
            $input = $this->inputs->update(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $this->validator->validate($body),
                $version,
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollInputConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        if ($input === null) {
            return Json::error($response, 'not_found', 'Mzdový vstup nebyl nalezen.', 404);
        }
        $this->audit($request, 'payroll.input.updated', $input);
        return Json::ok($response, ['input' => $input]);
    }

    /**
     * Zrušení vlastního konceptu mzdového vstupu.
     *
     * Nulový nebo omylem založený koncept jinak zablokuje mzdový běh a jediným
     * východiskem by bylo ho schválit — čímž by se dostal na výplatní pásku.
     *
     * @param array<string,string> $args
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $version = $this->rowVersion(
            $this->input($request)['row_version'] ?? null,
        );
        if ($version === null) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být kladné celé číslo.',
                422,
            );
        }
        try {
            $input = $this->inputs->cancel(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $version,
            );
        } catch (PayrollInputCancellationException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 409);
        } catch (PayrollInputConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        if ($input === null) {
            return Json::error($response, 'not_found', 'Mzdový vstup nebyl nalezen.', 404);
        }
        $this->audit($request, 'payroll.input.cancelled', $input);
        return Json::ok($response, ['input' => $input]);
    }

    /** @param array<string,string> $args */
    public function approve(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            AccessLevel::WRITE,
            'payroll.approve',
        )) !== null) {
            return $error;
        }
        $version = $this->rowVersion(
            $this->input($request)['row_version'] ?? null,
        );
        if ($version === null) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být kladné celé číslo.',
                422,
            );
        }
        try {
            $input = $this->inputs->approve(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $version,
                $this->userId($request),
            );
        } catch (PayrollInputApprovalException $e) {
            return Json::error(
                $response,
                $e->errorCode,
                $e->getMessage(),
                409,
            );
        } catch (PayrollInputConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        if ($input === null) {
            return Json::error($response, 'not_found', 'Mzdový vstup nebyl nalezen.', 404);
        }
        $this->audit($request, 'payroll.input.approved', $input);
        return Json::ok($response, ['input' => $input]);
    }

    /**
     * Storno schváleného benefitního vstupu — uvolnění ročního koše § 6 odst. 9 ZDP.
     *
     * Vyžaduje totéž oprávnění jako schválení (`payroll.approve`): uvolnit koš je
     * stejně silné rozhodnutí jako ho vyčerpat, jen opačným směrem.
     *
     * @param array<string,string> $args
     */
    public function reverseBenefit(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            AccessLevel::WRITE,
            'payroll.approve',
        )) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $version = $this->rowVersion($body['row_version'] ?? null);
        if ($version === null) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být kladné celé číslo.',
                422,
            );
        }
        $reason = $body['reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '') {
            return Json::error(
                $response,
                'validation_failed',
                'Důvod storna je povinný — bez něj nejde zpětně doložit, proč se koš uvolnil.',
                422,
            );
        }
        try {
            $input = $this->inputs->reverseBenefit(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $version,
                $this->userId($request),
                $reason,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollInputCancellationException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 409);
        } catch (PayrollInputConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        if ($input === null) {
            return Json::error($response, 'not_found', 'Mzdový vstup nebyl nalezen.', 404);
        }
        $this->audit($request, 'payroll.input.benefit_reversed', $input);
        return Json::ok($response, ['input' => $input]);
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
        ?string $permissionOverride = null,
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
        $permission = $permissionOverride ?? (
            $level === AccessLevel::READ
                ? 'payroll'
                : 'payroll.inputs.write'
        );
        if (!$this->requirePermission(
            $request,
            $response,
            $permission,
            $level,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body)
            ? PayrollTimeValue::row($body, 'request_body')
            : [];
    }

    private function period(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('period musí být měsíc YYYY-MM.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $value);
        if ($date === false || $date->format('Y-m') !== $value) {
            throw new \InvalidArgumentException('period musí být měsíc YYYY-MM.');
        }
        return $value . '-01';
    }

    private function rowVersion(mixed $value): ?int
    {
        $version = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        return $version === false ? null : (int) $version;
    }

    /** @param array<string,mixed> $input */
    private function audit(Request $request, string $action, array $input): void
    {
        $supplierId = $this->currentSupplierId($request);
        $this->logger->log(
            $action,
            $this->userId($request),
            'payroll_input',
            PayrollTimeValue::int($input['id'] ?? null, 'id'),
            [
                'employee_id' => PayrollTimeValue::int(
                    $input['employee_id'] ?? null,
                    'employee_id',
                ),
                'employment_id' => PayrollTimeValue::int(
                    $input['employment_id'] ?? null,
                    'employment_id',
                ),
                'component_id' => PayrollTimeValue::int(
                    $input['component_id'] ?? null,
                    'component_id',
                ),
                'period_start' => PayrollTimeValue::string(
                    $input['period_start'] ?? null,
                    'period_start',
                ),
                'status' => PayrollTimeValue::string(
                    $input['status'] ?? null,
                    'status',
                ),
                'row_version' => PayrollTimeValue::int(
                    $input['row_version'] ?? null,
                    'row_version',
                ),
            ],
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }

    /** @return array<string,mixed> */
    private function serverParams(Request $request): array
    {
        return PayrollTimeValue::row($request->getServerParams(), 'server_params');
    }
}
