<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds;

use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/** Krátkodobý autentizační tok Mobilního klíče podle specifikace ISDS 1.5.1. */
final class MobileKeyIsdsAuthenticator
{
    private const TOKEN_CONTEXT = 'isds:mobile-key-flow:v1';
    private const FLOW_TTL = 240;
    private const MAX_ATTEMPTS = 150;
    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT = 30;
    private const USER_AGENT = 'MyUcto-ISDS-MobileKey/1.0';

    /** @param null|callable(string,string,array<string,mixed>):array{status:int,body:string,cookies:array<string,string>} $httpDouble */
    public function __construct(
        private readonly SecretEncryption $crypto,
        private readonly IsdsAuthFlowStore $flows,
        private $httpDouble = null,
    ) {}

    /** @return array{flow_token:string,state:int,description:string,expires_at:string} */
    public function start(
        int $supplierId,
        int $userId,
        string $environment,
        string $username,
        string $communicationCode,
    ): array {
        return $this->startWithCredentials(
            $supplierId,
            $userId,
            $environment,
            new ChannelCredentials(
                boxId: '',
                authMode: 'mobile_key',
                username: SensitiveValue::fromProducer(static fn (): string => $username),
                password: SensitiveValue::fromProducer(static fn (): string => $communicationCode),
            ),
        );
    }

