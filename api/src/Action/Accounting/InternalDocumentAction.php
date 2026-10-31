<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\InternalDocumentException;
use MyInvoice\Service\Accounting\InternalDocumentService;
use MyInvoice\Service\Accounting\JournalLinkService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\CsvWriter;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Interní doklady - REST API agendy Účetnictví → Interní doklady.
 *
 *   GET    /api/accounting/internal-documents                 - seznam (filtry, řazení, stránkování)
 *   GET    /api/accounting/internal-documents/export          - CSV se stejnými filtry
 *   POST   /api/accounting/internal-documents                 - koncept
 *   GET    /api/accounting/internal-documents/{id}            - detail s řádky kontace
 *   PUT    /api/accounting/internal-documents/{id}            - úprava konceptu
 *   DELETE /api/accounting/internal-documents/{id}            - smazání konceptu
 *   POST   /api/accounting/internal-documents/{id}/post       - zaúčtování ručním zápisem deníku
 *   POST   /api/accounting/internal-documents/{id}/reverse    - storno zápisu protizápisem
 *   POST   /api/accounting/internal-documents/from-entry/{id} - hlavička nad existujícím ručním zápisem
 *
 * Práva zrcadlí ruční zápis deníku (RoutePermissionMap): čtení `accounting`, koncept
 * `accounting.journal.write`, zaúčtování a storno `accounting.journal.post`.
 */
