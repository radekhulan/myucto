<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Repository\EpoSigningCredentialRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * Jedno místo, které z `credential_id` udělá použitelný certifikát.
 *
 * ── Proč to vzniklo ─────────────────────────────────────────────────────────
 * Aplikace má JEDEN trezor certifikátů: `epo_signing_credentials`, plněný přes
 * Systém → Elektronické podpisy ({@see \MyInvoice\Action\Settings\CertificateVaultAction}).
 * Mzdová podání na ČSSZ z něj jen VYBÍRAJÍ
 * ({@see \MyInvoice\Action\Payroll\PayrollJmhzSigningProfileAction}). ISDS si
 * ale dosud držel vlastní kopie ve dvou tabulkách, takže týž certifikát
 * uživatel nahrával podruhé — a při obnově ho musel vyměnit na víc místech,
 * aniž by mu kterékoli z nich řeklo, že ta ostatní jsou prošlá.
 *
 * ── Co tahle třída dělá a co ne ─────────────────────────────────────────────
 * Dělá přesně to, co ciphertextová větev ISDS: vrátí dvojici
 * {@see SensitiveValue} (certifikát + heslo) a k tomu veřejná metadata.
 * Nedělá ŽÁDNÉ rozhodnutí o tom, jestli se smí podepsat — expirace, aktivace
 * brány i prostředí zůstávají na volajícím, protože každý kanál má jiné
 * pravidlo. {@see SharedCertificate::isExpired()} je jen dotaz, ne zákaz.
 *
 * ── Tvar tajemství ──────────────────────────────────────────────────────────
 * Trezor ukládá `base64_encode($pfx)` pod kontextem `epo:credential-pfx`
 * a heslo pod `epo:credential-passphrase`
 * ({@see \MyInvoice\Service\Epo\EpoSigningCredentialService::import()}).
 * ISDS ukládá do svých sloupců rovněž base64 PKCS#12, jen pod vlastním
 * kontextem — konzument je tedy shodný a větev navíc nic nepřepočítává.
 *
 * ── Fail-closed ─────────────────────────────────────────────────────────────
 * Osiřelý (smazaný) i měkce smazaný odkaz, certifikát nepovolený pro firmu
 * i nerozšifrovatelný ciphertext končí POJMENOVANOU výjimkou. Nikdy se
 * nepokračuje na prázdno — prázdný certifikát by se u ISDS projevil až
 * nesrozumitelným TLS selháním, typicky v den termínu.
 */
