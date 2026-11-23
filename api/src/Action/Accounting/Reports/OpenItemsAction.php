<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Reports\OpenItemsService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\OpenItemsListPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Otevřené položky a párování řádků deníku (okruhy) na libovolném účtu.
 *
 *   GET    /api/accounting/open-items/{accountId}?as_of=&only_open=&page=&per_page=
 *   GET    /api/accounting/open-items/{accountId}/suggestions?as_of=&days=
 *   POST   /api/accounting/open-items/{accountId}/suggestions/apply   {as_of, days, pairs?}
 *   POST   /api/accounting/open-items/{accountId}/pairings            {line_ids, note?}
 *   POST   /api/accounting/open-items/pairings/delete                 {pairing_ids}
 *   GET    /api/accounting/open-items/pairings/{id}
 *   POST   /api/accounting/open-items/pairings/{id}/lines             {line_ids}
 *   DELETE /api/accounting/open-items/pairings/{id}/lines/{entryId}/{lineNo}
 *   GET    /api/accounting/reports/open-items-list?as_of=&codes=261,335,395
 *   GET    /api/accounting/reports/open-items-list/export?as_of=&codes=&format=pdf|xlsx
 *
 * Práva řeší RoutePermissionMap (/api/accounting: GET = čtení, ostatní = zápis
 * účetnictví); zápisové metody si právo ověří i samy. ZÁMĚRNĚ bez guardu
 * uzavřeného období: párování je metadata, deník nemění.
 */
