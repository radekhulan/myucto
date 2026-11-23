<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollInputFilter;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Export\PayrollInputExportService;
use MyInvoice\Service\Payroll\Export\PayrollInputExportTooLargeException;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Export mzdových vstupů podle filtru stránky.
 *
 *   GET /api/payroll/inputs/export.xlsx?period=YYYY-MM&…filtr
 *   GET /api/payroll/inputs/export.pdf?period=YYYY-MM&…filtr
 *
 * Filtr má tytéž parametry jako výpis (`q`, `employee_id`, `employment_id`,
 * `component_id`, `component_code`, `source_kind`, `status`, `import_id`)
 * včetně rozsahu měsíců `period_to` — historie jednoho vztahu se stahuje
 * stejně jako se čte. Hlavička i název souboru pak nesou celý rozsah, ne jen
 * jeho začátek. `group_by` export ignoruje, mění jen pohled na stránce. Právo
 * je stejné jako na čtení výpisu, stahuje se jen v přihlášené relaci.
 */
final class PayrollInputsExportAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollInputExportService $export,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
    ) {}

    /** @param array<string,string> $args */
    public function export(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll', AccessLevel::READ, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }
        $format = $args['format'] ?? '';
        if (!in_array($format, ['xlsx', 'pdf'], true)) {
            return Json::error($response, 'validation_failed', 'Formát exportu musí být xlsx nebo pdf.', 422);
        }

        $query = $request->getQueryParams();
        $supplierId = $this->currentSupplierId($request);
        try {
            $filter = PayrollInputFilter::fromArray(
                self::period($query['period'] ?? null),
                [...$query, 'employment_id' => self::narrowingId($query, 'employment_id')],
            );
            $out = $format === 'pdf'
                ? $this->export->pdf($supplierId, $filter)
                : $this->export->xlsx($supplierId, $filter);
        } catch (PayrollInputExportTooLargeException $e) {
            return Json::error($response, 'export_too_large', $e->getMessage(), 422, [
                'row_count' => $e->rowCount,
                'limit' => $e->limit,
            ]);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->log->error('Export mzdových vstupů selhal: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'export_failed', 'Export mzdových vstupů se nepodařilo vytvořit.', 500);
        }

        // Export vynáší jména a částky mezd mimo aplikaci, proto se eviduje.
        $this->logger->log(
            'payroll.inputs.exported',
            $this->userId($request),
            'payroll_input',
            null,
            [
                'format' => $format,
                'row_count' => $out['row_count'],
                'filter' => $filter->toArray(),
            ],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        $response->getBody()->write($out['bytes']);

        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private static function period(mixed $value): string
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
}
