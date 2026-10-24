<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Middleware\DomainLoginSessionMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\UserRoleProfile;
use MyInvoice\Service\Tenant\ClientRoutePolicy;
use MyInvoice\Service\Tenant\TenantDomainContext;
use MyInvoice\Service\Tenant\TenantDomainResolver;
use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Jednorázový PKCE přechod z canonical loginu na session svázanou s vlastní doménou. */
final class DomainLoginService
{
    private const REQUEST_TTL_MINUTES = 10;
    private const CODE_TTL_SECONDS = 60;

    public function __construct(
        private readonly Connection $db,
        private readonly TenantDomainResolver $domainResolver,
        private readonly UserSupplierRepository $memberships,
        private readonly UserRoleProfile $roles,
        private readonly ClientRoutePolicy $clientRoutes,
        private readonly SecurityClock $clock,
        private readonly SessionManager $sessions,
    ) {}

    /** @return array{request_token:string,state:string,login_url:string,expires_in:int} */
    public function start(
        Request $request,
        string $pkceChallenge,
        string $returnPath,
        string $ip,
        string $handoffPath = '',
    ): array
    {
        $context = self::context($request);
        if ($context->mode !== TenantDomainContext::CUSTOM
            || !$context->allowsPortal()
            || $context->domainId === null
            || $context->supplierId === null
        ) {
            throw new DomainLoginException('domain_login_unavailable', 'Přihlášení přes vlastní doménu tu není dostupné.', 404);
        }
        self::assertChallenge($pkceChallenge);
        $returnPath = $this->safeReturnPath($returnPath);
        $handoffPath = trim($handoffPath);
        $canonicalHandoff = $handoffPath !== ''
            ? $this->clientRoutes->canonicalHandoffPath($handoffPath)
            : $this->clientRoutes->canonicalHandoffPath($returnPath);
        if ($handoffPath !== '' && $canonicalHandoff === null) {
            throw new DomainLoginException(
                'invalid_handoff_path',
                'Canonical přechod není pro tuto cestu povolený.',
                400,
            );
        }
        // Starší klient mohl poslat WebAuthn obrazovku přímo jako návratovou
        // cestu. Po canonical ceremonii by se na ní znovu spustil handoff a
        // vznikla smyčka, proto se výsledná custom-domain session vrátí na portál.
        if ($this->clientRoutes->canonicalHandoffPath($returnPath) !== null) {
            $returnPath = '/portal';
        }
        $canonical = $this->domainResolver->canonicalOrigin();
        if ($canonical === '') {
            throw new DomainLoginException('canonical_url_missing', 'Canonical app.url není nastavené.', 503);
        }

        $requestToken = self::randomToken();
        $state = self::randomToken();
        $packedIp = @inet_pton($ip);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO supplier_domain_login_requests
                (request_token_hash, supplier_domain_id, supplier_id, target_hostname,
                 state_hash, pkce_challenge, return_path, expires_at,
                 created_ip, created_user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?,
                     DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? MINUTE), ?, ?)'
        );
        $stmt->execute([
            self::hash($requestToken),
            $context->domainId,
            $context->supplierId,
            $context->hostname,
            self::hash($state),
            $pkceChallenge,
            $returnPath,
            self::REQUEST_TTL_MINUTES,
            $packedIp === false ? null : $packedIp,
            mb_substr($request->getHeaderLine('User-Agent'), 0, 255),
        ]);

        $loginUrl = $canonical . '/login?domain_login_request=' . rawurlencode($requestToken)
            . '&state=' . rawurlencode($state);
        if ($canonicalHandoff !== null) {
            $loginUrl .= '&domain_login_handoff=' . rawurlencode($canonicalHandoff);
        }

        return [
            'request_token' => $requestToken,
            'state' => $state,
            'login_url' => $loginUrl,
            'expires_in' => self::REQUEST_TTL_MINUTES * 60,
        ];
    }

    /**
     * Popis čekajícího předání pro potvrzovací obrazovku na canonical adrese.
     * Ověří totéž co autorizace (platnost, stav, doménu i členství), ale kód
     * nevydá a požadavek nespotřebuje.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $session
     * @return array{target_hostname:string,supplier_name:string}
     */
    public function describe(
        Request $request,
        string $requestToken,
        string $state,
        array $user,
        array $session,
    ): array {
        $userId = $this->assertCanonicalAuthorization($request, $requestToken, $state, $user, $session);
        $row = $this->authorizableRequest($this->db->pdo(), $requestToken, $state, $userId, false);
        return self::confirmationDetails($row);
    }

    /**
     * Vydá jednorázový autorizační kód pro vlastní doménu.
     *
     * Bez výslovného potvrzení (`$confirmed`) projde jen session, která vznikla
     * až v rámci tohoto předání, tedy přihlášením na canonical adrese po
     * založení požadavku. Starší session by jinak po jediném kliknutí na
     * podstrčený odkaz předala přihlášení doméně, kterou ovládá kdokoli s její DNS.
     * Superadmin bez přiřazení k firmě potvrzuje předání vždy, i hned po přihlášení.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $session
     * @return array{redirect_url:string}
     */
    public function authorize(
        Request $request,
        string $requestToken,
        string $state,
        array $user,
        array $session,
        bool $confirmed = false,
    ): array {
        $userId = $this->assertCanonicalAuthorization($request, $requestToken, $state, $user, $session);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $row = $this->authorizableRequest($pdo, $requestToken, $state, $userId, true);
            if (!$confirmed && (!self::sessionStartedWithin($session, $row) || $row['superadmin_without_membership'])) {
                throw new DomainLoginException(
                    'domain_login_confirmation_required',
                    'Předání přihlášení na vlastní doménu je potřeba potvrdit.',
                    409,
                    self::confirmationDetails($row),
                );
            }

            $code = self::randomToken();
            $update = $pdo->prepare(
                'UPDATE supplier_domain_login_requests
                    SET authorized_by = ?, authorization_code_hash = ?,
                        code_expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND),
                        auth_method = ?, assurance_level = ?, mfa_verified_at = ?,
                        auth_credential_id = ?
                  WHERE id = ? AND authorization_code_hash IS NULL AND used_at IS NULL'
            );
            $update->execute([
                $userId,
                self::hash($code),
                self::CODE_TTL_SECONDS,
                (string) ($session['auth_method'] ?? 'legacy'),
                (string) ($session['assurance_level'] ?? 'legacy'),
                self::nullableUtc($session['mfa_verified_at'] ?? null),
                isset($session['auth_credential_id']) ? (int) $session['auth_credential_id'] ?: null : null,
                (int) $row['id'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainLoginException('invalid_domain_login_request', 'Požadavek už byl použit.', 400);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return [
            'redirect_url' => 'https://' . $row['target_hostname'] . '/domain-login/callback'
                . '?request=' . rawurlencode($requestToken)
                . '&code=' . rawurlencode($code)
                . '&state=' . rawurlencode($state),
        ];
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $session
     */
    private function assertCanonicalAuthorization(
        Request $request,
        string $requestToken,
        string $state,
        array $user,
        array $session,
    ): int {
        $context = self::context($request);
        if ($context->mode !== TenantDomainContext::CANONICAL) {
            throw new DomainLoginException('canonical_origin_required', 'Domain login lze autorizovat jen na canonical originu.', 403);
        }
        self::assertOpaqueToken($requestToken);
        self::assertOpaqueToken($state);
        $flow = $request->getAttribute(DomainLoginSessionMiddleware::ATTR_VERIFIED_FLOW);
        // Potvrzení cílové domény nenahrazuje nové přihlášení do oddělené relace.
        if (!is_array($flow)
            || !hash_equals(self::hash($requestToken), (string) ($flow['request_token_hash'] ?? ''))
            || !hash_equals(self::hash($state), (string) ($flow['state_hash'] ?? ''))
        ) {
            throw new DomainLoginException('fresh_authentication_required', 'Pro klientskou doménu je nutné nové přihlášení.', 401);
        }
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1 || ($session['assurance_level'] ?? 'setup') === 'setup') {
            throw new DomainLoginException('authentication_required', 'Nejdřív dokonči přihlášení včetně MFA.', 401);
        }
        return $userId;
    }

    /**
     * Načte platný, dosud neautorizovaný požadavek a ověří členství uživatele
     * ve firmě, které doména patří. Superadmin smí i bez přiřazení (má přístup
     * ke všem firmám), vždy ale s výslovným potvrzením. Session domény je pak
     * svázaná s jejím hostname, zamčená na její firmu a na doméně projdou jen
     * cesty klientského rozhraní, takže víc než canonical adresa nedává.
     *
     * @return array<string,mixed>
     */
    private function authorizableRequest(PDO $pdo, string $requestToken, string $state, int $userId, bool $lock): array
    {
        $stmt = $pdo->prepare(
            "SELECT r.*, UNIX_TIMESTAMP(r.created_at) AS created_epoch,
                    d.status AS domain_status, d.purpose AS domain_purpose,
                    COALESCE(NULLIF(s.display_name, ''), s.company_name) AS supplier_name
               FROM supplier_domain_login_requests r
               JOIN supplier_domains d
                 ON d.id = r.supplier_domain_id AND d.supplier_id = r.supplier_id
               LEFT JOIN supplier s ON s.id = r.supplier_id
              WHERE r.request_token_hash = ?
                AND r.expires_at > UTC_TIMESTAMP(6)
                AND r.used_at IS NULL
              LIMIT 1" . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([self::hash($requestToken)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false
            || !hash_equals((string) $row['state_hash'], self::hash($state))
            || $row['domain_status'] !== 'active'
            || !in_array($row['domain_purpose'], ['portal', 'all'], true)
            || $row['authorization_code_hash'] !== null
        ) {
            throw new DomainLoginException('invalid_domain_login_request', 'Požadavek na přihlášení je neplatný nebo expiroval.', 400);
        }

        $member = in_array((int) $row['supplier_id'], $this->memberships->allowedSupplierIds($userId), true);
        if (!$member && !$this->roles->isSuperadmin($userId)) {
            throw new DomainLoginException('forbidden_supplier', 'K portálu této firmy nemáš oprávnění.', 403);
        }
        $row['superadmin_without_membership'] = !$member;
        return $row;
    }

    /**
     * Session vznikla až po založení požadavku, tedy přihlášením v rámci
     * tohoto předání. Rotace zamčené session zachovává původní `created_at`,
     * takže odemčení starší session čerstvé přihlášení nepředstírá.
     *
     * @param array<string,mixed> $session
     * @param array<string,mixed> $row
     */
    private static function sessionStartedWithin(array $session, array $row): bool
    {
        $sessionCreated = (int) ($session['created_at'] ?? 0);
        $requestCreated = (int) floor((float) ($row['created_epoch'] ?? 0));
        return $sessionCreated > 0 && $requestCreated > 0 && $sessionCreated >= $requestCreated;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{target_hostname:string,supplier_name:string}
     */
    private static function confirmationDetails(array $row): array
    {
        return [
            'target_hostname' => (string) $row['target_hostname'],
            'supplier_name' => (string) ($row['supplier_name'] ?? ''),
        ];
    }

    /** @return array{session:array<string,mixed>,return_path:string,supplier_id:int,domain_id:int,user_id:int} */
    public function exchange(
        Request $request,
        string $requestToken,
        string $code,
        string $state,
        string $verifier,
        string $ip,
    ): array {
        $context = self::context($request);
        if ($context->mode !== TenantDomainContext::CUSTOM
            || !$context->allowsPortal()
            || $context->domainId === null
            || $context->supplierId === null
        ) {
            throw new DomainLoginException('domain_login_unavailable', 'Cílová doména není aktivní.', 404);
        }
        self::assertOpaqueToken($requestToken);
        self::assertOpaqueToken($code);
        self::assertOpaqueToken($state);
        if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) !== 1) {
            throw new DomainLoginException('invalid_pkce_verifier', 'PKCE verifier nemá platný formát.', 400);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $cutoff = $this->clock->capture($pdo);
            $stmt = $pdo->prepare(
                "SELECT r.*, d.status AS domain_status, d.purpose AS domain_purpose
                   FROM supplier_domain_login_requests r
                   JOIN supplier_domains d
                     ON d.id = r.supplier_domain_id AND d.supplier_id = r.supplier_id
                  WHERE r.request_token_hash = ?
                    AND r.supplier_domain_id = ? AND r.supplier_id = ?
                    AND r.target_hostname = ?
                  LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([
                self::hash($requestToken),
                $context->domainId,
                $context->supplierId,
                $context->hostname,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false
                || $row['domain_status'] !== 'active'
                || !in_array($row['domain_purpose'], ['portal', 'all'], true)
                || $row['used_at'] !== null
                || $row['authorized_by'] === null
                || $row['authorization_code_hash'] === null
                || !hash_equals((string) $row['authorization_code_hash'], self::hash($code))
                || !hash_equals((string) $row['state_hash'], self::hash($state))
                || self::expired((string) $row['expires_at'], $cutoff->utc)
                || self::expired((string) ($row['code_expires_at'] ?? ''), $cutoff->utc)
                || !hash_equals((string) $row['pkce_challenge'], self::pkceChallenge($verifier))
            ) {
                throw new DomainLoginException('invalid_domain_login_code', 'Autorizační kód je neplatný, použitý nebo expiroval.', 400);
            }

            $userStmt = $pdo->prepare(
                'SELECT u.id FROM users u
                  WHERE u.id = ? AND u.is_active = 1
                  LIMIT 1 FOR UPDATE'
            );
            $userStmt->execute([(int) $row['authorized_by']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($user === false) {
                throw new DomainLoginException('authentication_expired', 'Uživatel už není aktivní.', 401);
            }
            $userId = (int) $user['id'];
            // Membership ověř znovu těsně před session (superadmin má přístup ke všem firmám).
            if (!in_array($context->supplierId, $this->memberships->allowedSupplierIds($userId), true)
                && !$this->roles->isSuperadmin($userId)
            ) {
                throw new DomainLoginException('forbidden_supplier', 'Přístup k firmě byl mezitím odebrán.', 403);
            }

            $authContext = new SessionAuthContext(
                (string) $row['auth_method'],
                (string) $row['assurance_level'],
                $row['mfa_verified_at'] !== null
                    ? new \DateTimeImmutable((string) $row['mfa_verified_at'], new \DateTimeZone('UTC'))
                    : null,
                $row['auth_credential_id'] !== null ? (int) $row['auth_credential_id'] : null,
            );
            // Výměnu volá hostname vlastní domény, tedy strana, která drží PKCE
            // verifier i výsledný token. Session proto platí jen na této doméně
            // a na canonical adrese ani jiné doméně ji AuthMiddleware nepřijme.
            $session = $this->sessions->createInTransaction(
                $pdo,
                $cutoff,
                $userId,
                $ip,
                $request->getHeaderLine('User-Agent'),
                $authContext,
                (int) $row['supplier_domain_id'],
            );
            $used = $pdo->prepare(
                'UPDATE supplier_domain_login_requests SET used_at = ? WHERE id = ? AND used_at IS NULL'
            );
            $used->execute([$cutoff->utcSql, (int) $row['id']]);
            if ($used->rowCount() !== 1) {
                throw new DomainLoginException('invalid_domain_login_code', 'Autorizační kód už byl použit.', 400);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return [
            'session' => $session,
            'return_path' => (string) $row['return_path'],
            'supplier_id' => (int) $row['supplier_id'],
            'domain_id' => (int) $row['supplier_domain_id'],
            'user_id' => $userId,
        ];
    }

    private static function context(Request $request): TenantDomainContext
    {
        $context = $request->getAttribute(TenantDomainMiddleware::ATTR_CONTEXT);
        if (!$context instanceof TenantDomainContext) {
            throw new DomainLoginException('domain_context_missing', 'Chybí bezpečný kontext domény.', 400);
        }
        return $context;
    }

    private static function assertChallenge(string $challenge): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge) !== 1) {
            throw new DomainLoginException('invalid_pkce_challenge', 'PKCE challenge musí být SHA-256 v base64url.', 400);
        }
    }

    private function safeReturnPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '/portal';
        if (!$this->clientRoutes->allowsReturnPath($path)) {
            throw new DomainLoginException('invalid_return_path', 'Návratová cesta není bezpečná.', 400);
        }
        return $path;
    }

    private static function assertOpaqueToken(string $token): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            throw new DomainLoginException('invalid_domain_login_request', 'Neplatný formát domain-login tokenu.', 400);
        }
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private static function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function expired(string $value, \DateTimeImmutable $cutoff): bool
    {
        if ($value === '') return true;
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC')) <= $cutoff;
        } catch (\Throwable) {
            return true;
        }
    }

    private static function nullableUtc(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        }
        try {
            return (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        } catch (\Throwable) {
            return null;
        }
    }
}
