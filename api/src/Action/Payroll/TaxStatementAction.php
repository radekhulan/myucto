<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\TaxStatement\DependentActivityStatement;
use MyInvoice\Service\Payroll\TaxStatement\TaxStatementService;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use MyInvoice\Service\Report\TaxSubmissionFilename;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Roční vyúčtování daně ze závislé činnosti a daně vybírané srážkou:
 *
 *   GET /api/payroll/reports/tax-statement/preview?year=2026
 *       → podklad obou vyúčtování (JSON)
 *   GET /api/payroll/reports/tax-statement?year=2026&form=dpzvd6
 *       → XML jedné písemnosti ke stažení (archivuje se jako každé EPO podání)
 *
 * `dpzvd6` = vyúčtování zálohové daně (§ 38j odst. 4 ZDP), `dpsvd2` = vyúčtování
 * daně vybírané srážkou podle zvláštní sazby (§ 38d ZDP). Jsou to dvě samostatná
 * podání s vlastní lhůtou, ne jedno se dvěma přílohami.
 */
final class TaxStatementAction
{
    public function __construct(
        private readonly TaxStatementService $service,
        private readonly TaxSubmissionArchiver $archiver,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PayrollModuleAccess $access,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'payroll.reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if (!$this->access->isEnabled($supplierId)) {
            return Json::error(
                $response,
                'payroll_disabled',
                'Vedení mezd je pro tuto firmu vypnuté v nastavení.',
                403,
            );
        }
        [$year, $error] = $this->parseYear($request);
        if ($error !== null) {
            return Json::error($response, 'validation_failed', $error, 400);
        }
        [$variant, $variantError] = $this->parseVariant($request);
        if ($variantError !== null) {
            return Json::error($response, 'validation_failed', $variantError, 400);
        }

        try {
            $result = $this->service->preview($supplierId, $year, ['variant' => $variant]);
        } catch (\DomainException | \InvalidArgumentException | \UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['year' => $year, 'statements' => $result]);
    }

    public function download(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'payroll.reports', AccessLevel::READ)
            || !RequestAuthorization::allows($request, 'reports.export', AccessLevel::READ)
        ) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if (!$this->access->isEnabled($supplierId)) {
            return Json::error(
                $response,
                'payroll_disabled',
                'Vedení mezd je pro tuto firmu vypnuté v nastavení.',
                403,
            );
        }
        [$year, $error] = $this->parseYear($request);
        if ($error !== null) {
            return Json::error($response, 'validation_failed', $error, 400);
        }
        [$variant, $variantError] = $this->parseVariant($request);
        if ($variantError !== null) {
            return Json::error($response, 'validation_failed', $variantError, 400);
        }

        $query = $request->getQueryParams();
        $formCode = strtolower(trim((string) ($query['form'] ?? '')));
        if (!in_array($formCode, TaxStatementService::FORMS, true)) {
            return Json::error(
                $response,
                'validation_failed',
                'Zvol tiskopis dpzvd6 (závislá činnost) nebo dpsvd2 (srážková daň).',
                400,
            );
        }
        $meta = ['variant' => $variant];
        if (isset($query['d_zjist']) && trim((string) $query['d_zjist']) !== '') {
            $meta['d_zjist'] = trim((string) $query['d_zjist']);
        }

        try {
            $result = $this->service->build($supplierId, $year, $formCode, $meta);
        } catch (\DomainException | \InvalidArgumentException | \UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        $userId = (int) ($user['id'] ?? 0);
        $archived = $this->archiver->archive(
            $supplierId,
            $formCode,
            $year,
            null,
            null,
            $result['xml'],
            $result['summary'] + ['warnings' => $result['warnings']],
            $userId ?: null,
            // Readonly stažení nesmí posouvat daňový zámek; vyúčtování navíc
            // není v `TaxSubmissionArchiver::VAT_LOCK_FORMS`.
            false,
            $variant,
        );

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('payroll.tax_statement_downloaded', $userId, null, null, [
            'form_code' => $formCode,
            'period' => (string) $year,
            'variant' => $variant,
            'submission_id' => $archived['submission_id'],
            'validation_status' => $archived['validation_status'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = TaxSubmissionFilename::forSnapshot([
            'id' => $archived['submission_id'],
            'form_code' => $formCode,
            'form_variant' => $variant,
            'period_year' => $year,
            'period_month' => null,
            'period_quarter' => null,
        ], 'xml');
        $response->getBody()->write($result['xml']);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @return array{0:int, 1:?string} */
    private function parseYear(Request $request): array
    {
        $query = $request->getQueryParams();
        // Vyúčtování se podává po skončení roku, takže výchozí je rok minulý.
        $year = (int) ($query['year'] ?? ((int) date('Y') - 1));
        if ($year < 2010 || $year > 2199) {
            return [0, 'Zdaňovací období musí být v rozsahu 2010 až 2199.'];
        }

        return [$year, null];
    }

    /** @return array{0:string, 1:?string} */
    private function parseVariant(Request $request): array
    {
        $query = $request->getQueryParams();
        $variant = strtoupper(trim(
            (string) ($query['variant'] ?? DependentActivityStatement::TYP_RADNE),
        ));
        if (!in_array($variant, DependentActivityStatement::TYPY, true)) {
            return ['', 'Typ vyúčtování musí být B, O, D nebo E.'];
        }

        return [$variant, null];
    }
}
