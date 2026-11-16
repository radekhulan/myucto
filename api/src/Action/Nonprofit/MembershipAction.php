<?php

declare(strict_types=1);

namespace MyInvoice\Action\Nonprofit;

use MyInvoice\Repository\Nonprofit\Membership\MembershipListRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRateRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBatchRepository;
use MyInvoice\Service\Nonprofit\Membership\MembershipListFilter;
use MyInvoice\Service\Nonprofit\Membership\MembershipBatchService;
use MyInvoice\Service\Nonprofit\Membership\MembershipRateService;
use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Http\IsoDate;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Nonprofit\Membership\MembershipChargeService;
use MyInvoice\Service\Nonprofit\Membership\MembershipCorrectionService;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipChargeRequest;
use MyInvoice\Service\Nonprofit\Membership\MembershipMemberService;
use MyInvoice\Service\Nonprofit\Membership\MembershipReconciliationService;
use MyInvoice\Service\Nonprofit\Membership\MembershipImportService;
use MyInvoice\Service\Nonprofit\Membership\MembershipListExporter;
use MyInvoice\Service\Accounting\Codebooks\CodebookImportException;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

final class MembershipAction
{
    use AccountingActionSupport;

    private readonly MembershipRepository $members;
    private readonly MembershipMemberService $memberService;
    private readonly MembershipChargeService $chargeService;
    private readonly MembershipReconciliationService $reconciliation;
    private readonly MembershipBatchService $batchService;
    private readonly TenantReferenceGuard $tenantRefs;
    private readonly MembershipCorrectionService $corrections;
    private readonly IpMatcher $ipMatcher;
    private readonly ActivityLogger $activity;

    /** Velikost nahrávaného seznamu jako u importu číselníků. */
    private const IMPORT_MAX_BYTES = 2 * 1024 * 1024;

    /** Audit a IP jsou povinné třídní parametry, aby je DI doplnil (volitelné autowire nevyplní a audit by nebyl zapečetěný). */
    public function __construct(private readonly Connection $db, PostingService $posting, ActivityLogger $activity, IpMatcher $ipMatcher, ?LoggerInterface $logger = null)
    {
        $this->ipMatcher = $ipMatcher;
        $this->activity = $activity;
        $this->tenantRefs = new TenantReferenceGuard($db);
        $this->members = new MembershipRepository($db);
        $charges = new MembershipChargeRepository($db);
        $this->memberService = new MembershipMemberService($db, $this->members);
        $this->chargeService = new MembershipChargeService($db, $this->members, $charges, $posting);
        $this->batchService = new MembershipBatchService($db, $this->chargeService, logger: $logger);
        $this->reconciliation = new MembershipReconciliationService(new MembershipBalanceRepository($db));
        $this->corrections = new MembershipCorrectionService($db, $this->members, $activity);
    }

