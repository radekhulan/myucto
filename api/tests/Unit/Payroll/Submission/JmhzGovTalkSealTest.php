<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use DOMDocument;
use DOMXPath;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDetachedSigner;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzEnvelopeSignerInterface;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkRequestShape;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use PHPUnit\Framework\TestCase;

/**
 * Zapečetění obálky je jediná metoda, jejíž výstup jde ven na ČSSZ. Přesně
 * tenhle tvar testovací VREP převzal, takže se tady hlídá, že se v něm nic
 * nerozjede — nejen že to „nespadne".
 *
 * Podepisuje se efemérním certifikátem vyrobeným v testu; skutečný podpisový
 * klíč se do sady nikdy nedostane.
 */
final class JmhzGovTalkSealTest extends TestCase
{
    private const NS_CSSZ = 'http://www.cssz.cz/XMLSchema/envelope';

    protected function setUp(): void
    {
        if (!function_exists('openssl_cms_sign') || !function_exists('openssl_cms_encrypt')) {
            self::markTestSkipped('Server nepodporuje CMS.');
        }
    }

    public function testSealedEnvelopeHasTheShapeVrepAccepted(): void
    {
        $dom = $this->sealedDom();
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('g', JmhzGovTalkEnvelope::NS_GOVTALK);
        $xpath->registerNamespace('c', self::NS_CSSZ);

        self::assertSame(
            'request',
            $this->text($xpath, '//g:Header/g:MessageDetails/g:Qualifier'),
        );
        self::assertSame(
            'submit',
            $this->text($xpath, '//g:Header/g:MessageDetails/g:Function'),
        );
        self::assertSame(
            JmhzTransportSample::VARIABLE_SYMBOL,
            $this->text($xpath, '//g:GovTalkDetails/g:Keys/g:Key[@Type="vars"]'),
        );
        // CorrelationID přiděluje až VREP; při odeslání musí být prázdné.
        self::assertSame(
            '',
            $this->text($xpath, '//g:Header/g:MessageDetails/g:CorrelationID'),
        );

        $message = $xpath->query('//c:Message')->item(0);
        self::assertInstanceOf(\DOMElement::class, $message);
        self::assertSame('1.2', $message->getAttribute('version'));
        self::assertSame('JMHZ25', $message->getAttribute('eType'));
    }

