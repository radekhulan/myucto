<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Repository\Submission\SubmissionChannelCredentialRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\Isds\IsdsClientCertificate;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * Trezor přístupu k datové schránce — VÝHRADNĚ systémový certifikát.
 *
 * Drží se přesně té cesty, kterou projekt používá pro podpisové certifikáty
 * ({@see \MyInvoice\Service\Epo\EpoSigningCredentialService}):
 *   1. brána na klíč — bez `secret_encryption_key` se nic neuloží (503),
 *   2. {@see SecretEncryption::encryptFor()} s vlastním kontextem na pole,
 *   3. odpověď API se čte z projekce, která ciphertext vůbec nevybírá.
 *
 * Kontexty jsou per-pole, takže záměna sloupců v DB nevede k tichému
 * dešifrování cizí hodnoty, ale k chybě.
 *
 * ⚠️ **Jméno a běžné heslo tahle třída nepřijímá ani nepersistuje.** Firemní
 * trezor je výhradně pro systémový certifikát. Jednorázové interaktivní metody
 * obsluhuje `SubmissionInboxAction`; běžné heslo použije jen v právě spuštěném
 * požadavku a SMS mezikrok drží nejvýše po TTL v odděleném šifrovaném flow.
 * Proto v této tabulce nejsou sloupce pro login ani heslo.
 */
