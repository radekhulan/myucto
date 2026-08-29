<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzCsszEncryption;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use PHPUnit\Framework\TestCase;

/**
 * Zašifrování těla podání pro ČSSZ je bod, kde z aplikace odchází rodná čísla
 * a mzdy. Chyba se tu neprojeví výjimkou, ale tím, že ČSSZ podání nerozbalí —
 * a to se pozná až z protokolu o dny později. Testy proto hlídají tři věci:
 * pořadí gzip → CMS → Base64, připnutý otisk certifikátu a to, že se otevřený
 * text nikde neprotlačí ven.
 */
final class JmhzCsszEncryptionTest extends TestCase
{
    private const PAYLOAD = '<?xml version="1.0"?><Podani><RodneCislo>7001010009</RodneCislo></Podani>';

    protected function setUp(): void
    {
        if (!function_exists('openssl_cms_encrypt')) {
            self::markTestSkipped('PHP nemá openssl_cms_encrypt().');
        }
    }

    /**
     * Připnutý otisk je jediná pojistka proti tomu, že se v repozitáři objeví
     * cizí certifikát. Kdyby se pin přestal ověřovat (nebo se vyměnil soubor),
     * podání by se zašifrovalo klíčem, ke kterému ČSSZ nemá protějšek.
     */
    public function testPinnedCertificateMatchesShippedFile(): void
    {
        $pem = (new JmhzCsszEncryption())->certificate();

        self::assertStringContainsString('BEGIN CERTIFICATE', $pem);
        $x509 = openssl_x509_read($pem);
        self::assertNotFalse($x509);
        self::assertSame(
            JmhzCsszEncryption::CERTIFICATE_SHA256,
            strtolower((string) openssl_x509_fingerprint($x509, 'sha256')),
        );
    }

    /**
     * Podací protokol ČSSZ předepisuje pořadí doslova: komprimovat, zašifrovat,
     * zakódovat pro přenos. Výsledek proto musí být čitelný Base64 a po dekódování
     * platná CMS/PKCS#7 struktura v DER — ne holý gzip a ne PEM obálka.
     */
    public function testSealProducesBase64OfDerCmsEnvelope(): void
    {
        $sealed = (new JmhzCsszEncryption())->seal(self::PAYLOAD);

        self::assertNotSame('', $sealed);
        self::assertSame($sealed, trim($sealed), 'Base64 nesmí mít okolní bílé znaky.');
        $der = base64_decode($sealed, true);
        self::assertIsString($der);
        self::assertNotSame('', $der);
        // DER SEQUENCE — CMS ContentInfo vždy začíná 0x30.
        self::assertSame(0x30, ord($der[0]));
        self::assertStringNotContainsString('BEGIN CMS', $der);
        self::assertStringNotContainsString('BEGIN PKCS7', $der);
    }

    /**
     * Nejdražší možná regrese: zašifrovat „naoko" a poslat na ČSSZ rodná čísla
     * v otevřeném tvaru. Zkontroluje se to nad zašifrovaným výstupem i nad jeho
     * Base64 podobou, protože Base64 by případný únik zamaskoval jen zdánlivě.
     */
    public function testSealNeverLeaksPlaintextOrGzipMagic(): void
    {
        $sealed = (new JmhzCsszEncryption())->seal(self::PAYLOAD);
        $der = base64_decode($sealed, true);
        self::assertIsString($der);

        self::assertStringNotContainsString('7001010009', $der);
        self::assertStringNotContainsString('RodneCislo', $der);
        self::assertStringNotContainsString('7001010009', $sealed);
        self::assertStringNotContainsString(
            base64_encode('RodneCislo'),
            $sealed,
        );
        // Kdyby se komprimát neprošifroval, začínal by gzip hlavičkou 1f 8b.
        self::assertNotSame("\x1f\x8b", substr($der, 0, 2));
    }

    /**
     * CMS je nedeterministické (náhodný obsahový klíč a IV), takže dvě zapečetění
     * téhož podání se MUSÍ lišit. Kdyby byla shodná, znamenalo by to statický klíč
     * — a z odchycených podání by šlo porovnávat obsah bez dešifrování.
     */
    public function testSealIsNotDeterministic(): void
    {
        $encryption = new JmhzCsszEncryption();

        self::assertNotSame(
            $encryption->seal(self::PAYLOAD),
            $encryption->seal(self::PAYLOAD),
        );
    }

