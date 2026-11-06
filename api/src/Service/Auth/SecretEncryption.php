<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;

/**
 * AES-256-GCM šifrování citlivých secretů (TOTP secrets, podobné).
 *
 * Klíč pochází z `cfg.app.secret_encryption_key` (32B base64). Pokud není nastaven,
 * použije se HKDF z `app.pepper` (back-compat — ale s explicitním klíčem je lepší).
 *
 * Format zašifrovaného textu: `enc:v1:{base64(nonce|ciphertext|tag)}`
 * Plain text bez prefixu se ponechá jako legacy (backward compat při migraci).
 */
final class SecretEncryption
{
    private const PREFIX = 'enc:v1:';
    private const CONTEXT_PREFIX = 'enc:v2:';
    private const NONCE_LEN = 12; // GCM standard

    public function __construct(private readonly Config $config) {}

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $ct = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return self::PREFIX . base64_encode($nonce . $ct . $tag);
    }

    public function decrypt(string $stored): string
    {
        if (str_starts_with($stored, self::CONTEXT_PREFIX)) {
            throw new \RuntimeException('Context-bound ciphertext requires decryptFor().');
        }
        // Legacy plaintext — bez prefixu se vrátí jak je
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }
        $blob = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($blob === false || strlen($blob) < self::NONCE_LEN + 16) {
            throw new \RuntimeException('Invalid ciphertext');
        }
        $nonce = substr($blob, 0, self::NONCE_LEN);
        $tag   = substr($blob, -16);
        $ct    = substr($blob, self::NONCE_LEN, -16);
        foreach ($this->keyRing() as $key) {
            $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            if ($pt !== false) {
                return $pt;
            }
        }
        throw new \RuntimeException('Decryption failed');
    }

    public function encryptFor(string $plaintext, string $context): string
    {
        $context = trim($context);
        if ($context === '') {
            throw new \InvalidArgumentException('Encryption context is required.');
        }
        $key = $this->key();
        $keyId = $this->keyId($key);
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $ct = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $context,
        );
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return self::CONTEXT_PREFIX . $keyId . ':' . base64_encode($nonce . $ct . $tag);
    }

    public function decryptFor(string $stored, string $context): string
    {
        $context = trim($context);
        if ($context === '') {
            throw new \InvalidArgumentException('Encryption context is required.');
        }
        if (!str_starts_with($stored, self::CONTEXT_PREFIX)) {
            return $this->decrypt($stored);
        }
        $encoded = substr($stored, strlen(self::CONTEXT_PREFIX));
        $separator = strpos($encoded, ':');
        if ($separator === false) {
            throw new \RuntimeException('Invalid ciphertext');
        }
        $keyId = substr($encoded, 0, $separator);
        $blob = base64_decode(substr($encoded, $separator + 1), true);
        if ($blob === false || strlen($blob) < self::NONCE_LEN + 16) {
            throw new \RuntimeException('Invalid ciphertext');
        }
        $key = $this->keyRing()[$keyId] ?? null;
        if ($key === null) {
            throw new \RuntimeException('Encryption key is unavailable');
        }
        $nonce = substr($blob, 0, self::NONCE_LEN);
        $tag = substr($blob, -16);
        $ct = substr($blob, self::NONCE_LEN, -16);
        $pt = openssl_decrypt(
            $ct,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $context,
        );
        if ($pt === false) {
            throw new \RuntimeException('Decryption failed');
        }
        return $pt;
    }

    /**
     * Přebalí kontextově šifrovanou hodnotu na aktuální klíč.
     *
     * Vrací `null`, když už hodnota aktuálním klíčem zašifrovaná je; volající
     * pak nic nepřepisuje, takže opakované spuštění rotace je bez účinku.
     * Nový ciphertext se před vrácením zpětně dešifruje a porovná s původním
     * plaintextem; volající tak nikdy nedostane hodnotu, kterou by pak nešlo
     * přečíst.
     */
    public function rewrapFor(string $stored, string $context): ?string
    {
        $keyId = $this->keyIdOf($stored);
        if ($keyId === null) {
            throw new \RuntimeException('Only context-bound ciphertext can be rewrapped.');
        }
        if (hash_equals($this->currentKeyId(), $keyId)) {
            return null;
        }
        $plaintext = $this->decryptFor($stored, $context);
        $rewrapped = $this->encryptFor($plaintext, $context);
        if (!hash_equals($plaintext, $this->decryptFor($rewrapped, $context))) {
            throw new \RuntimeException('Rewrapped ciphertext verification failed.');
        }

        return $rewrapped;
    }

    /** Identifikátor aktuálního klíče (prvních 16 hex sha256 klíče, ne klíč). */
    public function currentKeyId(): string
    {
        return $this->keyId($this->key());
    }

    /** Identifikátor klíče z `enc:v2:` hodnoty; `null` u jiného formátu. */
    public function keyIdOf(string $stored): ?string
    {
        if (!str_starts_with($stored, self::CONTEXT_PREFIX)) {
            return null;
        }
        $encoded = substr($stored, strlen(self::CONTEXT_PREFIX));
        $separator = strpos($encoded, ':');
        if ($separator === false || $separator === 0) {
            return null;
        }

        return substr($encoded, 0, $separator);
    }

    /** Je klíč s tímhle identifikátorem v konfiguraci (aktuální nebo předchozí)? */
    public function hasKeyId(string $keyId): bool
    {
        return isset($this->keyRing()[$keyId]);
    }

    /** @internal Pomáhá testům / migracím rozpoznat encrypted vs legacy plaintext */
    public function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX)
            || str_starts_with($stored, self::CONTEXT_PREFIX);
    }

    /**
     * Validace konfigurace klíče pro admin health warning.
     * null = OK (dedikovaný key), string = warning/error v CZ.
     */
    public function validateKey(): ?string
    {
        $b64 = (string) $this->config->get('app.secret_encryption_key', '');
        if ($b64 !== '') {
            $key = base64_decode($b64, true);
            if ($key === false || strlen($key) !== 32) {
                return 'cfg.app.secret_encryption_key musí být base64 klíč o délce 32B po dekódování.';
            }
            foreach ($this->previousKeyValues() as $previous) {
                $decoded = base64_decode($previous, true);
                if ($decoded === false || strlen($decoded) !== 32) {
                    return 'cfg.app.secret_encryption_previous_keys musí obsahovat jen base64 klíče o délce 32B.';
                }
            }
            return null;
        }

        $pepper = (string) $this->config->get('app.pepper', '');
        if ($pepper === '') {
            return 'Ani secret_encryption_key, ani app.pepper nejsou nastaveny.';
        }
        if (PepperPolicy::isPlaceholder($pepper)) {
            return 'secret_encryption_key chybí a app.pepper má zástupnou hodnotu ze vzorové konfigurace; tajemství jsou šifrovaná veřejně známým klíčem.';
        }

        return 'secret_encryption_key chybí, používá se HKDF fallback z app.pepper (méně bezpečné).';
    }

    private function key(): string
    {
        $b64 = (string) $this->config->get('app.secret_encryption_key', '');
        if ($b64 !== '') {
            $key = base64_decode($b64, true);
            if ($key === false || strlen($key) !== 32) {
                throw new \RuntimeException('cfg.app.secret_encryption_key musí být base64 klíč o délce 32B po dekódování.');
            }
            return $key;
        }
        // Fallback: HKDF z pepperu — méně bezpečné než dedikovaný klíč, ale funkční
        $pepper = (string) $this->config->get('app.pepper', '');
        if ($pepper === '') {
            throw new \RuntimeException('Ani secret_encryption_key, ani app.pepper nejsou nastaveny.');
        }
        // Zástupná hodnota ze vzorové konfigurace je veřejná a klíč z ní nic nechrání.
        // Produkce s ní nenastartuje ({@see PepperPolicy::productionProblem()}); ve vývoji
        // se tu záměrně neodmítá, aby lokální instalace neztratila už uložená tajemství.
        // Stav hlásí status() výše.
        return hash_hkdf('sha256', $pepper, 32, 'totp-secret');
    }

    /** @return array<string,string> */
    private function keyRing(): array
    {
        $keys = [$this->key()];
        foreach ($this->previousKeyValues() as $encoded) {
            $key = base64_decode($encoded, true);
            if ($key === false || strlen($key) !== 32) {
                continue;
            }
            $keys[] = $key;
        }
        $result = [];
        foreach ($keys as $key) {
            $result[$this->keyId($key)] = $key;
        }
        return $result;
    }

    /** @return list<string> */
    private function previousKeyValues(): array
    {
        $values = $this->config->get('app.secret_encryption_previous_keys', []);
        if (is_string($values)) {
            $values = array_filter(array_map('trim', explode(',', $values)));
        }
        if (!is_array($values)) {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $values),
        ));
    }

    private function keyId(string $key): string
    {
        return substr(hash('sha256', $key), 0, 16);
    }
}
