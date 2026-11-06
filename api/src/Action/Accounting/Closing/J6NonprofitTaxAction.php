<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Closing;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentConfirmationService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDocumentRenderer;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDraftQueryService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDraftService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentPreviewService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentReadService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentWithdrawalService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6DraftContext;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6HttpCursor;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6LegacyAssessmentConversionService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

final class J6NonprofitTaxAction
{
    use AccountingActionSupport;

    private const COLLECTIONS = ['decisions' => 'decisionPage', 'activities' => 'activityPage',
        'funding-pools' => 'fundingPoolPage', 'funding-links' => 'fundingLinkPage'];
    private const PATCHES = ['decisions' => 'patchDecisions', 'activities' => 'patchActivities',
        'funding-pools' => 'patchFundingPools', 'funding-links' => 'patchFundingLinks'];
    private const PREVIEW_KINDS = ['decision', 'result', 'coverage', 'usage', 'funding_reference', 'funding_history'];

    public function __construct(
        private readonly J6AssessmentDraftService $drafts,
        private readonly J6AssessmentDraftQueryService $queries,
        private readonly J6AssessmentPreviewService $previews,
        private readonly J6AssessmentConfirmationService $confirmation,
        private readonly J6AssessmentReadService $assessments,
        private readonly J6HttpCursor $cursors,
        private readonly AccountingPeriodRepository $periods,
        private readonly NonprofitTaxSnapshotManifestRepository $snapshots,
        private readonly J6AssessmentWithdrawalService $withdrawal,
        private readonly J6LegacyAssessmentConversionService $conversion,
        private readonly J6AssessmentDocumentRenderer $documents,
    ) {}

