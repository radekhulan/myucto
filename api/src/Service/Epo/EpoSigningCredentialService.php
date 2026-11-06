<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

use MyInvoice\Repository\EpoSigningCredentialRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use PDOException;

final class EpoSigningCredentialService
{
    private const MAX_PFX_BYTES = 262144;

    public function __construct(
        private readonly EpoSigningCredentialRepository $credentials,
        private readonly SecretEncryption $crypto,
        private readonly EpoCertificateInspector $certificateInspector,
    ) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function listOwnedForSupplier(int $ownerUserId, int $supplierId, bool $supplierScoped = false): array
    {
        $items = $this->credentials->listOwnedForSupplier($ownerUserId, $supplierId, $supplierScoped);
        // Klientské GET nesmí kvůli starším metadatům dešifrovat klíč ani zapisovat.
        if ($supplierScoped || $this->crypto->validateKey() !== null || !function_exists('openssl_pkcs12_read')) {
            return $items;
        }
        foreach ($items as &$item) {
            if ((bool) $item['ik_mpsv_present']) {
                continue;
            }
            $credentialId = (int) $item['id'];
            $credential = $this->credentials->findOwned($credentialId, $ownerUserId);
            if ($credential === null || !$this->storedCertificateContainsIkMpsv($credential)) {
                continue;
            }
            $this->credentials->markIkMpsvPresent($credentialId, $ownerUserId);
            $item['ik_mpsv_present'] = true;
        }
        unset($item);
        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    public function import(
        int $ownerUserId,
        int $supplierId,
        string $label,
        string $pfxBytes,
        string $pfxPassword,
        bool $supplierScoped = false,
    ): array {
        $configurationError = $this->crypto->validateKey();
        if ($configurationError !== null) {
            throw new EpoSubmissionException(
                'encryption_key_required',
                'Pro uložení soukromého klíče nastavte samostatný app.secret_encryption_key.',
                503,
            );
        }
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 120) {
            throw new EpoSubmissionException(
                'validation_failed',
                'Název certifikátu musí mít 1 až 120 znaků.',
                400,
            );
        }
        $size = strlen($pfxBytes);
        if ($size <= 0 || $size > self::MAX_PFX_BYTES) {
            throw new EpoSubmissionException(
                'invalid_certificate_file',
                'Soubor P12/PFX je prázdný nebo příliš velký.',
                400,
            );
        }
        if ($pfxPassword === '') {
            throw new EpoSubmissionException(
                'pfx_password_required',
                'Zadejte heslo k souboru P12/PFX.',
                400,
            );
        }
        if (!function_exists('openssl_pkcs12_read')) {
            throw new EpoSubmissionException(
                'openssl_unavailable',
                'Server nepodporuje práci s certifikáty PKCS#12.',
                503,
            );
        }

        $bundle = [];
        while (openssl_error_string() !== false) {
        }
        if (!@openssl_pkcs12_read($pfxBytes, $bundle, $pfxPassword)) {
            $errors = '';
            while (($error = openssl_error_string()) !== false) {
                $errors .= $error . "\n";
            }
            // OpenSSL 3 má šifru RC2 jen v legacy provideru; starší exporty
            // z Windows a některých autorit ji používají. Se špatným heslem
            // to nesouvisí a hláška o hesle by účetní poslala hledat jinde.
            if (str_contains($errors, 'unsupported')) {
                throw new EpoSubmissionException(
                    'certificate_legacy_encryption',
                    'Soubor P12/PFX používá zastaralé šifrování (RC2), které server nepodporuje. '
                    . 'Heslo za tím není. Vyexportujte certifikát znovu se šifrováním AES '
                    . '(ve Windows při exportu volba AES256-SHA256), nebo soubor převeďte '
                    . 'příkazy openssl pkcs12 -in stary.pfx -legacy -nodes -out docasny.pem '
                    . 'a openssl pkcs12 -export -in docasny.pem -out novy.pfx (docasny.pem pak smažte).',
                    400,
                );
            }
            throw new EpoSubmissionException(
                'invalid_certificate',
                'Soubor P12/PFX nelze otevřít. Zkontrolujte soubor a jeho heslo.',
                400,
            );
        }
        $certificate = (string) ($bundle['cert'] ?? '');
        $privateKey = (string) ($bundle['pkey'] ?? '');
        if ($certificate === '' || $privateKey === '') {
            throw new EpoSubmissionException(
                'private_key_missing',
                'Soubor neobsahuje certifikát se soukromým klíčem.',
                400,
            );
        }
        $parsed = openssl_x509_parse($certificate, false);
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        if (!is_array($parsed) || !is_string($fingerprint) || $fingerprint === '') {
            throw new EpoSubmissionException(
                'invalid_certificate',
                'Certifikát se nepodařilo přečíst.',
                400,
            );
        }

        $validFrom = (int) ($parsed['validFrom_time_t'] ?? 0);
        $validTo = (int) ($parsed['validTo_time_t'] ?? 0);
        if ($validFrom <= 0 || $validTo <= 0) {
            throw new EpoSubmissionException(
                'invalid_certificate',
                'Certifikát neobsahuje platné období.',
                400,
            );
        }
        $keyUsage = strtolower((string) (($parsed['extensions']['keyUsage'] ?? '')));
        if ($keyUsage !== '' && !str_contains($keyUsage, 'digital signature')) {
            throw new EpoSubmissionException(
                'certificate_cannot_sign',
                'Certifikát není určen pro elektronický podpis.',
                400,
            );
        }

        $subject = $this->dn((array) ($parsed['subject'] ?? []));
        $issuer = $this->dn((array) ($parsed['issuer'] ?? []));
        $ikMpsvPresent = $this->certificateInspector->containsIkMpsv($certificate, $parsed);
        try {
            $credentialId = $this->credentials->createForSupplier($ownerUserId, $supplierId, [
                'label' => $label,
                'pfx_ciphertext' => $this->crypto->encryptFor(
                    base64_encode($pfxBytes),
                    'epo:credential-pfx',
                ),
                'passphrase_ciphertext' => $this->crypto->encryptFor(
                    $pfxPassword,
                    'epo:credential-passphrase',
                ),
                'fingerprint_sha256' => strtolower(str_replace(':', '', $fingerprint)),
                'subject_dn' => $subject,
                'issuer_dn' => $issuer,
                'serial_hex' => isset($parsed['serialNumberHex'])
                    ? mb_substr((string) $parsed['serialNumberHex'], 0, 128)
                    : null,
                'valid_from' => date('Y-m-d H:i:s', $validFrom),
                'valid_to' => date('Y-m-d H:i:s', $validTo),
                'ik_mpsv_present' => $ikMpsvPresent,
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new EpoSubmissionException(
                    'certificate_exists',
                    'Tento certifikát už je v trezoru uložen.',
                    409,
                );
            }
            throw $e;
        }
        foreach ($this->credentials->listOwnedForSupplier($ownerUserId, $supplierId, $supplierScoped) as $item) {
            if ((int) $item['id'] === $credentialId) {
                return $item;
            }
        }
        throw new EpoSubmissionException(
            'credential_store_failed',
            'Certifikát byl uložen, ale nelze jej znovu načíst.',
            500,
        );
    }

    /**
     * Jediná cesta, kudy se certifikát z trezoru maže (EPO i Elektronické podpisy).
     *
     * Smí jen vlastník; cizí certifikát se hlásí jako nenalezený, aby odpověď
     * neprozradila, že existuje. Podpisový profil, přístup k datové schránce
     * a odesílací brána ISDS smazání blokují: certifikát tam skutečně podepisuje
     * nebo přihlašuje a tiché odpojení by se projevilo až selháním podání.
     * Volba certifikátu pro mzdová podání se zruší v téže transakci.
     *
     * @return array{payroll_selections_removed:list<array{supplier_id:int,environment:string}>}
     * @throws EpoSubmissionException
     */
    public function deleteOwned(int $credentialId, int $ownerUserId): array
    {
        $result = $this->credentials->deleteOwnedUnlessInUse($credentialId, $ownerUserId);
        if ($result['status'] === 'not_found') {
            throw new EpoSubmissionException(
                'credential_not_found',
                'Certifikát nebyl nalezen.',
                404,
            );
        }
        $usage = $result['usage'] ?? [];
        if ($result['status'] === 'in_use') {
            $places = [];
            if (($usage['signing_profiles'] ?? 0) > 0) {
                $places[] = 'podpisový profil (Systém → E-maily a certifikáty → Elektronické podpisy → Podpisové profily)';
            }
            if (($usage['data_box_channels'] ?? 0) > 0) {
                $places[] = 'přístup k datové schránce (Datová schránka)';
            }
            if (($usage['isds_gateways'] ?? 0) > 0) {
                $places[] = 'odesílací brána ISDS (Nastavení odesílací brány)';
            }
            throw new EpoSubmissionException(
                'credential_in_use',
                'Certifikát se ještě používá: ' . implode(', ', $places) . '. Nejdříve jej tam odpojte.',
                409,
                [
                    'linked_profiles_count' => (int) ($usage['signing_profiles'] ?? 0),
                    'linked_data_box_count' => (int) ($usage['data_box_channels'] ?? 0),
                    'linked_isds_gateway_count' => (int) ($usage['isds_gateways'] ?? 0),
                ],
            );
        }

        return ['payroll_selections_removed' => $result['payroll_selections_removed'] ?? []];
    }

    /**
     * @return array{pfx:string,password:string,credential:array<string,mixed>}
     */
    public function unlockForSigning(int $credentialId, int $ownerUserId, int $supplierId): array
    {
        if ($this->crypto->validateKey() !== null) {
            throw new EpoSubmissionException(
                'encryption_key_required',
                'Pro použití soukromého klíče nastavte samostatný app.secret_encryption_key.',
                503,
            );
        }
        $credential = $this->credentials->findUsable($credentialId, $ownerUserId, $supplierId);
        if ($credential === null) {
            throw new EpoSubmissionException(
                'credential_not_found',
                'Certifikát není dostupný pro tuto firmu.',
                404,
            );
        }
        $validFrom = strtotime((string) $credential['valid_from']);
        $validTo = strtotime((string) $credential['valid_to']);
        if ($validFrom === false || $validTo === false || $validFrom > time() || $validTo < time()) {
            throw new EpoSubmissionException(
                'certificate_expired',
                'Certifikát dosud není platný nebo už vypršel.',
                422,
            );
        }
        try {
            $pfx = base64_decode(
                $this->crypto->decryptFor(
                    (string) $credential['pfx_ciphertext'],
                    'epo:credential-pfx',
                ),
                true,
            );
            $password = $this->crypto->decryptFor(
                (string) $credential['passphrase_ciphertext'],
                'epo:credential-passphrase',
            );
        } catch (\RuntimeException) {
            throw new EpoSubmissionException(
                'credential_decryption_failed',
                'Soukromý klíč nelze dešifrovat.',
                500,
            );
        }
        if ($pfx === false || $pfx === '') {
            throw new EpoSubmissionException(
                'credential_decryption_failed',
                'Soukromý klíč nelze dešifrovat.',
                500,
            );
        }
        return ['pfx' => $pfx, 'password' => $password, 'credential' => $credential];
    }

    /** @param array<string,mixed> $dn */
    private function dn(array $dn): string
    {
        $parts = [];
        foreach ($dn as $key => $value) {
            if (is_scalar($value)) {
                $parts[] = $key . '=' . trim((string) $value);
            }
        }
        return mb_substr(implode(', ', $parts), 0, 1000);
    }

    /** @param array<string,mixed> $credential */
    private function storedCertificateContainsIkMpsv(array $credential): bool
    {
        try {
            $pfx = base64_decode(
                $this->crypto->decryptFor(
                    (string) $credential['pfx_ciphertext'],
                    'epo:credential-pfx',
                ),
                true,
            );
            $password = $this->crypto->decryptFor(
                (string) $credential['passphrase_ciphertext'],
                'epo:credential-passphrase',
            );
        } catch (\RuntimeException) {
            return false;
        }
        if (!is_string($pfx) || $pfx === '') {
            return false;
        }
        $bundle = [];
        if (!@openssl_pkcs12_read($pfx, $bundle, $password)) {
            return false;
        }
        $certificate = (string) ($bundle['cert'] ?? '');
        $parsed = $certificate !== '' ? openssl_x509_parse($certificate, false) : false;
        if ($certificate === '' || !is_array($parsed)) {
            return false;
        }
        return $this->certificateInspector->containsIkMpsv($certificate, $parsed);
    }
}
