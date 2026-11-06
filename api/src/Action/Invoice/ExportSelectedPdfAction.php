<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\DemoReadOnlyMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\MergedInvoicePdfExporter;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /api/invoices/export.pdf?ids=1,2,3&sign_pdf=0|1 */
final class ExportSelectedPdfAction
{
    private const MAX_INVOICES = 100;

    /**
     * Shodná definice „vystavených" dokladů jako u ZIP exportu za období
     * (ExportAction::findInvoiceIds). Koncept nemá přidělené číslo — do
     * hromadného exportu by šel s placeholderem DRAFT-{id}, a podepsat takový
     * celek by znamenalo podepsat něco, co ještě není daňový doklad.
     */
    private const EXPORTABLE_STATUSES = ['issued', 'sent', 'reminded', 'paid'];

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly MergedInvoicePdfExporter $exporter,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'utilities.export', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }

        try {
            $ids = $this->parseIds((string) ($request->getQueryParams()['ids'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\LengthException $e) {
            return Json::error($response, 'too_many', $e->getMessage(), 422);
        }

        $notExportable = [];
        foreach ($ids as $id) {
            $invoice = $this->invoices->find($id);
            if (!SupplierGuard::owns($request, $invoice)) {
                return Json::error($response, 'not_found', 'Jedna z vybraných faktur nebyla nalezena.', 404);
            }
            if (!in_array((string) ($invoice['status'] ?? ''), self::EXPORTABLE_STATUSES, true)) {
                $notExportable[] = (string) ($invoice['varsymbol'] ?? '') !== ''
                    ? (string) $invoice['varsymbol']
                    : '#' . $id;
            }
        }
        if ($notExportable !== []) {
            return Json::error(
                $response,
                'not_exportable',
                'Exportovat lze jen vystavené doklady. Vyřaď z výběru: ' . implode(', ', $notExportable) . '.',
                422,
            );
        }

        $supplierId = SupplierGuard::currentId($request);
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $sign = filter_var(
            $request->getQueryParams()['sign_pdf'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );

        ini_set('display_errors', '0');
        ini_set('html_errors', '0');
        ob_start();
        try {
            $result = $this->exporter->export(
                $ids,
                ['id' => $supplierId],
                $userId,
                $sign,
                !DemoReadOnlyMiddleware::enabled($request),
            );
        } catch (\DomainException $e) {
            ob_end_clean();
            return Json::error($response, 'signature_unavailable', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            ob_end_clean();
            return Json::internalError($response, 'export_failed', $e->getMessage(), 500);
        }
        ob_end_clean();

        // Načti a rovnou ukliď. register_shutdown_function() by na Windows temp
        // soubor nesmazal — shutdown funkce běží dřív než destruktor streamu, a
        // otevřený handle unlink() zablokuje. Velikost je stropovaná MAX_INVOICES.
        try {
            $content = file_get_contents($result['path']);
        } finally {
            @unlink($result['path']);
        }
        if ($content === false) {
            return Json::error($response, 'export_failed', 'Sloučené PDF nelze načíst.', 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoices.merged_pdf_exported', $userId, null, null, [
            'invoice_ids' => $ids,
            'count' => count($ids),
            'signed_pdf' => $result['signed'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = 'myucto-vybrane-faktury-' . date('Y-m-d') . '.pdf';
        $response->getBody()->write($content);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($content))
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @return list<int> */
    private function parseIds(string $raw): array
    {
        if ($raw === '') {
            throw new \InvalidArgumentException('Není vybrána žádná faktura.');
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            if (preg_match('/^[1-9][0-9]*$/', $part) !== 1) {
                throw new \InvalidArgumentException('Parametr ids musí obsahovat čísla oddělená čárkou.');
            }
            $ids[(int) $part] = (int) $part;
        }
        $ids = array_values($ids);
        if (count($ids) > self::MAX_INVOICES) {
            throw new \LengthException('Najednou lze exportovat maximálně 100 faktur.');
        }

        return $ids;
    }
}
