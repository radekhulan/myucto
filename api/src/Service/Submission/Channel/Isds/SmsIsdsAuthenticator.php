<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds;

use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/** Dvoukroková interaktivní TOTP autentizace ISDS pomocí kódu z SMS. */
final class SmsIsdsAuthenticator
{
    private const TOKEN_CONTEXT = 'isds:sms-flow:v1';
    private const FLOW_TTL = 300;
    private const MAX_ATTEMPTS = 5;
    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT = 30;
    private const USER_AGENT = 'MyUcto-ISDS-SMS/1.0';

    /** @param null|callable(string,string,array<string,mixed>):array{status:int,body:string,cookies:array<string,string>,headers?:array<string,string>} $httpDouble */
    public function __construct(
        private readonly SecretEncryption $crypto,
        private readonly IsdsAuthFlowStore $flows,
        private $httpDouble = null,
    ) {}

    /** @return array{flow_token:string,description:string,expires_at:string} */
    public function start(
        int $supplierId,
        int $userId,
        string $environment,
        string $username,
        string $password,
    ): array {
        $this->assertEncryptionReady();
        $this->assertEnvironment($environment);
        $username = $this->validateUsername($username);
        $this->validatePassword($password);

        $response = $this->loginRequest($environment, $username, $password, true);
        if ($response['status'] === 401) {
            throw new SubmissionChannelException(
                'isds_sms_login_rejected',
                $this->failureMessage($response, 'ISDS odmítl uživatelské jméno nebo heslo.'),
                401,
            );
        }
        if ($response['status'] !== 302) {
            throw new SubmissionChannelException(
                'isds_sms_send_failed',
                $this->failureMessage($response, 'ISDS neodeslal jednorázový SMS kód.'),
                502,
            );
        }

        $expires = time() + self::FLOW_TTL;
        $payload = json_encode([
            'username' => $username,
            'password' => $password,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $flowToken = $this->newFlowToken();
        $this->flows->create(
            hash('sha256', $flowToken),
            $supplierId,
            $userId,
            $environment,
            'sms',
            $this->crypto->encryptFor($payload, $this->flowContext($supplierId, $userId, $environment)),
            self::FLOW_TTL,
            self::MAX_ATTEMPTS,
        );

        return [
            'flow_token' => $flowToken,
            'description' => 'ISDS odeslal jednorázový kód na telefon registrovaný u tohoto uživatelského účtu.',
            'expires_at' => date(DATE_ATOM, $expires),
        ];
    }

    public function complete(
        string $flowToken,
        string $smsCode,
        int $supplierId,
        int $userId,
        string $environment,
    ): ChannelContext
    {
        $smsCode = trim($smsCode);
        if (preg_match('/^[0-9]{4,12}$/', $smsCode) !== 1) {
            throw new SubmissionChannelException('isds_sms_code_invalid', 'Zadejte platný číselný kód z SMS.', 400);
        }
        $this->assertEnvironment($environment);
        $flow = $this->claimFlow($flowToken, $supplierId, $userId, $environment);
        $state = $flow['state'];

        try {
            $response = $this->loginRequest($environment, $state['username'], $state['password'] . $smsCode, false);
        } catch (\Throwable $e) {
            $this->flows->release($flow['id']);
            throw $e;
        }
        if ($response['status'] === 401) {
            $this->flows->release($flow['id']);
            throw new SubmissionChannelException(
                'isds_sms_code_rejected',
                $this->failureMessage($response, 'ISDS odmítl jednorázový SMS kód nebo přihlášení.'),
                401,
            );
        }
        $sessionCookie = $response['cookies']['IPCZ-X-COOKIE'] ?? '';
        if ($response['status'] !== 302 || $sessionCookie === '') {
            $this->flows->release($flow['id']);
            throw new SubmissionChannelException(
                'isds_sms_session_failed',
                $this->failureMessage($response, 'ISDS po ověření SMS kódu nevydal přístupovou relaci.'),
                502,
            );
        }
        $cookie = $this->safeCookie($sessionCookie);
        if (!$this->flows->consume($flow['id'])) {
            throw new SubmissionChannelException('isds_sms_flow_invalid', 'Přihlášení pomocí SMS už bylo použito. Vyžádejte nový kód.', 409);
        }
        return new ChannelContext(
            $supplierId,
            $environment,
            new ChannelCredentials(
                boxId: '',
                authMode: 'sms',
                sessionCookie: SensitiveValue::fromProducer(static fn (): string => $cookie),
            ),
        );
    }

    public function logout(ChannelContext $context): void
    {
        if ($context->credentials->authMode !== 'sms' || $context->credentials->sessionCookie === null) {
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
        }
    }

    /** @return array{id:int,state:array{username:string,password:string}} */
    private function claimFlow(string $token, int $supplierId, int $userId, string $environment): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            throw new SubmissionChannelException('isds_sms_flow_invalid', 'Přihlášení pomocí SMS není platné. Vyžádejte nový kód.', 409);
        }
        $flow = $this->flows->claim(hash('sha256', $token), $supplierId, $userId, $environment, 'sms');
        if ($flow === null) {
            throw new SubmissionChannelException('isds_sms_flow_expired', 'Přihlášení pomocí SMS vypršelo, bylo použito nebo překročilo počet pokusů. Vyžádejte nový kód.', 409);
        }
        try {
            $payload = $this->crypto->decryptFor(
                $flow['payload_ciphertext'],
                $this->flowContext($supplierId, $userId, $environment),
            );
            $state = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->flows->consume($flow['id']);
            throw new SubmissionChannelException('isds_sms_flow_invalid', 'Přihlášení pomocí SMS není platné. Vyžádejte nový kód.', 409);
        }
        if (!is_array($state)) {
            $this->flows->consume($flow['id']);
            throw new SubmissionChannelException('isds_sms_flow_invalid', 'Přihlášení pomocí SMS není platné. Vyžádejte nový kód.', 409);
        }
        return [
            'id' => $flow['id'],
            'state' => [
                'username' => (string) ($state['username'] ?? ''),
                'password' => (string) ($state['password'] ?? ''),
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

    /** @return array{status:int,body:string,cookies:array<string,string>,headers?:array<string,string>} */
    private function loginRequest(string $environment, string $username, string $password, bool $sendSms): array
    {
        $host = $this->host($environment);
        $uri = $host . '/apps/DS/dx';
        $query = ['type' => 'totp'];
        if ($sendSms) {
            $query['sendSms'] = 'true';
        }
        $query['uri'] = $uri;
        return $this->request(
            $sendSms ? 'send_sms' : 'login',
            'POST',
            $host . '/as/processLogin?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            ['username' => $username, 'password' => $password],
        );
    }

    /**
     * @param array<string,mixed> $options
     * @return array{status:int,body:string,cookies:array<string,string>,headers:array<string,string>}
     */
    private function request(string $operation, string $method, string $url, array $options): array
    {
        if ($this->httpDouble !== null) {
            $response = ($this->httpDouble)($operation, $url, $options);
            $response['headers'] ??= [];
            return $response;
        }
        if (!function_exists('curl_init')) {
            throw new SubmissionChannelException('isds_curl_required', 'Pro připojení k datové schránce chybí rozšíření PHP cURL.', 503);
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new SubmissionChannelException('isds_sms_connection_failed', 'Spojení s přihlášením pomocí SMS se nepodařilo otevřít.', 502);
        }
        $cookies = [];
        $headers = [];
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
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$cookies, &$headers): int {
                $separator = strpos($header, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($header, 0, $separator)));
                    $value = trim(substr($header, $separator + 1));
                    if ($name !== '') {
                        $headers[$name] = $value;
                    }
                }
                if (stripos($header, 'Set-Cookie:') === 0) {
                    $pair = trim(explode(';', trim(substr($header, 11)), 2)[0] ?? '');
                    $cookieSeparator = strpos($pair, '=');
                    if ($cookieSeparator !== false) {
                        $cookies[substr($pair, 0, $cookieSeparator)] = substr($pair, $cookieSeparator + 1);
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
            throw new SubmissionChannelException('isds_sms_connection_failed', 'Spojení s přihlášením pomocí SMS se přerušilo' . ($error !== '' ? ' (' . $error . ')' : '') . '.', 502);
        }
        return ['status' => $status, 'body' => (string) $body, 'cookies' => $cookies, 'headers' => $headers];
    }

    /** @param array{headers?:array<string,string>} $response */
    private function failureMessage(array $response, string $fallback): string
    {
        $code = (string) (($response['headers'] ?? [])['x-response-message-code'] ?? '');
        return match ($code) {
            'authentication.error.intruderDetected' => 'ISDS tento přístup na 60 minut zablokoval.',
            'authentication.error.passwordExpired',
            'authentication.error.paswordExpired' => 'Platnost hesla ISDS skončila.',
            'authentication.info.cannotSendQuickly' => 'Nový SMS kód lze vyžádat nejdříve po uplynutí ochranné lhůty ISDS.',
            'authentication.info.totpNotSended' => 'ISDS nyní nedokázal SMS kód odeslat. Zkuste to později.',
            'authentication.error.badRole' => 'Tento účet nemá oprávnění pro požadovaný přístup k datové schránce.',
            default => $fallback,
        };
    }

    private function validateUsername(string $username): string
    {
        $username = trim($username);
        if ($username === '' || strlen($username) > 128 || preg_match('/[\x00-\x20:\x7f]/', $username) === 1) {
            throw new SubmissionChannelException('isds_sms_username_invalid', 'Vyplňte platné uživatelské jméno k datové schránce.', 400);
        }
        return $username;
    }

    private function validatePassword(string $password): void
    {
        if ($password === '' || strlen($password) > 512 || preg_match('/[\x00-\x1f\x7f]/', $password) === 1) {
            throw new SubmissionChannelException('isds_sms_password_invalid', 'Vyplňte heslo k datové schránce.', 400);
        }
    }

    private function host(string $environment): string
    {
        $this->assertEnvironment($environment);
        return 'https://www.' . ($environment === 'test' ? 'datovka-test.gov.cz' : 'datovka.gov.cz');
    }

    private function safeCookie(string $cookie): string
    {
        if (strlen($cookie) < 8 || strlen($cookie) > 4096 || preg_match('/[\x00-\x20;,\x7f]/', $cookie) === 1) {
            throw new SubmissionChannelException('isds_sms_cookie_invalid', 'Přihlašovací relace ISDS není platná.', 409);
        }
        return $cookie;
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
            throw new SubmissionChannelException('encryption_key_required', 'Pro krátkodobé přihlášení pomocí SMS musí být nastavený šifrovací klíč aplikace.', 503);
        }
    }
}