final readonly class SubmissionCredentialService
{
    private const CONTEXT_CERTIFICATE = 'isds:credential-certificate';
    private const CONTEXT_PASSPHRASE = 'isds:credential-passphrase';

    private const ENVIRONMENTS = ['production', 'test'];

    public function __construct(
        private SubmissionChannelCredentialRepository $repository,
        private SecretEncryption $crypto,
    ) {}

    /** @return list<array<string,mixed>> Bez tajných hodnot — bezpečné pro API. */
    public function listPublic(int $supplierId): array
    {
        return $this->repository->listPublic($supplierId);
    }

    /**
     * Uloží systémový certifikát. `$certificateBytes` i `$certificatePassphrase`
     * se ihned zašifrují a dál nikam nepokračují.
     *
     * @return array<string,mixed>
     */
    public function save(
        int $supplierId,
        string $environment,
        string $label,
        string $boxId,
        string $certificateBytes,
        ?string $certificatePassphrase,
        ?int $userId,
    ): array {
        $this->assertEncryptionReady();
        $this->assertEnvironment($environment);

        $boxId = strtolower(trim($boxId));
        if (preg_match('/^[a-z0-9]{7}$/', $boxId) !== 1) {
            throw new SubmissionChannelException(
                'invalid_box_id',
                'ID datové schránky má přesně 7 znaků (písmena a číslice). Zkontrolujte ho v Informačním systému datových schránek.',
                400,
            );
        }
        $label = trim($label);
        if ($label === '') {
            throw new SubmissionChannelException('label_required', 'Vyplňte název přístupu.', 400);
        }

        if ($certificateBytes === '') {
            throw new SubmissionChannelException(
                'certificate_required',
                'Nahrajte systémový certifikát k datové schránce (soubor PFX nebo P12). '
                . 'Jméno ani heslo se jako sdílený firemní přístup neukládají; '
                . 'pro jednorázové přihlášení je použijte při ručně spuštěném načtení zpráv.',
                400,
            );
        }

        [$fingerprint, $validTo] = $this->inspectCertificate($certificateBytes, $certificatePassphrase);

        $this->repository->save($supplierId, 'isds', $environment, [
            'label' => mb_substr($label, 0, 120),
            'box_id' => $boxId,
            'certificate_ciphertext' => $this->crypto->encryptFor(
                base64_encode($certificateBytes),
                self::CONTEXT_CERTIFICATE,
            ),
            'certificate_passphrase_ciphertext' => ($certificatePassphrase ?? '') !== ''
                ? $this->crypto->encryptFor((string) $certificatePassphrase, self::CONTEXT_PASSPHRASE)
                : null,
            'certificate_fingerprint' => $fingerprint,
            'certificate_valid_to' => $validTo,
        ], $userId);

        $saved = $this->repository->findPublic($supplierId, 'isds', $environment);
        if ($saved === null) {
            throw new SubmissionChannelException(
                'credential_store_failed',
                'Přístup se uložil, ale nelze ho znovu načíst.',
                500,
            );
        }
        return $saved;
    }

    public function delete(int $supplierId, string $environment): bool
    {
        $this->assertEnvironment($environment);
        return $this->repository->delete($supplierId, 'isds', $environment);
    }

    /**
     * Odemkne přihlášení pro jedno volání kanálu.
     *
     * Tajné hodnoty vystupují výhradně jako {@see SensitiveValue} a vznikají
     * uvnitř producer uzávěry — plaintext se tak nikdy nestane argumentem
     * volání, a tedy ani položkou stack trace.
     */
    public function unlock(int $supplierId, string $environment): ChannelContext
    {
        $this->assertEncryptionReady();
        $this->assertEnvironment($environment);

        $row = $this->repository->findWithSecrets($supplierId, 'isds', $environment);
        if ($row === null) {
            throw new SubmissionChannelException(
                'credentials_missing',
                'Přístup k datové schránce není nastavený. Doplňte systémový certifikát v Firma → Datová schránka.',
                409,
            );
        }

        try {
            $credentials = new ChannelCredentials(
                boxId: (string) $row['box_id'],
                authMode: (string) $row['auth_mode'],
                certificate: $this->reveal($row['certificate_ciphertext'] ?? null, self::CONTEXT_CERTIFICATE),
                certificatePassphrase: $this->reveal($row['certificate_passphrase_ciphertext'] ?? null, self::CONTEXT_PASSPHRASE),
            );
        } catch (\RuntimeException $e) {
            // Původní výjimka se ZÁMĚRNĚ nepředává dál jako `previous`:
            // nesla by v trace ciphertext i kontext. Uživateli to stejně
            // neřekne nic užitečného navíc.
            throw new SubmissionChannelException(
                'credential_decryption_failed',
                'Uložený certifikát k datové schránce se nepodařilo rozšifrovat. '
                . 'Nejspíš se změnil šifrovací klíč — nahrajte certifikát znovu.',
                500,
            );
        }

        return new ChannelContext($supplierId, $environment, $credentials);
    }

    // ───────────────────────── interní ─────────────────────────

    private function reveal(mixed $ciphertext, string $context): ?SensitiveValue
    {
        if (!is_string($ciphertext) || $ciphertext === '') {
            return null;
        }
        $crypto = $this->crypto;
        return SensitiveValue::fromProducer(static fn (): string => $crypto->decryptFor($ciphertext, $context));
    }

    /** @return array{0:?string,1:?string} fingerprint, valid_to */
    private function inspectCertificate(string $bytes, ?string $passphrase): array
    {
        try {
            $clientCertificate = IsdsClientCertificate::fromBase64(base64_encode($bytes), (string) $passphrase);
        } catch (\UnexpectedValueException) {
            throw new SubmissionChannelException(
                'invalid_certificate',
                'Nahraný soubor musí být PKCS#12 (PFX/P12) se soukromým klíčem a správným heslem.',
                400,
            );
        } finally {
            if (isset($clientCertificate)) {
                $clientCertificate->clear();
            }
        }

        $bundle = [];
        if (!@openssl_pkcs12_read($bytes, $bundle, (string) $passphrase)) {
            throw new SubmissionChannelException('invalid_certificate', 'Nahraný PKCS#12 certifikát se nepodařilo znovu načíst.', 400);
        }
        $certificate = (string) ($bundle['cert'] ?? '');

        $parsed = @openssl_x509_parse($certificate, false);
        $fingerprint = @openssl_x509_fingerprint($certificate, 'sha256');
        if (!is_array($parsed) || !is_string($fingerprint) || $fingerprint === '') {
            throw new SubmissionChannelException(
                'invalid_certificate',
                'Nahraný soubor se nepodařilo přečíst jako certifikát. Zkontrolujte soubor a jeho heslo.',
                400,
            );
        }

        $validTo = (int) ($parsed['validTo_time_t'] ?? 0);
        return [
            strtolower(str_replace(':', '', $fingerprint)),
            $validTo > 0 ? date('Y-m-d H:i:s', $validTo) : null,
        ];
    }

    private function assertEncryptionReady(): void
    {
        if ($this->crypto->validateKey() !== null) {
            throw new SubmissionChannelException(
                'encryption_key_required',
                'Pro uložení přihlášení k datové schránce nastavte cfg.app.secret_encryption_key.',
                503,
            );
        }
    }

    private function assertEnvironment(string $environment): void
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new SubmissionChannelException('invalid_environment', 'Neznámé prostředí.', 400);
        }
    }
}