final class InternalDocumentAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const FILTER_KEYS = ['status', 'kind', 'partner_id', 'q', 'from', 'to', 'sort_by', 'sort_dir'];

    public function __construct(
        private readonly InternalDocumentService $service,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly JournalLinkService $links,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, 'accounting', AccessLevel::READ, $err)) return $err;
        $q = $request->getQueryParams();
        return $this->run($request, $response, fn () => $this->service->list(
            $this->currentSupplierId($request), $this->filters($q),
            (int) ($q['page'] ?? 1), (int) ($q['per_page'] ?? 50),
        ));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, 'accounting', AccessLevel::READ, $err)) return $err;
        return $this->run($request, $response, fn () => $this->service->get($this->currentSupplierId($request), (int) $args['id']));
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, 'accounting.journal.write', AccessLevel::WRITE, $err)) return $err;
        return $this->run($request, $response, function () use ($request) {
            $doc = $this->service->create($this->currentSupplierId($request), $this->body($request), $this->userId($request));
            $this->log($request, 'internal_document.created', (int) $doc['id']);
            return $doc;
        }, 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, 'accounting.journal.write', AccessLevel::WRITE, $err)) return $err;
        return $this->run($request, $response, function () use ($request, $args) {
            $id = (int) $args['id'];
            $doc = $this->service->update($this->currentSupplierId($request), $id, $this->body($request), $this->userId($request));
            $this->log($request, 'internal_document.updated', $id);
            return $doc;
        });
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, 'accounting.journal.write', AccessLevel::WRITE, $err)) return $err;
        return $this->run($request, $response, function () use ($request, $args) {
            $id = (int) $args['id'];
            $this->service->deleteDraft($this->currentSupplierId($request), $id);
            $this->log($request, 'internal_document.deleted', $id);
            return ['deleted' => true];
        });
    }

    public function post(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, 'accounting.journal.post', AccessLevel::WRITE, $err)) return $err;
        return $this->run($request, $response, function () use ($request, $args) {
            $id = (int) $args['id'];
            $doc = $this->service->post($this->currentSupplierId($request), $id, $this->auditMeta($request));
            $this->log($request, 'internal_document.posted', $id, ['journal_entry_id' => $doc['journal_entry_id']]);
            return $doc;
        });
    }

    public function reverse(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, 'accounting.journal.post', AccessLevel::WRITE, $err)) return $err;
        return $this->run($request, $response, function () use ($request, $args) {
            $id = (int) $args['id'];
            $body = $this->body($request);
            $date = trim((string) ($body['entry_date'] ?? ''));
            $doc = $this->service->reverse($this->currentSupplierId($request), $id, (string) ($body['reason'] ?? ''),
                $date !== '' ? $date : null, $this->auditMeta($request));
            $this->log($request, 'internal_document.reversed', $id, ['reversal_entry_id' => $doc['reversal_entry_id']]);
            return $doc;
        });
    }

    public function fromEntry(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, 'accounting.journal.post', AccessLevel::WRITE, $err)) return $err;
        return $this->run($request, $response, function () use ($request, $args) {
            $entryId = (int) $args['id'];
            $result = $this->service->createFromEntry($this->currentSupplierId($request), $entryId,
                $this->body($request), $this->auditMeta($request));
            $doc = $result['document'];
            $this->log($request, 'internal_document.from_entry', (int) $doc['id'],
                ['journal_entry_id' => $entryId, 'document_no' => $doc['document_no'], 'entry_numbered' => $result['numbered']]);
            return $doc;
        }, 201);
    }

    /** CSV exportu se stejnými filtry jako seznam, po dávkách do dočasného proudu. */
    public function export(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, 'accounting', AccessLevel::READ, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $q = $request->getQueryParams();
        $fp = fopen('php://temp/maxmemory:2097152', 'w+');
        if ($fp === false) {
            return Json::error($response, 'export_failed', 'Export se nepodařilo připravit.', 500);
        }
        try {
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv($fp, ['Číslo', 'Datum dokladu', 'Datum účetního případu', 'Druh', 'Popis', 'Partner',
                'Částka', 'Stav', 'Zápis deníku', 'Storno zápis', 'Poznámka'], ';', '"', '');
            foreach ($this->service->exportRows($supplierId, $this->filters($q)) as $row) {
                fputcsv($fp, [
                    CsvWriter::safe($row['document_no'] ?? ''),
                    (string) $row['document_date'],
                    (string) $row['entry_date'],
                    (string) $row['kind'],
                    CsvWriter::safe($row['title']),
                    CsvWriter::safe($row['partner_name'] ?? ''),
                    number_format((float) $row['amount'], 2, ',', ''),
                    (string) $row['status'],
                    $row['journal_entry_id'] !== null ? (int) $row['journal_entry_id'] : '',
                    $row['reversal_entry_id'] !== null ? (int) $row['reversal_entry_id'] : '',
                    CsvWriter::safe($row['note'] ?? ''),
                ], ';', '"', '');
            }
        } catch (InternalDocumentException $e) {
            fclose($fp);
            return Json::error($response, 'internal_documents.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        rewind($fp);
        $this->logger->log('report.accounting_export', $this->userId($request), 'report', null,
            ['report' => 'internal_documents', 'format' => 'csv'],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);
        $label = preg_replace('/[^0-9-]/', '', (string) ($q['from'] ?? '')) ?: 'vse';
        return $response
            ->withBody(new Stream($fp))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="interni-doklady-' . $label . '.csv"')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    private function guard(Request $request, Response $response, string $permission, AccessLevel $level, ?Response &$err): bool
    {
        if (!$this->requirePermission($request, $response, $permission, $level, $err)) return false;
        return $this->requireDoubleEntry($this->db, $this->currentSupplierId($request), $response, $err);
    }

    private function filters(array $q): array
    {
        $filters = [];
        foreach (self::FILTER_KEYS as $key) {
            if (isset($q[$key]) && is_scalar($q[$key])) $filters[$key] = trim((string) $q[$key]);
        }
        return $filters;
    }

    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    private function run(Request $request, Response $response, callable $call, int $status = 200): Response
    {
        $foreign = $this->db->pdo()->inTransaction();
        try {
            $result = $call();
            if (is_array($result) && isset($result['document_links'])) {
                $result['document_links'] = $this->links->withDocumentTitles($this->currentSupplierId($request), $result['document_links']);
            }
            return Json::ok($response, $result, $status);
        } catch (InternalDocumentException $e) {
            return Json::error($response, 'internal_documents.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (ClosingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            if ($foreign && TransactionFailure::aborted($e)) {
                throw $e;
            }
            return $this->mapPostingError($response, $e);
        }
    }

    private function log(Request $request, string $action, int $id, array $details = []): void
    {
        $this->logger->log($action, $this->userId($request), 'internal_document', $id, $details,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }
}
