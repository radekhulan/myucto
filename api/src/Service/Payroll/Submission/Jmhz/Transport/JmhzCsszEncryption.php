<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Zašifrování těla podání pro ČSSZ.
 *
 * Podací protokol ČSSZ (v1.7, kap. „Struktura zprávy") předepisuje pořadí
 * doslova: *„Data podání musí být komprimována, zašifrována (v tomto pořadí…),
 * výsledek musí být zakódován pro přenos."* Tedy **gzip → CMS/PKCS#7 šifrování
 * → Base64**. Podepisuje se ale PŮVODNÍ tvar dat, ještě před komprimací —
 * podpis se proto počítá jinde a sem už nevstupuje.
 *
 * Šifrovací certifikát ČSSZ je připnutý v repozitáři a jeho otisk se ověřuje
 * při každém použití. Je veřejný a stejný pro testovací i produkční prostředí,
 * takže ho nemá smysl konfigurovat — zato má smysl poznat, že se změnil:
 * zašifrovat podání cizím certifikátem znamená, že ho ČSSZ nerozbalí a chyba
 * se projeví až protokolem.
 */
final class JmhzCsszEncryption
{
    /**
     * Otisk připnutého certifikátu `DIS.CSSZ.2025` (PostSignum Public CA 4,
     * platnost do 23. 2. 2028). Zdroj: https://www.cssz.gov.cz/web/cz/ke-stazeni
     */
    public const CERTIFICATE_SHA256 =
        'e409bab6924458e5983ebe002f8d1f90b848f17f6f2cb9a3eaf01a92b2146b70';

    private const RELATIVE_PATH = 'cssz-2025/DIS.CSSZ.2025.pem';

    public function __construct(private readonly ?string $resourceRoot = null) {}

    /** Připravené tělo pro `Message/Body`: base64 ze zašifrovaného gzipu. */
    public function seal(string $payload): string
    {
        if ($payload === '') {
            throw new JmhzTransportException(
                'jmhz_encryption_empty_payload',
                'Prázdné podání nelze zašifrovat.',
            );
        }
        $compressed = gzencode($payload, 9);
        if ($compressed === false) {
            throw new JmhzTransportException(
                'jmhz_encryption_compression_failed',
                'Podání se nepodařilo zkomprimovat.',
            );
        }

        return base64_encode($this->encrypt($compressed));
    }

    /** Certifikát ČSSZ v PEM, ověřený proti připnutému otisku. */
    public function certificate(): string
    {
        $root = $this->resourceRoot ?? dirname(__DIR__, 6) . '/resources/payroll/jmhz';
        $path = $root . DIRECTORY_SEPARATOR . self::RELATIVE_PATH;
        // Chybějící nebo nečitelný certifikát je tady OČEKÁVANÝ stav (chybná
        // instalace) a odpovědí na něj je JmhzTransportException. Bez potlačení
        // by se navrch vysypal ještě PHP warning s absolutní cestou — do logu
        // i do odpovědi, podle nastavení display_errors.
        $pem = @file_get_contents($path);
        if (!is_string($pem) || $pem === '') {
            throw new JmhzTransportException(
                'jmhz_encryption_certificate_missing',
                'Šifrovací certifikát ČSSZ není v aplikaci k dispozici.',
            );
        }
        $der = openssl_x509_read($pem);
        if ($der === false) {
            throw new JmhzTransportException(
                'jmhz_encryption_certificate_unreadable',
                'Šifrovací certifikát ČSSZ nelze načíst.',
            );
        }
        $fingerprint = openssl_x509_fingerprint($der, 'sha256');
        if (!is_string($fingerprint)
            || !hash_equals(self::CERTIFICATE_SHA256, strtolower($fingerprint))
        ) {
            throw new JmhzTransportException(
                'jmhz_encryption_certificate_untrusted',
                'Šifrovací certifikát ČSSZ nemá připnutý otisk.',
            );
        }

        return $pem;
    }

    private function encrypt(string $compressed): string
    {
        if (!function_exists('openssl_cms_encrypt')) {
            throw new JmhzTransportException(
                'jmhz_encryption_unavailable',
                'Server nepodporuje šifrování CMS/PKCS#7.',
            );
        }
        $input = self::tempPath('jmhz-plain-');
        $output = self::tempPath('jmhz-sealed-');
        try {
            if (file_put_contents($input, $compressed) === false) {
                throw new JmhzTransportException(
                    'jmhz_encryption_failed',
                    'Podání se nepodařilo připravit k zašifrování.',
                );
            }
            @chmod($input, 0600);
            $ok = @openssl_cms_encrypt(
                $input,
                $output,
                $this->certificate(),
                [],
                OPENSSL_CMS_BINARY,
                OPENSSL_ENCODING_DER,
                OPENSSL_CIPHER_AES_256_CBC,
            );
            $sealed = $ok ? @file_get_contents($output) : false;
            if (!is_string($sealed) || $sealed === '') {
                throw new JmhzTransportException(
                    'jmhz_encryption_failed',
                    'Podání se nepodařilo zašifrovat pro ČSSZ.',
                );
            }

            return $sealed;
        } finally {
            // Nezašifrovaná data podání nesmí zůstat na disku ani na okamžik
            // déle, než je nutné — jsou v nich rodná čísla a mzdy.
            foreach ([$input, $output] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private static function tempPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new JmhzTransportException(
                'jmhz_encryption_failed',
                'Nelze připravit dočasný soubor pro šifrování.',
            );
        }

        return $path;
    }
}
