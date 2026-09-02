<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Service\Submission\Channel\SensitiveValue;

/**
 * Certifikát vytažený ze sdíleného trezoru (`epo_signing_credentials`),
 * připravený k použití jedním voláním.
 *
 * Tajemství jsou výhradně {@see SensitiveValue}, tedy handle do trezoru —
 * nikdy plaintext v property. Metadata (popisek, subject, platnost, otisk)
 * jsou veřejná; ta se smějí do odpovědi API i do chybové hlášky.
 *
 * `certificate` drží **base64 PKCS#12**, ne syrové bajty. Je to týž tvar,
 * jaký vrací ciphertextová větev ISDS (`base64_encode()` před zašifrováním),
 * takže se obě cesty potkají beze změny konzumenta.
 */
final readonly class SharedCertificate
{
    public function __construct(
        public int $credentialId,
        public string $label,
        public ?string $subject,
        public ?string $fingerprint,
        public ?string $validFrom,
        public ?string $validTo,
        public SensitiveValue $certificate,
        public ?SensitiveValue $passphrase,
    ) {}

    /** Už vypršel? Prázdná platnost = neznámo, a to není důvod blokovat. */
    public function isExpired(?int $now = null): bool
    {
        if ($this->validTo === null || $this->validTo === '') {
            return false;
        }
        $validTo = strtotime($this->validTo);

        return $validTo !== false && $validTo < ($now ?? time());
    }

    /**
     * Serializace by handle vytáhla mimo trezor — stejné pravidlo jako
     * u {@see \MyInvoice\Service\Submission\Channel\ChannelCredentials}.
     */
    public function __serialize(): array
    {
        throw new \LogicException('Certifikát ze sdíleného trezoru nelze serializovat.');
    }
}
