<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\VatCoefficientRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Koeficient krácení nároku na odpočet dle § 76 ZDPH (C2', audit 2026-07, vat).
 *
 *   GET  /api/reports/vat-coefficient?year=2026 — čtení (admin/účetní/readonly)
 *   PUT  /api/reports/vat-coefficient           — nastavení zálohového koeficientu (admin/účetní)
 *   POST /api/reports/vat-coefficient/settle     — explicitní roční vypořádání (admin)
 *
 * Zálohový koeficient (provisional_percent, § 76 odst. 6) se uplatňuje na ř. 52 DPHDP3
 * každé zdaňovací období roku. Vypořádací (final_percent, § 76 odst. 7) se počítá ze
 * skutečných dat celého roku a ukládá se JEN touto explicitní POST akcí — nikdy jako
 * vedlejší efekt náhledu/downloadu přiznání (dorevize B8: readonly GET nesmí mutovat).
 */
final class VatCoefficientAction
{
    public function __construct(
        private readonly VatCoefficientRepository $coefficients,
        private readonly DphPriznaniBuilder $builder,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        if (!$this->allowed($request, AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $year = $this->year($request);
        if ($year === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok.', 422);
        }

        $row = $this->coefficients->get($supplierId, $year);
        $resolved = $this->coefficients->resolveProvisionalPercent($supplierId, $year);
        return Json::ok($response, [
            'year'                          => $year,
            'provisional_percent'           => $row['provisional_percent'] ?? null,
            'resolved_provisional_percent'  => $resolved,
            'carried_forward'               => ($row['provisional_percent'] ?? null) === null && $resolved !== null,
            'final_percent'                 => $row['final_percent'] ?? null,
            'numerator_czk'                 => $row['numerator_czk'] ?? null,
            'denominator_czk'               => $row['denominator_czk'] ?? null,
            'settled_at'                    => $row['settled_at'] ?? null,
        ]);
    }

    public function setProvisional(Request $request, Response $response): Response
    {
        if (!$this->allowed($request, AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $year = $this->year($request, $body);
        if ($year === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok.', 422);
        }
        if (!array_key_exists('provisional_percent', $body) || !is_numeric($body['provisional_percent'])) {
            return Json::error($response, 'validation_failed', 'provisional_percent (0-100) je povinné.', 422);
        }
        $percent = (int) round((float) $body['provisional_percent']);
        if ($percent < 0 || $percent > 100) {
            return Json::error($response, 'validation_failed', 'Koeficient musí být 0-100 %.', 422);
        }

        $before = $this->coefficients->get($supplierId, $year)['provisional_percent'] ?? null;
        $this->coefficients->setProvisionalPercent($supplierId, $year, $percent);

        $this->logger->log(
            'accounting.vat_coefficient_set',
            $this->userId($request),
            'supplier',
            $supplierId,
            ['year' => $year, 'before' => $before, 'after' => $percent],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, ['year' => $year, 'provisional_percent' => $percent]);
    }

    public function settle(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.finalize', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Vypořádání koeficientu smí provést jen admin.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $year = $this->year($request, $body);
        if ($year === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok.', 422);
        }

        try {
            $coef = $this->builder->computeAnnualCoefficient($supplierId, $year);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'settlement_failed', $e->getMessage(), 500);
        }
        if ($coef['kr_year'] <= 0.0) {
            return Json::error(
                $response,
                'nothing_to_settle',
                "Za rok {$year} nejsou žádné doklady s kráceným nárokem na odpočet (§ 76) — není co vypořádat.",
                422,
            );
        }

        $this->coefficients->settleYear(
            $supplierId,
            $year,
            $coef['final_percent'],
            $coef['numerator'],
            $coef['denominator'],
            $this->userId($request),
        );

        $this->logger->log(
            'accounting.vat_coefficient_settled',
            $this->userId($request),
            'supplier',
            $supplierId,
            [
                'year'          => $year,
                'final_percent' => $coef['final_percent'],
                'numerator'     => $coef['numerator'],
                'denominator'   => $coef['denominator'],
            ],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, [
            'year'            => $year,
            'final_percent'   => $coef['final_percent'],
            'numerator_czk'   => $coef['numerator'],
            'denominator_czk' => $coef['denominator'],
        ]);
    }

    private function allowed(Request $request, AccessLevel $minimum): bool
    {
        return RequestAuthorization::allows($request, 'reports', $minimum);
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** @param array<string,mixed> $body */
    private function year(Request $request, array $body = []): ?int
    {
        $raw = $body['year'] ?? ($request->getQueryParams()['year'] ?? null);
        if ($raw === null || !is_numeric($raw)) {
            return null;
        }
        $year = (int) $raw;
        return ($year >= 2020 && $year <= 2050) ? $year : null;
    }
}
