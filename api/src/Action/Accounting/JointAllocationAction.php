<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JointAllocationKeyRepository;
use MyInvoice\Repository\JointAllocationReadRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Allocation\JointAllocationException;
use MyInvoice\Service\Accounting\Allocation\JointAllocationJournalAdapter;
use MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion;
use MyInvoice\Service\Accounting\Allocation\JointAllocationPreviewService;
use MyInvoice\Service\Accounting\Allocation\JointAllocationService;
use MyInvoice\Service\Accounting\PostingException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class JointAllocationAction
{
    use AccountingActionSupport;

    public function __construct(private readonly Connection $db, private readonly JournalEntryRepository $journal) {}

    public function keys(Request $request, Response $response): Response
    {
        return $this->run($request, $response, false, fn (int $supplierId): array => (new JointAllocationKeyRepository($this->db))->list($supplierId));
    }

    public function candidates(Request $request, Response $response): Response
    {
        return $this->run($request, $response, false, fn (int $supplierId): array =>
            (new \MyInvoice\Service\Accounting\Allocation\JointAllocationCandidateService($this->db))->list($supplierId, $request->getQueryParams()));
    }

    public function revenueProposal(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        return $this->run($request, $response, false, fn (int $supplierId): array =>
            (new \MyInvoice\Service\Accounting\Allocation\JointRevenueProposalService($this->db))->build($supplierId, $query['from'] ?? null, $query['to'] ?? null));
    }

    public function batches(Request $request, Response $response): Response
    {
        return $this->run($request, $response, false, fn (int $supplierId): array => (new JointAllocationReadRepository($this->db))->list(
            $supplierId, isset($request->getQueryParams()['before_id']) ? max(1, (int) $request->getQueryParams()['before_id']) : PHP_INT_MAX));
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, false, fn (int $supplierId): array => (new JointAllocationReadRepository($this->db))->find($supplierId, (int) ($args['id'] ?? 0)));
    }

    public function createKey(Request $request, Response $response): Response
    {
        return $this->run($request, $response, true, function (int $supplierId) use ($request): array {
            $body = (array) $request->getParsedBody();
            if (!is_string($body['code'] ?? null) || !is_string($body['name'] ?? null) || !is_array($body['version'] ?? null)) {
                throw new JointAllocationException('allocation_invalid_key', 'Zadejte kód, název a verzi alokačního klíče.');
            }
            return (new JointAllocationKeyRepository($this->db))->create($supplierId, $body['code'],
                $body['name'], $body['version'], $this->userId($request));
        });
    }

    public function createVersion(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, true, fn (int $supplierId): array => (new JointAllocationKeyRepository($this->db))->addVersion(
            $supplierId, (int) ($args['id'] ?? 0), (array) $request->getParsedBody(), $this->userId($request)));
    }

    public function preview(Request $request, Response $response): Response
    {
        return $this->run($request, $response, false, function (int $supplierId) use ($request): array {
            $body = (array) $request->getParsedBody();
            if (!is_int($body['key_version_id'] ?? null) || $body['key_version_id'] <= 0 || !is_array($body['source_line_ids'] ?? null)) {
                throw new JointAllocationException('allocation_invalid_id', 'Vyberte verzi klíče a zdrojové řádky.');
            }
            return (new JointAllocationPreviewService($this->db))->preview($supplierId, $body['key_version_id'],
                JointAllocationKeyVersion::date($body['target_date'] ?? null), $body['source_line_ids'], \MyInvoice\Service\Accounting\Allocation\JointAllocationExactCells::normalize($body['exact_cells'] ?? []));
        });
    }

    public function apply(Request $request, Response $response): Response
    {
        return $this->run($request, $response, true, fn (int $supplierId): array => $this->service()->apply($supplierId,
            (array) $request->getParsedBody(), $this->userId($request)));
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, true, fn (int $supplierId): array => $this->service()->cancel($supplierId,
            array_replace((array) $request->getParsedBody(), ['batch_id' => (int) ($args['id'] ?? 0)]), $this->userId($request)));
    }

    private function service(): JointAllocationService
    {
        return new JointAllocationService($this->db, new JointAllocationJournalAdapter($this->journal));
    }

    private function run(Request $request, Response $response, bool $write, callable $work): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting', $write ? AccessLevel::WRITE : AccessLevel::READ, $err)) return $err;
        if ($write && !RequestAuthorization::isSessionAuth($request)) {
            return Json::error($response, 'allocation_session_only', 'Společné alokace lze měnit pouze z webového rozhraní.', 403);
        }
        try {
            return Json::ok($response, $work($this->currentSupplierId($request)));
        } catch (JointAllocationException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        } catch (PostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        }
    }
}
