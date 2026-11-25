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
        private readonly SecretEncryption $secrets, private readonly OAuthClientFlow $flow,
        private readonly CsasSharedConsent $shared) {}

    /**
     * Platný přístup. Propadlý access token obnoví přes společnou vrstvu ({@see OAuthClientFlow::rotate()}):
     * pod zámkem souhlasu načte uložený přístup znovu (souběžný požadavek ho mohl obnovit)
     * a obnovený refresh token uloží dřív, než se použije.
     *
     * Jeden souhlas banky může pokrývat více účtů firmy ({@see CsasSiblingAccountLinker}). Každý účet
     * má vlastní kopii přístupu, obnovené tokeny se ale hned uloží i ostatním účtům téhož souhlasu.
     * Další účet pak banku o obnovu nežádá a zámek je proto společný pro celou aplikaci u banky.
     *
     * Kopie sdíleného souhlasu jiné firmy ({@see CsasSharedConsent}) platí jen, dokud je sdílení
     * nastavené a hlavní firma má tentýž souhlas pořád připojený.
     */
    public function access(#[\SensitiveParameter] string $serialized): array
    {
        $data = $this->decode($serialized);
        if (self::isSharedCopy($data)) $this->assertSharedConsent($data);
        if (self::fresh($data)) return $data;
        $supplierId = $data['supplier_id'];
        $connectionId = $data['connection_id'];
        $rotation = null;
        try {
            return $this->flow->rotate('bank-token', self::consentLockKey($data),
                fn (): array => $this->stored($supplierId, $connectionId),
                static fn (array $current): bool => self::fresh($current),
                function (array $current) use (&$rotation): array {
                    $tokens = $this->api->refresh($current);
                    $rotation = ['previous_refresh_token' => $current['refresh_token'], 'tokens' => $tokens];
                    return self::withTokens($current, $tokens);
                },
                function (array $current) use ($supplierId, $connectionId, &$rotation): bool {
                    $ciphertext = $this->secrets->encryptFor($this->encode($current), self::context($supplierId, $connectionId));
                    if (!str_starts_with($ciphertext, 'enc:v2:') || !$this->repository->rotate($supplierId, $connectionId, $ciphertext)) return false;
                    if (is_array($rotation)) $this->shareRotation($current, $rotation['previous_refresh_token'], $rotation['tokens']);
                    return true;
                },
                static fn (): BankConnectorOperationException => new BankConnectorOperationException('bank_connection_busy'));
        } catch (OAuthException) {
            throw new BankConnectorException('credential_rotation_failed', 'Obnovený přístup nelze bezpečně uložit.');
        }
    }

    /**
     * Zámek obnovy: firma a registrace aplikace u banky. Nezávisí na refresh tokenu, aby se
     * souběžné požadavky účtů téhož souhlasu řadily za sebe i při případné rotaci tokenu.
     */
    public static function consentLockKey(#[\SensitiveParameter] array $data): string
    {
        return self::consentOwner($data) . ':csas:' . substr(hash('sha256', ($data['environment'] ?? 'production') . "\0" . $data['client_id']), 0, 24);
    }

    /** Firma, které souhlas banky patří: u kopie sdíleného souhlasu hlavní firma, jinak firma napojení. */
    public static function consentOwner(#[\SensitiveParameter] array $data): int
    {
        return $data['consent_supplier_id'] ?? $data['supplier_id'];
    }

    /** Je přístup kopií sdíleného souhlasu hlavní firmy uloženou u jiné firmy? */
    public static function isSharedCopy(#[\SensitiveParameter] array $data): bool
    {
        return self::consentOwner($data) !== $data['supplier_id'];
    }

    /** Patří oba přístupy k témuž souhlasu banky (stejný vlastník, aplikace, prostředí a refresh token)? */
    public static function sameConsent(#[\SensitiveParameter] array $a, #[\SensitiveParameter] array $b): bool
    {
        return self::consentOwner($a) === self::consentOwner($b) && ($a['environment'] ?? 'production') === ($b['environment'] ?? 'production')
            && hash_equals($a['client_id'], $b['client_id']) && hash_equals($a['refresh_token'], $b['refresh_token']);
    }

    /**
     * Kopie souhlasu jiné firmy se smí použít, jen když sdílení její souhlas povoluje a nosná firma
     * má tentýž souhlas pořád připojený. Odpojení nebo nové přihlášení nosné firmy tak kopie zastaví
     * hned, ne až ve chvíli, kdy banka odmítne token; účet pak převezme jiný souhlas, který ho pokrývá.
     */
    public function sharedConsentAlive(#[\SensitiveParameter] array $copy): bool
    {
        $owner = self::consentOwner($copy);
        if (!$this->shared->allows($owner)) return false;
        foreach ($this->repository->connectedCredentials($owner) as $row) {
            try {
                $stored = $this->decode($this->secrets->decryptFor($row['token_ciphertext'], self::context($owner, $row['id'])));
            } catch (\Throwable) {
                continue;
            }
            if (!self::isSharedCopy($stored) && $stored['connection_id'] === $row['id'] && self::sameConsent($stored, $copy)) return true;
        }
        return false;
    }

    private function assertSharedConsent(#[\SensitiveParameter] array $copy): void
    {
        if ($this->sharedConsentAlive($copy)) return;
        throw new BankConnectorException('csas_shared_consent_unavailable', 'Sdílený souhlas České spořitelny už není připojený.');
    }

    /** @param array<string,mixed> $tokens odpověď banky na obnovu */
    private static function withTokens(#[\SensitiveParameter] array $current, #[\SensitiveParameter] array $tokens): array
    {
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
    }

    /**
     * Obnovené tokeny uloží i ostatním účtům téhož souhlasu. Účet, kterému uložení selže,
     * si přístup obnoví sám při dalším použití (refresh token ČS se obnovou nemění).
     * Souhlas sdílený napříč firmami ({@see CsasSharedConsent}) se rozešle všem firmám instalace.
     */
    private function shareRotation(#[\SensitiveParameter] array $rotated, #[\SensitiveParameter] string $previousRefreshToken,
        #[\SensitiveParameter] array $tokens): void
    {
        $owner = self::consentOwner($rotated);
        $before = ['refresh_token' => $previousRefreshToken] + $rotated;
        $rows = !$this->shared->disabled()
            ? $this->repository->allConnectedCredentials()
            : array_map(static fn (array $row): array => $row + ['supplier_id' => $rotated['supplier_id']],
                $this->repository->connectedCredentials($rotated['supplier_id']));
        foreach ($rows as $row) {
            $supplierId = $row['supplier_id'];
            if ($supplierId === $rotated['supplier_id'] && $row['id'] === $rotated['connection_id']) continue;
            try {
                $sibling = $this->decode($this->secrets->decryptFor($row['token_ciphertext'], self::context($supplierId, $row['id'])));
                if ($sibling['supplier_id'] !== $supplierId || $sibling['connection_id'] !== $row['id'] || !self::sameConsent($sibling, $before)) continue;
                $ciphertext = $this->secrets->encryptFor($this->encode(self::withTokens($sibling, $tokens)), self::context($supplierId, $row['id']));
                if (str_starts_with($ciphertext, 'enc:v2:')) $this->repository->rotate($supplierId, $row['id'], $ciphertext);
            } catch (\Throwable) {
                continue;
            }
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
            // consent_supplier_id má jen kopie sdíleného souhlasu hlavní firmy ({@see CsasSharedConsent}).
            $optional = ['scope', 'payment_account_suitable', 'consent_supplier_id'];
            if (!is_array($data) || array_diff(array_keys($data), $required, $optional) || array_diff($required, array_keys($data))
                || $data['version'] !== 1) throw new \RuntimeException();
            if (array_key_exists('scope', $data) && (!is_string($data['scope']) || strlen($data['scope']) > 1024
                || preg_match('/[\x00-\x1f\x7f]/', $data['scope']))) throw new \RuntimeException();
            if (array_key_exists('payment_account_suitable', $data) && !is_bool($data['payment_account_suitable'])) throw new \RuntimeException();
            foreach (['supplier_id', 'connection_id', 'access_expires_at'] as $key) {
                if (!is_int($data[$key]) || $data[$key] < 1) throw new \RuntimeException();
            }
            if (array_key_exists('consent_supplier_id', $data)
                && (!is_int($data['consent_supplier_id']) || $data['consent_supplier_id'] < 1)) throw new \RuntimeException();
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