final readonly class SharedCertificateResolver
{
    /** Kontexty musí sedět na {@see \MyInvoice\Service\Epo\EpoSigningCredentialService}. */
    private const CONTEXT_CERTIFICATE = 'epo:credential-pfx';
    private const CONTEXT_PASSPHRASE = 'epo:credential-passphrase';

    public function __construct(
        private EpoSigningCredentialRepository $credentials,
        private SecretEncryption $crypto,
    ) {}

    /**
     * Metadata certifikátů, ze kterých smí uživatel u téhle firmy vybírat.
     *
     * Nikdy nesahá na ciphertext — vrací jen to, co se smí objevit v odpovědi
     * API: popisek, subject, otisk a platnost. Filtruje na ty, které vlastník
     * pro firmu povolil; nabízet ostatní by znamenalo nabídnout volbu, kterou
     * odemykání vzápětí odmítne.
     *
     * @return list<array{
     *   id:int,label:string,subject:?string,fingerprint:?string,
     *   valid_from:?string,valid_to:?string,expired:bool,valid_now:bool
     * }>
     */
    public function listUsable(int $ownerUserId, int $supplierId, ?int $now = null): array
    {
        $now ??= time();
        $items = [];
        foreach ($this->credentials->listOwnedForSupplier($ownerUserId, $supplierId) as $row) {
            if (($row['enabled_for_supplier'] ?? false) !== true) {
                continue;
            }
            $validTo = $this->nullableString($row['valid_to'] ?? null);
            $validToTs = $validTo !== null ? strtotime($validTo) : false;
            $items[] = [
                'id' => (int) $row['id'],
                'label' => (string) ($row['label'] ?? ''),
                'subject' => $this->nullableString($row['subject_dn'] ?? null),
                'fingerprint' => $this->nullableString($row['fingerprint_sha256'] ?? null),
                'valid_from' => $this->nullableString($row['valid_from'] ?? null),
                'valid_to' => $validTo,
                'expired' => $validToTs !== false && $validToTs < $now,
                'valid_now' => ($row['valid_now'] ?? false) === true,
            ];
        }

        return $items;
    }

    /**
     * Veřejná metadata jednoho certifikátu — pro projekci, která ciphertext
     * vůbec nevybírá. `null`, když odkaz osiřel; volající rozhodne, jestli je
     * to chyba (odemykání) nebo jen varování v kartě (výpis).
     *
     * @return array{
     *   id:int,label:string,subject:?string,fingerprint:?string,
     *   valid_from:?string,valid_to:?string
     * }|null
     */
    public function metadata(int $credentialId): ?array
    {
        if ($credentialId <= 0) {
            return null;
        }
        $row = $this->credentials->findShared($credentialId);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'label' => (string) ($row['label'] ?? ''),
            'subject' => $this->nullableString($row['subject_dn'] ?? null),
            'fingerprint' => $this->nullableString($row['fingerprint_sha256'] ?? null),
            'valid_from' => $this->nullableString($row['valid_from'] ?? null),
            'valid_to' => $this->nullableString($row['valid_to'] ?? null),
        ];
    }

    /**
     * Odemkne certifikát z trezoru pro jedno volání.
     *
     * `$supplierId` je `null` jen tam, kde firma neexistuje — registrace
     * odesílací brány je instalačně globální (jedna na prostředí, bez
     * `supplier_id`). Všude jinde se předává a kontroluje.
     *
     * @throws SubmissionChannelException
     */
    public function resolve(int $credentialId, ?int $supplierId): SharedCertificate
    {
        $this->assertEncryptionReady();

        if ($credentialId <= 0) {
            throw new SubmissionChannelException(
                'shared_certificate_invalid_reference',
                'Odkaz na certifikát ve sdíleném trezoru není platný.',
                500,
            );
        }

        $row = $this->credentials->findShared($credentialId);
        if ($row === null) {
            // Osiřelý odkaz. Vazba je schválně jen index, ne cizí klíč (trezor
            // maže měkce), takže tohle je jediné místo, kde se to pozná.
            throw new SubmissionChannelException(
                'shared_certificate_missing',
                'Vybraný certifikát už ve sdíleném trezoru není. '
                . 'Vyberte v Systém → Elektronické podpisy jiný, nebo ho nahrajte znovu.',
                409,
            );
        }

        if ($supplierId !== null && !$this->credentials->isEnabledForSupplier($credentialId, $supplierId)) {
            throw new SubmissionChannelException(
                'shared_certificate_not_enabled',
                'Vybraný certifikát není pro tuhle firmu povolený. '
                . 'Povolte ho v Systém → Elektronické podpisy, nebo vyberte jiný.',
                409,
            );
        }

        $crypto = $this->crypto;
        $pfxCiphertext = (string) ($row['pfx_ciphertext'] ?? '');
        $passphraseCiphertext = (string) ($row['passphrase_ciphertext'] ?? '');

        try {
            $certificate = SensitiveValue::fromProducer(
                static fn (): string => $crypto->decryptFor($pfxCiphertext, self::CONTEXT_CERTIFICATE),
            );
            $passphrase = $passphraseCiphertext !== ''
                ? SensitiveValue::fromProducer(
                    static fn (): string => $crypto->decryptFor($passphraseCiphertext, self::CONTEXT_PASSPHRASE),
                )
                : null;
        } catch (\RuntimeException) {
            // Původní výjimka se ZÁMĚRNĚ nepředává dál jako `previous`: nesla
            // by v trace ciphertext i šifrovací kontext. Uživateli to navíc
            // neřekne nic užitečného — viz `credential_decryption_failed`
            // v SubmissionCredentialService.
            throw new SubmissionChannelException(
                'shared_certificate_decryption_failed',
                'Certifikát ze sdíleného trezoru se nepodařilo rozšifrovat. '
                . 'Nejspíš se změnil šifrovací klíč — nahrajte certifikát znovu.',
                500,
            );
        }

        if ($certificate->isEmpty()) {
            throw new SubmissionChannelException(
                'shared_certificate_decryption_failed',
                'Certifikát ze sdíleného trezoru se nepodařilo rozšifrovat. '
                . 'Nejspíš se změnil šifrovací klíč — nahrajte certifikát znovu.',
                500,
            );
        }

        return new SharedCertificate(
            credentialId: (int) $row['id'],
            label: (string) ($row['label'] ?? ''),
            subject: $this->nullableString($row['subject_dn'] ?? null),
            fingerprint: $this->nullableString($row['fingerprint_sha256'] ?? null),
            validFrom: $this->nullableString($row['valid_from'] ?? null),
            validTo: $this->nullableString($row['valid_to'] ?? null),
            certificate: $certificate,
            passphrase: $passphrase,
        );
    }

    private function assertEncryptionReady(): void
    {
        if ($this->crypto->validateKey() !== null) {
            throw new SubmissionChannelException(
                'encryption_key_required',
                'Pro použití certifikátu ze sdíleného trezoru nastavte cfg.app.secret_encryption_key.',
                503,
            );
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
