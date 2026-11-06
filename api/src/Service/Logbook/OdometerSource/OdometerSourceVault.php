<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\OdometerSource;

use MyInvoice\Service\Auth\SecretEncryption;

/**
 * Šifrované přístupy zdrojů stavu tachometru (vzor {@see \MyInvoice\Service\Bank\Connector\CsasCredentialVault}).
 *
 * Přístupy se ukládají jen jako context-bound enc:v2 ciphertext. Kontext i obálka váží
 * data na firmu, napojení (účet u zdroje) a zdroj; obsah `data` je plochá mapa řetězců
 * a čísel, jejíž význam zná jen zdroj. Rozpracované přihlášení šifruje společná vrstva
 * OAuth klienta ({@see \MyInvoice\Service\OAuth\OAuthClientFlow}).
 */
final class OdometerSourceVault
{
    private const ENVELOPE_KEYS = ['version', 'supplier_id', 'ref', 'provider', 'data'];
    private const MAX_FIELDS = 32;

    public function __construct(private readonly SecretEncryption $secrets) {}

    public function assertKeyAvailable(): void
    {
        if ($this->secrets->validateKey() !== null) {
            throw new OdometerSourceException('encryption_key_unavailable', 'Chybí šifrovací klíč aplikace (app.secret_encryption_key).');
        }
    }

    /** @param array<string,string|int> $credentials */
    public function sealCredentials(int $supplierId, int $accountId, string $provider, #[\SensitiveParameter] array $credentials): string
    {
        return $this->seal(self::context($supplierId, $accountId, $provider, 'credentials'), $supplierId, $accountId, $provider, $credentials);
    }

    /** @return array<string,string|int> */
    public function openCredentials(int $supplierId, int $accountId, string $provider, #[\SensitiveParameter] string $ciphertext): array
    {
        return $this->open(self::context($supplierId, $accountId, $provider, 'credentials'), $supplierId, $accountId, $provider, $ciphertext);
    }

    /** Kontext šifry: firma + napojení (účet u zdroje) + zdroj + druh dat. */
    public static function context(int $supplierId, int $accountId, string $provider, string $type): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $provider)) {
            throw new OdometerSourceException(OdometerSourceException::UNKNOWN_PROVIDER, 'Neplatný kód zdroje stavu tachometru.');
        }
        return sprintf('odometer-source:supplier:%d:account:%d:provider:%s:type:%s', $supplierId, $accountId, $provider, $type);
    }

    private function seal(string $context, int $supplierId, int $ref, string $provider, #[\SensitiveParameter] array $data): string
    {
        $envelope = ['version' => 1, 'supplier_id' => $supplierId, 'ref' => $ref, 'provider' => $provider, 'data' => $data];
        $json = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->decode($json, $supplierId, $ref, $provider);
        $this->assertKeyAvailable();
        $ciphertext = $this->secrets->encryptFor($json, $context);
        if (!str_starts_with($ciphertext, 'enc:v2:')) throw $this->invalid();
        return $ciphertext;
    }

    private function open(string $context, int $supplierId, int $ref, string $provider, #[\SensitiveParameter] string $ciphertext): array
    {
        if (!str_starts_with($ciphertext, 'enc:v2:')) throw $this->invalid();
        try {
            $json = $this->secrets->decryptFor($ciphertext, $context);
        } catch (\Throwable) {
            throw $this->invalid();
        }
        return $this->decode($json, $supplierId, $ref, $provider);
    }

    /** @return array<string,string|int> */
    private function decode(#[\SensitiveParameter] string $json, int $supplierId, int $ref, string $provider): array
    {
        try {
            if (strlen($json) > 65535) throw new \RuntimeException();
            $envelope = json_decode($json, true, 3, JSON_THROW_ON_ERROR);
            if (!is_array($envelope) || array_diff(array_keys($envelope), self::ENVELOPE_KEYS)
                || array_diff(self::ENVELOPE_KEYS, array_keys($envelope))
                || $envelope['version'] !== 1 || $envelope['supplier_id'] !== $supplierId
                || $envelope['ref'] !== $ref || $envelope['provider'] !== $provider) throw new \RuntimeException();
            $data = $envelope['data'];
            if (!is_array($data) || $data === [] || count($data) > self::MAX_FIELDS || array_is_list($data)) throw new \RuntimeException();
            foreach ($data as $key => $value) {
                if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $key)) throw new \RuntimeException();
                if (is_int($value)) continue;
                if (!is_string($value) || $value === '' || strlen($value) > 16384
                    || preg_match('/[\x00-\x20\x7f]/', $value)) throw new \RuntimeException();
            }
            return $data;
        } catch (\Throwable) {
            throw $this->invalid();
        }
    }

    private function invalid(): OdometerSourceException
    {
        return new OdometerSourceException(OdometerSourceException::REAUTH_REQUIRED, 'Uložený přístup ke zdroji je neplatný, připojte auto znovu.');
    }
}
