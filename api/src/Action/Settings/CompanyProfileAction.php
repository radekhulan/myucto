<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileException;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileExporter;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileFormat;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileImporter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Profil firmy: export a nahrání ručně vybudovaného nastavení (Nastavení → Profil firmy,
 * průvodci převodu z jiného programu).
 *
 *   GET  /api/settings/company-profile          stáhne profil (sekce, které uživatel smí číst)
 *   POST /api/settings/company-profile/import   {profile, dry_run = true, sections?}
 *
 * Nahrání vyžaduje zápisové oprávnění každé sekce, kterou profil nese; bez něj se
 * nenahraje nic. Výchozí je zkouška nanečisto, ostré nahrání musí klient vyžádat.
 */
final class CompanyProfileAction
{
    public function __construct(
        private readonly CompanyProfileExporter $exporter,
        private readonly CompanyProfileImporter $importer,
        private readonly DimensionRepository $dimensions,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function export(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        $sections = array_values(array_filter(
            CompanyProfileFormat::SECTIONS,
            static fn (string $s): bool => RequestAuthorization::allows($request, CompanyProfileFormat::SECTION_PERMISSIONS[$s], AccessLevel::READ),
        ));
        if ($sections === []) {
            return Json::error($response, 'forbidden', 'Pro export profilu firmy nemáte oprávnění.', 403);
        }
        try {
            $profile = $this->exporter->export($supplierId, $sections);
        } catch (CompanyProfileException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $this->log($request, 'company_profile.exported', $supplierId, ['sections' => array_keys($profile['sections'])]);

        $ic = preg_replace('/[^0-9A-Za-z]/', '', (string) ($profile['company']['ic'] ?? '')) ?: (string) $supplierId;

        return Json::ok($response, $profile)
            ->withHeader('Content-Disposition', sprintf('attachment; filename="profil-firmy-%s-%s.json"', $ic, date('Y-m-d')));
    }

    public function import(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $profile = $body['profile'] ?? null;
        $dryRun = filter_var($body['dry_run'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        $only = null;
        if (isset($body['sections'])) {
            if (!is_array($body['sections'])) {
                return Json::error($response, 'validation_failed', 'sections musí být seznam sekcí.', 422);
            }
            $only = array_values(array_map('strval', $body['sections']));
        }

        try {
            $sections = CompanyProfileFormat::sections($profile, $only);
            $present = array_keys($sections);
            if (LicenseMiddleware::state($request)?->hasCommercialFeatures() === false) {
                $company = (array) ($sections['company'] ?? []);
                $dimensionTypes = (array) (($sections['dimensions']['types'] ?? []));
                if ($dimensionTypes !== [] || ($sections['dimension_defaults'] ?? []) !== []
                    || ($sections['dimension_rules'] ?? []) !== []
                    || (!empty($company['dimensions_enabled']) && !$this->dimensions->enabled($supplierId))) {
                    return Json::error($response, 'license_commercial_feature_unavailable',
                        'Dimenze vyžadují aktivní licenci účetnictví.', 403);
                }
            }
            $missing = [];
            foreach ($present as $section) {
                $permission = CompanyProfileFormat::SECTION_PERMISSIONS[$section];
                if (!RequestAuthorization::allows($request, $permission, AccessLevel::WRITE)) {
                    $missing[$permission] = true;
                }
            }
            if ($missing !== []) {
                return Json::error($response, 'forbidden', 'Profil mění nastavení, ke kterému nemáte oprávnění: '
                    . implode(', ', array_keys($missing)) . '.', 403, ['missing_permissions' => array_keys($missing)]);
            }
            $result = $this->importer->import($supplierId, $profile, $dryRun, $only, self::userId($request));
        } catch (CompanyProfileException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, ['section' => $e->section]);
        }

        if (!$dryRun) {
            $this->log($request, 'company_profile.imported', $supplierId, [
                'changed' => $result['changed'],
                'sections' => array_map(
                    static fn (array $s): array => array_intersect_key($s, array_flip(['created', 'updated', 'unchanged', 'removed'])),
                    $result['sections'],
                ),
                'source_ic' => is_array($profile) ? ($profile['company']['ic'] ?? null) : null,
            ]);
        }

        return Json::ok($response, $result);
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, int $supplierId, array $payload): void
    {
        $this->logger->log($action, self::userId($request), 'supplier', $supplierId, $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);
    }

    private static function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);

        return $id > 0 ? $id : null;
    }
}
