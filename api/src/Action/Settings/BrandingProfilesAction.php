<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\BrandingProfileRepository;
use MyInvoice\Security\OperationalSettingsAccess;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Branding\BrandingProfileValidation;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\SupplierLogoConverter;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class BrandingProfilesAction
{
    private const MAX_FILE_SIZE = 1_048_576;

    public function __construct(
        private readonly BrandingProfileRepository $profiles,
        private readonly SupplierLogoConverter $logoConverter,
        private readonly InvoicePdfRenderer $invoicePdf,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        // Původní staff/demo GET zůstává kompatibilní. Klientská role však smí
        // profilová data číst až se stejným WRITE právem, které vyžadují mutace.
        if (RequestAuthorization::isClientType($request) && !$this->canManage($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění spravovat branding.', 403);
        }
        $supplierId = $this->supplierId($request);
        if ($supplierId <= 0) return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        return Json::ok($response, $this->profiles->listForSupplier($supplierId));
    }

    public function publicList(Request $request, Response $response): Response
    {
        $supplierId = $this->supplierId($request);
        if ($supplierId <= 0) return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        if (!$this->profiles->isEnabled($supplierId)) return Json::ok($response, []);
        $profiles = array_map(static function (array $profile): array {
            unset($profile['email_profile_id']);
            return $profile;
        }, $this->profiles->listForSupplier($supplierId, true));
        return Json::ok($response, $profiles);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->canManage($request)) return Json::error($response, 'forbidden', 'Nemáš oprávnění měnit branding.', 403);
        $supplierId = $this->supplierId($request);
        if ($supplierId <= 0) return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        if (($gate = $this->requireEnabled($response, $supplierId)) !== null) return $gate;
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = BrandingProfileValidation::validate($body);
        $errors = array_merge($errors, $this->emailProfileErrors($supplierId, $body));
        if ($errors !== []) return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        try {
            $id = $this->profiles->create($supplierId, $body);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return Json::error($response, 'profile_conflict', 'Brandingový profil s tímto názvem už existuje.', 409);
            }
            throw $e;
        }
        $profile = $this->profiles->findForSupplier($id, $supplierId);
        $this->invoicePdf->invalidateDraftsBySupplier($supplierId);
        $this->log($request, 'branding_profile.created', $id, ['name' => $profile['name'] ?? null]);
        return Json::ok($response, $profile, 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->canManage($request)) return Json::error($response, 'forbidden', 'Nemáš oprávnění měnit branding.', 403);
        $supplierId = $this->supplierId($request);
        if (($gate = $this->requireEnabled($response, $supplierId)) !== null) return $gate;
        $id = (int) ($args['id'] ?? 0);
        if ($this->profiles->findForSupplier($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Brandingový profil nenalezen.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = BrandingProfileValidation::validate($body, true);
        $errors = array_merge($errors, $this->emailProfileErrors($supplierId, $body));
        if ($errors !== []) return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        try {
            $this->profiles->update($id, $supplierId, $body);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return Json::error($response, 'profile_conflict', 'Brandingový profil s tímto názvem už existuje.', 409);
            }
            throw $e;
        }
        $profile = $this->profiles->findForSupplier($id, $supplierId);
        $this->invoicePdf->invalidateDraftsBySupplier($supplierId);
        $this->log($request, 'branding_profile.updated', $id, ['fields' => array_keys($body)]);
        return Json::ok($response, $profile);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->canManage($request)) return Json::error($response, 'forbidden', 'Nemáš oprávnění měnit branding.', 403);
        $supplierId = $this->supplierId($request);
        if (($gate = $this->requireEnabled($response, $supplierId)) !== null) return $gate;
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->profiles->findForSupplier($id, $supplierId);
        $deleted = $this->profiles->delete($id, $supplierId);
        if (!$deleted) return Json::error($response, 'not_found', 'Brandingový profil nenalezen.', 404);
        $this->invoicePdf->invalidateDraftsBySupplier($supplierId);
        $this->log($request, 'branding_profile.deleted', $id, ['name' => $existing['name'] ?? null]);
        return Json::ok($response, ['deleted' => true]);
    }

    public function setDefault(Request $request, Response $response, array $args): Response
    {
        if (!$this->canManage($request)) return Json::error($response, 'forbidden', 'Nemáš oprávnění měnit branding.', 403);
        $supplierId = $this->supplierId($request);
        if (($gate = $this->requireEnabled($response, $supplierId)) !== null) return $gate;
        $id = (int) ($args['id'] ?? 0);
        if (!$this->profiles->setDefault($id, $supplierId)) {
            return Json::error($response, 'not_found', 'Aktivní brandingový profil nenalezen.', 404);
        }
        $profile = $this->profiles->findForSupplier($id, $supplierId);
        $this->invoicePdf->invalidateDraftsBySupplier($supplierId);
        $this->log($request, 'branding_profile.default_changed', $id, ['name' => $profile['name'] ?? null]);
        return Json::ok($response, $profile);
    }

    public function uploadLogo(Request $request, Response $response, array $args): Response
    {
        if (!$this->canManage($request)) return Json::error($response, 'forbidden', 'Nemáš oprávnění měnit branding.', 403);
        $supplierId = $this->supplierId($request);
        if (($gate = $this->requireEnabled($response, $supplierId)) !== null) return $gate;
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->profiles->findForSupplier($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Brandingový profil nenalezen.', 404);
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return Json::error($response, 'no_file', 'Žádný soubor nebyl odeslán (pole `file`).', 400);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'upload_failed', 'Nahrání souboru selhalo.', 400);
        }
        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            return Json::error($response, 'invalid_file_size', 'Logo musí mít 1 B až 1 MiB.', 413);
        }
        $tmpPath = sys_get_temp_dir() . '/.branding-upload-' . bin2hex(random_bytes(8));
        try {
            $file->moveTo($tmpPath);
            $result = $this->logoConverter->process($tmpPath, $supplierId, $id);
            $this->profiles->setLogoPath($id, $supplierId, $result['logo_path']);
            $this->discardOrphanLogo((string) ($existing['logo_path'] ?? ''), $result['logo_path'], $supplierId);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'conversion_failed', $e->getMessage(), 400);
        } finally {
            @unlink($tmpPath);
        }
        $profile = $this->profiles->findForSupplier($id, $supplierId);
        $this->invoicePdf->invalidateDraftsBySupplier($supplierId);
        $this->log($request, 'branding_profile.logo_uploaded', $id, [
            'width' => $result['width'] ?? null,
            'height' => $result['height'] ?? null,
        ]);
        return Json::ok($response, $profile);
    }

    public function deleteLogo(Request $request, Response $response, array $args): Response
    {
        if (!$this->canManage($request)) return Json::error($response, 'forbidden', 'Nemáš oprávnění měnit branding.', 403);
        $supplierId = $this->supplierId($request);
        if (($gate = $this->requireEnabled($response, $supplierId)) !== null) return $gate;
        $id = (int) ($args['id'] ?? 0);
        if (!$this->profiles->setLogoPath($id, $supplierId, null)) {
            return Json::error($response, 'not_found', 'Brandingový profil nenalezen.', 404);
        }
        // Soubor záměrně nemažeme: vystavené faktury jej mohou mít ve snapshotu.
        $profile = $this->profiles->findForSupplier($id, $supplierId);
        $this->invoicePdf->invalidateDraftsBySupplier($supplierId);
        $this->log($request, 'branding_profile.logo_deleted', $id, []);
        return Json::ok($response, $profile);
    }

    /**
     * Re-upload loga vyrábí nový soubor (jméno nese hash obsahu), takže ten
     * předchozí by na disku zůstal navždy. Smažeme ho jen tehdy, když ho nedrží
     * žádný jiný profil, dodavatel ani snapshot vystavené faktury — u těch je
     * zachování souboru záměr (viz `deleteLogo`).
     */
    private function discardOrphanLogo(string $old, string $new, int $supplierId): void
    {
        if ($old === '' || $old === $new) return;
        if ($this->profiles->isLogoPathInUse($old, $supplierId)) return;
        $abs = \MyInvoice\Service\Mail\SafeLogoPath::resolve($old, $supplierId);
        if ($abs === null) return;
        \MyInvoice\Service\Pdf\PdfLogoFlattener::cleanup($abs);
        @unlink($abs);
        $svgSidecar = preg_replace('/\.png$/i', '.svg', $abs);
        if (is_string($svgSidecar) && $svgSidecar !== $abs) @unlink($svgSidecar);
    }

    /**
     * Mutace profilů dávají smysl jen se zapnutým modulem — čtecí cesty (PDF,
     * e-maily, snapshot) gatují na `supplier.branding_profiles_enabled` v SQL,
     * takže bez téhle brány šlo profil založit a pak ho nikde nezobrazit.
     */
    private function requireEnabled(Response $response, int $supplierId): ?Response
    {
        if ($this->profiles->isEnabled($supplierId)) return null;
        return Json::error(
            $response,
            'branding_profiles_disabled',
            'Brandingové profily nejsou pro tohoto dodavatele zapnuté.',
            409,
        );
    }

    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    /** @param array<string,mixed> $body @return array<string,list<string>> */
    private function emailProfileErrors(int $supplierId, array $body): array
    {
        if (!array_key_exists('email_profile_id', $body)
            || $body['email_profile_id'] === null
            || $body['email_profile_id'] === ''
        ) {
            return [];
        }
        $profileId = (int) $body['email_profile_id'];
        if ($profileId > 0 && $this->profiles->hasActiveEmailProfile($supplierId, $profileId)) return [];

        return ['email_profile_id' => ['Vybraný e-mailový profil není aktivní v aktuální firmě.']];
    }

    private function canManage(Request $request): bool
    {
        return OperationalSettingsAccess::branding($request);
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, int $profileId, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log(
            $action,
            isset($user['id']) ? (int) $user['id'] : null,
            'branding_profile',
            $profileId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->supplierId($request),
        );
    }
}
