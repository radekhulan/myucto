<?php

declare(strict_types=1);

namespace MyInvoice\Service\OAuth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;
use MyInvoice\Repository\OAuthSessionRepository;
use MyInvoice\Service\Auth\SecretEncryption;

/**
 * Společná vrstva pro napojení, kde je MyÚčto klientem OAuth (banky, zdroje stavu
 * tachometru, …). Poskytovatel dodá jen adresy a parametry svého autorizačního serveru
 * ({@see OAuthProviderConfig}); tok přihlášení je pro všechny stejný:
 *
 *   1. {@see authorize()} vygeneruje state a PKCE (S256), poskytovatel z nich sestaví
 *      přesměrování nebo vyžádá device code,
 *   2. {@see store()} uloží rozpracované přihlášení do oauth_sessions: state jen jako
 *      hash, tajemství šifrované s kontextem firma + doména + poskytovatel,
 *   3. návrat přesměrováním jde na jeden callback {@see CALLBACK_PATH}, který podle hashe
 *      state předá přihlášení handleru domény ({@see OAuthCallbackHandler}); ten si ho
 *      jednorázově převezme přes {@see claim()},
 *   4. Device Code Flow se dotazuje přes {@see open()} a průběžný stav ukládá {@see update()},
 *   5. obnova tokenu běží přes {@see rotate()} pod zámkem napojení a nový token se uloží
 *      dřív, než se použije: rotující refresh token má jen jednu platnou kopii.
 */
final class OAuthClientFlow
{
    public const FLOW_REDIRECT    = 'redirect';
    public const FLOW_DEVICE_CODE = 'device_code';
    public const STAGE_OAUTH      = 'oauth';
    public const CALLBACK_PATH    = '/api/oauth/callback';
    public const STATE_HEX        = 'hex';
    public const STATE_BASE64URL  = 'base64url';

    private const ENVELOPE_KEYS = ['version', 'supplier_id', 'domain', 'provider', 'subject_id', 'verifier', 'data'];
    private const MAX_TTL = 86400;

    public function __construct(
        private readonly OAuthSessionRepository $sessions,
        private readonly SecretEncryption $secrets,
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    public function assertReady(): void
    {
        if ($this->secrets->validateKey() !== null) {
            throw new OAuthException(OAuthException::ENCRYPTION_KEY, 'Chybí šifrovací klíč aplikace (app.secret_encryption_key).');
        }
    }

    /** Veřejná adresa aplikace s danou cestou; app.url musí být https bez cesty. */
    public function appUrl(string $path): string
    {
        $base = rtrim(trim((string) $this->config->get('app.url', '')), '/');
        $parts = parse_url($base);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new OAuthException(OAuthException::APP_URL_INVALID, 'Adresa aplikace (app.url) musí být https bez cesty.');
        }
        return $base . $path;
    }

    public function callbackUrl(): string
    {
        return $this->appUrl(self::CALLBACK_PATH);
    }

    /**
     * Nový state (256 bitů) a PKCE verifier. Tvar state volí poskytovatel podle toho, co
     * jeho server přijímá: hex (64 znaků), nebo base64url (43 znaků).
     */
    public function authorize(bool $pkce = true, string $stateFormat = self::STATE_HEX): OAuthAuthorization
    {
        $random = random_bytes(32);
        $state = match ($stateFormat) {
            self::STATE_HEX => bin2hex($random),
            self::STATE_BASE64URL => rtrim(strtr(base64_encode($random), '+/', '-_'), '='),
            default => throw new OAuthException(OAuthException::INVALID_REQUEST, 'Neznámý tvar state.'),
        };
        return new OAuthAuthorization($state, $pkce ? Pkce::verifier() : null);
    }

