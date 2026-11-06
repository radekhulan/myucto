<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollInputApprovalException;
use MyInvoice\Repository\Payroll\PayrollInputCancellationException;
use MyInvoice\Repository\Payroll\PayrollInputConflictException;
use MyInvoice\Repository\Payroll\PayrollInputFilter;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Component\PayrollInputPreviewService;
use MyInvoice\Service\Payroll\Component\PayrollInputValidator;
use MyInvoice\Service\Payroll\PayrollClosedRunGuard;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Payroll\PayrollRunClosedException;
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
        private readonly PayrollHistoricalPeriodService $historicalPeriods,
        private readonly PayrollClosedRunGuard $closedRuns,
    ) {}

    /**
     * Výpis mzdových vstupů: jeden měsíc (`period`), nebo rozsah měsíců
     * (`period` + `period_to`) — na kartě zaměstnance se seznam čte jako
     * historie vztahu, ne jako jeden měsíc. Se `group_by=period` se rozsah
     * svine na řádek na měsíc.
     */
    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ, null, true)) !== null) {
            return $error;
        }
        $query = $request->getQueryParams();
        $limit = max(1, min(
            PayrollInputRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollInputRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $supplierId = $this->currentSupplierId($request);
        $groupBy = $query['group_by'] ?? null;
        try {
            $filter = PayrollInputFilter::fromArray(
                $this->period($query['period'] ?? null),
                [...$query, 'employment_id' => self::narrowingId($query, 'employment_id')],
            );
            if ($groupBy !== null && $groupBy !== ''
                && !in_array($groupBy, PayrollInputFilter::GROUP_BY, true)
            ) {
                throw new \InvalidArgumentException(
                    'group_by smí být employee, component nebo period.',
                );
            }
            $grouped = is_string($groupBy) && $groupBy !== '';
            $summary = $this->inputs->summary($supplierId, $filter);
            $page = $grouped
                ? ['items' => [], 'total' => $summary['total']]
                : $this->inputs->listFiltered($supplierId, $filter, $limit, $offset);
            $groups = $grouped
                ? $this->inputs->groups($supplierId, $filter, $groupBy, $limit, $offset)
                : null;
            $facets = $this->inputs->facets(
                $supplierId,
                $filter->periodStart,
                $filter->employmentId,
                $filter->periodEnd,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        // `employment_id` se vrací zpátky, aby prohlížeč poznal zúžený prázdný
        // seznam od nezúženého — bez toho vypadá obojí stejně. Souhrn je za CELÝ
        // filtr: podle stránky by „Schválit" ukazovalo jen těch pětadvacet.
        return Json::ok($response, [
            'inputs' => $page['items'],
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
            'employment_id' => $filter->employmentId,
            'summary' => $summary,
            'group_by' => $grouped ? $groupBy : null,
            'groups' => $groups['items'] ?? null,
            'group_total' => $groups['total'] ?? null,
            'facets' => $facets,
            'filter' => $filter->toArray(),
            /*
             * Koncepty za období, které vedl předchozí program, se jen OZNAČÍ.
             * Schválit je nejde k ničemu použít — mzdový běh za takový měsíc
             * nejde založit — ale smazat ani schovat se nesmí: jsou podkladem
             * pro srovnávací sestavu a pro počáteční stavy kumulací.
             *
             * U rozsahu měsíců se za historický považuje jen výpis, který
             * NECELÝ leží před začátkem. Kdyby stačil první měsíc rozsahu,
             * označil by se i výpis „leden až prosinec" u firmy, která vede
             * mzdy od června — a ta polovina roku, kterou MyÚčto počítá, by
             * v něm zmizela pod hlavičkou historie.
             */
            ...$this->historicalPeriods->describe(
                $supplierId,
                substr($filter->periodEnd ?? $filter->periodStart, 0, 7),
            ),
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
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE, null, true)) !== null) {
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
            $data = $this->validator->validate($this->input($request));
            $this->assertMonthOpen($this->currentSupplierId($request), $data);
            $input = $this->inputs->create(
                $this->currentSupplierId($request),
                $data,
                $this->userId($request),
            );
        } catch (PayrollRunClosedException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 409);
        } catch (\MyInvoice\Service\Payroll\PayrollHourlyWageConflictException $e) {
            return Json::error($response, $e::ERROR_CODE, $e->getMessage(), 409);
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
     * Přijímá buď výčet `ids` (nejvýše {@see PayrollInputRepository::APPROVE_BATCH_MAX}),
     * nebo `period` s volitelným filtrem (`filter` — tytéž parametry jako výpis),
     * kdy server projde VŠECHNY koncepty odpovídající filtru po dávkách. Na
     * velkém měsíci může odpovědět `complete = false`; prohlížeč pak pošle
     * `after_id = next_after_id` a pokračuje. Idempotentní: už schválený vstup
     * se hlásí jako přeskočený, ne jako chyba.
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
        $userId = $this->userId($request);
        $filter = null;
        try {
            $ids = $this->batchIds($body);
            if ($ids === null) {
                $filter = $this->batchFilter($body);
                $result = $this->inputs->approveByFilter(
                    $supplierId,
                    $filter,
                    $userId,
                    $this->afterId($body),
                );
            } else {
                $result = [
                    ...$this->inputs->approveBatch($supplierId, $ids, $userId),
                    'remaining' => 0,
                    'complete' => true,
                    'next_after_id' => 0,
                ];
            }
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->logger->log(
            'payroll.inputs.approved_batch',
            $userId,
            'payroll_input',
            null,
            [
                'approved_count' => count($result['approved']),
                'skipped_count' => count($result['skipped']),
                'failed_count' => count($result['failed']),
                'remaining' => $result['remaining'],
                'filter' => $filter?->toArray(),
            ],
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, $result);
    }

    /**
     * Hromadné zrušení konceptů — výčtem `ids` nebo podle filtru, stejně jako
     * {@see approveBatch()}.
     *
     * Každý vstup prochází stejnými zábranami jako jednotlivé zrušení: vstup
     * navázaný na vyúčtování cesty nebo zmrazený v revizi běhu se nezruší a
     * vrátí se ve `failed` s důvodem. Do auditu jde výčet zrušených id — zrušení
     * nejde vrátit, takže stopa musí říct přesně co.
     */
    public function cancelBatch(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $supplierId = $this->currentSupplierId($request);
        $filter = null;
        try {
            $ids = $this->batchIds($body);
            if ($ids === null) {
                $filter = $this->batchFilter($body);
                $result = $this->inputs->cancelByFilter($supplierId, $filter, $this->afterId($body));
            } else {
                $result = [
                    ...$this->inputs->cancelBatch($supplierId, $ids),
                    'remaining' => 0,
                    'complete' => true,
                    'next_after_id' => 0,
                ];
            }
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->logger->log(
            'payroll.inputs.cancelled_batch',
            $this->userId($request),
            'payroll_input',
            null,
            [
                'cancelled_ids' => $result['cancelled'],
                'skipped_count' => count($result['skipped']),
                'failed_count' => count($result['failed']),
                'remaining' => $result['remaining'],
                'filter' => $filter?->toArray(),
            ],
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, $result);
    }

    /**
     * Filtr hromadné akce: `period` + volitelné `filter` (tytéž klíče jako
     * výpis). Starší tvar `{period, employment_id}` platí dál.
     *
     * Rozsah období (`period_to`) se tudy VĚDOMĚ nepustí a odmítne se jako
     * chyba vstupu. Hromadné schválení a zrušení jede přes `approveByFilter()` /
     * `cancelByFilter()`, tedy přes všechny koncepty odpovídající filtru —
     * s rozsahem by jedno kliknutí sáhlo i na uzavřené měsíce, které uživatel
     * má na obrazovce jen jako historii. Tiché ignorování by bylo horší než
     * odmítnutí: prohlížeč by poslal rozsah, dostal 200 a nikde by se
     * nedozvěděl, že se zpracoval jediný měsíc.
     *
     * @param array<string,mixed> $body
     */
    private function batchFilter(array $body): PayrollInputFilter
    {
        $filter = $body['filter'] ?? [];
        if (!is_array($filter) || ($filter !== [] && array_is_list($filter))) {
            throw new \InvalidArgumentException('filter musí být objekt.');
        }
        /** @var array<string,mixed> $filter */
        foreach ([$body['period_to'] ?? null, $filter['period_to'] ?? null] as $periodTo) {
            if ($periodTo !== null && $periodTo !== '') {
                throw new \InvalidArgumentException(
                    'Hromadná akce jede vždy nad jedním obdobím; period_to tu není '
                    . 'podporované.',
                );
            }
        }
        if (!array_key_exists('employment_id', $filter) && array_key_exists('employment_id', $body)) {
            $filter['employment_id'] = $body['employment_id'];
        }

        return PayrollInputFilter::fromArray($this->period($body['period'] ?? null), $filter);
    }

    /** @param array<string,mixed> $body */
    private function afterId(array $body): int
    {
        $value = $body['after_id'] ?? 0;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($id === false) {
            throw new \InvalidArgumentException('after_id musí být nezáporné celé číslo.');
        }

        return (int) $id;
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
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE, null, true)) !== null) {
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
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $current = $this->inputs->find($supplierId, $id);
        if ($current === null) {
            return Json::error($response, 'not_found', 'Mzdový vstup nebyl nalezen.', 404);
        }
        try {
            $data = $this->validator->validate([...self::storedInputBody($current), ...$body]);
            // Uzavřený nesmí být ani měsíc, odkud vstup odchází, ani ten, kam jde.
            $this->assertMonthOpen($supplierId, $current);
            $this->assertMonthOpen($supplierId, $data);
            $input = $this->inputs->update(
                $supplierId,
                $id,
                $data,
                $version,
            );
        } catch (PayrollRunClosedException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 409);
        } catch (\MyInvoice\Service\Payroll\PayrollHourlyWageConflictException $e) {
            return Json::error($response, $e::ERROR_CODE, $e->getMessage(), 409);
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
     * Uložený vstup v tvaru těla požadavku — úprava přebírá, co klient
     * neposlal. `source_kind` se neskládá: úprava ho stejně bere z uloženého
     * původu ({@see PayrollInputRepository::update()}).
     *
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    private static function storedInputBody(array $current): array
    {
        $month = static fn (mixed $date): ?string => is_string($date) && $date !== ''
            ? substr($date, 0, 7)
            : null;

        return [
            'employee_id' => $current['employee_id'] ?? null,
            'employment_id' => $current['employment_id'] ?? null,
            'component_id' => $current['component_id'] ?? null,
            'period' => $month($current['period_start'] ?? null),
            'source_period' => $month($current['source_period_start'] ?? null),
            'amount_minor' => $current['amount_minor'] ?? null,
            'quantity_milliunits' => $current['quantity_milliunits'] ?? null,
            'external_id' => $current['external_id'] ?? null,
        ];
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
            $current = $this->inputs->find($this->currentSupplierId($request), (int) ($args['id'] ?? 0));
            if ($current !== null) {
                $this->assertMonthOpen($this->currentSupplierId($request), $current);
            }
            $input = $this->inputs->approve(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $version,
                $this->userId($request),
            );
        } catch (PayrollRunClosedException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 409);
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

    /**
     * Ruční vstup do měsíce, za který je mzda vztahu uzavřená, se odmítá.
     * Automatické cesty (materializace absencí, ukončení vztahu, převzetí)
     * jdou přímo přes repozitář a mají vlastní pravidla.
     *
     * @param array<string,mixed> $input
     */
    private function assertMonthOpen(int $supplierId, array $input): void
    {
        $period = $input['period_start'] ?? null;
        $employmentId = $input['employment_id'] ?? null;
        if (!is_string($period) || $period === '' || !is_numeric($employmentId)) {
            return;
        }
        $this->closedRuns->assertOpen($supplierId, $period, (int) $employmentId);
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
        ?string $permissionOverride = null,
        bool $allowBearer = false,
    ): ?Response {
        if (!$allowBearer && !RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
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
