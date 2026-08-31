<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollSubmissionConflictException;
use MyInvoice\Repository\Payroll\PayrollSubmissionInboxRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\PayrollObligationSubjectFormatter;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionInboxService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollSubmissionInboxAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollSubmissionInboxService $inbox,
        private readonly PayrollSubmissionInboxRepository $items,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->authorize($request, $response, AccessLevel::READ, $error)) {
            return $this->errorResponse($error);
        }

        $query = $request->getQueryParams();
        try {
            $environment = $this->environment($query['environment'] ?? null);
            $status = $this->statusFilter($query['status'] ?? null);
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        // Strop je tvrdý, ne jen výchozí — z URL ho zvednout nejde.
        $limit = max(1, min(
            PayrollSubmissionInboxRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollSubmissionInboxRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        // Derivace položek se musí odehrát před čtením — je to jediné místo,
        // kde položky inboxu vznikají. Čte se ale až stránka, ne celý inbox.
        $supplierId = $this->currentSupplierId($request);
        $this->inbox->sync($supplierId, $environment);
        // Stav filtruje SERVER, aby `total` popisoval právě ty řádky, které
        // stránka ukáže. Výchozí `unresolved` je to, co inbox jako pracovní
        // seznam chce; vyřešené položky zůstávají dohledatelné parametrem.
        $page = $this->items->listItemsPage(
            $supplierId,
            $environment,
            $limit,
            $offset,
            $status,
        );

        // `subject_reference` je interní složený klíč — účetní s ním nic
        // neudělá. `subject_label` dodává jen to, co jde ověřit ze sdíleného
        // formátovače (viz jeho docblock); zbytek zůstává `null`, ne hádaný.
        $items = array_map(
            static fn (array $item): array => $item + [
                'subject_label' => PayrollObligationSubjectFormatter::humanSubject(
                    $item['agenda_code'],
                    $item['subject_reference'],
                ),
            ],
            $page['items'],
        );

        // `summary` se počítá nad celým inboxem, ne nad stránkou — jinak by
        // „kolik toho čeká" záviselo na tom, kde uživatel v seznamu je.
        return Json::ok($response, [
            'environment' => $environment,
            'status' => $status,
            'summary' => $this->items->statusSummary($supplierId, $environment),
            'items' => $items,
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
        ])
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function acknowledge(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize($request, $response, AccessLevel::WRITE, $error)) {
            return $this->errorResponse($error);
        }
        $itemId = $this->positiveInteger($args['itemId'] ?? null);
        $userId = $this->userId($request);
        $body = $this->objectBody($request);
        if ($itemId === null || $userId === null || $body === null) {
            return Json::error(
                $response,
                'validation_failed',
                'Tělo požadavku musí obsahovat platnou verzi položky.',
                422,
            );
        }
        $rowVersion = filter_var(
            $body['row_version'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if (!is_int($rowVersion)) {
            return Json::error(
                $response,
                'validation_failed',
                'Verze položky musí být kladné celé číslo.',
                422,
            );
        }

        $supplierId = $this->currentSupplierId($request);
        try {
            $result = $this->inbox->acknowledge(
                $supplierId,
                $itemId,
                $rowVersion,
                $userId,
            );
        } catch (PayrollSubmissionConflictException $exception) {
            return Json::error(
                $response,
                'conflict',
                $exception->getMessage(),
                409,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException $exception) {
            return Json::error(
                $response,
                'not_found',
                $exception->getMessage(),
                404,
            );
        }

        $this->activity->log(
            'payroll.submission_inbox_acknowledged',
            $userId,
            'payroll_submission_inbox_item',
            $itemId,
            ['row_version' => $result['row_version']],
            $this->ipMatcher->clientIpFromRequest(
                $this->serverParams($request),
            ),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, $result);
    }

    /** @param array<string,string> $args */
    public function snooze(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize($request, $response, AccessLevel::WRITE, $error)) {
            return $this->errorResponse($error);
        }
        $itemId = $this->positiveInteger($args['itemId'] ?? null);
        $userId = $this->userId($request);
        $body = $this->objectBody($request);
        if ($itemId === null || $userId === null || $body === null) {
            return Json::error(
                $response,
                'validation_failed',
                'Tělo požadavku musí obsahovat verzi, termín a důvod odložení.',
                422,
            );
        }
        $rowVersion = filter_var(
            $body['row_version'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $snoozedUntil = $body['snoozed_until'] ?? null;
        $reason = $body['reason'] ?? null;
        if (!is_int($rowVersion)
            || !is_string($snoozedUntil)
            || !is_string($reason)
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Tělo požadavku musí obsahovat verzi, termín a důvod odložení.',
                422,
            );
        }

        $supplierId = $this->currentSupplierId($request);
        try {
            $result = $this->inbox->snooze(
                $supplierId,
                $itemId,
                $rowVersion,
                $snoozedUntil,
                $reason,
                $userId,
            );
        } catch (PayrollSubmissionConflictException $exception) {
            return Json::error(
                $response,
                'conflict',
                $exception->getMessage(),
                409,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException $exception) {
            return Json::error(
                $response,
                'not_found',
                $exception->getMessage(),
                404,
            );
        }

        $this->activity->log(
            'payroll.submission_inbox_snoozed',
            $userId,
            'payroll_submission_inbox_item',
            $itemId,
            [
                'row_version' => $result['row_version'],
                'snoozed_until' => $result['snoozed_until'],
            ],
            $this->ipMatcher->clientIpFromRequest(
                $this->serverParams($request),
            ),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, $result);
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $minimum,
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
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            $minimum,
            $error,
        )) {
            return false;
        }

        return $this->requirePayrollEnabled(
            $request,
            $response,
            $this->access,
            $error,
        );
    }

    private function errorResponse(?Response $error): Response
    {
        if ($error === null) {
            throw new \LogicException(
                'Chybí odpověď pro zamítnuté oprávnění.',
            );
        }

        return $error;
    }

    private function environment(mixed $value): string
    {
        if ($value === null) {
            return 'production';
        }
        if (!is_string($value)
            || !in_array($value, ['production', 'test'], true)
        ) {
            throw new \InvalidArgumentException(
                'Prostředí inboxu musí být production nebo test.',
            );
        }

        return $value;
    }

    private function statusFilter(mixed $value): string
    {
        if ($value === null || $value === '') {
            return PayrollSubmissionInboxRepository::STATUS_FILTER_DEFAULT;
        }
        if (!is_string($value)
            || !in_array($value, PayrollSubmissionInboxRepository::STATUS_FILTERS, true)
        ) {
            throw new \InvalidArgumentException(
                'Výběr stavu inboxu musí být jeden z: '
                    . implode(', ', PayrollSubmissionInboxRepository::STATUS_FILTERS) . '.',
            );
        }

        return $value;
    }

    /** @return array<string,mixed>|null */
    private function objectBody(Request $request): ?array
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || array_is_list($body)) {
            return null;
        }

        return $body;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $result = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        return is_int($result) ? $result : null;
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
}
