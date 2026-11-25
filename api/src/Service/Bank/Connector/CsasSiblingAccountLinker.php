<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Repository\CsasOAuthRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\AccountNumberNormalizer;

/**
 * Propojí další účty firmy u České spořitelny, které pokrývá tentýž souhlas banky.
 *
 * Souhlas Premium API (scope siblings.accounts) vydává banka pro všechny účty, které klient
 * v George povolil, nejen pro ten, ze kterého se připojení zahájilo. Po připojení jednoho účtu
 * proto dostanou vlastní kopii přístupu i ostatní aktivní účty firmy s kódem banky 0800,
 * pokud je banka v souhlasu vrací (stejný IBAN i měna) a dosud napojené nejsou. Kopie sdílí
 * tokeny souhlasu; obnovu tokenu pak {@see CsasCredentialVault::access()} uloží všem najednou.
 *
 * Nepropojí se účet napojený vlastním souhlasem, ani účet, který uživatel odpojil. Při novém
 * přihlášení se ale převezmou účty, které patřily k předchozímu souhlasu téhož účtu.
 */
final class CsasSiblingAccountLinker
{
    public const LINKED = 'linked';
    public const RELINKED = 'relinked';
    public const ALREADY_LINKED = 'already_linked';
    public const SEPARATE_CONSENT = 'separate_consent';
    public const DISCONNECTED = 'disconnected';
    public const NOT_IN_CONSENT = 'not_in_consent';
    public const OWNER_MISMATCH = 'owner_mismatch';
    public const FAILED = 'failed';

    public function __construct(private readonly CsasOAuthRepository $oauth, private readonly BankConnectionRepository $connections,
        private readonly CsasCredentialVault $vault, private readonly SecretEncryption $secrets, private readonly BankConnectorCallGuard $calls,
        private readonly CsasSharedConsent $shared) {}

