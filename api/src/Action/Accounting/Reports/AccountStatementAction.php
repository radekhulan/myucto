<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Reports\AccountStatementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\AccountStatementPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Opis účtu (Epic F2) — stránkované pohyby s běžícím zůstatkem, vč. analytik
 * pod syntetikou. Vlastnictví účtu ověřuje service (cizí/neexistující → 404).
 *
 *   GET /api/accounting/reports/account-statement/{accountId}        — data
 *   GET /api/accounting/reports/account-statement/{accountId}/export — PDF / XLSX
 */
final class AccountStatementAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const MAX_PER_PAGE = 200;

    /** Export tiskne celý rozsah bez stránkování (obraty a KS jsou za celý rozsah). */
    private const EXPORT_MAX_ROWS = 100000;

    public function __construct(
        private readonly AccountStatementService $statement,
        private readonly AccountStatementPdfRenderer $pdf,
        private readonly ReportXlsxExporter $xlsx,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $params = $this->validateParams($request, $response, $args, $err);
        if ($params === null) return $err;

        try {
            $data = $this->statement->build($supplierId, $params['account_id'], $params['from'], $params['to'], $params['page'], $params['per_page'], $params['after_closing'], $params['counter_account_id']);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Opis účtu se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        return Json::ok($response, $data);
    }

    public function export(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $params = $this->validateParams($request, $response, $args, $err);
        if ($params === null) return $err;

        $format = strtolower(trim((string) ($request->getQueryParams()['format'] ?? '')));
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', "format musí být 'pdf' nebo 'xlsx'.", 422);
        }

        try {
            $data = $this->statement->build($supplierId, $params['account_id'], $params['from'], $params['to'], 1, self::EXPORT_MAX_ROWS, $params['after_closing'], $params['counter_account_id']);
            $data['from'] = $params['from'];
            $data['to']   = $params['to'];
            $out = $format === 'pdf'
                ? [
                    'bytes'    => $this->pdf->render($data),
                    'filename' => sprintf('opis-uctu-%s-%s-%s.pdf', (string) ($data['account']['code'] ?? ''), $params['from'], $params['to']),
                    'mime'     => 'application/pdf',
                ]
                : $this->xlsx->accountStatement($data);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Export opisu účtu selhal: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        $this->logger->log('report.accounting_export', $this->userId($request), 'report', null,
            ['report' => 'account_statement', 'format' => $format],
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
     * @return array{account_id:int, from:string, to:string, page:int, per_page:int, after_closing:bool, counter_account_id:?int}|null
     */
    private function validateParams(Request $request, Response $response, array $args, ?Response &$err): ?array
    {
        $accountId = (int) ($args['accountId'] ?? 0);
        if ($accountId <= 0) {
            $err = Json::error($response, 'validation_failed', 'Neplatné ID účtu.', 422);
            return null;
        }

        $q = $request->getQueryParams();
        $dates = [];
        foreach (['from', 'to'] as $key) {
            $v = trim((string) ($q[$key] ?? ''));
            if (!$this->isDate($v)) {
                $err = Json::error($response, 'validation_failed', "{$key} je povinný a musí být datum (YYYY-MM-DD).", 422);
                return null;
            }
            $dates[$key] = $v;
        }
        if ($dates['from'] > $dates['to']) {
            $err = Json::error($response, 'validation_failed', 'from nesmí být větší než to.', 422);
            return null;
        }

        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = (int) ($q['per_page'] ?? 50);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $counterRaw = trim((string) ($q['counter_account_id'] ?? ''));
        if ($counterRaw !== '' && !ctype_digit($counterRaw)) {
            $err = Json::error($response, 'validation_failed', 'counter_account_id musí být ID účtu.', 422);
            return null;
        }

        $err = null;
        return [
            'account_id' => $accountId,
            'from'       => $dates['from'],
            'to'         => $dates['to'],
            'page'       => $page,
            'per_page'   => $perPage,
            // Výchozí je stav PŘED uzavřením knih — po uzavření je zůstatek účtu
            // nulový a opis by k rozvahovému dni neukázal nic.
            'after_closing' => (string) ($q['after_closing'] ?? '') === '1',
            'counter_account_id' => $counterRaw === '' ? null : (int) $counterRaw,
        ];
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