    /** @return array{flow_token:string,state:int,description:string,expires_at:string} */
    public function startWithCredentials(
        int $supplierId,
        int $userId,
        string $environment,
        ChannelCredentials $credentials,
    ): array {
        $this->assertEncryptionReady();
        $this->assertEnvironment($environment);
        if ($credentials->authMode !== 'mobile_key' || $credentials->username === null || $credentials->password === null) {
            throw new SubmissionChannelException('isds_mobile_credentials_missing', 'Chybí přihlášení pro Mobilní klíč.', 400);
        }
        try {
            $username = $credentials->username->reveal();
            $communicationCode = $credentials->password->reveal();
        } catch (\RuntimeException) {
            throw new SubmissionChannelException(
                'isds_mobile_credential_decryption_failed',
                'Uložené přihlášení Mobilním klíčem nelze rozšifrovat. Uložte ho znovu.',
                500,
            );
        }
        $username = trim($username);
        if ($username === '' || strlen($username) > 128 || preg_match('/[\x00-\x20:\x7f]/', $username) === 1) {
            throw new SubmissionChannelException('isds_mobile_username_invalid', 'Vyplňte platné uživatelské jméno k datové schránce.', 400);
        }
        if ($communicationCode === '' || strlen($communicationCode) > 256 || preg_match('/[\x00-\x1f\x7f]/', $communicationCode) === 1) {
            throw new SubmissionChannelException('isds_mobile_code_invalid', 'Vyplňte komunikační kód pro Mobilní klíč.', 400);
        }

        $response = $this->loginRequest($environment, $username, $communicationCode, null);
        if ($response['status'] === 401) {
            throw new SubmissionChannelException('isds_mobile_login_rejected', 'ISDS odmítl uživatelské jméno nebo komunikační kód Mobilního klíče.', 401);
        }
        $sCookieName = isset($response['cookies']['IPCZ-S-COOKIE'])
            ? 'IPCZ-S-COOKIE'
            : 'S-COOKIE';
        $sCookie = $response['cookies'][$sCookieName] ?? '';
        if ($response['status'] !== 302 || $sCookie === '') {
            error_log(json_encode([
                'event' => 'isds_mobile_login_unexpected_response',
                'environment' => $environment,
                'status' => $response['status'],
                'cookie_names' => array_keys($response['cookies']),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            throw new SubmissionChannelException('isds_mobile_login_failed', 'ISDS nezahájil přihlášení Mobilním klíčem.', 502);
        }

        $expires = time() + self::FLOW_TTL;
        $payload = json_encode([
            'username' => $username,
            'communication_code' => $communicationCode,
            's_cookie_name' => $sCookieName,
            's_cookie' => $this->safeCookie($sCookie),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $flowToken = $this->newFlowToken();
        $this->flows->create(
            hash('sha256', $flowToken),
            $supplierId,
            $userId,
            $environment,
            'mobile_key',
            $this->crypto->encryptFor($payload, $this->flowContext($supplierId, $userId, $environment)),
            self::FLOW_TTL,
            self::MAX_ATTEMPTS,
        );

        return [
            'flow_token' => $flowToken,
            'state' => 1,
            'description' => 'Požadavek byl předán ISDS. Potvrďte přihlášení v aplikaci Mobilní klíč.',
            'expires_at' => date(DATE_ATOM, $expires),
        ];
    }

    /**
     * @return array{state:int,description:string,context:?ChannelContext}
     */
    public function continue(string $flowToken, int $supplierId, int $userId, string $environment): array
    {
        $this->assertEnvironment($environment);
        $flow = $this->claimFlow($flowToken, $supplierId, $userId, $environment);
        $state = $flow['state'];
        try {
            $response = $this->request(
                'status',
                'GET',
                $this->host($environment) . '/as/mepWsStateUpdate2',
                ['cookie' => $state['s_cookie_name'] . '=' . $this->safeCookie($state['s_cookie'])],
            );
        } catch (\Throwable $e) {
            $this->flows->release($flow['id']);
            throw $e;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->flows->release($flow['id']);
            throw new SubmissionChannelException('isds_mobile_status_failed', 'Stav přihlášení Mobilním klíčem se nepodařilo ověřit.', 502);
        }
        try {
            $statusBody = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->flows->release($flow['id']);
            throw new SubmissionChannelException('isds_mobile_status_malformed', 'ISDS vrátil nečitelný stav Mobilního klíče.', 502);
        }
        $status = (int) ($statusBody['status'] ?? -1);
        $description = trim((string) ($statusBody['description'] ?? ''));
        if ($status === 2) {
            try {
                $login = $this->loginRequest(
                    $environment,
                    $state['username'],
                    $state['communication_code'],
                    $state['s_cookie'],
                    $state['s_cookie_name'],
                );
            } catch (\Throwable $e) {
                $this->flows->release($flow['id']);
                throw $e;
            }
            $sessionCookie = $login['cookies']['IPCZ-X-COOKIE'] ?? '';
            if ($login['status'] !== 302 || $sessionCookie === '') {
                $this->flows->release($flow['id']);
                throw new SubmissionChannelException('isds_mobile_session_failed', 'ISDS potvrdil Mobilní klíč, ale nevydal přístupovou relaci.', 502);
            }
            $cookie = $this->safeCookie($sessionCookie);
            if (!$this->flows->consume($flow['id'])) {
                throw new SubmissionChannelException('isds_mobile_flow_invalid', 'Přihlášení Mobilním klíčem už bylo použito. Spusťte ho znovu.', 409);
            }
            $context = new ChannelContext(
                $supplierId,
                $environment,
                new ChannelCredentials(
                    boxId: '',
                    authMode: 'mobile_key',
                    sessionCookie: SensitiveValue::fromProducer(static fn (): string => $cookie),
                ),
            );
            return ['state' => 2, 'description' => $description !== '' ? $description : 'Přihlášení potvrzeno.', 'context' => $context];
        }
        if ($status === 3) {
            $this->flows->consume($flow['id']);
            throw new SubmissionChannelException('isds_mobile_rejected', 'Přihlášení bylo zamítnuto nebo vypršel čas pro potvrzení.', 409);
        }
        if ($status === 19) {
            $this->flows->consume($flow['id']);
            throw new SubmissionChannelException('isds_mobile_push_failed', 'ISDS nedokázal odeslat upozornění do Mobilního klíče. Spusťte přihlášení znovu.', 502);
        }
        if (!in_array($status, [1, 11, 12, 13], true)) {
            $this->flows->consume($flow['id']);
            throw new SubmissionChannelException('isds_mobile_flow_unknown', 'ISDS přihlašovací požadavek nezná.', 409);
        }
        $this->flows->release($flow['id']);
        return [
            'state' => $status,
            'description' => $description !== '' ? $description : 'Čeká se na potvrzení v Mobilním klíči.',
            'context' => null,
        ];
    }

    public function logout(ChannelContext $context): void
    {
        if ($context->credentials->authMode !== 'mobile_key' || $context->credentials->sessionCookie === null) {
            return;
        }
        $host = $this->host($context->environment);
        $uri = $host . '/apps/DS/dx';
        try {
            $this->request(
                'logout',
                'GET',
                $host . '/as/processLogout?uri=' . rawurlencode($uri),
                ['cookie' => 'IPCZ-X-COOKIE=' . $this->safeCookie($context->credentials->sessionCookie->reveal())],
            );
        } catch (\Throwable) {
            // Relace se po 30 minutách nečinnosti zneplatní sama. Chyba úklidu
            // nesmí přebít výsledek už dokončeného načtení zpráv.
        }
    }

    /** @return array{id:int,state:array{username:string,communication_code:string,s_cookie_name:string,s_cookie:string}} */
    private function claimFlow(string $token, int $supplierId, int $userId, string $environment): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            throw new SubmissionChannelException('isds_mobile_flow_invalid', 'Přihlášení Mobilním klíčem není platné. Spusťte ho znovu.', 409);
        }
        $flow = $this->flows->claim(hash('sha256', $token), $supplierId, $userId, $environment, 'mobile_key');
        if ($flow === null) {
            throw new SubmissionChannelException('isds_mobile_flow_expired', 'Přihlášení Mobilním klíčem vypršelo, bylo použito nebo překročilo počet pokusů. Spusťte ho znovu.', 409);
        }
        try {
            $payload = $this->crypto->decryptFor(
                $flow['payload_ciphertext'],
                $this->flowContext($supplierId, $userId, $environment),
            );
            $state = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->flows->consume($flow['id']);
            throw new SubmissionChannelException('isds_mobile_flow_invalid', 'Přihlášení Mobilním klíčem není platné. Spusťte ho znovu.', 409);
        }
        if (!is_array($state)) {
            $this->flows->consume($flow['id']);
            throw new SubmissionChannelException('isds_mobile_flow_invalid', 'Přihlášení Mobilním klíčem není platné. Spusťte ho znovu.', 409);
        }
        return [
            'id' => $flow['id'],
            'state' => [
                'username' => (string) ($state['username'] ?? ''),
                'communication_code' => (string) ($state['communication_code'] ?? ''),
                's_cookie_name' => $this->safeStateCookieName((string) ($state['s_cookie_name'] ?? 'S-COOKIE')),
                's_cookie' => (string) ($state['s_cookie'] ?? ''),
            ],
        ];
    }

    private function newFlowToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function flowContext(int $supplierId, int $userId, string $environment): string
    {
        return self::TOKEN_CONTEXT . ":supplier:{$supplierId}:user:{$userId}:environment:{$environment}";
    }

    /** @return array{status:int,body:string,cookies:array<string,string>} */
    private function loginRequest(
        string $environment,
        string $username,
        string $code,
        ?string $sCookie,
        string $sCookieName = 'S-COOKIE',
    ): array
    {
        $host = $this->host($environment);
        $uri = $host . '/apps/DS/dx';
        $url = $host . '/as/processLogin?' . http_build_query([
            'type' => 'mep-ws',
            'applicationName' => 'MyÚčto',
            'uri' => $uri,
        ], '', '&', PHP_QUERY_RFC3986);
        $options = ['username' => $username, 'password' => $code];
        if ($sCookie !== null) {
            $options['cookie'] = $this->safeStateCookieName($sCookieName) . '=' . $this->safeCookie($sCookie);
        }
        return $this->request('login', 'POST', $url, $options);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{status:int,body:string,cookies:array<string,string>}
     */
    private function request(string $operation, string $method, string $url, array $options): array
    {
        if ($this->httpDouble !== null) {
            return ($this->httpDouble)($operation, $url, $options);
        }
        if (!function_exists('curl_init')) {
            throw new SubmissionChannelException('isds_curl_required', 'Pro připojení k datové schránce chybí rozšíření PHP cURL.', 503);
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new SubmissionChannelException('isds_mobile_connection_failed', 'Spojení s přihlášením Mobilního klíče se nepodařilo otevřít.', 502);
        }
        $cookies = [];
        $curlOptions = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $method === 'POST' ? '' : null,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTPHEADER => ['User-Agent: ' . self::USER_AGENT, 'Expect:'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$cookies): int {
                if (stripos($header, 'Set-Cookie:') === 0) {
                    $pair = trim(explode(';', trim(substr($header, 11)), 2)[0] ?? '');
                    $separator = strpos($pair, '=');
                    if ($separator !== false) {
                        $cookies[substr($pair, 0, $separator)] = substr($pair, $separator + 1);
                    }
                }
                return strlen($header);
            },
        ];
        if (isset($options['username'], $options['password'])) {
            $curlOptions[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $curlOptions[CURLOPT_USERPWD] = (string) $options['username'] . ':' . (string) $options['password'];
        }
        if (isset($options['cookie'])) {
            $curlOptions[CURLOPT_COOKIE] = (string) $options['cookie'];
        }
        curl_setopt_array($handle, $curlOptions);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false) {
            throw new SubmissionChannelException('isds_mobile_connection_failed', 'Spojení s Mobilním klíčem se přerušilo' . ($error !== '' ? ' (' . $error . ')' : '') . '.', 502);
        }
        return ['status' => $status, 'body' => (string) $body, 'cookies' => $cookies];
    }

    private function host(string $environment): string
    {
        $this->assertEnvironment($environment);
        return 'https://www.' . ($environment === 'test' ? 'datovka-test.gov.cz' : 'datovka.gov.cz');
    }

    private function safeCookie(string $cookie): string
    {
        if (strlen($cookie) < 8 || strlen($cookie) > 4096 || preg_match('/[\x00-\x20;,\x7f]/', $cookie) === 1) {
            throw new SubmissionChannelException('isds_mobile_cookie_invalid', 'Přihlašovací relace Mobilního klíče není platná.', 409);
        }
        return $cookie;
    }

    private function safeStateCookieName(string $cookieName): string
    {
        if (!in_array($cookieName, ['S-COOKIE', 'IPCZ-S-COOKIE'], true)) {
            throw new SubmissionChannelException('isds_mobile_cookie_invalid', 'Přihlašovací relace Mobilního klíče není platná.', 409);
        }
        return $cookieName;
    }

    private function assertEnvironment(string $environment): void
    {
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new SubmissionChannelException('invalid_environment', 'Neznámé prostředí.', 400);
        }
    }

    private function assertEncryptionReady(): void
    {
        if ($this->crypto->validateKey() !== null) {
            throw new SubmissionChannelException('encryption_key_required', 'Pro přihlášení Mobilním klíčem musí být nastavený samostatný šifrovací klíč aplikace.', 503);
        }
    }
}
