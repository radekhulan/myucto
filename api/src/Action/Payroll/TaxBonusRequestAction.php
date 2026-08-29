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
use MyInvoice\Service\Payroll\TaxBonus\TaxBonusClaim;
use MyInvoice\Service\Payroll\TaxBonus\TaxBonusRequestService;
use MyInvoice\Service\Payroll\TaxBonus\TaxBonusRequestXmlBuilder;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use MyInvoice\Service\Report\TaxSubmissionFilename;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Žádosti o poukázání chybějící částky vyplacené na daňových bonusech:
 *
 *   GET /api/payroll/reports/tax-bonus-request/preview?year=2026&month=3
 *       → podklad za měsíc + obě možné žádosti (JSON)
 *   GET /api/payroll/reports/tax-bonus-request?year=2026&month=3&form=dpzmb1
 *       → XML jedné žádosti ke stažení (archivuje se jako každé EPO podání)
 *
 * `dpzmb1` = § 35d odst. 5 (měsíční daňový bonus), `dpzdb1` = § 35d odst. 9
 * (doplatek z ročního zúčtování). Vyplatí-li plátce na bonusech víc, než srazil
 * na zálohách, doplácí rozdíl ze svého — a bez téhle žádosti mu ty peníze leží
 * u státu.
 */
final class TaxBonusRequestAction
{
    public function __construct(
        private readonly TaxBonusRequestService $service,
        private readonly TaxSubmissionArchiver $archiver,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'payroll.reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        [$year, $month, $error] = $this->parsePeriod($request);
        if ($error !== null) {
            return Json::error($response, 'validation_failed', $error, 400);
        }
        try {
            $result = $this->service->preview($supplierId, $year, $month);
        } catch (\DomainException | \InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $result);
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
        [$year, $month, $error] = $this->parsePeriod($request);
        if ($error !== null) {
            return Json::error($response, 'validation_failed', $error, 400);
        }
        $query = $request->getQueryParams();
        $formCode = strtolower(trim((string) ($query['form'] ?? '')));
        if (!in_array($formCode, [TaxBonusClaim::FORM_MONTHLY, TaxBonusClaim::FORM_ANNUAL], true)) {
            return Json::error(
                $response,
                'validation_failed',
                'Zvol tiskopis dpzmb1 (§ 35d odst. 5) nebo dpzdb1 (§ 35d odst. 9).',
                400,
            );
        }
        $zadTyp = strtoupper(trim((string) ($query['zad_typ'] ?? TaxBonusRequestXmlBuilder::ZAD_TYP_BEZNA)));
        $meta = ['zad_typ' => $zadTyp];
        if (isset($query['kc_ponech']) && $query['kc_ponech'] !== '') {
            $meta['kc_ponech'] = (int) $query['kc_ponech'];
        }

        try {
            $result = $this->service->build($supplierId, $year, $month, $formCode, $meta);
        } catch (\DomainException | \InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        $userId = (int) ($user['id'] ?? 0);
        $archived = $this->archiver->archive(
            $supplierId,
            $formCode,
            $year,
            $month,
            null,
            $result['xml'],
            $result['summary'] + ['warnings' => $result['warnings']],
            $userId ?: null,
            // Readonly download nesmí posouvat daňový zámek (dorevize B8, HIGH#1);
            // žádost o bonus navíc není v `TaxSubmissionArchiver::VAT_LOCK_FORMS`.
            false,
            $zadTyp,
        );

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('payroll.tax_bonus_request_downloaded', $userId, null, null, [
            'form_code' => $formCode,
            'period' => sprintf('%04d-%02d', $year, $month),
            'kc_bonus_vl' => $result['summary']['kc_bonus_vl'] ?? null,
            'zad_typ' => $zadTyp,
            'submission_id' => $archived['submission_id'],
            'validation_status' => $archived['validation_status'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = TaxSubmissionFilename::forSnapshot([
            'id' => $archived['submission_id'],
            'form_code' => $formCode,
            'form_variant' => $zadTyp,
            'period_year' => $year,
            'period_month' => $month,
            'period_quarter' => null,
        ], 'xml');
        $response->getBody()->write($result['xml']);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @return array{0:int, 1:int, 2:?string} */
    private function parsePeriod(Request $request): array
    {
        $query = $request->getQueryParams();
        $year = (int) ($query['year'] ?? date('Y'));
        $month = (int) ($query['month'] ?? date('n'));
        if ($month < 1 || $month > 12) {
            return [0, 0, 'Neplatný měsíc.'];
        }

        return [$year, $month, null];
    }
}