    /**
     * Podepisují se PŮVODNÍ bajty datové věty, ne zašifrované tělo. Kdyby se
     * pořadí obrátilo, ČSSZ by podpis neměla proti čemu ověřit — a poznalo by
     * se to až odmítnutým podáním.
     */
    public function testSignatureVerifiesAgainstThePlainPayload(): void
    {
        $material = self::certificate();
        $payload = JmhzTransportSample::payload();
        $dom = $this->sealedDom($payload, $material);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('c', self::NS_CSSZ);

        $signature = base64_decode(
            $this->text($xpath, '//c:Header/c:Signature'),
            true,
        );
        self::assertIsString($signature);
        self::assertNotSame('', $signature);

        $root = tempnam(sys_get_temp_dir(), 'jmhz-root-');
        $signatureFile = tempnam(sys_get_temp_dir(), 'jmhz-sig-');
        $contentFile = tempnam(sys_get_temp_dir(), 'jmhz-body-');
        try {
            file_put_contents((string) $root, $material['cert']);
            file_put_contents((string) $signatureFile, $signature);
            // Podpis je odpojený: obsah se předává zvlášť. Podepsané jsou
            // tytéž bajty, které serializér vydal, ne jejich přeformátování.
            file_put_contents((string) $contentFile, $payload);

            // U odpojeného podpisu je vstupem OBSAH a podpis se předává zvlášť
            // jako `sigfile` — přesně tak, jak ho ověřuje protistrana.
            self::assertTrue(openssl_cms_verify(
                (string) $contentFile,
                OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED,
                null,
                [(string) $root],
                null,
                null,
                null,
                (string) $signatureFile,
                OPENSSL_ENCODING_DER,
            ));
        } finally {
            foreach ([$root, $signatureFile, $contentFile] as $file) {
                if (is_string($file) && is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * PREZEC i REGZEC jsou samostatné doložené třídy GovTalk. Registrační
     * artefakt se nesmí před podpisem znovu serializovat: i XML deklarace a
     * konce řádků jsou součástí zmrazených bajtů a jejich SHA-256.
     */
    public function testPrezecSignatureVerifiesAgainstTheExactFrozenBytes(): void
    {
        $material = self::certificate();
        $payload = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\r\n"
            . '<PREZEC xmlns="http://schemas.cssz.cz/PREZEC/2026">'
            . '<employees><employee act="9"><comp vs="1234567890"/></employee></employees>'
            . '</PREZEC>';
        $dom = $this->sealedDom($payload, $material, 'CSSZ_PREZEC');
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('c', self::NS_CSSZ);
        $signature = base64_decode(
            $this->text($xpath, '//c:Header/c:Signature'),
            true,
        );
        self::assertIsString($signature);

        $root = tempnam(sys_get_temp_dir(), 'prezec-root-');
        $signatureFile = tempnam(sys_get_temp_dir(), 'prezec-sig-');
        $contentFile = tempnam(sys_get_temp_dir(), 'prezec-body-');
        try {
            file_put_contents((string) $root, $material['cert']);
            file_put_contents((string) $signatureFile, $signature);
            file_put_contents((string) $contentFile, $payload);

            self::assertTrue(openssl_cms_verify(
                (string) $contentFile,
                OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED,
                null,
                [(string) $root],
                null,
                null,
                null,
                (string) $signatureFile,
                OPENSSL_ENCODING_DER,
            ));
        } finally {
            foreach ([$root, $signatureFile, $contentFile] as $file) {
                if (is_string($file) && is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /** Tělo jde ven zašifrované — nezašifrovaná datová věta v něm být nesmí. */
    public function testBodyDoesNotLeakThePayload(): void
    {
        $sealed = $this->sealed();

        self::assertStringNotContainsString('variabilniSymbol', $sealed);
        self::assertStringNotContainsString('hlavicka', $sealed);
    }

    public function testSealedDocumentIsSendableWithoutAnotherSigningLayer(): void
    {
        $document = $this->sealedDocument();

        self::assertTrue($document->sealed);
        self::assertSame($document->unsignedXml, $document->sendableXml(null));
    }

    public function testSealedDocumentRefusesASecondSignature(): void
    {
        $signer = new class implements JmhzEnvelopeSignerInterface {
            public function sign(string $envelopeXml): string
            {
                return $envelopeXml;
            }
        };

        $this->expectException(JmhzTransportException::class);
        $this->expectExceptionMessageMatches('/už podpis nese/');

        $this->sealedDocument()->sendableXml($signer);
    }

    /** @param array{cert:string,pfx:string,password:string}|null $material */
    private function sealedDocument(
        ?string $payload = null,
        ?array $material = null,
        string $submissionClass = 'CSSZ_JMHZ',
    ): \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkDocument {
        $material ??= self::certificate();

        return (new JmhzGovTalkEnvelope(JmhzGovTalkRequestShape::documented()))->seal(
            $payload ?? JmhzTransportSample::payload(),
            JmhzTransportSample::VARIABLE_SYMBOL,
            $submissionClass,
            'test',
            new JmhzSoftwareIdentification('MyUcto', '1.0'),
            new JmhzDetachedSigner(),
            $material['pfx'],
            $material['password'],
        );
    }

    /** @param array{cert:string,pfx:string,password:string}|null $material */
    private function sealed(
        ?string $payload = null,
        ?array $material = null,
        string $submissionClass = 'CSSZ_JMHZ',
    ): string
    {
        return $this->sealedDocument($payload, $material, $submissionClass)->unsignedXml;
    }

    /** @param array{cert:string,pfx:string,password:string}|null $material */
    private function sealedDom(
        ?string $payload = null,
        ?array $material = null,
        string $submissionClass = 'CSSZ_JMHZ',
    ): DOMDocument
    {
        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($this->sealed($payload, $material, $submissionClass)));

        return $dom;
    }

    private function text(DOMXPath $xpath, string $expression): string
    {
        $node = $xpath->query($expression)->item(0);

        return $node === null ? '' : trim($node->textContent);
    }

    /** @return array{cert:string,pfx:string,password:string} */
    private static function certificate(): array
    {
        static $material = null;
        if (is_array($material)) {
            return $material;
        }
        // OpenSSL na Windows nemusí mít openssl.cnf na očekávaném místě a bez
        // něj klíč nevyrobí. Test si proto nese vlastní minimální konfiguraci,
        // aby nezáviselo na tom, jak je stroj poskládaný.
        $config = self::opensslConfig();
        $options = ['config' => $config];
        $key = openssl_pkey_new($options + [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key, self::opensslErrors());
        $csr = openssl_csr_new(
            ['commonName' => 'JMHZ Test', 'countryName' => 'CZ'],
            $key,
            $options + ['digest_alg' => 'sha256'],
        );
        self::assertNotFalse($csr, self::opensslErrors());
        $certificate = openssl_csr_sign(
            $csr,
            null,
            $key,
            1,
            $options + ['digest_alg' => 'sha256'],
        );
        self::assertNotFalse($certificate, self::opensslErrors());
        openssl_x509_export($certificate, $pem);
        $password = 'jmhz-test';
        self::assertTrue(
            openssl_pkcs12_export($certificate, $pfx, $key, $password),
            self::opensslErrors(),
        );

        return $material = [
            'cert' => (string) $pem,
            'pfx' => (string) $pfx,
            'password' => $password,
        ];
    }

    private static function opensslConfig(): string
    {
        static $path = null;
        if (is_string($path)) {
            return $path;
        }
        $file = tempnam(sys_get_temp_dir(), 'jmhz-openssl-');
        self::assertIsString($file);
        file_put_contents(
            $file,
            "[req]\ndistinguished_name = dn\n[dn]\n[v3_ca]\n",
        );
        register_shutdown_function(static function () use ($file): void {
            if (is_file($file)) {
                unlink($file);
            }
        });

        return $path = $file;
    }

    private static function opensslErrors(): string
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors === [] ? 'OpenSSL nehlásí chybu.' : implode('; ', $errors);
    }
}