    public function testEmptyPayloadIsRejectedBeforeAnyCrypto(): void
    {
        try {
            (new JmhzCsszEncryption())->seal('');
            self::fail('Prázdné podání se nesmí zašifrovat.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_encryption_empty_payload', $e->errorCode);
        }
    }

    /**
     * Chybějící certifikát nesmí spadnout na varování `file_get_contents()`
     * a prázdném řetězci — volající potřebuje strojově rozeznatelný kód, aby
     * uměl podání odložit místo označit za odeslané.
     */
    public function testMissingCertificateFileReportsTransportErrorCode(): void
    {
        $root = $this->tempDir();
        /*
         * Odpovědí na chybějící certifikát je VÝHRADNĚ `JmhzTransportException`.
         * Dřív k ní `certificate()` přidával ještě warning z `file_get_contents()`
         * — do logu i (podle `display_errors`) do odpovědi, včetně absolutní
         * cesty. Handler tady warning nepotlačuje, ale ZACHYTÁVÁ: kdyby se
         * zavináč ze zdroje ztratil, test spadne na tomhle, ne na PHPUnit
         * převodu warningů.
         */
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            // Vlastní handler dostane i potlačenou diagnostiku — `@` jen zúží
            // `error_reporting()`. Zajímá nás proto jen to, co by se REÁLNĚ
            // vypsalo do logu; ostatní se tiše zahodí, stejně jako v běhu.
            if ((error_reporting() & $severity) !== 0) {
                $warnings[] = $message;
            }
            return true;
        }, E_WARNING | E_NOTICE);
        try {
            (new JmhzCsszEncryption($root))->certificate();
            self::fail('Chybějící certifikát musí selhat.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_encryption_certificate_missing', $e->errorCode);
        } finally {
            restore_error_handler();
            $this->removeDir($root);
        }
        self::assertSame([], $warnings, 'Chybějící certifikát nesmí vyhodit PHP warning.');
    }

    /**
     * Podvržený (byť syntakticky platný) certifikát musí propadnout na pinu,
     * ne projít. Tohle je jediný test, který skutečně ověřuje, že se otisk
     * porovnává, a ne jen počítá.
     */
    public function testCertificateWithDifferentFingerprintIsRejected(): void
    {
        $root = $this->tempDir();
        try {
            $dir = $root . DIRECTORY_SEPARATOR . 'cssz-2025';
            self::assertTrue(mkdir($dir, 0700, true));
            file_put_contents(
                $dir . DIRECTORY_SEPARATOR . 'DIS.CSSZ.2025.pem',
                $this->selfSignedCertificate(),
                LOCK_EX,
            );

            try {
                (new JmhzCsszEncryption($root))->certificate();
                self::fail('Cizí certifikát musí propadnout na připnutém otisku.');
            } catch (JmhzTransportException $e) {
                self::assertSame('jmhz_encryption_certificate_untrusted', $e->errorCode);
            }
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Nezašifrované podání se při šifrování zapisuje do dočasného souboru.
     * Ten po sobě `seal()` musí uklidit i při úspěchu — jinak by rodná čísla
     * zůstala ležet v systémovém temp adresáři.
     */
    public function testSealLeavesNoTemporaryPlaintextBehind(): void
    {
        $before = $this->tempFiles();
        (new JmhzCsszEncryption())->seal(self::PAYLOAD);

        // Souběžně běžící testy mohou mít vlastní dočasné soubory, takže se
        // nesrovnává celý seznam — jen to, že žádný nový nedrží NAŠE podání.
        $leaked = [];
        foreach (array_diff($this->tempFiles(), $before) as $path) {
            $content = @file_get_contents($path);
            if (is_string($content)
                && str_contains((string) @gzdecode($content), '7001010009')
            ) {
                $leaked[] = $path;
            }
        }
        self::assertSame([], $leaked);
    }

    /** @return list<string> */
    private function tempFiles(): array
    {
        $found = [];
        foreach (['jmhz-plain-*', 'jmhz-sealed-*'] as $pattern) {
            $matches = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . $pattern);
            if ($matches !== false) {
                $found = [...$found, ...$matches];
            }
        }
        sort($found);
        return $found;
    }

    /**
     * Cizí certifikát je v testu zapečený natvrdo, ne generovaný za běhu:
     * `openssl_pkey_new()` potřebuje na Windows nastavené `openssl.cnf` a bez
     * něj by test padal z důvodu, který s testovaným kódem nesouvisí.
     * Ke klíči nemáme (a nechceme mít) privátní protějšek — stačí, že má
     * jiný otisk než připnutý DIS.CSSZ.2025.
     */
    private function selfSignedCertificate(): string
    {
        return <<<'PEM'
            -----BEGIN CERTIFICATE-----
            MIIDjjCCAnagAwIBAgIBADANBgkqhkiG9w0BAQsFADBgMRkwFwYDVQQDDBBOZXBy
            YXZ5IERJUy5DU1NaMQswCQYDVQQGEwJBVTETMBEGA1UECAwKU29tZS1TdGF0ZTEh
            MB8GA1UECgwYSW50ZXJuZXQgV2lkZ2l0cyBQdHkgTHRkMB4XDTI2MDgyOTAxMDEz
            NloXDTM2MDgyNjAxMDEzNlowYDEZMBcGA1UEAwwQTmVwcmF2eSBESVMuQ1NTWjEL
            MAkGA1UEBhMCQVUxEzARBgNVBAgMClNvbWUtU3RhdGUxITAfBgNVBAoMGEludGVy
            bmV0IFdpZGdpdHMgUHR5IEx0ZDCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoC
            ggEBAK+KzO5LHa0Uz0kSxfqIjMqnE4O3xC1jJ7sQh4zW6BpYragbsCa/kMj6qMTN
            OBDmsrrWXdGbviyu3b91atfGpf+pfFsF27p+Z+4RlIyWf915A4UGpRYcQ6YoNoci
            rX4OPjL7MksS3/eD9YNc5lyCx1s//pp1jq3YSeXO7YGY9UU5AXirksqQ/JOUrHif
            oTU569FQcoAwjNSd8V/PjY/WYrHCmGVqSjWzH+M1HoR1f308MJMhTJmJnqkhDAja
            FpEd78a1+7FZHwmPQA3kH2jDRXwu+9f9LQFerXE4ShqqLMOj712XJe6l3jJHyaD3
            fwiI8TmRlWYm1YNBXYhSlWLM4D8CAwEAAaNTMFEwHQYDVR0OBBYEFAVP7S6hqKlo
            fgibQZmX6uXJ9aZCMB8GA1UdIwQYMBaAFAVP7S6hqKlofgibQZmX6uXJ9aZCMA8G
            A1UdEwEB/wQFMAMBAf8wDQYJKoZIhvcNAQELBQADggEBAC9iifc8FILDG30JQnXi
            ZCw1JSKltWNTkOgfiy3uyJ/hoURI2NsqI4VvVweYXFPavrRQZLYiYZBntDpwRjSP
            3LSZD0BPtbAGmpA0rebK7CkJJx29BWNk+SFOT9ZHa4Ijnih9zcezMAoDlKEpNtrD
            QMbgKUQm8VTuuCiLggsJdx63ET6xdhKJUJ3/+Rl3itlxtHwcfC8/9zqPkjXld9UM
            Str6TEsp4DrNykgDxsk0GEV19+KW3gIO836wXQRUlf40ZhNiNc2QCeBYCMCsmj5x
            YALSgS2HQ2dNRw0GkX1OhHX0RTqszc3Zev+QXwjt0UPi6BmdYevHFmrQdAMJiVPw
            OjI=
            -----END CERTIFICATE-----
            PEM;
    }

    private function tempDir(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jmhz-cert-root-');
        self::assertIsString($path);
        unlink($path);
        self::assertTrue(mkdir($path, 0700));
        return $path;
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) ? $this->removeDir($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
