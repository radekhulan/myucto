<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eet\Transport;

use MyInvoice\Service\Eet\Transport\EetCredentialException;
use MyInvoice\Service\Eet\Transport\EetPkcs12Reader;
use PHPUnit\Framework\TestCase;

/**
 * Meze čtečky PKCS#12 proti souborům, které by server zaměstnaly odvozováním
 * klíčů. Soubory se skládají ručně z DER, aby šlo nastavit počty iterací
 * a položek, které `openssl_pkcs12_export()` nastavit neumí.
 */
final class EetPkcs12LimitsTest extends TestCase
{
    private const PASSWORD = 'Limit-test-1';
    private const OID_DATA = '1.2.840.113549.1.7.1';
    private const OID_ENCRYPTED_DATA = '1.2.840.113549.1.7.6';
    private const OID_PBE_3DES = '1.2.840.113549.1.12.1.3';
    private const OID_SHA1 = '1.3.14.3.2.26';
    private const OID_SHROUDED_KEY_BAG = '1.2.840.113549.1.12.10.1.2';
    private const OID_CERT_BAG = '1.2.840.113549.1.12.10.1.3';
    private const OID_X509_CERT = '1.2.840.113549.1.9.22.1';

    public function testSingleDerivationOverPerCallLimitIsRefusedBeforeOpenssl(): void
    {
        $pfx = self::pfx([self::dataInfo([])], self::junkMac(100_001));

        self::assertRefused($pfx, 'iterací', true);
        self::assertRefused($pfx, 'iterací', false);
    }

    public function testVisibleDerivationsOverFileBudgetAreRefused(): void
    {
        // MAC 100k + 2 × 3DES (klíč 2 bloky + IV 1 blok) po 100k = 700k > 600k.
        $pfx = self::pfx([self::encryptedInfo(100_000, 'x'), self::encryptedInfo(100_000, 'x')], self::junkMac(100_000));

        self::assertRefused($pfx, 'mnoho iterací', true);
        self::assertRefused($pfx, 'mnoho iterací', false);
    }

    public function testHiddenDerivationInsideEncryptedContentCountsToBudget(): void
    {
        // Vnější obsah 3 × 100k je vidět předem, zašifrovaný klíč 3 × 100k až po dešifrování.
        $inner = self::der(0x30, self::shroudedBag(100_000));
        $authSafe = self::der(0x30, self::encryptedInfo(100_000, $inner));
        $pfx = self::der(0x30, self::integer(3) . self::contentInfo(self::OID_DATA, self::octets($authSafe)) . self::validMac($authSafe, 1));

        self::assertRefused($pfx, 'mnoho iterací', false);
    }

    public function testTooManyContentInfosAreRefused(): void
    {
        $pfx = self::pfx(array_fill(0, 17, self::dataInfo([])), self::junkMac(1));

        self::assertRefused($pfx, 'počet obsahů', true);
        self::assertRefused($pfx, 'počet obsahů', false);
    }

    public function testTooManyBagsAreRefused(): void
    {
        $pfx = self::pfx([self::dataInfo(array_fill(0, 9, self::certBag())), self::dataInfo(array_fill(0, 8, self::certBag()))], self::junkMac(1));

        self::assertRefused($pfx, 'počet položek', true);
        self::assertRefused($pfx, 'počet položek', false);
    }

    public function testRegularFileStaysWithinLimits(): void
    {
        $decoded = EetPkcs12Reader::decode(EetTestPki::pkcs12(), EetTestPki::PASSWORD);
        self::assertStringContainsString('BEGIN CERTIFICATE', $decoded['cert']);
        self::assertStringContainsString('BEGIN CERTIFICATE', EetPkcs12Reader::read(EetTestPki::pkcs12(), EetTestPki::PASSWORD)['cert']);
    }

    private static function assertRefused(string $pfx, string $message, bool $viaRead): void
    {
        $started = hrtime(true);
        try {
            $viaRead ? EetPkcs12Reader::read($pfx, self::PASSWORD) : EetPkcs12Reader::decode($pfx, self::PASSWORD);
            self::fail('Soubor nad mezí prošel.');
        } catch (EetCredentialException $exception) {
            self::assertSame('unreadable', $exception->reason, $exception->getMessage());
            self::assertStringContainsString($message, $exception->getMessage());
        }
        self::assertLessThan(5_000_000_000, hrtime(true) - $started);
    }

    // --- skládání DER ---

