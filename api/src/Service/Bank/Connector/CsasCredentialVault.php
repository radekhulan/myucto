<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Repository\CsasOAuthRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\OAuth\OAuthClientFlow;
use MyInvoice\Service\OAuth\OAuthException;

final class CsasCredentialVault
{
    public function __construct(private readonly CsasApiClient $api, private readonly CsasOAuthRepository $repository,
        private readonly SecretEncryption $secrets, private readonly OAuthClientFlow $flow) {}

    /**
     * Platný přístup. Propadlý access token obnoví přes společnou vrstvu ({@see OAuthClientFlow::rotate()}):
     * pod zámkem napojení načte uložený přístup znovu (souběžný požadavek ho mohl obnovit)
     * a obnovený refresh token uloží dřív, než se použije.
     */
    public function access(#[\SensitiveParameter] string $serialized): array
    {
        $data = $this->decode($serialized);
        if (self::fresh($data)) return $data;
        $supplierId = $data['supplier_id'];
        $connectionId = $data['connection_id'];
        try {
            return $this->flow->rotate('bank-token', $supplierId . ':' . $connectionId,
                fn (): array => $this->stored($supplierId, $connectionId),
                static fn (array $current): bool => self::fresh($current),
                function (array $current): array {
                    $tokens = $this->api->refresh($current);
                    $current['access_token'] = $tokens['access_token'];
                    $current['access_expires_at'] = time() + $tokens['expires_in'];
                    $current['refresh_token'] = $tokens['refresh_token'] ?? $current['refresh_token'];
                    // Odvolání platebního oprávnění se projeví hned; scope bez platby čtení nezastaví.
                    // Obnova ale platby nepřidá: připojení jen pro čtení je získá jen novým souhlasem.
                    if (isset($tokens['scope'])) {
                        $current['scope'] = CsasApiClient::grantsPayments((string) ($current['scope'] ?? ''))
                            ? $tokens['scope']
                            : implode(' ', array_values(array_diff(preg_split('/\s+/', trim($tokens['scope'])) ?: [], ['siblings.payments'])));
                    }
                    return $current;
                },
                function (array $current) use ($supplierId, $connectionId): bool {
                    $ciphertext = $this->secrets->encryptFor($this->encode($current), self::context($supplierId, $connectionId));
                    return str_starts_with($ciphertext, 'enc:v2:') && $this->repository->rotate($supplierId, $connectionId, $ciphertext);
                },
                static fn (): BankConnectorOperationException => new BankConnectorOperationException('bank_connection_busy'));
        } catch (OAuthException) {
            throw new BankConnectorException('credential_rotation_failed', 'Obnovený přístup nelze bezpečně uložit.');
        }
    }

    public function decode(#[\SensitiveParameter] string $serialized): array
    {
        try {
            if (strlen($serialized) > 65535) throw new \RuntimeException();
            $data = json_decode($serialized, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new \RuntimeException();
            $data['environment'] ??= 'production';
            $required = ['version', 'supplier_id', 'connection_id', 'api_key', 'client_id', 'client_secret', 'redirect_uri',
                'access_token', 'refresh_token', 'access_expires_at', 'account_id', 'account_iban', 'account_currency', 'call_guard_key', 'environment'];
            // Přístupy z doby, kdy konektor jen četl, udělený scope ani vhodnost účtu pro platby nemají.
            $optional = ['scope', 'payment_account_suitable'];
            if (!is_array($data) || array_diff(array_keys($data), $required, $optional) || array_diff($required, array_keys($data))
                || $data['version'] !== 1) throw new \RuntimeException();
            if (array_key_exists('scope', $data) && (!is_string($data['scope']) || strlen($data['scope']) > 1024
                || preg_match('/[\x00-\x1f\x7f]/', $data['scope']))) throw new \RuntimeException();
            if (array_key_exists('payment_account_suitable', $data) && !is_bool($data['payment_account_suitable'])) throw new \RuntimeException();
            foreach (['supplier_id', 'connection_id', 'access_expires_at'] as $key) {
                if (!is_int($data[$key]) || $data[$key] < 1) throw new \RuntimeException();
            }
            foreach (array_diff($required, ['version', 'supplier_id', 'connection_id', 'access_expires_at']) as $key) {
                if (!is_string($data[$key]) || $data[$key] === '' || strlen($data[$key]) > 16384
                    || preg_match('/[\x00-\x1f\x7f]/', $data[$key])) throw new \RuntimeException();
            }
            if (!preg_match('/^CZ\d{2}0800\d{16}$/D', $data['account_iban'])
                || !preg_match('/^[A-Z]{3}$/D', $data['account_currency'])
                || !preg_match('/^[a-f0-9]{64}$/D', $data['call_guard_key'])) throw new \RuntimeException();
            $this->api->assertEnvironment($data);
            return $data;
        } catch (\Throwable) {
            throw new BankConnectorException(BankConnectorException::INVALID_TOKEN, 'Neplatný přístup České spořitelny.');
        }
    }

    public function encode(#[\SensitiveParameter] array $data): string
    {
        $value = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->decode($value);
        return $value;
    }

    /**
     * Smí napojení zakládat platby? Souhlas musí obsahovat siblings.payments a banka musela
     * účet při připojení označit jako vhodný (suitableScope.PISP). Starý přístup bez těchto
     * údajů umí jen číst; odesílání zpřístupní nový souhlas.
     *
     * @return 'available'|'authorization_scope_missing'|'account_not_suitable'
     */
    public static function paymentAvailability(#[\SensitiveParameter] array $data): string
    {
        if (!CsasApiClient::grantsPayments((string) ($data['scope'] ?? ''))) return 'authorization_scope_missing';
        return ($data['payment_account_suitable'] ?? false) === true ? 'available' : 'account_not_suitable';
    }

    public static function context(int $supplierId, int $connectionId): string
    {
        return sprintf('bank-connection:supplier:%d:connection:%d:type:token', $supplierId, $connectionId);
    }

    private static function fresh(array $data): bool
    {
        return $data['access_expires_at'] > time() + 30;
    }

    /** Přístup tak, jak je teď uložený u napojení; bez přístupu (odpojeno) ho obnovit nejde. */
    private function stored(int $supplierId, int $connectionId): array
    {
        $ciphertext = $this->repository->credential($supplierId, $connectionId);
        if ($ciphertext === null) {
            throw new BankConnectorException('credential_rotation_failed', 'Obnovený přístup nelze bezpečně uložit.');
        }
        try {
            $serialized = $this->secrets->decryptFor($ciphertext, self::context($supplierId, $connectionId));
        } catch (\Throwable) {
            throw new BankConnectorException(BankConnectorException::INVALID_TOKEN, 'Neplatný přístup České spořitelny.');
        }
        $data = $this->decode($serialized);
        if ($data['supplier_id'] !== $supplierId || $data['connection_id'] !== $connectionId) {
            throw new BankConnectorException(BankConnectorException::INVALID_TOKEN, 'Neplatný přístup České spořitelny.');
        }
        return $data;
    }
}
