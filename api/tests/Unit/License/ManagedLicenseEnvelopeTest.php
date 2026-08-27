<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\License;

use MyInvoice\Service\License\LicenseTokenVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Obálka, kterou provozovatel doručuje licenci do spravované instalace.
 *
 * Spravovanou instalaci dostává zákazník hotovou a licenční klíč nemá kam
 * opsat. První zaplacená hostovaná objednávka proto naběhla na zkušební
 * období: klíč se do instance nikdy nedostal. Doručení je teď součástí
 * zřízení a pro běžící instalaci existuje `POST /api/managed/license`.
 *
 * ⚠️ Autentizace je Ed25519 podpis, ne sdílené heslo. Tenhle test drží
 * kontrakt obálky — kdyby se rozešel s licenčním serverem, instalace by
 * doručení tiše odmítala.
 */
final class ManagedLicenseEnvelopeTest extends TestCase
{
    /** @return array{0:string,1:string} [veřejný klíč base64, tajný klíč syrový] */
    private function keypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            base64_encode(sodium_crypto_sign_publickey($pair)),
            sodium_crypto_sign_secretkey($pair),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function envelope(array $payload, string $secretKey): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sig = sodium_crypto_sign_detached((string) $json, $secretKey);

        return $this->b64url((string) $json) . '.' . $this->b64url($sig);
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'purpose'     => 'managed_license',
            'instance_id' => 'a6a6ff99-d014-4dc2-9ada-7b438550377d',
            'license_key' => 'MYU-AAAA-BBBB-CCCC-DDDD',
            'iat'         => time(),
        ];
    }

    public function testSignedEnvelopeVerifies(): void
    {
        [$public, $secret] = $this->keypair();

        $decoded = (new LicenseTokenVerifier())->verify($this->envelope($this->payload(), $secret), $public);

        self::assertNotNull($decoded, 'správně podepsaná obálka musí projít');
        self::assertSame('managed_license', $decoded['purpose']);
        self::assertSame('MYU-AAAA-BBBB-CCCC-DDDD', $decoded['license_key']);
    }

    public function testEnvelopeSignedByForeignKeyIsRejected(): void
    {
        [$public] = $this->keypair();
        [, $foreignSecret] = $this->keypair();

        // Kdo nemá podepisovací klíč licenčního serveru, nesmí instalaci vnutit
        // žádnou licenci — ani svoji vlastní, ani cizí.
        $decoded = (new LicenseTokenVerifier())->verify($this->envelope($this->payload(), $foreignSecret), $public);

        self::assertNull($decoded);
    }

    public function testTamperedAddresseeIsRejected(): void
    {
        [$public, $secret] = $this->keypair();
        $envelope = $this->envelope($this->payload(), $secret);

        // Přepsání adresáta na cizí instalaci — přesně to, čemu podpis brání.
        [$payloadPart, $signaturePart] = explode('.', $envelope, 2);
        $payload = json_decode((string) base64_decode(strtr($payloadPart, '-_', '+/')), true);
        $payload['instance_id'] = 'cizi-instalace';
        $forged = $this->b64url((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            . '.' . $signaturePart;

        self::assertNull((new LicenseTokenVerifier())->verify($forged, $public));
    }

    public function testGarbageIsRejectedWithoutThrowing(): void
    {
        [$public] = $this->keypair();
        $verifier = new LicenseTokenVerifier();

        self::assertNull($verifier->verify('', $public));
        self::assertNull($verifier->verify('bez-tecky', $public));
        self::assertNull($verifier->verify('a.b', $public), 'krátký podpis nesmí shodit sodium');
    }
}