    /** @param list<string> $contentInfos */
    private static function pfx(array $contentInfos, string $mac): string
    {
        $authSafe = self::der(0x30, implode('', $contentInfos));

        return self::der(0x30, self::integer(3) . self::contentInfo(self::OID_DATA, self::octets($authSafe)) . $mac);
    }

    /** @param list<string> $bags */
    private static function dataInfo(array $bags): string
    {
        return self::contentInfo(self::OID_DATA, self::octets(self::der(0x30, implode('', $bags))));
    }

    private static function encryptedInfo(int $iterations, string $plain): string
    {
        $salt = str_repeat("\x11", 8);
        $encryptedContent = self::der(0x30, self::oid(self::OID_DATA) . self::pbe($salt, $iterations) . self::der(0x80, self::encrypt3des($plain, $salt, $iterations)));

        return self::contentInfo(self::OID_ENCRYPTED_DATA, self::der(0x30, self::integer(0) . $encryptedContent));
    }

    private static function shroudedBag(int $iterations): string
    {
        $salt = str_repeat("\x22", 8);
        $value = self::der(0x30, self::pbe($salt, $iterations) . self::octets(str_repeat("\0", 16)));

        return self::der(0x30, self::oid(self::OID_SHROUDED_KEY_BAG) . self::der(0xA0, $value));
    }

    private static function certBag(): string
    {
        $certBag = self::der(0x30, self::oid(self::OID_X509_CERT) . self::der(0xA0, self::octets('x')));

        return self::der(0x30, self::oid(self::OID_CERT_BAG) . self::der(0xA0, $certBag));
    }

    private static function pbe(string $salt, int $iterations): string
    {
        return self::der(0x30, self::oid(self::OID_PBE_3DES) . self::der(0x30, self::octets($salt) . self::integer($iterations)));
    }

    private static function encrypt3des(string $plain, string $salt, int $iterations): string
    {
        if ($plain === 'x') {
            return str_repeat("\0", 16);
        }
        $bmp = self::bmp(self::PASSWORD);
        $key = EetPkcs12Reader::pkcs12Kdf('sha1', $bmp, $salt, 1, $iterations, 24);
        $iv = EetPkcs12Reader::pkcs12Kdf('sha1', $bmp, $salt, 2, $iterations, 8);

        return (string) openssl_encrypt($plain, 'des-ede3-cbc', $key, OPENSSL_RAW_DATA, $iv);
    }

    private static function junkMac(int $iterations): string
    {
        return self::mac(str_repeat("\0", 20), str_repeat("\x33", 8), $iterations);
    }

    private static function validMac(string $authSafe, int $iterations): string
    {
        $salt = str_repeat("\x33", 8);
        $key = EetPkcs12Reader::pkcs12Kdf('sha1', self::bmp(self::PASSWORD), $salt, 3, $iterations, 20);

        return self::mac(hash_hmac('sha1', $authSafe, $key, true), $salt, $iterations);
    }

    private static function mac(string $digest, string $salt, int $iterations): string
    {
        $digestInfo = self::der(0x30, self::der(0x30, self::oid(self::OID_SHA1) . "\x05\x00") . self::octets($digest));

        return self::der(0x30, $digestInfo . self::octets($salt) . self::integer($iterations));
    }

    private static function contentInfo(string $oid, string $content): string
    {
        return self::der(0x30, self::oid($oid) . self::der(0xA0, $content));
    }

    private static function bmp(string $password): string
    {
        return mb_convert_encoding($password, 'UTF-16BE', 'UTF-8') . "\0\0";
    }

    private static function octets(string $value): string
    {
        return self::der(0x04, $value);
    }

    private static function integer(int $value): string
    {
        $bytes = ltrim(pack('N', $value), "\0");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\0" . $bytes;
        }

        return self::der(0x02, $bytes);
    }

    private static function oid(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        $bytes = chr($parts[0] * 40 + $parts[1]);
        foreach (array_slice($parts, 2) as $part) {
            $chunk = chr($part & 0x7F);
            while (($part >>= 7) > 0) {
                $chunk = chr(0x80 | ($part & 0x7F)) . $chunk;
            }
            $bytes .= $chunk;
        }

        return self::der(0x06, $bytes);
    }

    private static function der(int $tag, string $content): string
    {
        $length = strlen($content);
        if ($length < 0x80) {
            return chr($tag) . chr($length) . $content;
        }
        $lengthBytes = ltrim(pack('N', $length), "\0");

        return chr($tag) . chr(0x80 | strlen($lengthBytes)) . $lengthBytes . $content;
    }
}