final class OpenItemsAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const MAX_PER_PAGE = 500;
    private const MAX_LINES = 500;

    /** Soupis na obrazovce ukazuje prvních N položek účtu, export celý rozsah. */
    private const LISTING_SCREEN_ROWS = 200;
    private const LISTING_EXPORT_ROWS = 100000;

    public function __construct(
        private readonly OpenItemsService $service,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
        private readonly OpenItemsListPdfRenderer $listingPdf,
        private readonly ReportXlsxExporter $xlsx,
        private readonly ActivityLogger $logger,
    ) {}

    public function listing(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $asOf = $this->asOf($q, $response, $err);
        if ($asOf === null) return $err;
        $codes = $this->listingCodes($q, $response, $err);
        if ($codes === null) return $err;

        return $this->run($response, fn () => $this->service->listing($supplierId, $asOf, $codes, self::LISTING_SCREEN_ROWS));
    }

    public function exportListing(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $asOf = $this->asOf($q, $response, $err);
        if ($asOf === null) return $err;
        $codes = $this->listingCodes($q, $response, $err);
        if ($codes === null) return $err;
        $format = strtolower(trim((string) ($q['format'] ?? '')));
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', "format musí být 'pdf' nebo 'xlsx'.", 422);
        }

        try {
            $data = $this->service->listing($supplierId, $asOf, $codes, self::LISTING_EXPORT_ROWS);
            $out = $format === 'pdf'
                ? ['bytes' => $this->listingPdf->render($data), 'filename' => 'otevrene-polozky-' . $asOf . '.pdf', 'mime' => 'application/pdf']
                : $this->xlsx->openItemsList($data);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Export soupisu otevřených položek selhal: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        $this->logger->log('report.accounting_export', $this->userId($request), 'report', null,
            ['report' => 'open_items_list', 'format' => $format],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);

        $response->getBody()->write($out['bytes']);
        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string,mixed> $q
     * @return list<string>|null
     */
    private function listingCodes(array $q, Response $response, ?Response &$err): ?array
    {
        $err = null;
        $codes = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($q['codes'] ?? ''))), static fn (string $c): bool => $c !== '')));
        foreach ($codes as $code) {
            if (preg_match('/^[0-9A-Za-z.]{1,20}$/D', $code) !== 1) {
                $err = Json::error($response, 'validation_failed', 'codes musí být seznam kódů účtů oddělených čárkou.', 422);
                return null;
            }
        }
        if (count($codes) > OpenItemsService::LISTING_MAX_ACCOUNTS) {
            $err = Json::error($response, 'validation_failed', 'Najednou lze vybrat nejvýš ' . OpenItemsService::LISTING_MAX_ACCOUNTS . ' účtů.', 422);
            return null;
        }
        return $codes;
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $asOf = $this->asOf($q, $response, $err);
        if ($asOf === null) return $err;

        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($q['per_page'] ?? 100)));
        $onlyOpen = (string) ($q['only_open'] ?? '1') !== '0';

        return $this->run($response, fn () => $this->service->build(
            $supplierId, (int) ($args['accountId'] ?? 0), $asOf, $onlyOpen, $page, $perPage,
        ));
    }

    public function suggestions(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $asOf = $this->asOf($q, $response, $err);
        if ($asOf === null) return $err;
        $days = $this->days($q);

        return $this->run($response, fn () => [
            'as_of' => $asOf,
            'days'  => $days,
            'items' => $this->service->suggestions($supplierId, (int) ($args['accountId'] ?? 0), $asOf, $days),
        ]);
    }

    public function applySuggestions(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $asOf = $this->asOf($body, $response, $err);
        if ($asOf === null) return $err;

        $pairs = null;
        if (array_key_exists('pairs', $body) && $body['pairs'] !== null) {
            if (!is_array($body['pairs']) || count($body['pairs']) > OpenItemsService::MAX_SUGGESTIONS) {
                return Json::error($response, 'validation_failed', 'pairs musí být seznam dvojic řádků.', 422);
            }
            $pairs = [];
            foreach ($body['pairs'] as $pair) {
                if (!is_array($pair) || count($pair) < 2) {
                    return Json::error($response, 'validation_failed', 'pairs musí být seznam dvojic řádků.', 422);
                }
                $pairs[] = array_values(array_map('intval', $pair));
            }
        }

        $accountId = (int) ($args['accountId'] ?? 0);
        $meta = $this->meta($request);
        return $this->run($response, fn () => [
            'created' => $this->service->applySuggestions($supplierId, $accountId, $asOf, $this->days($body), $pairs, $meta),
        ]);
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $lineIds = $this->lineIds($body, $response, $err);
        if ($lineIds === null) return $err;
        $note = isset($body['note']) && is_string($body['note']) ? $body['note'] : null;

        $accountId = (int) ($args['accountId'] ?? 0);
        $meta = $this->meta($request);
        return $this->run($response, fn () => $this->service->create($supplierId, $accountId, $lineIds, $note, $meta), 201);
    }

    public function pairing(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        return $this->run($response, fn () => $this->service->pairing($supplierId, (int) ($args['id'] ?? 0)));
    }

    public function addLines(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $lineIds = $this->lineIds($body, $response, $err);
        if ($lineIds === null) return $err;

        $meta = $this->meta($request);
        return $this->run($response, fn () => $this->service->addLines($supplierId, (int) ($args['id'] ?? 0), $lineIds, $meta));
    }

    public function removeLine(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $meta = $this->meta($request);
        return $this->run($response, fn () => [
            'pairing' => $this->service->removeLine(
                $supplierId,
                (int) ($args['id'] ?? 0),
                (int) ($args['entryId'] ?? 0),
                (int) ($args['lineNo'] ?? 0),
                $meta,
            ),
        ]);
    }

    public function delete(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $ids = $body['pairing_ids'] ?? null;
        if (!is_array($ids) || $ids === [] || count($ids) > self::MAX_LINES) {
            return Json::error($response, 'validation_failed', 'pairing_ids musí být neprázdný seznam.', 422);
        }

        $meta = $this->meta($request);
        return $this->run($response, fn () => [
            'deleted' => $this->service->delete($supplierId, array_values(array_map('intval', $ids)), $meta),
        ]);
    }

    /**
     * @param callable():mixed $fn
     */
    private function run(Response $response, callable $fn, int $status = 200): Response
    {
        try {
            return Json::ok($response, $fn(), $status);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Otevřené položky: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Akci se nepodařilo provést.', 500);
        }
    }

    /**
     * @param array<string,mixed> $source
     */
    private function asOf(array $source, Response $response, ?Response &$err): ?string
    {
        $v = trim((string) ($source['as_of'] ?? ''));
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            $err = Json::error($response, 'validation_failed', 'as_of je povinné datum (YYYY-MM-DD).', 422);
            return null;
        }
        $err = null;
        return $v;
    }

    /**
     * @param array<string,mixed> $source
     */
    private function days(array $source): int
    {
        $days = (int) ($source['days'] ?? OpenItemsService::DEFAULT_SUGGESTION_DAYS);
        return max(0, min(366, $days));
    }

    /**
     * @param array<string,mixed> $body
     * @return list<int>|null
     */
    private function lineIds(array $body, Response $response, ?Response &$err): ?array
    {
        $ids = $body['line_ids'] ?? null;
        if (!is_array($ids) || $ids === [] || count($ids) > self::MAX_LINES) {
            $err = Json::error($response, 'validation_failed', 'line_ids musí být neprázdný seznam řádků deníku.', 422);
            return null;
        }
        $err = null;
        return array_values(array_map('intval', $ids));
    }

    /**
     * @return array{user_id:?int, ip:?string, user_agent:?string}
     */
    private function meta(Request $request): array
    {
        return [
            'user_id'    => $this->userId($request),
            'ip'         => $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            'user_agent' => $request->getHeaderLine('User-Agent'),
        ];
    }
}