    /** Hash state z návratu; null, když state nemá tvar, který vrstva vydává. */
    public static function stateHash(#[\SensitiveParameter] string $state): ?string
    {
        return preg_match('/^(?:[a-f0-9]{64}|[A-Za-z0-9_-]{43})$/D', $state) === 1 ? hash('sha256', $state) : null;
    }

    /**
     * Uloží rozpracované přihlášení. Předchozí nedokončené přihlášení téhož napojení
     * (firma + doména + poskytovatel + subjekt) tím končí.
     *
     * @param array<string,mixed> $secret tajná data poskytovatele (klíče, device code, …)
     */
    public function store(OAuthAuthorization $authorization, int $supplierId, int $userId, string $domain, string $provider,
        int $subjectId, string $flow, #[\SensitiveParameter] array $secret, int $ttl, string $stage = self::STAGE_OAUTH): void
    {
        if ($supplierId < 1 || $userId < 1 || $subjectId < 1 || $ttl < 1 || $ttl > self::MAX_TTL
            || !in_array($flow, [self::FLOW_REDIRECT, self::FLOW_DEVICE_CODE], true)) {
            throw new OAuthException(OAuthException::INVALID_REQUEST, 'Neplatné rozpracované přihlášení.');
        }
        $hash = $authorization->stateHash();
        $ciphertext = $this->seal(self::context($supplierId, $domain, $provider, $subjectId, $userId, $stage, $hash),
            $supplierId, $domain, $provider, $subjectId, $authorization->codeVerifier, $secret);
        $this->sessions->create($hash, $supplierId, $userId, $domain, $provider, $subjectId, $flow, $stage, $ciphertext, $ttl);
    }

    /**
     * Údaje rozpracovaného přihlášení pro rozeslání callbacku (bez tajemství).
     *
     * @return array<string,mixed>|null
     */
    public function find(#[\SensitiveParameter] string $state): ?array
    {
        $hash = self::stateHash($state);
        return $hash === null ? null : $this->sessions->find($hash);
    }

    /**
     * Jednorázově převezme čekající přihlášení z návratu přesměrováním. Null = neznámý,
     * vypršelý, už použitý nebo cizí (jiná firma, uživatel, doména či poskytovatel) state.
     */
    public function claim(#[\SensitiveParameter] string $state, int $supplierId, int $userId, string $domain, string $provider,
        string $stage = self::STAGE_OAUTH): ?OAuthSession
    {
        $hash = self::stateHash($state);
        if ($hash === null || $userId < 1) return null;
        $row = $this->sessions->claim($hash, $supplierId, $userId, $domain, $provider, $stage);
        if ($row === null) return null;
        try {
            return $this->session($row);
        } catch (OAuthException $e) {
            $this->sessions->finish($hash, $supplierId, false, $e->errorCode);
            throw $e;
        }
    }

    /**
     * Rozpracované přihlášení napojení (polling device code, výsledek callbacku), ještě
     * nedokončené. Pokračovat smí jen uživatel, který přihlášení zahájil, stejně jako u callbacku.
     */
    public function open(string $stateHash, int $supplierId, int $userId, string $domain, string $provider, int $subjectId): ?OAuthSession
    {
        if ($userId < 1) return null;
        $row = $this->sessions->open($stateHash, $supplierId, $userId, $domain, $provider, $subjectId);
        return $row === null ? null : $this->session($row);
    }

    /** @param array<string,mixed> $secret nová tajná data rozpracovaného přihlášení */
    public function update(OAuthSession $session, #[\SensitiveParameter] array $secret): OAuthSession
    {
        $ciphertext = $this->seal(self::context($session->supplierId, $session->domain, $session->provider, $session->subjectId,
            $session->userId, $session->stage, $session->stateHash), $session->supplierId, $session->domain, $session->provider,
            $session->subjectId, $session->codeVerifier, $secret);
        if (!$this->sessions->updateSecret($session->stateHash, $session->supplierId, $ciphertext)) {
            throw new OAuthException(OAuthException::INVALID_STATE, 'Přihlášení už neprobíhá.');
        }
        return new OAuthSession($session->stateHash, $session->supplierId, $session->userId, $session->domain, $session->provider,
            $session->subjectId, $session->flow, $session->stage, $session->status, $session->expired, $secret, $session->codeVerifier);
    }

    /** Ukončí přihlášení a smaže jeho tajemství. */
    public function finish(OAuthSession $session, bool $success, ?string $errorCode = null): void
    {
        $this->sessions->finish($session->stateHash, $session->supplierId, $success, $errorCode);
    }

    /** @return array<string,mixed>|null poslední přihlášení napojení (stav pro UI, bez tajemství) */
    public function latest(int $supplierId, string $domain, string $provider, int $subjectId): ?array
    {
        return $this->sessions->latest($supplierId, $domain, $provider, $subjectId);
    }

    /**
     * Čekající přihlášení uživatele v dané fázi; nerozšifrovatelná se přeskočí.
     *
     * @return list<OAuthSession>
     */
    public function pendingForUser(int $supplierId, int $userId, string $domain, string $provider, string $stage): array
    {
        $result = [];
        foreach ($this->sessions->pendingForUser($supplierId, $userId, $domain, $provider, $stage) as $row) {
            try {
                $result[] = $this->session($row);
            } catch (OAuthException) {
            }
        }
        return $result;
    }

    /**
     * Spustí `$callback` pod zámkem napojení. Zámek je per napojení napříč procesy
     * (MariaDB GET_LOCK); obnova téhož zámku v jednom spojení je povolená.
     *
     * @template T
     * @param callable():T $callback
     * @param (callable():\Throwable)|null $busy výjimka, když je napojení zamčené jinde
     * @param int $timeout kolik sekund čekat na uvolnění zámku
     * @return T
     */
    public function withConnectionLock(string $scope, string|int $key, callable $callback, ?callable $busy = null, int $timeout = 10): mixed
    {
        $pdo = $this->db->pdo();
        $name = NamedLockName::for($this->db, $scope, $key);
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$name, max(0, $timeout)]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw $busy !== null ? $busy() : new OAuthException(OAuthException::CONNECTION_BUSY, 'Napojení právě používá jiný požadavek.');
        }
        try {
            return $callback();
        } finally {
            try {
                $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Platné přístupy napojení. Propadlé obnoví pod zámkem napojení: přístupy načte znovu
     * (souběžný požadavek je mohl mezitím obnovit), a když jsou pořád propadlé, obnoví je
     * a nové hned uloží. Nepodaří-li se uložení, obnovené přístupy se nepoužijí — server
     * starý refresh token už zneplatnil a napojení se musí přihlásit znovu.
     *
     * @template C of array
     * @param callable():C          $load    aktuální přístupy z úložiště
     * @param callable(C):bool      $isFresh
     * @param callable(C):C         $refresh obnova u autorizačního serveru
     * @param callable(C):bool      $persist okamžitý zápis; false = nelze uložit
     * @param (callable():\Throwable)|null $busy
     * @return C
     */
    public function rotate(string $scope, string|int $key, callable $load, callable $isFresh, callable $refresh, callable $persist,
        ?callable $busy = null): array
    {
        return $this->withConnectionLock($scope, $key, function () use ($load, $isFresh, $refresh, $persist): array {
            $current = $load();
            if ($isFresh($current)) return $current;
            $next = $refresh($current);
            if (!$persist($next)) {
                throw new OAuthException(OAuthException::ROTATION_FAILED, 'Obnovený přístup nelze bezpečně uložit.');
            }
            return $next;
        }, $busy);
    }

    /** Kontext šifry rozpracovaného přihlášení: firma + doména + poskytovatel + napojení, uživatel, fáze a state. */
    public static function context(int $supplierId, string $domain, string $provider, int $subjectId, int $userId, string $stage,
        string $stateHash): string
    {
        foreach ([$domain, $provider, $stage] as $code) {
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $code) !== 1) {
                throw new OAuthException(OAuthException::INVALID_REQUEST, 'Neplatný kód domény, poskytovatele nebo fáze.');
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $stateHash) !== 1) {
            throw new OAuthException(OAuthException::INVALID_STATE, 'Neplatný state.');
        }
        return sprintf('oauth-session:supplier:%d:domain:%s:provider:%s:subject:%d:user:%d:stage:%s:state:%s',
            $supplierId, $domain, $provider, $subjectId, $userId, $stage, $stateHash);
    }

    private function session(array $row): OAuthSession
    {
        $envelope = $this->unseal(self::context($row['supplier_id'], $row['domain'], $row['provider'], $row['subject_id'],
            $row['user_id'], $row['stage'], $row['state_hash']), (string) $row['secret_ciphertext']);
        if ($envelope['supplier_id'] !== $row['supplier_id'] || $envelope['domain'] !== $row['domain']
            || $envelope['provider'] !== $row['provider'] || $envelope['subject_id'] !== $row['subject_id']) {
            throw self::invalid();
        }
        return new OAuthSession($row['state_hash'], $row['supplier_id'], $row['user_id'], $row['domain'], $row['provider'],
            $row['subject_id'], $row['flow'], $row['stage'], $row['status'], $row['expired'], $envelope['data'], $envelope['verifier']);
    }

    private function seal(string $context, int $supplierId, string $domain, string $provider, int $subjectId,
        #[\SensitiveParameter] ?string $verifier, #[\SensitiveParameter] array $data): string
    {
        $this->assertReady();
        try {
            $json = json_encode(['version' => 1, 'supplier_id' => $supplierId, 'domain' => $domain, 'provider' => $provider,
                'subject_id' => $subjectId, 'verifier' => $verifier, 'data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES, 16);
        } catch (\JsonException) {
            throw new OAuthException(OAuthException::INVALID_REQUEST, 'Rozpracované přihlášení nelze uložit.');
        }
        if (strlen($json) > 65535) throw new OAuthException(OAuthException::INVALID_REQUEST, 'Rozpracované přihlášení je příliš velké.');
        $ciphertext = $this->secrets->encryptFor($json, $context);
        if (!str_starts_with($ciphertext, 'enc:v2:')) throw new OAuthException(OAuthException::ENCRYPTION_KEY, 'Šifrování selhalo.');
        return $ciphertext;
    }

    /** @return array{supplier_id:int, domain:string, provider:string, subject_id:int, verifier:?string, data:array<string,mixed>} */
    private function unseal(string $context, #[\SensitiveParameter] string $ciphertext): array
    {
        if (!str_starts_with($ciphertext, 'enc:v2:')) throw self::invalid();
        try {
            $envelope = json_decode($this->secrets->decryptFor($ciphertext, $context), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw self::invalid();
        }
        if (!is_array($envelope) || array_diff(self::ENVELOPE_KEYS, array_keys($envelope)) || array_diff(array_keys($envelope), self::ENVELOPE_KEYS)
            || $envelope['version'] !== 1 || !is_int($envelope['supplier_id']) || !is_int($envelope['subject_id'])
            || !is_string($envelope['domain']) || !is_string($envelope['provider'])
            || ($envelope['verifier'] !== null && (!is_string($envelope['verifier']) || !Pkce::isValidVerifier($envelope['verifier'])))
            || !is_array($envelope['data']) || ($envelope['data'] !== [] && array_is_list($envelope['data']))) {
            throw self::invalid();
        }
        return $envelope;
    }

    private static function invalid(): OAuthException
    {
        return new OAuthException(OAuthException::INVALID_STATE, 'Rozpracované přihlášení nelze přečíst.');
    }
}