    /**
     * Propojí účty ČS ostatních firem instalace, které souhlas firmy pokrývá ({@see CsasSharedConsent});
     * firma se pro ně stává nosnou. Pravidla jsou stejná jako u účtů jedné firmy; kopie přístupu navíc
     * nese vlastníka souhlasu a platební oprávnění se z ní odebere, takže napojení jiné firmy jen čte.
     * Účet už napojený souhlasem jiné nosné firmy zůstává u ní, dokud ho ta má připojený.
     *
     * Účet jiné firmy se převezme, jen když ho banka vede na tutéž firmu (majitel účtu ze souhlasu
     * odpovídá názvu firmy). Jinak by si správce kterékoli firmy instalace mohl zapsáním cizího
     * čísla účtu mezi své účty otevřít pohyby účtu, který mu nepatří.
     *
     * @param array<string,mixed> $primary platný přístup nosné firmy (ne kopie)
     * @param list<array<string,mixed>> $remoteAccounts účty souhlasu z {@see CsasApiClient::accounts()}
     * @param array<string,mixed>|null $previous dřívější přístup nosné firmy; jeho kopie se převezmou na nový souhlas
     * @return list<array{supplier_id:int,currency_id:int,status:string,connection_id?:int}>
     */
    public function linkShared(#[\SensitiveParameter] array $primary, array $remoteAccounts,
        #[\SensitiveParameter] ?array $previous = null, bool $apply = true): array
    {
        $owner = $primary['supplier_id'];
        if (CsasCredentialVault::isSharedCopy($primary) || !$this->shared->allows($owner)) return [];
        $results = [];
        foreach ($this->oauth->otherSuppliersAccounts($owner) as $local) {
            $results[] = ['supplier_id' => $local['supplier_id']]
                + $this->linkOne($local['supplier_id'], $local, $primary, $remoteAccounts, $previous, $apply, true);
        }
        return $results;
    }

    /**
     * @param array<string,mixed> $primary platný přístup právě připojeného účtu ({@see CsasCredentialVault::decode()})
     * @param list<array<string,mixed>> $remoteAccounts účty souhlasu z {@see CsasApiClient::accounts()}
     * @param array<string,mixed>|null $previous dřívější přístup téhož účtu; jeho účty se převezmou na nový souhlas
     * @return list<array{currency_id:int,status:string,connection_id?:int}>
     */
    public function link(int $primaryCurrencyId, #[\SensitiveParameter] array $primary, array $remoteAccounts,
        #[\SensitiveParameter] ?array $previous = null, bool $apply = true): array
    {
        $supplierId = $primary['supplier_id'];
        $results = [];
        foreach ($this->oauth->siblingAccounts($supplierId, $primaryCurrencyId) as $local) {
            $results[] = $this->linkOne($supplierId, $local, $primary, $remoteAccounts, $previous, $apply);
        }
        return $results;
    }

    /** @return array{currency_id:int,status:string,connection_id?:int} */
    private function linkOne(int $supplierId, array $local, #[\SensitiveParameter] array $primary, array $remoteAccounts,
        #[\SensitiveParameter] ?array $previous, bool $apply, bool $verifyOwner = false): array
    {
        $currencyId = $local['currency_id'];
        try {
            $status = $this->candidateStatus($supplierId, $local, $primary, $previous);
            if ($status !== null) return ['currency_id' => $currencyId, 'status' => $status];
            $remote = $this->remoteMatch($local, $primary, $remoteAccounts);
            if ($remote === null) return ['currency_id' => $currencyId, 'status' => self::NOT_IN_CONSENT];
            if ($verifyOwner && !self::ownedBy($remote, (string) ($local['company_name'] ?? ''))) {
                return ['currency_id' => $currencyId, 'status' => self::OWNER_MISMATCH];
            }
            $relink = $local['token_ciphertext'] !== null;
            if (!$apply) return ['currency_id' => $currencyId, 'status' => $relink ? self::RELINKED : self::LINKED];
            $connectionId = $this->calls->withConnectionLock($supplierId, $currencyId,
                fn (): ?int => $this->save($supplierId, $currencyId, $local['token_ciphertext'], $primary, $remote));
            return $connectionId === null
                ? ['currency_id' => $currencyId, 'status' => self::FAILED]
                : ['currency_id' => $currencyId, 'status' => $relink ? self::RELINKED : self::LINKED, 'connection_id' => $connectionId];
        } catch (\Throwable) {
            return ['currency_id' => $currencyId, 'status' => self::FAILED];
        }
    }

    /** Vede banka účet na firmu tohoto názvu? Porovnává se bez diakritiky, velikosti písmen a interpunkce. */
    public static function ownedBy(array $remote, string $companyName): bool
    {
        $company = self::comparableName($companyName);
        $owners = $remote['ownersNames'] ?? null;
        if ($company === '' || !is_array($owners)) return false;
        foreach ($owners as $owner) {
            if (is_string($owner) && self::comparableName($owner) === $company) return true;
        }
        return false;
    }

    private static function comparableName(string $name): string
    {
        $decomposed = \Normalizer::normalize($name, \Normalizer::FORM_KD);
        $plain = preg_replace('/\p{Mn}+/u', '', is_string($decomposed) ? $decomposed : $name) ?? '';
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($plain)) ?? '';
    }

    /** Důvod, proč účet nepropojit, nebo null, když se propojit má. */
    private function candidateStatus(int $supplierId, array $local, #[\SensitiveParameter] array $primary,
        #[\SensitiveParameter] ?array $previous): ?string
    {
        if ($local['token_ciphertext'] === null) {
            return $local['disconnected'] ? self::DISCONNECTED : null;
        }
        if ($local['provider'] !== CsasOnboardingService::PROVIDER || $local['connection_id'] === null) return self::SEPARATE_CONSENT;
        try {
            $current = $this->vault->decode($this->secrets->decryptFor($local['token_ciphertext'],
                CsasCredentialVault::context($supplierId, $local['connection_id'])));
        } catch (\Throwable) {
            return self::SEPARATE_CONSENT;
        }
        if ($current['connection_id'] !== $local['connection_id']) return self::SEPARATE_CONSENT;
        if (CsasCredentialVault::sameConsent($current, $primary)) return self::ALREADY_LINKED;
        // Převzatý souhlas jiné firmy ustoupí vlastnímu souhlasu firmy a nahradí se i tehdy, když ho
        // nosná firma odpojila nebo udělila znovu.
        if (CsasCredentialVault::isSharedCopy($current) && $current['supplier_id'] === $supplierId
            && (CsasCredentialVault::consentOwner($primary) === $supplierId || !$this->vault->sharedConsentAlive($current))) return null;
        return $previous !== null && CsasCredentialVault::sameConsent($current, $previous) ? null : self::SEPARATE_CONSENT;
    }

    /** @return array<string,mixed>|null jediný účet souhlasu se stejným číslem a měnou */
    private function remoteMatch(array $local, #[\SensitiveParameter] array $primary, array $remoteAccounts): ?array
    {
        if ($local['account_number'] === '') return null;
        $matches = array_values(array_filter($remoteAccounts, static fn (array $remote): bool =>
            is_string($remote['id'] ?? null) && $remote['id'] !== ''
            && is_string($remote['identification']['iban'] ?? null)
            && preg_match('/^CZ\d{2}0800\d{16}$/D', $remote['identification']['iban']) === 1
            && $remote['identification']['iban'] !== $primary['account_iban']
            && ($remote['currency'] ?? null) === $local['code']
            && AccountNumberNormalizer::equalsCzech($remote['identification']['iban'], $local['account_number'])));
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Uloží kopii přístupu pod zámkem účtu. Stav napojení se ověří znovu: mezitím ho mohl
     * připojit nebo změnit jiný požadavek, a pak se nepřepisuje.
     */
    private function save(int $supplierId, int $currencyId, ?string $expectedCiphertext, #[\SensitiveParameter] array $primary,
        array $remote): ?int
    {
        $current = $this->connections->findWithCredentialByCurrency($supplierId, $currencyId);
        $currentCiphertext = $current !== null && $current['has_token'] ? (string) $current['token_ciphertext'] : null;
        if ($currentCiphertext !== $expectedCiphertext) return null;
        $connectionId = $this->connections->ensure($supplierId, $currencyId, CsasOnboardingService::PROVIDER);
        $credentials = $primary;
        $credentials['supplier_id'] = $supplierId;
        $credentials['connection_id'] = $connectionId;
        $credentials['account_id'] = $remote['id'];
        $credentials['account_iban'] = $remote['identification']['iban'];
        $credentials['account_currency'] = $remote['currency'];
        $credentials['call_guard_key'] = bin2hex(random_bytes(32));
        $credentials['payment_account_suitable'] = CsasOnboardingService::suitableForPayments($remote);
        $owner = CsasCredentialVault::consentOwner($primary);
        if ($owner !== $supplierId) {
            // Účet jiné firmy přes sdílený souhlas: jen čtení výpisů.
            $credentials['consent_supplier_id'] = $owner;
            $credentials['payment_account_suitable'] = false;
            $credentials['scope'] = implode(' ', array_values(array_diff(
                preg_split('/\s+/', trim((string) ($primary['scope'] ?? ''))) ?: [], ['siblings.payments', ''])));
        }
        $ciphertext = $this->secrets->encryptFor($this->vault->encode($credentials), CsasCredentialVault::context($supplierId, $connectionId));
        if (!str_starts_with($ciphertext, 'enc:v2:')) throw new BankConnectorOperationException('encryption_failed');
        // Převzaté napojení si nechá stav zapnutí, který mu dal uživatel; nové se zapne.
        $enabled = $currentCiphertext === null || (bool) $current['enabled'];
        $this->connections->saveValidated($supplierId, $connectionId, CsasOnboardingService::PROVIDER, $ciphertext, $enabled,
            $remote['identification']['iban'], '0800', $remote['currency']);
        return $connectionId;
    }
}