    public function members(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, false, function (int $supplier) use ($request): array {
            $query = $request->getQueryParams();
            $after = $this->queryInteger($query['after_id'] ?? 0, 'after_id', 0);
            $limit = $this->queryInteger($query['limit'] ?? 100, 'limit', 1, 100);
            $filter = MembershipListFilter::parse($query);
            $items = (new MembershipListRepository($this->db))->page($supplier, $after, $limit + 1, $filter);
            $more = count($items) > $limit;
            if ($more) {
                array_pop($items);
            }
            return ['items' => $items, 'next_after_id' => $more ? $items[array_key_last($items)]['id'] : null];
        });
    }

    /**
     * Export seznamu členů (XLSX/CSV) se stejnými filtry jako seznam. Stejné právo jako čtení seznamu,
     * jen z přihlášené relace; soubor nenese adresy ani kontaktní údaje (krok 8 #321).
     */
    public function exportMembers(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::error($response, 'token_endpoint_forbidden', 'API token má přístup pouze k veřejnému API (/api/v1). Tento endpoint je dostupný jen z webového rozhraní.', 403);
        }
        if (!$this->requirePermission($request, $response, 'accounting', AccessLevel::READ, $error)) return $error;
        $query = $request->getQueryParams();
        try {
            if (array_diff(array_keys($query), ['format', 'search', 'year', 'title_key', 'payment_status']) !== []) {
                throw new MembershipException('nonprofit_membership_invalid', 'Export přijímá jen formát a filtry seznamu.');
            }
            $format = $query['format'] ?? 'xlsx';
            unset($query['format']);
            // XLSX do stropu 5000 členů trvá jednotky sekund; výchozí limit 30 s by na pomalém serveru nestačil.
            @set_time_limit(300);
            $file = (new MembershipListExporter($this->db))->export($this->currentSupplierId($request), MembershipListFilter::parse($query), is_string($format) ? $format : '');
        } catch (MembershipException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }
        // Soubor nese jména a čísla členů: stažení zapsat do aktivity jako ostatní účetní exporty (report.accounting_export).
        [$ip, $agent] = $this->origin($request);
        $this->activity->log('report.accounting_export', $this->userId($request), 'report', null,
            // Hledaný text může být jméno, do aktivity jde jen příznak.
            ['report' => 'nonprofit_members', 'format' => $format, 'search' => trim((string) ($query['search'] ?? '')) !== '']
                + array_intersect_key($query, array_flip(['year', 'title_key', 'payment_status'])),
            $ip, $agent, $this->currentSupplierId($request));
        $response->getBody()->write($file['bytes']);
        return $response
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($file['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * Import seznamu členů: multipart `file`, `dry_run` (výchozí 1 = náhled) a u potvrzení `preview_hash`.
     * Zápis jen s právem zápisu členské evidence a z přihlášené relace, stejně jako založení člena.
     */
    public function importMembers(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, true, function (int $supplier) use ($request): array {
            $body = (array) ($request->getParsedBody() ?? []);
            if (array_diff(array_keys($body), ['dry_run', 'preview_hash']) !== []) {
                throw new MembershipException('nonprofit_membership_invalid', 'Import přijímá jen soubor, dry_run a preview_hash.');
            }
            $dryRun = !in_array(strtolower(trim((string) ($body['dry_run'] ?? '1'))), ['0', 'false', 'no'], true);
            $hash = isset($body['preview_hash']) && is_string($body['preview_hash']) ? $body['preview_hash'] : null;
            [$content, $filename] = $this->uploadedList($request);
            [$ip, $agent] = $this->origin($request);
            // Potvrzení zapisuje až 2000 řádků po jednom (~17 dotazů na řádek); výchozí limit 30 s by ho
            // na pomalejším serveru přerušil fatální chybou uprostřed, kdy už neproběhne ani audit.
            if (!$dryRun) @set_time_limit(600);
            try {
                return (new MembershipImportService($this->db, $this->activity, $this->memberService))
                    ->run($supplier, $this->actor($request), $content, $filename, $dryRun, $hash, $ip, $agent,
                        RequestAuthorization::canCreateContactsByMembershipImport($request));
            } catch (CodebookImportException $e) {
                // Překročený počet řádků má vlastní kód, aby web řekl skutečný důvod (export může být delší).
                // Sdílené čtení dává kód too_many_rows i příliš velkému rozbalenému XLSX; rozliší je jen text.
                if ($e->errorCode === 'too_many_rows' && str_contains($e->getMessage(), 'řádků')) throw new MembershipException('nonprofit_membership_import_too_many_rows', $e->getMessage(), 422);
                throw new MembershipException('nonprofit_membership_import_file_invalid', $e->getMessage(), $e->httpStatus === 415 ? 415 : 422);
            }
        });
    }

    /** @return array{0:string,1:string} obsah a název nahraného XLSX/CSV po kontrole velikosti, přípony a obsahu */
    private function uploadedList(Request $request): array
    {
        $file = null;
        foreach ($request->getUploadedFiles() as $node) {
            if ($node instanceof UploadedFileInterface) {
                $file = $node;
                break;
            }
        }
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) throw new MembershipException('nonprofit_membership_import_file_invalid', 'Nahrajte soubor XLSX nebo CSV.', 415);
        if ((int) ($file->getSize() ?? 0) > self::IMPORT_MAX_BYTES) throw new MembershipException('nonprofit_membership_import_file_invalid', 'Soubor je větší než 2 MB.', 415);
        $filename = (string) ($file->getClientFilename() ?? '');
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $content = (string) $file->getStream()->getContents();
        $mime = $content === '' ? '' : (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        $ok = match ($ext) {
            'xlsx' => str_contains($mime, 'zip') || $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => str_starts_with($mime, 'text/') || in_array($mime, ['application/csv', 'application/vnd.ms-excel', 'application/octet-stream'], true),
            default => false,
        };
        if (!$ok) throw new MembershipException('nonprofit_membership_import_file_invalid', 'Podporované formáty jsou XLSX a CSV s odpovídajícím obsahem.', 415);
        return [$content, $filename];
    }

    public function rates(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, false, function (int $supplier) use ($request): array {
            $query = $request->getQueryParams();
            $after = $this->queryInteger($query['after_id'] ?? 0, 'after_id', 0);
            $active = $this->queryInteger($query['active_only'] ?? 1, 'active_only', 0, 1);
            $items = (new MembershipRateRepository($this->db))->page($supplier, $after, (bool) $active);
            $date = $query['date'] ?? date('Y-m-d');
            if (!is_string($date) || !IsoDate::isValid($date)) throw new MembershipException('nonprofit_membership_rate_invalid', 'Neplatné datum výběru účtů.', 422);
            return ['items' => $items, 'next_after_id' => count($items) === 100 ? $items[array_key_last($items)]['id'] : null,
                'revenue_accounts' => (new MembershipRateService($this->db))->revenueAccounts($supplier, $date)];
        });
    }

    public function titles(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, false, function (int $supplier) use ($request): array {
            $q = $request->getQueryParams();
            $year = isset($q['year']) && $q['year'] !== '' ? $this->queryInteger($q['year'], 'year', 1900, 9998) : null;
            $after = $q['after_title_key'] ?? '';
            if (!is_string($after)) {
                throw new MembershipException('nonprofit_membership_list_filter_invalid', 'Neplatné označení příspěvku.', 422);
            }
            if ($after !== '') {
                $after = MembershipChargeRequest::key($after, 'titulu');
            }
            $limit = $this->queryInteger($q['limit'] ?? 100, 'limit', 1, 100);
            $items = (new MembershipListRepository($this->db))->titles($supplier, $year, $after, $limit + 1);
            $more = count($items) > $limit;
            if ($more) {
                array_pop($items);
            }
            return ['items' => $items, 'next_after_title_key' => $more ? $items[array_key_last($items)] : null];
        });
    }

    public function selectionCheck(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, false, function (int $supplier) use ($request): array {
            $body = $this->body($request, ['member_ids', 'filters']);
            if (!is_array($body['member_ids']) || !array_is_list($body['member_ids']) || count($body['member_ids']) > 500 || !is_array($body['filters'])) {
                throw new MembershipException('nonprofit_membership_list_filter_invalid', 'Neplatný výběr členů.', 422);
            }
            foreach ($body['member_ids'] as $id) {
                if (!is_int($id) || $id <= 0) {
                    throw new MembershipException('nonprofit_membership_list_filter_invalid', 'Neplatný výběr členů.', 422);
                }
            }
            $ids = array_values(array_unique($body['member_ids']));
            return ['member_ids' => (new MembershipListRepository($this->db))->matchingSelection($supplier, $ids, MembershipListFilter::parse($body['filters']))];
        });
    }

    public function batches(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, false, function (int $supplier) use ($request): array {
            $before = $this->queryInteger($request->getQueryParams()['before_id'] ?? PHP_INT_MAX, 'before_id', 1);
            $items = (new MembershipBatchRepository($this->db))->recent($supplier, $before);
            return ['items' => $items, 'next_before_id' => count($items) === 20 ? $items[array_key_last($items)]['id'] : null];
        });
    }

    public function batch(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, fn (int $supplier): array => $this->batchResponse($request, $this->batchService->detail(
            $supplier,
            (int) $args['id'],
            $this->queryInteger($request->getQueryParams()['after_member_id'] ?? 0, 'after_member_id', 0),
            write: RequestAuthorization::allows($request, 'accounting.journal.post', AccessLevel::WRITE),
        )));
    }

    public function createBatch(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, true, function (int $supplier) use ($request): array {
            $body = $this->body($request, ['template', 'selection_mode', 'member_ids', 'idempotency_key']);
            return $this->batchResponse($request, $this->batchService->create($supplier, $body, $this->actor($request)));
        }, 201);
    }

    public function prepareBatch(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, true, fn (int $supplier): array => $this->batchResponse($request, $this->batchService->prepare($supplier, (int) $args['id'])));
    }

    public function confirmBatch(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, true, function (int $supplier) use ($request, $args): array {
            $body = $this->body($request, ['approval_token']);
            if (!is_string($body['approval_token'])) throw new MembershipException('nonprofit_membership_batch_invalid', 'Potvrzení dávky nemá platný tvar.');
            return $this->batchResponse($request, $this->batchService->confirm($supplier, (int) $args['id'], $body['approval_token'], $this->actor($request)));
        });
    }

    public function cancelBatch(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, true, fn (int $supplier): array => $this->batchResponse(
            $request,
            $this->batchService->cancel($supplier, (int) $args['id'], $this->actor($request)),
        ));
    }

    public function renewBatchPreview(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, true, fn (int $supplier): array => $this->batchResponse(
            $request,
            $this->batchService->renewPreview($supplier, (int) $args['id'], $this->actor($request)),
        ));
    }

    /** Interní identita replaye nepatří do DTO a schvalovací token je pouze pro zapisující roli. */
    private function batchResponse(Request $request, array $result): array
    {
        unset($result['batch']['request_hash'], $result['batch']['idempotency_key'], $result['batch']['batch_uid']);
        if (!RequestAuthorization::allows($request, 'accounting.journal.post', AccessLevel::WRITE)) unset($result['batch']['approval_token']);
        return $result;
    }

    public function createRate(Request $request, Response $response): Response
    {
        return $this->saveRate($request, $response, null);
    }

    public function rate(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, fn (int $supplier): array =>
            (new MembershipRateRepository($this->db))->find($supplier, (int) $args['id'])
            ?? throw new MembershipException('nonprofit_membership_rate_not_found', 'Sazba nebyla nalezena v této organizaci.', 404));
    }

    public function updateRate(Request $request, Response $response, array $args): Response
    {
        return $this->saveRate($request, $response, (int) $args['id']);
    }

    private function saveRate(Request $request, Response $response, ?int $id): Response
    {
        return $this->handle($request, $response, true, function (int $supplier) use ($request, $id): array {
            $body = $request->getParsedBody();
            if (!is_array($body)) throw new MembershipException('nonprofit_membership_invalid', 'Sazba musí být strukturovaný požadavek.');
            return (new MembershipRateService($this->db))->save($supplier, $id, $body);
        }, $id === null ? 201 : 200, 'accounting.templates');
    }

    public function member(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, function (int $supplier) use ($request, $args): array {
            $member = $this->members->member($supplier, (int) $args['id'])
                ?? throw new MembershipException('nonprofit_membership_member_not_found', 'Člen nebyl nalezen v této organizaci.', 404);
            $query = $request->getQueryParams();
            $after = $this->queryInteger($query['after_claim_id'] ?? 0, 'after_claim_id', 0);
            $limit = $this->queryInteger($query['limit'] ?? 100, 'limit', 1, 100);
            $history = $this->members->history($supplier, $member['id']);
            $detail = ['member' => $member, 'history' => $history];
            // Dostupnost oprav čte předpisy, důkazy operací a dávky člena; počítá se jen pro toho, kdo smí opravovat.
            if (RequestAuthorization::allows($request, 'accounting.journal.post', AccessLevel::WRITE)) {
                $detail['corrections'] = $this->corrections->availability($supplier, $member, $history);
            }
            return $detail + ['balance' => $this->reconciliation->memberBalance($supplier, $member['id'], $after, $limit)];
        });
    }

    public function updateMember(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, true, function (int $supplier) use ($request, $args): array {
            $body = $this->body($request, ['client_id', 'member_number', 'expected_client_id', 'expected_member_number']);
            if (is_int($body['client_id'])) {
                $badRefs = $this->tenantRefs->violations($supplier, ['client_id' => $body['client_id']], ['client_id']);
                if ($badRefs !== []) throw new MembershipException('invalid_reference', TenantReferenceGuard::message($badRefs), 400);
            }
            [$ip, $agent] = $this->origin($request);
            return $this->corrections->updateIdentity($supplier, (int) $args['id'], $body, $this->actor($request), $ip, $agent);
        });
    }

    public function deleteMember(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, true, function (int $supplier) use ($request, $args): array {
            // Potvrzený kontakt a číslo v dotazu (tělo u DELETE proxy a klienti neposílají spolehlivě).
            $query = $request->getQueryParams();
            $client = $query['expected_client_id'] ?? null;
            $number = $query['expected_member_number'] ?? null;
            if (!is_string($client) || preg_match('/^[1-9][0-9]{0,17}$/', $client) !== 1 || !is_string($number)
                || trim($number) === '' || mb_strlen($number) > 80 || array_diff(array_keys($query), ['expected_client_id', 'expected_member_number']) !== []) {
                throw new MembershipException('nonprofit_membership_invalid', 'Smazání člena vyžaduje původní kontakt a členské číslo.');
            }
            [$ip, $agent] = $this->origin($request);
            return $this->corrections->delete($supplier, (int) $args['id'], $this->actor($request),
                ['client_id' => (int) $client, 'member_number' => $number], $ip, $agent);
        });
    }

    public function voidRevision(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, true, function (int $supplier) use ($request, $args): array {
            $body = $this->body($request, ['expected_revision', 'reason', 'idempotency_key']);
            [$ip, $agent] = $this->origin($request);
            return $this->corrections->voidRevision($supplier, (int) $args['id'], (int) $args['revisionId'], $body, $this->actor($request), $ip, $agent);
        }, 201);
    }

    /** @return array{0:?string,1:string} */
    private function origin(Request $request): array
    {
        return [$this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent')];
    }

    public function claim(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, fn (int $supplier): array => $this->reconciliation->claimSettlement($supplier, (int) $args['id']));
    }

    public function reconciliation(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, fn (int $supplier): array => $this->reconciliation->periodReconciliation($supplier, (int) $args['id']));
    }

    public function createMember(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, true, function (int $supplier) use ($request): array {
            $body = $this->body($request, ['client_id', 'member_number']);
            if (!is_int($body['client_id']) || !is_string($body['member_number'])) throw new MembershipException('nonprofit_membership_invalid', 'Člen vyžaduje klienta a členské číslo.');
            $badRefs = $this->tenantRefs->violations($supplier, $body, ['client_id']);
            if ($badRefs !== []) throw new MembershipException('invalid_reference', TenantReferenceGuard::message($badRefs), 400);
            return $this->memberService->create($supplier, $body['client_id'], $body['member_number'], $this->actor($request));
        }, 201);
    }

    public function appendRevision(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, true, function (int $supplier) use ($request, $args): array {
            $body = $this->body($request, ['status', 'effective_from', 'legal_basis', 'expected_revision', 'idempotency_key']);
            return $this->memberService->appendRevision($supplier, (int) $args['id'], $body, $this->actor($request));
        }, 201);
    }

    public function preview(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, true, function (int $supplier) use ($request): array {
            $body = $this->body($request, ['operation', 'request']);
            if (!is_array($body['request'])) throw new MembershipException('nonprofit_membership_invalid', 'Požadavek operace musí být strukturovaný.');
            return match ($body['operation']) {
                'issue' => $this->chargeService->previewIssue($supplier, $body['request']),
                'reverse' => $this->chargeService->previewReverse($supplier, $body['request']),
                default => throw new MembershipException('nonprofit_membership_invalid', 'Neplatný druh členské operace.'),
            };
        });
    }

    public function confirm(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, true, function (int $supplier) use ($request): array {
            $body = $this->body($request, ['operation', 'request', 'expected_case_version', 'preview_hash', 'idempotency_key']);
            if (!is_array($body['request']) || !is_int($body['expected_case_version']) || !is_string($body['preview_hash']) || !is_string($body['idempotency_key'])) {
                throw new MembershipException('nonprofit_membership_invalid', 'Potvrzení členské operace nemá platný tvar.');
            }
            return match ($body['operation']) {
                'issue' => $this->chargeService->confirmIssue($supplier, $body['request'], $body['expected_case_version'], $body['preview_hash'], $body['idempotency_key'], $this->actor($request)),
                'reverse' => $this->chargeService->confirmReverse($supplier, $body['request'], $body['expected_case_version'], $body['preview_hash'], $body['idempotency_key'], $this->actor($request)),
                default => throw new MembershipException('nonprofit_membership_invalid', 'Neplatný druh členské operace.'),
            };
        });
    }

    private function handle(Request $request, Response $response, bool $write, callable $work, int $status = 200, string $writePermission = 'accounting.journal.post'): Response
    {
        if (!$write && !RequestAuthorization::actsAsSession($request)) {
            return Json::error($response, 'token_endpoint_forbidden', 'API token má přístup pouze k veřejnému API (/api/v1). Tento endpoint je dostupný jen z webového rozhraní.', 403);
        }
        if ($write && !RequestAuthorization::actsAsSession($request)) return Json::sessionRequired($response, 'Změna členské evidence vyžaduje přihlášenou relaci.');
        $allowed = $write
            ? $this->requirePermission($request, $response, $writePermission, AccessLevel::WRITE, $error)
            : $this->requirePermission($request, $response, 'accounting', AccessLevel::READ, $error);
        if (!$allowed) return $error;
        try {
            return Json::ok($response, $work($this->currentSupplierId($request)), $status);
        } catch (MembershipException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        } catch (PostingException $e) {
            return $this->mapPostingError($response, $e);
        }
    }

    private function body(Request $request, array $fields): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || array_diff(array_keys($body), $fields) !== [] || array_diff($fields, array_keys($body)) !== []) {
            throw new MembershipException('nonprofit_membership_invalid', 'Požadavek musí obsahovat právě podporovaná pole.');
        }
        return $body;
    }

    private function actor(Request $request): int
    {
        return $this->userId($request) ?? throw new MembershipException('nonprofit_membership_actor_required', 'Změna členské evidence vyžaduje autora.', 403);
    }

    private function queryInteger(mixed $value, string $field, int $minimum, int $maximum = PHP_INT_MAX): int
    {
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) $value = (int) $value;
        if (!is_int($value) || $value < $minimum || $value > $maximum) throw new MembershipException('nonprofit_membership_invalid', 'Neplatný parametr ' . $field . '.');
        return $value;
    }
}
