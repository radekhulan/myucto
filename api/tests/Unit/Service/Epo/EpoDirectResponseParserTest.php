<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Epo;

use MyInvoice\Service\Epo\EpoDirectResponseParser;
use PHPUnit\Framework\TestCase;

final class EpoDirectResponseParserTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];
    private ?string $lastCertificatePath = null;

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    public function testAcceptsOfficialTestMarkerAndPreservesWarnings(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Chyby>
  <Chyba Typ="P" Zkr="WARN"><Text>Propustná kontrola.</Text></Chyba>
  <Chyba Typ="I" Zkr="TEST_REZIM"><Text>Podání nebylo přijato, protože bylo odesláno v testovacím režimu.</Text></Chyba>
</Chyby>
XML;

        $result = (new EpoDirectResponseParser())->testResult($xml);

        self::assertTrue($result['passed']);
        self::assertCount(2, $result['messages']);
        self::assertSame('WARN', $result['messages'][0]['code']);
    }

    public function testBlocksStructuralAndCriticalTestErrors(): void
    {
        $xml = <<<'XML'
<Chyby>
  <Chyba Typ="S" Zkr="SCHEMA"><Text>Chyba struktury.</Text></Chyba>
  <Chyba Typ="K" Zkr="FIELD" Polozka="dic"><Text>Chybí DIČ.</Text></Chyba>
  <Chyba Typ="I" Zkr="TEST_REZIM"><Text>Testovací režim.</Text></Chyba>
</Chyby>
XML;

        $result = (new EpoDirectResponseParser())->testResult($xml);

        self::assertFalse($result['passed']);
        self::assertSame('dic', $result['messages'][1]['field']);
    }

    /**
     * Nepropustná chyba (`Typ="N"`) podání zastaví — ostrý EPO ho s ní
     * nepřijme. Dřív se propadala mezi propustné a zkušební běh hlásil
     * „prošlo" u výkazu, který ve skutečnosti projít nemůže; to je horší než
     * chyba, protože brána svítí zeleně.
     */
    public function testBlocksImpassableTestErrors(): void
    {
        $xml = <<<'XML'
<Chyby>
  <Chyba Typ="N" Zkr="KONTROLA" Radek="13"><Text>Jedná se o dodatečné VDA, na ř. 13 Části I. hodnota Sl.10 musí být &lt;&gt; 0.</Text></Chyba>
  <Chyba Typ="P" Zkr="WARN"><Text>Číslo územního pracoviště není vyplněno.</Text></Chyba>
  <Chyba Typ="I" Zkr="TEST_REZIM"><Text>Testovací režim.</Text></Chyba>
</Chyby>
XML;

        $result = (new EpoDirectResponseParser())->testResult($xml);

        self::assertFalse($result['passed']);
        self::assertSame('13', $result['messages'][0]['line']);
    }

    /** Propustná chyba sama o sobě podání nebrání — zůstává „prošlo". */
    public function testPassableErrorsAloneDoNotBlock(): void
    {
        $xml = <<<'XML'
<Chyby>
  <Chyba Typ="P" Zkr="WARN"><Text>Zadané datum není pracovní den.</Text></Chyba>
  <Chyba Typ="I" Zkr="TEST_REZIM"><Text>Testovací režim.</Text></Chyba>
</Chyby>
XML;

        self::assertTrue((new EpoDirectResponseParser())->testResult($xml)['passed']);
    }

    /**
     * Ostrá cesta se musí rozhodovat stejně jako zkušební: obálka `Chyby`, ve které jsou
     * jen PROPUSTNÉ (`P`) a informativní (`I`) zprávy, není odmítnutí. Konkrétně jde
     * o chyby č. 58 / č. 60 u KH oddílu A.2 s dodavatelem bez EU DIČ — GFŘ je výslovně
     * označuje za propustné a podání nebrání (issue #53). Brát je jako `rejected`
     * by uživatele svedlo k opakovanému odeslání přijatého výkazu.
     */
    public function testPassableSubmitEnvelopeIsNotFlaggedAsBlocking(): void
    {
        $xml = <<<'XML'
<Chyby>
  <Chyba Typ="P" Zkr="60" Oddil="A.2"><Text>Není vyplněno VAT ID dodavatele.</Text></Chyba>
  <Chyba Typ="P" Zkr="58" Oddil="A.2"><Text>Není vyplněn kód státu dodavatele.</Text></Chyba>
</Chyby>
XML;

        $result = (new EpoDirectResponseParser())->submitEnvelope($xml);

        self::assertSame('errors', $result['kind']);
        self::assertFalse($result['blocking'], 'propustné chyby 58/60 nesmí platit za odmítnutí');
        self::assertCount(2, $result['messages']);
    }

    public function testBlockingSubmitEnvelopeStaysBlocking(): void
    {
        $xml = <<<'XML'
<Chyby>
  <Chyba Typ="P" Zkr="60"><Text>Není vyplněno VAT ID dodavatele.</Text></Chyba>
  <Chyba Typ="K" Zkr="FIELD"><Text>Chybí DIČ podávajícího.</Text></Chyba>
</Chyby>
XML;

        $result = (new EpoDirectResponseParser())->submitEnvelope($xml);

        self::assertSame('errors', $result['kind']);
        self::assertTrue($result['blocking'], 'kritická chyba musí zůstat blokující i vedle propustné');
    }

    /**
     * Potvrzení se hledá DŘÍV než kořen `Chyby` — přijaté podání, jehož protokol nese
     * propustné chyby, se nesmí vyhodnotit jako odmítnuté.
     */
    public function testConfirmationWinsOverPassableErrorsInSameEnvelope(): void
    {
        $result = (new EpoDirectResponseParser())->submitEnvelope(
            '<Chyby><Chyba Typ="P" Zkr="58"><Text>Není vyplněn kód státu.</Text></Chyba>'
            . '<Potvrzeni ID_predani="ABC123" Heslo="secret"/></Chyby>',
        );

        self::assertSame('offline', $result['kind']);
        self::assertSame('ABC123', $result['transfer_id']);
    }

    public function testRecognizesOfflineReceiptWithoutExposingItAsError(): void
    {
        $result = (new EpoDirectResponseParser())->submitEnvelope(
            '<Odpoved><Potvrzeni ID_predani="ABC123" Heslo="secret"/></Odpoved>',
        );

        self::assertSame('offline', $result['kind']);
        self::assertSame('ABC123', $result['transfer_id']);
        self::assertSame('secret', $result['transfer_password']);
    }

    public function testVerifiesSignedConfirmationAndMatchesSentCmsBytes(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        $sent = random_bytes(200);
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data><Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/></Pisemnost>',
            bin2hex($sent),
        );
        $path = $this->signDer($confirmationXml);
        $bytes = (string) file_get_contents($path);

        $result = (new EpoDirectResponseParser())->confirmation($bytes, $sent);

        self::assertTrue($result['signature_valid']);
        self::assertTrue($result['is_confirmation']);
        self::assertTrue($result['content_match']);
        self::assertFalse($result['epo_signer_valid']);
        self::assertSame('123456', $result['reference']);
        self::assertSame('state-secret', $result['state_password']);
    }

    /**
     * Doloženo skutečně přijatým kontrolním hlášením v testovacím prostředí:
     * EPO vrací v potvrzence REDUKOVANOU podobu podání — 3 465 B se smrsklo
     * na 935 B, zmizely detailní řádky, identifikace software se přepsala na
     * „null" a čísla se přeformátovala. Porovnávat obsah proto nemůže vyjít
     * nikdy a každé podání končilo jako `confirmation_content_mismatch`.
     * Skutečnou vazbou je `Kontrola/Soubor/@KC`, tedy MD5 odeslaného XML.
     */
    public function testMatchesReducedConfirmationByItsChecksum(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        $sentXml = '<Pisemnost><DPHKH1 dic="CZ00000019" rok="2026"><VetaD/><VetaA2/></DPHKH1></Pisemnost>';
        $reduced = '<Pisemnost><DPHKH1 dic="CZ00000019" rok="2026"><VetaD/></DPHKH1></Pisemnost>';
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data>'
                . '<Kontrola><Soubor Delka="935" KC="%s" Nazev="DPHKH1-x"/></Kontrola>'
                . '<Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/>'
                . '</Pisemnost>',
            bin2hex($reduced),
            md5($sentXml),
        );
        $confirmation = (string) file_get_contents($this->signDer($confirmationXml));

        $result = (new EpoDirectResponseParser())->confirmation($confirmation, $sentXml);

        self::assertTrue($result['content_match']);
    }

    /**
     * Kontrolní součet zůstává pojistkou, ne formalitou: potvrzenka k cizímu
     * podání se nesmí spárovat jen proto, že přišla podepsaná.
     */
    public function testForeignChecksumStillFailsTheContentMatch(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data>'
                . '<Kontrola><Soubor Delka="10" KC="%s" Nazev="DPHKH1-x"/></Kontrola>'
                . '<Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/>'
                . '</Pisemnost>',
            bin2hex('<Pisemnost/>'),
            md5('cizi podani'),
        );
        $confirmation = (string) file_get_contents($this->signDer($confirmationXml));

        $result = (new EpoDirectResponseParser())->confirmation($confirmation, '<Pisemnost/>');

        self::assertFalse($result['content_match']);
    }

    public function testDoesNotDowngradeEmbeddedContentMismatch(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data><Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/></Pisemnost>',
            bin2hex('different signed payload'),
        );
        $path = $this->signDer($confirmationXml);
        $bytes = (string) file_get_contents($path);

        $result = (new EpoDirectResponseParser())->confirmation($bytes, 'expected payload');

        self::assertTrue($result['signature_valid']);
        self::assertFalse($result['content_match']);
    }

    public function testMatchesEmbeddedOriginalXmlAgainstSubmittedCms(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        $sentXml = '<Pisemnost><DPHDP3 dic="CZ00000019" rok="2026"/></Pisemnost>';
        $sentCms = (string) file_get_contents($this->signDer($sentXml));
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data><Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/></Pisemnost>',
            bin2hex($sentXml),
        );
        $confirmation = (string) file_get_contents($this->signDer($confirmationXml));

        $result = (new EpoDirectResponseParser())->confirmation($confirmation, $sentCms);

        self::assertTrue($result['content_match']);
    }

    public function testMatchesEmbeddedKhZipAgainstSubmittedCms(): void
    {
        if (!function_exists('openssl_cms_sign') || !class_exists(\ZipArchive::class)) {
            self::markTestSkipped('OpenSSL CMS nebo ZIP není dostupné.');
        }
        $sentXml = '<Pisemnost><DPHKH1 dic="CZ00000019" rok="2026"/></Pisemnost>';
        $sentZip = $this->zipXml($sentXml);
        $sentCms = (string) file_get_contents($this->signDer($sentZip));
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data><Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/></Pisemnost>',
            bin2hex($sentZip),
        );
        $confirmation = (string) file_get_contents($this->signDer($confirmationXml));

        $result = (new EpoDirectResponseParser())->confirmation($confirmation, $sentCms);

        self::assertTrue($result['content_match']);
    }

    public function testRecognizesOfficialEpoSignerIdentity(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        $sent = 'signed payload';
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data><Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/></Pisemnost>',
            bin2hex($sent),
        );
        $path = $this->signDer($confirmationXml, [
            'commonName' => 'Spolecne technicke zarizeni spravcu dane',
            'organizationName' => 'Česká republika - Generální finanční ředitelství',
            'countryName' => 'CZ',
        ]);
        $bytes = (string) file_get_contents($path);

        $result = (new EpoDirectResponseParser($this->lastCertificatePath))
            ->confirmation($bytes, $sent);

        self::assertTrue($result['epo_signer_valid']);
        self::assertTrue($result['chain_valid']);
    }

    /**
     * Nastavený, ale chybějící `epo.ca_bundle_path` je chyba KONFIGURACE, ne vadná dodejka.
     *
     * Reálný případ z produkce: cesta k bundlu se nastavila dřív, než na server dorazil
     * samotný soubor. Ověření řetězce správně selhalo fail-closed, jenže výsledek byl
     * k nerozeznání od zfalšované potvrzenky — účetní viděla „potvrzení se nepodařilo
     * bezpečně ověřit" u kontrolního hlášení, které správce daně přijal, a odeslat ho
     * podruhé by znamenalo duplicitní podání.
     */
    public function testReportsConfiguredTrustStoreAsUnavailableWhenFileIsMissing(): void
    {
        $missing = sys_get_temp_dir() . '/epo-ca-bundle-neexistuje-' . bin2hex(random_bytes(6)) . '.pem';
        self::assertFileDoesNotExist($missing);

        self::assertTrue(
            (new EpoDirectResponseParser($missing))->trustStoreUnavailable(),
            'Nastavená cesta bez souboru musí být hlášená jako nedostupný trust store.'
        );
        self::assertFalse(
            (new EpoDirectResponseParser($this->lastCertificatePath))->trustStoreUnavailable(),
            'Dostupný bundle nedostupný není.'
        );
        self::assertFalse(
            (new EpoDirectResponseParser())->trustStoreUnavailable(),
            'Bez nastavené cesty se používá systémový store — to není chyba konfigurace.'
        );
    }

    public function testRecognizesSandboxSignerOnlyInTestEnvironment(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        $sent = 'signed payload';
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data><Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/></Pisemnost>',
            bin2hex($sent),
        );
        $path = $this->signDer($confirmationXml, [
            'commonName' => 'Testovací zařízení - nelze učinit platné podání',
            'organizationName' => 'Česká republika - Generální finanční ředitelství',
            'countryName' => 'CZ',
        ]);
        $bytes = (string) file_get_contents($path);
        $fingerprint = openssl_x509_fingerprint(
            (string) file_get_contents((string) $this->lastCertificatePath),
            'sha256',
        );
        self::assertIsString($fingerprint);
        $parser = new EpoDirectResponseParser(null, [], [$fingerprint]);

        $test = $parser->confirmation($bytes, $sent, 'test');
        $production = $parser->confirmation($bytes, $sent, 'production');
        $withoutTrustAnchor = (new EpoDirectResponseParser())
            ->confirmation($bytes, $sent, 'test');
        $wrongTrustAnchor = (new EpoDirectResponseParser(
            null,
            [],
            [str_repeat('0', 64)],
        ))->confirmation($bytes, $sent, 'test');

        self::assertTrue($test['signature_valid']);
        self::assertTrue($test['epo_signer_valid']);
        self::assertFalse($production['epo_signer_valid']);
        self::assertFalse($withoutTrustAnchor['epo_signer_valid']);
        self::assertFalse($wrongTrustAnchor['epo_signer_valid']);
    }

    public function testConfiguredSignerFingerprintIsEnforced(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        $sent = 'signed payload';
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data><Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/></Pisemnost>',
            bin2hex($sent),
        );
        $path = $this->signDer($confirmationXml, [
            'commonName' => 'Spolecne technicke zarizeni spravcu dane',
            'organizationName' => 'Česká republika - Generální finanční ředitelství',
            'countryName' => 'CZ',
        ]);
        $bytes = (string) file_get_contents($path);
        $fingerprint = openssl_x509_fingerprint(
            (string) file_get_contents((string) $this->lastCertificatePath),
            'sha256',
        );
        self::assertIsString($fingerprint);

        $accepted = (new EpoDirectResponseParser(
            $this->lastCertificatePath,
            [$fingerprint],
        ))->confirmation($bytes, $sent);
        $rejected = (new EpoDirectResponseParser(
            $this->lastCertificatePath,
            [str_repeat('0', 64)],
        ))->confirmation($bytes, $sent);

        self::assertTrue($accepted['epo_signer_valid']);
        self::assertFalse($rejected['epo_signer_valid']);
    }

    public function testMissingConfiguredCaBundleFailsClosed(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        $sent = 'signed payload';
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data><Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/></Pisemnost>',
            bin2hex($sent),
        );
        $bytes = (string) file_get_contents($this->signDer($confirmationXml));

        $result = (new EpoDirectResponseParser(
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-epo-ca-bundle.pem',
        ))->confirmation($bytes, $sent);

        self::assertFalse($result['chain_valid']);
    }

    public function testParsesRemoteStatusWithoutDynamicXmlExpansion(): void
    {
        $result = (new EpoDirectResponseParser())->status(
            '<Stav><por_podani>123</por_podani><stav_podapl>3</stav_podapl><stav_podapl_text>Přijato</stav_podapl_text></Stav>',
        );

        self::assertSame('123', $result['por_podani']);
        self::assertSame('3', $result['stav_podapl']);
        self::assertSame('Přijato', $result['stav_podapl_text']);
    }

    /** @param array<string,string>|null $distinguishedName */
    private function signDer(string $content, ?array $distinguishedName = null): string
    {
        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ];
        foreach ([
            getenv('OPENSSL_CONF') ?: null,
            'C:/inetpub/php/extras/ssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
        ] as $config) {
            if (is_string($config) && is_file($config)) {
                $options['config'] = $config;
                break;
            }
        }
        $key = openssl_pkey_new($options);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(
            $distinguishedName ?? ['commonName' => 'Synthetic EPO Test'],
            $key,
            $options,
        );
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_x509_export($certificate, $certificatePem));
        $this->lastCertificatePath = $this->tempFile($certificatePem);
        $input = $this->tempFile($content);
        $output = $this->tempFile('');
        self::assertTrue(openssl_cms_sign(
            $input,
            $output,
            $certificate,
            $key,
            [],
            OPENSSL_CMS_BINARY,
            OPENSSL_ENCODING_DER,
        ));
        return $output;
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epo-direct-test-');
        self::assertNotFalse($path);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function zipXml(string $xml): string
    {
        $path = $this->tempFile('');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('DPHKH1.xml', $xml));
        self::assertTrue($zip->close());
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        return $bytes;
    }
}
