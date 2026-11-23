<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\License\LicenseSeatLimitExceeded;
use MyInvoice\Service\License\LicensePayrollLimitExceeded;
use MyInvoice\Service\License\LicenseState;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Setup\PasswordSetupLinkIssuer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UserAdminAction
{
    private const DISPOSABLE_USER_TABLES = [
        'api_tokens', 'bank_oauth_sessions', 'crm_action_item_dismissals',
        'login_otps', 'mcp_oauth_codes', 'mcp_oauth_grants',
        'mfa_recovery_codes', 'mfa_step_up_proofs', 'oauth_sessions', 'password_resets',
        'saved_filters', 'sessions', 'trusted_devices', 'user_preferences',
        'user_suppliers', 'webauthn_ceremonies', 'webauthn_credentials',
        'user_technical_access', 'user_technical_suppliers', 'instance_smtp_test_attempts',
        'user_external_identities', 'oidc_link_invites', 'oidc_login_flows',
    ];

    /**
     * Auditní stopa smazání nebrání a autora si drží dál. `activity_log.user_id` nemá
     * cizí klíč (migrace 1945), protože vstupuje do hashe řetězu a nesmí se přepsat.
     * Stejně se zachová autor technické události a neměnného schválení SMTP cíle.
     * Výjimka platí jen pro sloupec BEZ cizího klíče — instalace, kde klíč ještě je,
     * by záznamy vynulovala, a proto u ní smazání blokuje dál.
     */
    private const AUDIT_USER_TABLES = ['activity_log', 'instance_admin_events', 'instance_smtp_targets'];

    private const ER_LOCK_WAIT_TIMEOUT = 1205;

    /**
     * Každá tabulka information_schema má vlastní podmínku `= DATABASE()`. Spojení
     * přes sloupec schématu (`r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA`) ani podmínka
     * v ON u LEFT JOIN MariaDB pro výběr databáze nepoužije a otevře tabulky všech
     * databází na serveru (EXPLAIN „Scanned all databases"): se sousedními instalacemi
     * nebo paralelními testy to trvá minuty a čeká na jejich DDL.
     */
    private const USER_FOREIGN_KEYS_SQL = "WITH r AS (
                SELECT TABLE_NAME, CONSTRAINT_NAME, DELETE_RULE
                  FROM information_schema.REFERENTIAL_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND REFERENCED_TABLE_NAME = 'users'
            )
            SELECT k.TABLE_NAME, k.COLUMN_NAME, r.DELETE_RULE
              FROM information_schema.KEY_COLUMN_USAGE k
              JOIN r
                ON r.TABLE_NAME = k.TABLE_NAME
               AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = DATABASE()
               AND k.REFERENCED_TABLE_SCHEMA = DATABASE()
               AND k.REFERENCED_TABLE_NAME = 'users'";

    private const USER_IMPLICIT_COLUMNS_SQL = "SELECT c.TABLE_NAME, c.COLUMN_NAME
              FROM information_schema.COLUMNS c
             WHERE c.TABLE_SCHEMA = DATABASE()
               AND c.TABLE_NAME <> 'users'
               AND c.DATA_TYPE IN ('tinyint', 'smallint', 'mediumint', 'int', 'bigint')
               AND (c.COLUMN_NAME REGEXP '(^|_)user_id$' OR c.COLUMN_NAME REGEXP '_by$'
                    OR c.COLUMN_NAME IN ('actor_id', 'assigned_to'))
               AND NOT EXISTS (
                    SELECT 1
                      FROM information_schema.KEY_COLUMN_USAGE k
                     WHERE k.TABLE_SCHEMA = DATABASE()
                       AND k.TABLE_NAME = c.TABLE_NAME
                       AND k.COLUMN_NAME = c.COLUMN_NAME
                       AND k.REFERENCED_TABLE_NAME IS NOT NULL
               )";

    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessions,
        private readonly LicenseCapacityGate $capacity,
        private readonly PasswordSetupLinkIssuer $passwordSetupLinks,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly \MyInvoice\Service\Auth\LoginPolicyService $loginPolicy,
        private readonly \MyInvoice\Service\Auth\Oidc\OidcProviderRegistry $oidcProviders,
        private readonly \MyInvoice\Service\Auth\Oidc\OidcInviteMailer $ssoInvites,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $statement = $this->db->pdo()->query($this->selectSql() . ' ORDER BY u.id');
        if ($statement === false) {
            throw new \RuntimeException('Seznam uživatelů se nepodařilo načíst.');
        }
        return Json::ok($response, array_map($this->normalize(...), $statement->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $body = (array) ($request->getParsedBody() ?? []);
        $email = trim((string) ($body['email'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        $roleId = (int) ($body['role_id'] ?? 0);
        $locale = (string) ($body['locale'] ?? 'cs');
        $password = (string) ($body['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return Json::error($response, 'validation_failed', 'Neplatný email.', 400);
        if ($name === '') return Json::error($response, 'validation_failed', 'Jméno je povinné.', 400);
        if (!in_array($locale, ['cs', 'en'], true)) return Json::error($response, 'validation_failed', 'Neplatný locale.', 400);
        $role = $this->activeRole($roleId);
        if ($role === null) return Json::error($response, 'validation_failed', 'Vybraná role neexistuje nebo není aktivní.', 400);

        // ⚠️ Prázdné heslo NENÍ chyba — je to pozvánka. Uživatel si heslo nastaví
        // sám z jednorázového odkazu, takže ho admin nemusí vymýšlet, posílat
        // nezabezpečeným kanálem ani znát. Heslo zadané výslovně se chová jako dřív.
        // Při vynuceném poskytovateli identity heslo nikdo nezadává ani nenastavuje.
        // Účet dostane náhodné nepoužitelné heslo a pozvánku k propojení identity.
        $ssoProvider = $this->loginPolicy->requiredProvider();
        if ($ssoProvider !== null) {
            $password = '';
        }
        $invite = $password === '';
        if ($invite) {
            $password = $this->passwordSetupLinks->randomPassword();
        }
        try {
            $this->hasher->validate($password);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        $passwordHash = $this->hasher->hash($password);

        // Zápis i čerstvý přepočet míst jsou jedna serializovaná operace.
        try {
            $id = $this->capacity->mutateSeats(
                function () use ($email, $passwordHash, $name, $role, $roleId, $locale): int {
                    $pdo = $this->db->pdo();
                    $stmt = $pdo->prepare(
                        'INSERT INTO users (email, password_hash, name, role, role_id, locale, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)'
                    );
                    // Obsazený e-mail je očekávaný stav (→ 409 níž), ne chyba do logu.
                    \MyInvoice\Infrastructure\Database\DbErrorLogger::expectingDuplicates(
                        ['uq_users_email'],
                        fn () => $stmt->execute([$email, $passwordHash, $name, $this->legacyRole($role), $roleId, $locale]),
                    );
                    return (int) $pdo->lastInsertId();
                },
            );
        } catch (LicenseSeatLimitExceeded $e) {
            return Json::error($response, self::blockCode($e->reason), self::blockMessage($e->reason, $e->state), 403);
        } catch (LicensePayrollLimitExceeded) {
            return Json::error($response, 'license_payroll_user_limit', 'Zvolená role by zabrala další placené místo v modulu Mzdy. Nejprve rozšiřte mzdový doplněk.', 403, ['buy_url' => '/activation/purchase#payroll-addon']);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_users_email')) {
                return Json::error($response, 'email_taken', 'Email je už registrovaný.', 409);
            }
            throw $e;
        }
        $this->log($request, 'user.created', $id, ['email' => $email, 'role_id' => $roleId, 'invite' => $invite]);

        $user = $this->fetchUser($id);
        if ($invite && $ssoProvider !== null) {
            $user['invite_sent'] = $this->sendSsoInvite($request, $id, $email, $name, $locale, $ssoProvider);
        } elseif ($invite) {
            // ⚠️ Neúspěch odeslání nesmí objednávku uživatele shodit — účet už je
            // založený a zabírá licenční místo, rollback tady by ho jen osiřel.
            // Admin se o tom ale MUSÍ dozvědět: bez odkazu se do účtu nikdo
            // nedostane, protože heslo je náhodné a nikdo ho nezná.
            $user['invite_sent'] = $this->sendPasswordLink($request, $id, $email, $name, $locale, 'setup');
        }

        return Json::ok($response, $user, 201);
    }

    /**
     * Znovu pošle odkaz na nastavení hesla už existujícímu uživateli.
     *
     * ⚠️ Vydává se `reset`, ne `setup`. Setupový token dává po nastavení hesla
     * rovnou sezení — u účtu, který si mezitím zapnul vlastní TOTP na instalaci
     * bez povinného MFA, by odkaz z e-mailu obešel jeho druhý faktor. Lhůta
     * zůstává onboardingová (24 h): pozvánku od admina otevře člověk klidně až
     * druhý den, na rozdíl od obnovy, kterou si právě vyžádal sám.
     *
     * @param array<string,string> $args
     */
    public function sendPasswordSetup(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $id = (int) ($args['id'] ?? 0);
        $row = $this->fetchUser($id);
        if ($row === null) return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);
        if (!$row['is_active']) {
            return Json::error($response, 'user_inactive', 'Deaktivovanému uživateli odkaz neposíláme — nejdřív ho aktivujte.', 409);
        }
        if ($this->loginPolicy->isEnforced()) {
            return self::passwordDisabled($response);
        }

        $sent = $this->sendPasswordLink(
            $request,
            $id,
            (string) $row['email'],
            (string) ($row['name'] ?? ''),
            (string) ($row['locale'] ?? 'cs'),
            'reset',
        );
        if (!$sent) {
            return Json::error($response, 'mail_failed', 'Odkaz se nepodařilo odeslat. Zkontrolujte nastavení odchozí pošty.', 502);
        }

        return Json::ok($response, ['ok' => true]);
    }

    private static function passwordDisabled(Response $response): Response
    {
        return Json::error(
            $response,
            'password_login_disabled',
            'Instalace se přihlašuje přes poskytovatele identity organizace, heslo se nepoužívá.',
            409,
        );
    }

    /** Pozvánka k propojení identity u vynuceného poskytovatele. Vrací, jestli mail odešel. */
    private function sendSsoInvite(
        Request $request,
        int $userId,
        string $email,
        string $name,
        string $locale,
        string $providerId,
    ): bool {
        $provider = $this->oidcProviders->get($providerId);
        $actor = (array) $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, []);
        try {
            if ($provider === null) {
                throw new \RuntimeException('Poskytovatel identity není zapnutý.');
            }
            $smtp = $this->ssoInvites->send($userId, $email, $name, $locale, $provider, isset($actor['id']) ? (int) $actor['id'] : null);
            $this->log($request, 'user.sso_invite_sent', $userId, [
                'to' => [$email], 'provider' => $providerId, 'smtp_response' => $smtp,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->log($request, 'user.invite_mail_failed', $userId, [
                'to' => [$email], 'purpose' => 'sso_invite', 'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Vydá jednorázový odkaz a pošle ho uživateli. Vrací, jestli mail odešel.
     *
     * Loguje se tady, ne u volajících: obě cesty (pozvánka při založení účtu
     * i pozdější znovuposlání) posílají tentýž e-mail, takže musí skončit
     * jedním párem akcí `user.password_link_sent` / `user.invite_mail_failed`
     * — jinak jedna z nich chybí v přehledu odeslaných e-mailů.
     *
     * @param 'setup'|'reset' $purpose
     */
    private function sendPasswordLink(
        Request $request,
        int $userId,
        string $email,
        string $name,
        string $locale,
        string $purpose,
    ): bool {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        try {
            $link = $purpose === 'setup'
                ? $this->passwordSetupLinks->issue($this->db->pdo(), $userId, $ip)
                : $this->passwordSetupLinks->issueReset($this->db->pdo(), $userId, $ip);
            $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
            $smtpResponse = $this->mailer->sendTemplate(
                'user_invite',
                in_array($locale, ['cs', 'en'], true) ? $locale : 'cs',
                [$email],
                [
                    'name' => $name,
                    'resetLink' => $appUrl . '/reset?token=' . $link['token'],
                    'expiresIn' => PasswordSetupLinkIssuer::SETUP_TTL_HOURS . ' hodin',
                ],
            );
            $this->log($request, 'user.password_link_sent', $userId, [
                'to' => [$email],
                'purpose' => $purpose,
                'smtp_response' => $smtpResponse,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->log($request, 'user.invite_mail_failed', $userId, [
                'to' => [$email],
                'purpose' => $purpose,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * @param array<string,string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $id = (int) ($args['id'] ?? 0);
        $row = $this->fetchUser($id);
        if ($row === null) return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);
        $body = (array) ($request->getParsedBody() ?? []);

        $newRole = null;
        if (array_key_exists('role_id', $body)) {
            $newRole = $this->activeRole((int) $body['role_id']);
            if ($newRole === null) return Json::error($response, 'validation_failed', 'Vybraná role neexistuje nebo není aktivní.', 400);
            if ($newRole['role_type'] !== $row['role']['type']
                && $newRole['role_type'] !== 'instance_admin' && $row['role']['type'] !== 'instance_admin') {
                $incompatible = $this->incompatibleOverrides($id, (string) $newRole['role_type']);
                if ($incompatible !== []) {
                    return Json::error($response, 'incompatible_supplier_roles', 'Nejprve odstraň nekompatibilní role u přiřazených firem.', 409, [
                        'supplier_ids' => $incompatible,
                    ]);
                }
            }
        }

        $willBeSuperadmin = $newRole !== null
            ? $newRole['system_key'] === 'superadmin'
            : (bool) $row['is_superadmin'];
        $willBeActive = array_key_exists('is_active', $body) ? (bool) $body['is_active'] : (bool) $row['is_active'];

        $sets = [];
        $params = [];
        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') return Json::error($response, 'validation_failed', 'Jméno je povinné.', 400);
            $sets[] = 'name = ?'; $params[] = $name;
        }
        if ($newRole !== null) {
            $sets[] = 'role_id = ?'; $params[] = (int) $newRole['id'];
            $sets[] = 'role = ?'; $params[] = $this->legacyRole($newRole);
        }
        if (array_key_exists('locale', $body)) {
            if (!in_array($body['locale'], ['cs', 'en'], true)) return Json::error($response, 'validation_failed', 'Neplatný locale.', 400);
            $sets[] = 'locale = ?'; $params[] = (string) $body['locale'];
        }
        if (array_key_exists('is_active', $body)) {
            $sets[] = 'is_active = ?'; $params[] = (bool) $body['is_active'] ? 1 : 0;
        }
        if (!empty($body['password']) && $this->loginPolicy->isEnforced()) {
            return self::passwordDisabled($response);
        }
        if (!empty($body['password'])) {
            try { $this->hasher->validate((string) $body['password']); }
            catch (\InvalidArgumentException $e) { return Json::error($response, 'validation_failed', $e->getMessage(), 400); }
            $sets[] = 'password_hash = ?'; $params[] = $this->hasher->hash((string) $body['password']);
            $sets[] = 'totp_secret = CASE WHEN totp_enabled = 0 THEN NULL ELSE totp_secret END';
        }
        if ($sets === []) return Json::ok($response, $row);

        try {
            if (!$this->guardedUserUpdate($id, implode(', ', $sets), $params, $willBeSuperadmin, $willBeActive, $newRole)) {
                return Json::error($response, 'last_admin', 'Nelze odebrat roli ani deaktivovat posledního aktivního superadmina.', 409);
            }
        } catch (LicenseSeatLimitExceeded $e) {
            return Json::error($response, self::blockCode($e->reason), self::blockMessage($e->reason, $e->state), 403);
        } catch (LicensePayrollLimitExceeded) {
            return Json::error($response, 'license_payroll_user_limit', 'Zvolená role by zabrala další placené místo v modulu Mzdy. Nejprve rozšiřte mzdový doplněk.', 403, ['buy_url' => '/activation/purchase#payroll-addon']);
        }
        if ($newRole !== null && (int) $row['role_id'] !== (int) $newRole['id']) {
            $this->log($request, 'user.role_updated', $id, ['from_role_id' => $row['role_id'], 'to_role_id' => (int) $newRole['id']]);
        }
        // Změna hesla nebo deaktivace nesmí nechat běžet staré session — sám update
        // provádí guardedUserUpdate() výš, tady se jen odvolá přihlášení.
        $mustRevokeSessions = !empty($body['password'])
            || (array_key_exists('is_active', $body) && !(bool) $body['is_active']);
        if ($mustRevokeSessions) {
            $this->sessions->destroyAllForUser($id);
        }
        $this->log($request, 'user.updated', $id, ['fields' => array_keys($body)]);
        return Json::ok($response, $this->fetchUser($id));
    }

    /**
     * @param array<string,string> $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $id = (int) ($args['id'] ?? 0);
        $row = $this->fetchUser($id);
        if ($row === null) return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);
        $actor = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if ((int) ($actor['id'] ?? 0) === $id) return Json::error($response, 'self_delete_forbidden', 'Nelze deaktivovat vlastní účet.', 409);
        try {
            if (!$this->guardedUserUpdate($id, 'is_active = 0', [], false, false)) {
                return Json::error($response, 'last_admin', 'Nelze deaktivovat posledního aktivního superadmina.', 409);
            }
        } catch (LicenseSeatLimitExceeded $e) {
            return Json::error($response, self::blockCode($e->reason), self::blockMessage($e->reason, $e->state), 403);
        } catch (LicensePayrollLimitExceeded) {
            return Json::error($response, 'license_payroll_user_limit', 'Zvolená role by zabrala další placené místo v modulu Mzdy. Nejprve rozšiřte mzdový doplněk.', 403, ['buy_url' => '/activation/purchase#payroll-addon']);
        }
        $this->sessions->destroyAllForUser($id);
        $this->log($request, 'user.deactivated', $id, []);
        return Json::ok($response, ['deactivated' => true]);
    }

    public function purge(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $id = (int) ($args['id'] ?? 0);
        $row = $this->fetchUser($id);
        if ($row === null) return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);
        $actor = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if ((int) ($actor['id'] ?? 0) === $id) return Json::error($response, 'self_delete_forbidden', 'Nelze smazat vlastní účet.', 409);
        if ($row['is_active']) {
            return Json::error($response, 'user_active', 'Uživatele nejdřív deaktivujte.', 409);
        }
        return $this->deleteInactive($request, $response, $id, (string) $row['email']);
    }

    private function deleteInactive(Request $request, Response $response, int $id, string $email): Response
    {
        $pdo = $this->db->pdo();
        // Metadata se čtou před transakcí: zámek řádku uživatele pak drží jen
        // krátké dotazy na data, ne průchod information_schema.
        $references = $this->userReferenceColumns();
        try {
            $conflict = TransactionFailure::retrying($pdo, function () use ($pdo, $id, $references): ?array {
                $pdo->beginTransaction();
                try {
                    $locked = $pdo->prepare('SELECT is_active FROM users WHERE id = ? FOR UPDATE');
                    $locked->execute([$id]);
                    $active = $locked->fetchColumn();
                    if ($active === false || (int) $active === 1) {
                        $pdo->rollBack();
                        return ['user_changed', 'Stav uživatele se změnil. Obnovte seznam.'];
                    }
                    if ($this->hasUserData($id, $references)) {
                        $pdo->rollBack();
                        return ['user_in_use', 'Uživatel má navázaná data. Ponechte ho deaktivovaného.'];
                    }
                    $delete = $pdo->prepare('DELETE FROM users WHERE id = ? AND is_active = 0');
                    $delete->execute([$id]);
                    $pdo->commit();
                    return null;
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return Json::error($response, 'user_in_use', 'Uživatel má navázaná data. Ponechte ho deaktivovaného.', 409);
            }
            if (TransactionFailure::aborted($e) || (int) ($e->errorInfo[1] ?? 0) === self::ER_LOCK_WAIT_TIMEOUT) {
                return Json::error($response, 'user_busy', 'Uživatele právě upravuje jiná operace. Zkuste smazání za chvíli znovu.', 409);
            }
            throw $e;
        }
        if ($conflict !== null) {
            return Json::error($response, $conflict[0], $conflict[1], 409);
        }
        $this->log($request, 'user.deleted', $id, ['email' => $email]);
        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * Sloupce, jejichž hodnota v kterémkoli řádku brání smazání uživatele.
     *
     * @return list<array{0:string,1:string}>
     */
    private function userReferenceColumns(): array
    {
        $pdo = $this->db->pdo();
        $columns = [];
        foreach ($pdo->query(self::USER_FOREIGN_KEYS_SQL)->fetchAll(\PDO::FETCH_ASSOC) as $relation) {
            if ($relation['DELETE_RULE'] === 'CASCADE'
                && in_array($relation['TABLE_NAME'], self::DISPOSABLE_USER_TABLES, true)) continue;
            $columns[] = [(string) $relation['TABLE_NAME'], (string) $relation['COLUMN_NAME']];
        }
        foreach ($pdo->query(self::USER_IMPLICIT_COLUMNS_SQL)->fetchAll(\PDO::FETCH_ASSOC) as $relation) {
            if (in_array($relation['TABLE_NAME'], self::AUDIT_USER_TABLES, true)) continue;
            $columns[] = [(string) $relation['TABLE_NAME'], (string) $relation['COLUMN_NAME']];
        }
        return $columns;
    }

    /** @param list<array{0:string,1:string}> $references */
    private function hasUserData(int $id, array $references): bool
    {
        $pdo = $this->db->pdo();
        foreach ($references as [$table, $column]) {
            $table = str_replace('`', '``', $table);
            $column = str_replace('`', '``', $column);
            $reference = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE `{$column}` = ? LIMIT 1");
            $reference->execute([$id]);
            if ($reference->fetchColumn() !== false) return true;
        }
        return false;
    }

    private function guard(Request $request, Response $response): ?Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['is_superadmin'] ?? false) !== true) {
            return Json::error($response, 'forbidden_permission', 'Pouze superadmin.', 403);
        }
        return null;
    }

    private function activeRole(int $id): ?array
    {
        if ($id <= 0) return null;
        $stmt = $this->db->pdo()->prepare('SELECT id, system_key, role_type FROM roles WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<int> */
    private function incompatibleOverrides(int $userId, string $roleType): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT us.supplier_id FROM user_suppliers us JOIN roles r ON r.id = us.role_id
              WHERE us.user_id = ? AND us.role_id IS NOT NULL AND r.role_type <> ? ORDER BY us.supplier_id'
        );
        $stmt->execute([$userId, $roleType]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Serializuje všechny změny uživatelů přes řádky aktivních superadminů.
     * Dva souběžné requesty tak nemohou oba pozorovat počet 2 a deaktivovat oba.
     *
     * @param list<mixed> $params
     */
    private function guardedUserUpdate(
        int $id,
        string $setSql,
        array $params,
        bool $willBeSuperadmin,
        bool $willBeActive,
        ?array $roleChange = null,
    ): bool
    {
        return $this->capacity->mutateSeats(function () use (
            $id,
            $setSql,
            $params,
            $willBeSuperadmin,
            $willBeActive,
            $roleChange,
        ): bool {
            $pdo = $this->db->pdo();
            $stmt = $pdo->query(
                "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                  WHERE u.is_active = 1 AND r.is_active = 1
                    AND r.system_key = 'superadmin' AND r.role_type = 'superadmin'
                  ORDER BY u.id FOR UPDATE"
            );
            $activeSuperadminIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
            if (in_array($id, $activeSuperadminIds, true)
                && (!$willBeSuperadmin || !$willBeActive)
                && count($activeSuperadminIds) <= 1
            ) {
                return false;
            }

            // Konverze role, odstranění rozsahů a revokace přístupů musí být atomické.
            $target = $pdo->prepare('SELECT u.role_id, r.role_type FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? FOR UPDATE');
            $target->execute([$id]);
            $currentRole = $target->fetch(\PDO::FETCH_ASSOC);
            $technicalTransition = $roleChange !== null && $currentRole !== false
                && (int) $currentRole['role_id'] !== (int) $roleChange['id']
                && ($currentRole['role_type'] === 'instance_admin' || $roleChange['role_type'] === 'instance_admin');
            if ($technicalTransition) {
                foreach (['user_suppliers', 'user_technical_suppliers', 'user_technical_access'] as $table) {
                    $pdo->prepare("DELETE FROM {$table} WHERE user_id = ?")->execute([$id]);
                }
                $pdo->prepare('UPDATE users SET default_supplier_id = NULL WHERE id = ?')->execute([$id]);
                $pdo->prepare('UPDATE sessions SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6)) WHERE user_id = ?')->execute([$id]);
                $pdo->prepare('UPDATE api_tokens SET revoked_at = COALESCE(revoked_at, NOW()) WHERE user_id = ?')->execute([$id]);
                $pdo->prepare('UPDATE mcp_oauth_grants SET revoked_at = COALESCE(revoked_at, NOW()) WHERE user_id = ?')->execute([$id]);
                // MFA faktory zůstávají; rozpracované přihlášení nebo souhlas pokračovat nesmí.
                foreach (['mcp_oauth_codes', 'oauth_sessions', 'bank_oauth_sessions', 'trusted_devices',
                    'login_otps', 'webauthn_ceremonies', 'mfa_step_up_proofs', 'password_resets'] as $table) {
                    $pdo->prepare("DELETE FROM {$table} WHERE user_id = ?")->execute([$id]);
                }
            }
            $params[] = $id;
            $pdo->prepare('UPDATE users SET ' . $setSql . ' WHERE id = ?')->execute($params);
            return true;
        });
    }

    private function fetchUser(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare($this->selectSql() . ' WHERE u.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->normalize($row) : null;
    }

    private function selectSql(): string
    {
        // Při vynuceném poskytovateli identity správce vidí, jaký účet organizace
        // je k uživateli napojený. Identita poskytovatele je u účtu nejvýš jedna
        // (OidcLoginService), dotaz jde po indexu ix_external_identity_user.
        $provider = $this->loginPolicy->requiredProvider();
        $sso = $provider === null
            ? 'NULL AS sso_identity_id, NULL AS sso_email, NULL AS sso_display_name, NULL AS sso_last_used_at'
            : 'x.id AS sso_identity_id, x.email_at_link AS sso_email, x.display_name AS sso_display_name,
               x.last_used_at AS sso_last_used_at';
        $join = $provider === null
            ? ''
            : ' LEFT JOIN user_external_identities x ON x.id = (
                    SELECT MIN(x2.id) FROM user_external_identities x2
                     WHERE x2.user_id = u.id AND x2.provider = ' . $this->db->pdo()->quote($provider) . '
               )';
        return 'SELECT u.id, u.email, u.name, u.role_id, u.locale, u.is_active, u.created_at, u.last_login_at,
                       u.totp_enabled,
                       (SELECT COUNT(*) FROM webauthn_credentials c WHERE c.user_id = u.id AND c.revoked_at IS NULL) AS passkey_count,
                       r.name AS role_name, r.role_type, r.is_active AS role_active, r.system_key, ' . $sso . '
                  FROM users u JOIN roles r ON r.id = u.role_id' . $join;
    }

    private function normalize(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'email' => (string) $row['email'], 'name' => (string) $row['name'],
            'role_id' => (int) $row['role_id'],
            'role' => ['id' => (int) $row['role_id'], 'name' => (string) $row['role_name'], 'type' => (string) $row['role_type'],
                'is_active' => (bool) $row['role_active'], 'system_key' => $row['system_key']],
            'is_superadmin' => $row['system_key'] === 'superadmin', 'locale' => (string) $row['locale'],
            'is_active' => (bool) $row['is_active'], 'created_at' => $row['created_at'], 'last_login_at' => $row['last_login_at'],
            'mfa' => ['totp' => (int) $row['totp_enabled'] === 1, 'passkeys' => (int) $row['passkey_count']],
            'sso_identity' => $row['sso_identity_id'] === null ? null : [
                'provider' => $this->loginPolicy->requiredProvider(),
                'email' => $row['sso_email'] !== null ? (string) $row['sso_email'] : null,
                'display_name' => $row['sso_display_name'] !== null ? (string) $row['sso_display_name'] : null,
                'last_used_at' => $row['sso_last_used_at'],
            ],
        ];
    }

    /**
     * Kód chyby podle důvodu. ⚠️ Dva různé kódy schválně: frontend na ně reaguje
     * jinak — u chybějící licence vede k aktivaci, u vyčerpaných míst k navýšení.
     */
    private static function blockCode(string $reason): string
    {
        return $reason === LicenseState::BLOCK_NO_LICENSE ? 'license_required' : 'license_user_limit';
    }

    /**
     * ⚠️ Hláška musí říct, co s tím — ne jen že to nejde. A musí být zřejmé,
     * že se to týká jen provozních rolí: účet s právem jen pro čtení jde
     * založit i bez licence a často je to přesně to, co admin potřebuje.
     */
    private static function blockMessage(string $reason, LicenseState $state): string
    {
        if ($reason !== LicenseState::BLOCK_NO_LICENSE) {
            return 'Byl dosažen počet uživatelů podle vaší licence. Rozšiřte předplatné, '
                . 'nebo uvolněte místo deaktivací jiného uživatele.';
        }

        // ⚠️ „Licence propadla" a „licenci jste nikdy neměli" vyžadují jiný krok.
        // Posílat zákazníka, který licenci aktivovanou MÁ, do sekce Aktivace je
        // rada, po které se nic nezmění — jemu propadlo předplatné.
        if ($state->state === LicenseState::DEGRADED) {
            return 'Platnost licence vypršela, takže jde zakládat jen uživatele s právem '
                . 'jen pro čtení. Obnovte předplatné, nebo uživateli přidělte roli jen pro čtení.';
        }

        return 'Bez platné licence lze zakládat jen uživatele s právem jen pro čtení. '
            . 'Aktivujte licenci v sekci Aktivace, nebo uživateli přidělte roli jen pro čtení.';
    }
    private function legacyRole(array $role): string
    {
        return match ($role['system_key'] ?? null) {
            'superadmin' => 'admin', 'accountant' => 'accountant', 'readonly' => 'readonly', 'client' => 'client',
            default => $role['role_type'] === 'client' ? 'client' : 'readonly',
        };
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, (int) ($user['id'] ?? 0), 'user', $entityId, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
