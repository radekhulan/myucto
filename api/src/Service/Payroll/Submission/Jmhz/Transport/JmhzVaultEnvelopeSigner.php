<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Epo\EpoPkcs7Signer;
use MyInvoice\Service\Signing\PersonalCertificateVaultService;

/**
 * Podepsání obálky JMHZ certifikátem z osobního trezoru.
 *
 * Trezor je v aplikaci **jeden** (`epo_signing_credentials`) a plní se z EPO
 * konfigurace; čte z něj už podpis e-mailů i PDF. Mzdy pro něj dosud neměly
 * konzumenta, takže se certifikát nikam znovu nenahrává — jen se zapojuje.
 *
 * Podepisuje se CMS/PKCS#7 stejně jako u EPO. Je to doložený mechanismus na
 * PŘÍCHOZÍ straně (protokol ČSSZ nese podpis jako base64 PKCS#7 v
 * `Message/Header/Signature/SignatureValue`) a stejný, jaký používá e-Podání
 * na daňovém portálu. Pro ODCHOZÍ obálku VREP to zůstává nedoložené — proto
 * tahle třída obálku jen podepíše a nikde netvrdí, že je tím odesílatelná;
 * to rozhoduje `JmhzGovTalkEnvelope` a jeho `JmhzGovTalkRequestShape`.
 *
 * Dvě pojistky navíc, obě fail-closed:
 *
 * 1. **Platnost certifikátu se ověřuje před podpisem.** Podepsat prošlým
 *    certifikátem znamená podání, které ČSSZ odmítne — a lhůta mezitím běží.
 * 2. **Sériové číslo se porovnává s tím, které je u ČSSZ zaregistrované.**
 *    V trezoru může být víc certifikátů; podepsat tím nesprávným vypadá jako
 *    úspěch až do protokolu.
 */
final readonly class JmhzVaultEnvelopeSigner implements JmhzEnvelopeSignerInterface
{
    public function __construct(
        private PersonalCertificateVaultService $vault,
        private SecretEncryption $secrets,
        private int $credentialId,
        private int $ownerUserId,
        private int $supplierId,
        /**
         * Sériové číslo certifikátu zaregistrovaného u ČSSZ. `null` znamená,
         * že shodu neověřujeme — použitelné jen v testech.
         */
        private ?string $registeredSerialNumber = null,
        private EpoPkcs7Signer $signer = new EpoPkcs7Signer(),
        private ?string $today = null,
    ) {}

    public function sign(string $envelopeXml): string
    {
        if (trim($envelopeXml) === '') {
            throw new JmhzTransportException(
                'jmhz_signing_empty_payload',
                'Prázdnou obálku nelze podepsat.',
            );
        }
        $material = $this->unlock();

        return $this->signer->sign(
            $envelopeXml,
            $material['pfx'],
            $material['password'],
        );
    }

    /**
     * Ověřený klíčový materiál pro vrstvy, které si podpis staví samy — podpis
     * datové věty uvnitř ČSSZ obálky potřebuje odpojený PKCS#7 nad PŮVODNÍMI
     * bajty, ne nad obálkou, takže si ho `JmhzGovTalkEnvelope::seal()` dělá
     * vlastní cestou.
     *
     * Materiál se vydává jen přes tuhle metodu právě proto, aby ty pojistky
     * nešlo obejít: platnost certifikátu i shoda s registrací u ČSSZ se ověří
     * pokaždé, ať se podepisuje odkudkoli.
     *
     * @return array{pfx:string,password:string,credential:array<string,mixed>}
     */
    public function unlock(): array
    {
        $resolved = $this->vault->resolve(
            $this->credentialId,
            $this->ownerUserId,
            $this->supplierId,
        );
        $this->assertUsable($resolved);

        return [
            'pfx' => $resolved['pfx'],
            'password' => $this->secrets->decrypt($resolved['password_enc']),
            'credential' => $resolved['credential'],
        ];
    }

    /** @param array<string,mixed> $resolved */
    private function assertUsable(array $resolved): void
    {
        $today = $this->today ?? (new \DateTimeImmutable(
            'now',
            new \DateTimeZone('Europe/Prague'),
        ))->format('Y-m-d');

        $validTo = $resolved['certificate_valid_to'] ?? null;
        if (is_string($validTo) && $validTo !== '' && strcmp(substr($validTo, 0, 10), $today) < 0) {
            throw new JmhzTransportException(
                'jmhz_signing_certificate_expired',
                "Podpisový certifikát vypršel {$validTo}; podání by ČSSZ odmítla.",
            );
        }
        $validFrom = $resolved['certificate_valid_from'] ?? null;
        if (is_string($validFrom) && $validFrom !== ''
            && strcmp(substr($validFrom, 0, 10), $today) > 0
        ) {
            throw new JmhzTransportException(
                'jmhz_signing_certificate_not_yet_valid',
                "Podpisový certifikát platí až od {$validFrom}.",
            );
        }

        if ($this->registeredSerialNumber === null) {
            return;
        }
        $credential = $resolved['credential'] ?? null;
        $serial = is_array($credential) ? ($credential['serial_hex'] ?? null) : null;
        if (!is_string($serial) || $serial === '') {
            throw new JmhzTransportException(
                'jmhz_signing_certificate_unidentified',
                'Certifikát v trezoru nemá sériové číslo, takže ho nelze ověřit'
                    . ' proti registraci u ČSSZ.',
            );
        }
        if (CsszCertificateSerialNumber::normalizeRegisteredInput(
            $this->registeredSerialNumber,
        ) === null) {
            throw new JmhzTransportException(
                'jmhz_signing_serial_unreadable',
                'Sériové číslo certifikátu není v čitelném tvaru.',
            );
        }
        if (!CsszCertificateSerialNumber::matches(
            $serial,
            $this->registeredSerialNumber,
        )) {
            throw new JmhzTransportException(
                'jmhz_signing_certificate_not_registered',
                'Certifikát v trezoru není ten, který je u ČSSZ zaregistrovaný.',
            );
        }
    }

}
