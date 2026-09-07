<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

final class KbPlusRegistrationClient
{
    private const SOFTWARE_STATEMENT_URL = 'https://client-registration.api-gateway.kb.cz/v3/software-statements';
    private const MAX_RESPONSE_BYTES = 64 * 1024;

    public function __construct(private readonly ClientInterface $http) {}

    /**
     * @param array<string,mixed> $credentials
     * @param array<string,mixed> $metadata
     */
    public function createSoftwareStatement(
        #[\SensitiveParameter] array $credentials,
        #[\SensitiveParameter] array $metadata,
    ): string {
        $body = $this->metadata($metadata);
        try {
            $curl = BankClientCertificate::curlOptions(
                $this->credential($credentials, 'certificate_p12', 32768),
                $this->credential($credentials, 'certificate_password', 1024, true),
            );
        } catch (BankConnectorOperationException $e) {
            throw new BankConnectorException($e->errorCode, 'Certifikát pro registraci KB+ není platný.');
        }

        $responseTooLarge = false;
        $sink = $this->responseSink($responseTooLarge);
        try {
            $response = $this->http->request('POST', self::SOFTWARE_STATEMENT_URL, [
                'headers' => [
                    'Accept' => 'text/plain',
                    'Content-Type' => 'application/json',
                    'apiKey' => $this->credential($credentials, 'client_registration_api_key', 16384),
                    'x-correlation-id' => $this->correlationId(),
                    'User-Agent' => 'MyUcto-KBPlus-Connector/1.0',
                ],
                'body' => json_encode(
                    $body,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    32,
                ),
                'curl' => $curl,
                'allow_redirects' => false,
                'connect_timeout' => 5.0,
                'timeout' => 20.0,
                'verify' => true,
                'http_errors' => false,
                'stream' => true,
                'sink' => $sink,
                'debug' => false,
            ]);
        } catch (\JsonException) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Metadata aplikace KB+ nelze serializovat.',
            );
        } catch (BankConnectorException $e) {
            throw $e;
        } catch (\Throwable) {
            if ($responseTooLarge) {
                throw new BankConnectorException(
                    BankConnectorException::RESPONSE_TOO_LARGE,
                    'KB+ vrátila příliš velký software statement.',
                );
            }
            throw new BankConnectorException(
                BankConnectorException::REMOTE_UNAVAILABLE,
                'Registrační API KB+ je dočasně nedostupné.',
            );
        }

        $status = $response->getStatusCode();
        if ($status !== 201) {
            throw new BankConnectorException(
                match ($status) {
                    401, 403 => BankConnectorException::INVALID_TOKEN,
                    429 => BankConnectorException::RATE_LIMITED,
                    default => $status >= 500
                        ? BankConnectorException::REMOTE_UNAVAILABLE
                        : BankConnectorException::REMOTE_HTTP_ERROR,
                },
                'Registrační API KB+ požadavek odmítlo.',
                false,
                $status,
            );
        }

        $statement = trim($this->body($response));
        if (strlen($statement) > 32768 || !$this->isSignedJwt($statement)) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'KB+ vrátila neplatný software statement.',
                false,
                $status,
            );
        }
        return $statement;
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function metadata(array $metadata): array
    {
        $allowed = [
            'softwareName', 'softwareNameEn', 'softwareId', 'softwareVersion', 'softwareUri',
            'redirectUris', 'registrationBackUri', 'contacts', 'logoUri', 'tosUri', 'policyUri',
        ];
        if (array_diff(array_keys($metadata), $allowed) !== []) {
            throw $this->invalidMetadata();
        }
        $result = [
            'softwareName' => $this->text($metadata, 'softwareName', 5, 50),
            'softwareNameEn' => $this->text($metadata, 'softwareNameEn', 5, 50),
            'softwareId' => $this->text($metadata, 'softwareId', 1, 64),
            'softwareVersion' => $this->text($metadata, 'softwareVersion', 1, 30),
            'redirectUris' => $this->uris($metadata['redirectUris'] ?? null, 10),
            'tokenEndpointAuthMethod' => 'client_secret_post',
            'grantTypes' => ['authorization_code', 'refresh_token'],
            'responseTypes' => ['code'],
            'registrationBackUri' => $this->uri($metadata['registrationBackUri'] ?? null),
            'contacts' => $this->contacts($metadata['contacts'] ?? null),
        ];
        foreach (['softwareUri', 'logoUri', 'tosUri', 'policyUri'] as $key) {
            if (array_key_exists($key, $metadata)) {
                $result[$key] = $this->uri($metadata[$key]);
            }
        }
        return $result;
    }

    private function isSignedJwt(string $jwt): bool
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            return false;
        }
        foreach ($parts as $part) {
            if (!preg_match('/^[A-Za-z0-9_-]+$/D', $part)) {
                return false;
            }
        }
        $header = $this->base64UrlDecode($parts[0]);
        if ($header === null) {
            return false;
        }
        try {
            $decoded = json_decode($header, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        return is_array($decoded)
            && is_string($decoded['alg'] ?? null)
            && in_array($decoded['alg'], ['HS256', 'RS256', 'PS256', 'ES256'], true);
    }

    private function body(ResponseInterface $response): string
    {
        try {
            $stream = $response->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $body = '';
            while (!$stream->eof() && strlen($body) <= self::MAX_RESPONSE_BYTES) {
                $chunk = $stream->read(min(8192, self::MAX_RESPONSE_BYTES + 1 - strlen($body)));
                if ($chunk === '') {
                    throw new \RuntimeException();
                }
                $body .= $chunk;
            }
        } catch (\Throwable) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'KB+ vrátila nečitelný software statement.',
                false,
                $response->getStatusCode(),
            );
        }
        if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new BankConnectorException(
                BankConnectorException::RESPONSE_TOO_LARGE,
                'KB+ vrátila příliš velký software statement.',
                false,
                $response->getStatusCode(),
            );
        }
        return $body;
    }

    private function responseSink(bool &$tooLarge): FnStream
    {
        $buffer = Utils::streamFor(Utils::tryFopen('php://memory', 'w+b'));
        return FnStream::decorate($buffer, [
            'write' => static function (string $data) use ($buffer, &$tooLarge): int {
                $size = $buffer->getSize() ?? $buffer->tell();
                if (strlen($data) > self::MAX_RESPONSE_BYTES - $size) {
                    $tooLarge = true;
                    throw new \RuntimeException('KB+ registration response exceeded its size limit.');
                }
                return $buffer->write($data);
            },
        ]);
    }

    /** @param array<string,mixed> $credentials */
    private function credential(
        #[\SensitiveParameter] array $credentials,
        string $key,
        int $maxLength,
        bool $allowEmpty = false,
    ): string {
        $value = $credentials[$key] ?? null;
        if (
            !is_string($value)
            || (!$allowEmpty && $value === '')
            || strlen($value) > $maxLength
            || preg_match('/[\x00-\x1F\x7F]/', $value)
        ) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_TOKEN,
                'Konfigurace registrace KB+ není úplná.',
            );
        }
        return $value;
    }

    /** @param array<string,mixed> $source */
    private function text(array $source, string $key, int $min, int $max): string
    {
        $value = $source[$key] ?? null;
        if (
            !is_string($value)
            || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value) < $min
            || mb_strlen($value) > $max
            || preg_match('/[\x00-\x1F\x7F]/u', $value)
        ) {
            throw $this->invalidMetadata();
        }
        return $value;
    }

    /** @return list<string> */
    private function uris(mixed $value, int $max): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > $max) {
            throw $this->invalidMetadata();
        }
        $uris = array_map(fn (mixed $uri): string => $this->uri($uri), $value);
        if (count(array_unique($uris)) !== count($uris)) {
            throw $this->invalidMetadata();
        }
        return $uris;
    }

    private function uri(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 2048) {
            throw $this->invalidMetadata();
        }
        $parts = parse_url($value);
        if (
            ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw $this->invalidMetadata();
        }
        return $value;
    }

    /** @return list<string> */
    private function contacts(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 2) {
            throw $this->invalidMetadata();
        }
        $contacts = [];
        foreach ($value as $contact) {
            if (!is_string($contact) || strlen($contact) > 50 || !preg_match('/^email: [^\s@]+@[^\s@]+\.[^\s@]+$/D', $contact)) {
                throw $this->invalidMetadata();
            }
            $contacts[] = $contact;
        }
        if (count(array_unique($contacts)) !== count($contacts)) {
            throw $this->invalidMetadata();
        }
        return $contacts;
    }

    private function base64UrlDecode(string $value): ?string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($value, true);
        return $decoded === false ? null : $decoded;
    }

    private function correlationId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function invalidMetadata(): BankConnectorException
    {
        return new BankConnectorException(
            'kb_plus_registration_invalid',
            'Metadata aplikace KB+ nejsou platná.',
        );
    }
}