    public function source(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, fn(int $supplier): array =>
            $this->assessments->currentSourceHeader($supplier, (int) $args['periodId']));
    }

    public function open(Request $request, Response $response, array $args): Response
    {
        return $this->draftHandle($request, $response, true, function(int $supplier) use ($request, $args): array {
            $body = $this->body($request, ['profile', 'method'], ['profile']);
            $this->profile($body);
            $draft = $this->drafts->openCurrent($supplier, (int) $args['periodId'], $body['profile'], $body['method'] ?? null, $this->actor($request));
            return $this->envelope($request, $draft);
        });
    }

    public function currentDraft(Request $request, Response $response, array $args): Response
    {
        return $this->draftHandle($request, $response, false, function(int $supplier) use ($request, $args): array {
            $draft = $this->queries->current($supplier, (int) $args['periodId'], $this->actor($request));
            return $draft === null ? ['draft' => null, 'capabilities' => null] : $this->envelope($request, $draft);
        });
    }

    public function getDraft(Request $request, Response $response, array $args): Response
    {
        return $this->draftHandle($request, $response, false, fn(int $supplier): array =>
            $this->envelope($request, $this->draft($request, $supplier, $args)));
    }

    public function reset(Request $request, Response $response, array $args): Response
    {
        return $this->draftHandle($request, $response, true, function(int $supplier) use ($request, $args): array {
            $body = $this->body($request, ['expected_revision', 'profile', 'method'], ['expected_revision', 'profile']);
            $this->profile($body);
            $draft = $this->drafts->resetCurrent($supplier, (int) $args['periodId'], (int) $args['draftId'],
                $this->positive($body['expected_revision']), $body['profile'], $body['method'] ?? null, $this->actor($request));
            return $this->envelope($request, $draft);
        });
    }

    public function draftSource(Request $request, Response $response, array $args): Response
    {
        return $this->draftHandle($request, $response, false, function(int $supplier) use ($request, $args): array {
            $draft = $this->draft($request, $supplier, $args);
            $context = $this->context($request, $draft);
            $limit = $this->limit($request, 100);
            $binding = $this->binding($request, $draft, 'draft', 'source', $limit);
            $position = $this->after($request, $binding);
            $page = $this->queries->sourcePage($context, $this->ordinal($position));
            return ['draft' => $page['draft']] + $this->sourceSlice($page, $position, $limit, $binding);
        });
    }

    public function collection(Request $request, Response $response, array $args): Response
    {
        return $this->draftHandle($request, $response, false, function(int $supplier) use ($request, $args): array {
            $draft = $this->draft($request, $supplier, $args);
            $kind = (string) $args['collection'];
            $method = self::COLLECTIONS[$kind] ?? throw new \InvalidArgumentException('Collection is invalid.');
            $limit = $this->limit($request);
            $binding = $this->binding($request, $draft, 'draft', $kind, $limit);
            $page = $this->queries->$method($this->context($request, $draft), $this->after($request, $binding), $limit);
            return $this->page($page, $binding);
        });
    }

    public function patch(Request $request, Response $response, array $args): Response
    {
        return $this->draftHandle($request, $response, true, function(int $supplier) use ($request, $args): array {
            $body = $this->patchBody($request);
            $method = self::PATCHES[(string) $args['collection']] ?? throw new \InvalidArgumentException('Collection is invalid.');
            $draft = $this->drafts->$method($supplier, (int) $args['periodId'], (int) $args['draftId'],
                $this->positive($body['expected_revision']), $body['upserts'], $body['deletes'], $this->actor($request));
            return $this->envelope($request, $draft);
        });
    }

    public function members(Request $request, Response $response, array $args): Response
    {
        return $this->draftHandle($request, $response, false, function(int $supplier) use ($request, $args): array {
            $draft = $this->draft($request, $supplier, $args);
            $limit = $this->limit($request);
            $binding = $this->binding($request, $draft, 'draft', 'funding-members', $limit)
                + ['pool_kind' => (string) $args['poolKind'], 'anchor' => (string) $args['anchor']];
            return $this->page($this->queries->fundingMemberPoolPage($this->context($request, $draft),
                (string) $args['poolKind'], (string) $args['anchor'], $this->after($request, $binding), $limit), $binding);
        });
    }

    public function patchMembers(Request $request, Response $response, array $args): Response
    {
        return $this->draftHandle($request, $response, true, function(int $supplier) use ($request, $args): array {
            $body = $this->patchBody($request);
            $draft = $this->drafts->patchFundingMembers($supplier, (int) $args['periodId'], (int) $args['draftId'],
                $this->positive($body['expected_revision']), (string) $args['poolKind'], (string) $args['anchor'],
                $body['upserts'], $body['deletes'], $this->actor($request));
            return $this->envelope($request, $draft);
        });
    }

    public function preview(Request $request, Response $response, array $args): Response
    {
        return $this->draftHandle($request, $response, true, function(int $supplier) use ($request, $args): array {
            $body = $this->body($request, ['expected_revision'], ['expected_revision']);
            $receipt = $this->previews->prepare($this->context($request, $this->draft($request, $supplier, $args), $this->positive($body['expected_revision'])));
            return ['header' => $receipt->header->toArray(), 'root_hashes' => $receipt->rootHashes,
                'counts' => $receipt->counts, 'evidence_root_hashes' => $receipt->evidenceRootHashes,
                'evidence_counts' => $receipt->evidenceCounts, 'summary' => $receipt->summary,
                'preview_token' => $receipt->previewToken, 'expires_in' => 900];
        });
    }

    public function previewPage(Request $request, Response $response, array $args): Response
    {
        return $this->draftHandle($request, $response, false, function(int $supplier) use ($request, $args): array {
            $kind = (string) $args['kind'];
            if (!in_array($kind, self::PREVIEW_KINDS, true)) throw new \InvalidArgumentException('Preview kind is invalid.');
            $pageNo = $this->queryInt($request, 'page', 0, 0, PHP_INT_MAX);
            try {
                $page = $this->previews->page((string) $args['token'], $this->context($request, $this->draft($request, $supplier, $args)), $kind, intdiv($pageNo, 5));
            } catch (\UnexpectedValueException) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_preview_not_found', 'Náhled není dostupný. Vytvořte jej znovu.', 404);
            }
            $offset = ($pageNo % 5) * 100;
            if ($offset > count($page['rows'])) throw new \InvalidArgumentException('Preview page is invalid.');
            return ['kind' => $kind, 'rows' => array_slice($page['rows'], $offset, 100),
                'next_page' => count($page['rows']) > $offset + 100 ? $pageNo + 1
                    : ($page['next_page'] === null ? null : $page['next_page'] * 5)];
        });
    }

    public function confirm(Request $request, Response $response, array $args): Response
    {
        return $this->draftHandle($request, $response, true, function(int $supplier) use ($request, $args): array {
            $body = $this->body($request, ['expected_revision', 'expected_assessment_id', 'reason', 'idempotency_key'],
                ['expected_revision', 'expected_assessment_id', 'reason', 'idempotency_key']);
            if (!is_string($body['reason']) || !is_string($body['idempotency_key'])
                || ($body['expected_assessment_id'] !== null && (!is_int($body['expected_assessment_id']) || $body['expected_assessment_id'] <= 0))) {
                throw new \InvalidArgumentException('Confirmation identity is invalid.');
            }
            return $this->confirmation->confirm($this->context($request, $this->draft($request, $supplier, $args), $this->positive($body['expected_revision'])),
                $body['idempotency_key'], $body['reason'], $body['expected_assessment_id'])->toArray();
        });
    }

    public function history(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, function(int $supplier) use ($request, $args): array {
            $period = (int) ($args['periodId'] ?? $args['id']);
            $this->period($supplier, $period);
            $limit = $this->limit($request, 100);
            $head = $this->assessments->historyHeader($supplier, $period);
            // Kurzor patří k hlavě historie: nová revize během listování starý kurzor zneplatní.
            $binding = ['actor_id' => $this->actor($request), 'supplier_id' => $supplier, 'period_id' => $period,
                'object_kind' => 'history', 'object_id' => $head['assessment_id'] ?? 0,
                'revision' => $head['revision_no'] ?? 0, 'kind' => 'history', 'limit' => $limit];
            $after = $this->after($request, $binding);
            if ($after !== null && (!is_int($after) || $after < 0)) throw new \InvalidArgumentException('History cursor is invalid.');
            return ['head' => ['assessment_id' => $head['assessment_id'], 'revision_no' => $head['revision_no'],
                'status' => $head['status']]] + $this->page($this->assessments->history($supplier, $period, $after ?? 0, $limit), $binding);
        });
    }

    public function periodSummary(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, function(int $supplier) use ($args): array {
            $period = (int) $args['periodId'];
            $this->period($supplier, $period);
            return $this->assessments->periodSummary($supplier, $period);
        });
    }

    public function draftSourceSummary(Request $request, Response $response, array $args): Response
    {
        return $this->draftHandle($request, $response, false, function(int $supplier) use ($request, $args): array {
            $draft = $this->draft($request, $supplier, $args);
            return $this->queries->sourceSummary($this->context($request, $draft));
        });
    }

    public function withdraw(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, true, function(int $supplier) use ($request, $args): array {
            $body = $this->body($request, ['reason', 'idempotency_key'], ['reason', 'idempotency_key']);
            $reason = $this->text($body['reason'], 2000);
            $key = $this->text($body['idempotency_key'], 80);
            return $this->withdrawal->withdraw($supplier, (int) $args['periodId'], (int) $args['assessmentId'],
                $reason, $key, $this->actor($request))->toArray();
        });
    }

    public function convertLegacy(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, true, function(int $supplier) use ($request, $args): array {
            $body = $this->body($request, ['expected_storage_version', 'expected_input_hash', 'reason'],
                ['expected_storage_version', 'expected_input_hash', 'reason']);
            if (!is_string($body['expected_storage_version']) || !is_string($body['expected_input_hash'])) {
                throw new \InvalidArgumentException('Conversion identity is invalid.');
            }
            return $this->conversion->convert($supplier, (int) $args['periodId'], (int) $args['assessmentId'],
                $body['expected_storage_version'], $body['expected_input_hash'], $this->text($body['reason'], 500),
                $this->actor($request))->toArray();
        });
    }

    public function document(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, function(int $supplier) use ($response, $args): Response {
            $format = (string) $args['format'];
            $summary = $this->assessments->summaryById($supplier, (int) $args['assessmentId']);
            $payload = $this->assessments->documentSummary($supplier, $summary['period_id'], $summary['id']);
            $bytes = $format === 'pdf' ? $this->documents->render($payload) : $this->documents->xlsx($payload);
            $response->getBody()->write($bytes);
            return $response->withHeader('Content-Type', $format === 'pdf' ? 'application/pdf'
                    : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('Content-Disposition', 'attachment; filename="danove-posouzeni-' . $summary['id'] . '.' . $format . '"');
        });
    }

    public function summary(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, fn(int $supplier): array =>
            $this->assessments->summaryById($supplier, (int) $args['assessmentId']));
    }

    public function frozenPage(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, function(int $supplier) use ($request, $args): array {
            $assessment = $this->assessments->summaryById($supplier, (int) $args['assessmentId']);
            $kind = (string) $args['kind'];
            $limit = $this->limit($request, 100);
            if (!in_array($kind, ['source', 'decision', 'result', 'coverage', 'usage'], true)) throw new \InvalidArgumentException('Assessment page kind is invalid.');
            $binding = $this->binding($request, $assessment, 'assessment', $kind, $limit);
            $position = $this->after($request, $binding);
            $page = $kind === 'source'
                ? $this->assessments->sourcePage($supplier, $assessment['period_id'], $assessment['id'], $this->ordinal($position))
                : $this->assessments->calculationPage($supplier, $assessment['period_id'], $assessment['id'], $kind, $this->ordinal($position));
            $out = $this->sourceSlice($page, $position, $limit, $binding);
            if ($kind !== 'source') unset($out['findings']);
            return ($kind === 'source' ? [] : ['kind' => $kind]) + $out;
        });
    }

    public function export(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, function(int $supplier) use ($response, $args): Response {
            $assessment = $this->assessments->summaryById($supplier, (int) $args['assessmentId']);
            $resource = fopen('php://temp/maxmemory:2097152', 'w+b');
            if ($resource === false) throw new \RuntimeException('Cannot create export stream.');
            try {
                foreach ($this->assessments->export($supplier, $assessment['period_id'], $assessment['id']) as $record) {
                    $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                    $offset = 0;
                    while ($offset < strlen($line)) {
                        $written = fwrite($resource, substr($line, $offset));
                        if ($written === false || $written === 0) throw new \RuntimeException('Cannot write export stream.');
                        $offset += $written;
                    }
                }
                rewind($resource);
                return $response->withBody(new Stream($resource))->withHeader('Content-Type', 'application/x-ndjson; charset=utf-8')
                    ->withHeader('Content-Disposition', 'attachment; filename="danove-posouzeni-' . $assessment['id'] . '.ndjson"');
            } catch (\Throwable $error) {
                fclose($resource);
                throw $error;
            }
        });
    }

    private function draft(Request $request, int $supplier, array $args): array
    {
        return $this->queries->get($supplier, (int) $args['periodId'], (int) $args['draftId'], $this->actor($request));
    }

    private function context(Request $request, array $draft, ?int $expected = null): J6DraftContext
    {
        if ($expected !== null && $expected !== $draft['revision_no']) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_changed', 'Koncept posouzení se změnil. Načtěte jej znovu.', 409);
        }
        return new J6DraftContext($draft['supplier_id'], $draft['period_id'], $draft['id'], $draft['revision_no'], $this->actor($request), $draft['source_input_hash']);
    }

    private function envelope(Request $request, array $draft): array
    {
        $view = $this->queries->get($draft['supplier_id'], $draft['period_id'], $draft['id'], $this->actor($request));
        $edit = RequestAuthorization::allows($request, 'accounting.periods.close', AccessLevel::WRITE);
        $captured = is_int($view['source_manifest_id'] ?? null) && $view['source_manifest_id'] > 0;
        $complete = false;
        if ($captured) {
            $manifest = $this->snapshots->manifest($view['supplier_id'], $view['source_manifest_id']);
            if ($manifest['period_id'] !== $view['period_id'] || !hash_equals($manifest['input_hash'], $view['source_input_hash'])) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_reconciliation_failed', 'Účetní podklad konceptu nelze ověřit.', 409);
            }
            $complete = $manifest['finding_count'] === 0;
        }
        return ['draft' => $view, 'capabilities' => ['can_edit' => $edit, 'can_preview' => $edit && $complete,
            'can_confirm' => $edit && $complete, 'source_complete' => $complete,
            'reason_code' => !$captured ? 'nonprofit_tax_draft_source_not_captured' : ($complete ? null : 'nonprofit_tax_sources_incomplete')]];
    }

    private function binding(Request $request, array $owner, string $type, string $kind, int $limit): array
    {
        return ['actor_id' => $this->actor($request), 'supplier_id' => $owner['supplier_id'], 'period_id' => $owner['period_id'],
            'object_kind' => $type, 'object_id' => $owner['id'], 'revision' => $owner['revision_no'], 'kind' => $kind,
            'source_input_hash' => $kind === 'source' ? ($owner['snapshot_input_hash'] ?? $owner['source_input_hash']) : $owner['source_input_hash'], 'limit' => $limit];
    }

    private function sourceSlice(array $page, int|string|array|null $position, int $limit, array $binding): array
    {
        $ordinal = $this->ordinal($position);
        $rowOffset = $position['row_offset'] ?? 0;
        $findingOffset = $position['finding_offset'] ?? 0;
        $findings = $page['findings'] ?? [];
        foreach ([$rowOffset, $findingOffset] as $offset) if (!is_int($offset) || $offset < 0) throw new \InvalidArgumentException('Page offset is invalid.');
        if ($rowOffset > count($page['rows']) || $findingOffset > count($findings)) throw new \InvalidArgumentException('Page offset is invalid.');
        $rows = array_slice($page['rows'], $rowOffset, $limit);
        $visibleFindings = array_slice($findings, $findingOffset, $limit);
        $rowOffset += count($rows);
        $findingOffset += count($visibleFindings);
        $next = $rowOffset < count($page['rows']) || $findingOffset < count($findings)
            ? ['after_ordinal' => $ordinal, 'row_offset' => $rowOffset, 'finding_offset' => $findingOffset]
            : ($page['next_cursor'] === null ? null : ['after_ordinal' => $page['next_cursor'], 'row_offset' => 0, 'finding_offset' => 0]);
        return ['rows' => $rows, 'findings' => $visibleFindings, 'after' => $next === null ? null : $this->cursors->encode($binding, $next)];
    }

    private function ordinal(int|string|array|null $position): int
    {
        if ($position === null) return -1;
        if (!is_array($position) || !is_int($position['after_ordinal'] ?? null) || $position['after_ordinal'] < -1
            || array_diff(array_keys($position), ['after_ordinal', 'row_offset', 'finding_offset']) !== []) throw new \InvalidArgumentException('Source cursor is invalid.');
        return $position['after_ordinal'];
    }

    private function page(array $page, array $binding): array
    {
        $next = $page['next_cursor'];
        unset($page['next_cursor']);
        return $page + ['after' => $next === null ? null : $this->cursors->encode($binding, $next)];
    }

    private function after(Request $request, array $binding): int|string|array|null
    {
        $query = $request->getQueryParams();
        if (!array_key_exists('after', $query)) return null;
        if (!is_string($query['after'])) throw new \InvalidArgumentException('Cursor must be a string.');
        return $this->cursors->decode($query['after'], $binding);
    }

    private function limit(Request $request, int $maximum = 500): int
    {
        return $this->queryInt($request, 'limit', 100, 1, $maximum);
    }

    private function queryInt(Request $request, string $key, int $default, int $minimum, int $maximum): int
    {
        $value = $request->getQueryParams()[$key] ?? $default;
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) $value = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($value) || $value < $minimum || $value > $maximum) throw new \InvalidArgumentException('Page bounds are invalid.');
        return $value;
    }

    private function period(int $supplier, int $period): void
    {
        if ($this->periods->findById($supplier, $period) === null) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_period_not_found', 'Účetní období nebylo nalezeno.', 404);
        }
    }

    private function text(mixed $value, int $maximum): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $maximum) {
            throw new \InvalidArgumentException('Text field is invalid.');
        }
        return trim($value);
    }

    private function positive(mixed $value): int
    {
        if (!is_int($value) || $value <= 0) throw new \InvalidArgumentException('Positive integer is required.');
        return $value;
    }

    private function profile(array $body): void
    {
        if (!is_array($body['profile']) || (isset($body['method']) && !is_array($body['method']))) throw new \InvalidArgumentException('Profile and method must be objects.');
    }

    private function patchBody(Request $request): array
    {
        $body = $this->body($request, ['expected_revision', 'upserts', 'deletes'], ['expected_revision', 'upserts', 'deletes']);
        if (!is_array($body['upserts']) || !array_is_list($body['upserts']) || !is_array($body['deletes']) || !array_is_list($body['deletes'])
            || count($body['upserts']) + count($body['deletes']) > 500) throw new \InvalidArgumentException('Patch exceeds its bound.');
        return $body;
    }

    private function body(Request $request, array $allowed, array $required): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || array_diff(array_keys($body), $allowed) !== [] || array_diff($required, array_keys($body)) !== []) throw new \InvalidArgumentException('Request fields are invalid.');
        return $body;
    }

    private function actor(Request $request): int
    {
        return $this->positive($this->userId($request));
    }

    private function draftHandle(Request $request, Response $response, bool $write, callable $work): Response
    {
        return $this->handle($request, $response, $write, $work, true);
    }

    private function handle(Request $request, Response $response, bool $write, callable $work, bool $sessionOnly = false): Response
    {
        if (($write || $sessionOnly) && !RequestAuthorization::isSessionAuth($request)) return Json::error($response, 'nonprofit_tax_session_only', 'Koncept daňového posouzení vyžaduje přihlášenou relaci.', 403);
        if (!($write ? $this->requireClose($request, $response, $error) : $this->requirePermission($request, $response, 'accounting', AccessLevel::READ, $error))) return $error;
        try {
            $result = $work($this->currentSupplierId($request));
            $response = $result instanceof Response ? $result : Json::ok($response, $result);
            return $response->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Content-Type-Options', 'nosniff');
        } catch (NonprofitTaxAssessmentException $error) {
            return Json::error($response, $error->errorCode, $error->getMessage(), $error->httpStatus);
        } catch (\InvalidArgumentException $error) {
            return Json::error($response, 'invalid_input', 'Požadavek na daňové posouzení není platný.', 400);
        }
    }
}
