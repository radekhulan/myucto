<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\OneTimeTokenException;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\Tenant\SupplierDomainHostnameCollisionException;
use MyInvoice\Service\Tenant\SupplierDomainRegistrationService;
use MyInvoice\Service\Tenant\SupplierDomainVerificationService;
use MyInvoice\Service\Tenant\TenantDomainFeature;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SupplierDomainAction
{
    public function __construct(
        private readonly SupplierDomainRepository $domains,
        private readonly SupplierDomainRegistrationService $registration,
        private readonly SupplierDomainVerificationService $verification,
        private readonly MfaStepUpService $stepUp,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly TenantDomainFeature $feature,
        // H-30: featura je v buildu i na spravovaných instancích, ale zákazník
        // by tu založil doménu, kterou nikdy neověří — certifikát ani směrování
        // pro cizí hostname na cizí infrastruktuře nikdo nezřídí.
        private readonly ManagedModeGuard $managed,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) return $error;
        return Json::ok($response, array_map($this->present(...), $this->domains->listForSupplier($this->supplierId($request))));
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) return $error;
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $domain = $this->registration->register(
                $this->supplierId($request),
                (string) ($body['hostname'] ?? ''),
                (string) ($body['purpose'] ?? 'all'),
                $this->userId($request),
            );
            if (($body['is_primary'] ?? false) === true) {
                $domain = $this->domains->update(
                    $this->supplierId($request),
                    (int) $domain['id'],
                    (string) $domain['purpose'],
                    true,
                    $this->userId($request),
                );
            }
            $this->log($request, 'supplier_domain.created', (int) $domain['id'], [
                'hostname' => $domain['hostname'],
                'purpose' => $domain['purpose'],
            ]);
            return Json::ok($response, $this->present($domain), 201);
        } catch (SupplierDomainHostnameCollisionException $e) {
            return Json::error($response, 'canonical_hostname_conflict', $e->getMessage(), 409);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return Json::error($response, 'hostname_taken', 'Tento hostname už je přiřazený.', 409);
            }
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) return $error;
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $domain = $this->domains->update(
                $this->supplierId($request),
                (int) ($args['id'] ?? 0),
                (string) ($body['purpose'] ?? 'all'),
                ($body['is_primary'] ?? false) === true,
                $this->userId($request),
            );
            $this->log($request, 'supplier_domain.updated', (int) $domain['id'], [
                'purpose' => $domain['purpose'],
                'is_primary' => $domain['is_primary'],
            ]);
            return Json::ok($response, $this->present($domain));
        } catch (\OutOfBoundsException) {
            return Json::error($response, 'not_found', 'Doména nebyla nalezena.', 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'domain_active', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
    }

    public function rotateChallenge(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) return $error;
        try {
            $domain = $this->domains->rotateChallenge(
                $this->supplierId($request),
                (int) ($args['id'] ?? 0),
                $this->userId($request),
            );
            $this->log($request, 'supplier_domain.challenge_rotated', (int) $domain['id'], []);
            return Json::ok($response, $this->present($domain));
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
    }

    public function verify(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) return $error;
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        try {
            $verification = $this->verification->verifyCurrent($sid, $id, $this->userId($request));
            $this->logVerification($request, $id, $verification['checks']);
            return Json::ok($response, [
                'domain' => $this->present($verification['domain']),
                'checks' => $verification['checks'],
            ]);
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
    }

    public function activate(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) return $error;
        $body = (array) ($request->getParsedBody() ?? []);
        $id = (int) ($args['id'] ?? 0);
        try {
            $domain = $this->domains->findOwned($this->supplierId($request), $id);
            if ($domain === null) {
                return Json::error($response, 'not_found', 'Doména nebyla nalezena.', 404);
            }
            $this->registration->assertNotCanonicalHostname((string) $domain['hostname']);
            if ($domain['status'] !== 'verified' || $domain['verified_at'] === null) {
                return Json::error(
                    $response,
                    'domain_not_verified',
                    'Doména musí mít čerstvě ověřené DNS a HTTPS.',
                    409,
                );
            }
            $this->stepUp->consume(
                trim((string) ($body['step_up_token'] ?? '')),
                $this->userId($request),
                (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, ''),
                MfaStepUpService::domainActivationOperation($id),
            );
            $verification = $this->verification->verifyCurrent(
                $this->supplierId($request),
                $id,
                $this->userId($request),
                $domain,
            );
            $this->logVerification($request, $id, $verification['checks']);
            if (!$verification['checks']['verified']) {
                return Json::error(
                    $response,
                    'domain_not_verified',
                    $verification['checks']['error'] ?? 'DNS nebo HTTPS ověření domény selhalo.',
                    409,
                );
            }
            $domain = $this->domains->activate(
                $this->supplierId($request),
                $id,
                ($body['is_primary'] ?? true) === true,
                $this->userId($request),
            );
            $this->log($request, 'supplier_domain.activated', $id, [
                'hostname' => $domain['hostname'],
                'purpose' => $domain['purpose'],
            ]);
            return Json::ok($response, $this->present($domain));
        } catch (OneTimeTokenException|StepUpOperationException $e) {
            return Json::error($response, 'step_up_required', $e->getMessage(), 403, [
                'operation' => MfaStepUpService::domainActivationOperation($id),
            ]);
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
    }

    public function disable(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) return $error;
        $id = (int) ($args['id'] ?? 0);
        try {
            $this->domains->disable($this->supplierId($request), $id, $this->userId($request));
            $this->log($request, 'supplier_domain.disabled', $id, []);
            return Json::ok($response, ['disabled' => true]);
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) return $error;
        $id = (int) ($args['id'] ?? 0);
        try {
            $this->domains->delete($this->supplierId($request), $id);
            $this->log($request, 'supplier_domain.deleted', $id, []);
            return Json::ok($response, ['deleted' => true]);
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        // Vypnutá featura nesmí jít obejít přímým voláním API. Odpověď je 404,
        // ne 403 — s vypnutými doménami tahle plocha prostě neexistuje.
        if (!$this->feature->isEnabled()) {
            return Json::error($response, 'not_found', 'Vlastní domény nejsou v této instalaci zapnuté.', 404);
        }
        // Na rozdíl od vypnuté featury tady odpověď plochu POJMENUJE: doména
        // existovat může, jen ji nezakládá zákazník. 404 by vypadalo jako chyba.
        // Zamčené je i čtení — jinak by UI muselo hádat, co smí zobrazit.
        if (($locked = $this->managed->deny($response, ManagedModeGuard::CAPABILITY_CUSTOM_DOMAINS)) !== null) {
            return $locked;
        }
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'Domény lze spravovat jen z webového rozhraní.');
        }
        if (!RequestAuthorization::allows($request, 'settings.domains', $level)) {
            return Json::error($response, 'forbidden_permission', 'Pro správu domén nemáš oprávnění.', 403);
        }
        return null;
    }

    private function supplierId(Request $request): int
    {
        $id = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($id < 1) throw new \DomainException('Firma není vybraná.');
        return $id;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0);
    }

    /** @param array<string,mixed> $domain @return array<string,mixed> */
    private function present(array $domain): array
    {
        $hostname = (string) $domain['hostname'];
        $token = (string) $domain['verification_token'];
        $domain['dns'] = [
            'type' => 'TXT',
            'name' => '_myucto-challenge.' . $hostname,
            'value' => 'myucto-verification=' . $token,
        ];
        $domain['verification_url'] = 'https://' . $hostname . '/api/public/domain-verification/' . $token;
        // Karta aliasu ukazuje URL právě tohoto hostname. Efektivní doménu pro
        // nové odkazy vybírá TenantUrlResolver podle primárních flagů.
        $domain['portal_url'] = 'https://' . $hostname . '/portal';
        $domain['public_base_url'] = 'https://' . $hostname . '/';
        unset($domain['verification_token']);
        return $domain;
    }

    private function domainError(Response $response, \Throwable $e): Response
    {
        return match (true) {
            $e instanceof \OutOfBoundsException => Json::error($response, 'not_found', 'Doména nebyla nalezena.', 404),
            $e instanceof SupplierDomainHostnameCollisionException
                => Json::error($response, 'canonical_hostname_conflict', $e->getMessage(), 409),
            $e instanceof \DomainException => Json::error($response, 'domain_state_conflict', $e->getMessage(), 409),
            $e instanceof PDOException && $e->getCode() === '23000'
                => Json::error($response, 'domain_conflict', 'Primární doména pro tento účel už existuje.', 409),
            default => throw $e,
        };
    }

    /** @param array{verified:bool,dns:bool,https:bool,error:?string} $checks */
    private function logVerification(Request $request, int $domainId, array $checks): void
    {
        $this->log($request, 'supplier_domain.verification_checked', $domainId, [
            'verified' => $checks['verified'],
            'dns' => $checks['dns'],
            'https' => $checks['https'],
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, int $domainId, array $payload): void
    {
        $this->activity->log(
            $action,
            $this->userId($request),
            'supplier_domain',
            $domainId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->supplierId($request),
        );
    }
}
