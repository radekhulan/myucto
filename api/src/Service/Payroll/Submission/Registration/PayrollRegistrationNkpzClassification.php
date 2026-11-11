<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

/**
 * Národní klasifikace postavení v zaměstnání (NKPZ) z iSMS ČSÚ, jen reference.
 *
 * Datový slovník JMHZ 1.4.1.6 nesl NKPZ vloženou, slovník 1.5.0.3 ji vede jen
 * jako externí referenci „CIS Klasif. postavení v zaměstn" bez položek. Tady
 * je připnutý (otiskem exportu i manifestu) číselník ČSÚ 80145 podle sdělení
 * č. 401/2024 Sb.
 *
 * Vložená verze ze slovníku 1.4.1.6 se od ČSÚ liší v jediném listu: slovník
 * má 2119 „Zaměstnavatelé – fyzické osoby jinde neuvedené", sdělení 401/2024
 * Sb. i iSMS ČSÚ mají kód 2219. Kód 2219 je tisková chyba přílohy sdělení
 * (stojí uvnitř bloku 211 fyzických osob, systematická část NKPZ ČSÚ i EDV
 * REGZEC27 1.5.0.3 a číselník MPSV DS_CIS_Klasifikace_postaveni_v_zamestnani
 * mají 2119). Podání proto tuhle třídu NEPOUŽÍVÁ: postavení v zaměstnání
 * pro oba ročníky bere {@see PayrollRegistrationEmploymentStatusCodebook}
 * z výčtu EDV. Třída zůstává jako reference a hlídač příchodu nového sdělení
 * ČSÚ. Porovnání a postup obnovy jsou v api/resources/payroll/nkpz/README.md.
 */
final class PayrollRegistrationNkpzClassification
{
    public const SCHEMA_VERSION = 'nkpz-classification.v1';
    public const PACKAGE_KEY = 'nkpz-csu-80145-2026-11-03-v1';
    public const DIRECTORY = 'csu-80145-2026-11-03';
    public const APPLIES_FROM = '2027-01-01';
    public const MANIFEST_SHA256 =
        '35e2d0839d3290b1aba6ad0d401f6f439a83d9043e3b188fd43b8223dd794134';

    /** @var array<string,string>|null kód listu → název */
    private static ?array $leaves = null;

    /**
     * Čtyřmístné listy klasifikace (kód → název).
     *
     * @return array<string,string>
     */
    public static function leaves(?string $resourceRoot = null): array
    {
        if ($resourceRoot === null && self::$leaves !== null) {
            return self::$leaves;
        }
        $root = $resourceRoot ?? dirname(__DIR__, 5) . '/resources/payroll/nkpz';
        $directory = $root . DIRECTORY_SEPARATOR . self::DIRECTORY;
        $json = @file_get_contents($directory . DIRECTORY_SEPARATOR . 'manifest.json');
        if ($json === false || $json === '') {
            throw new \RuntimeException('Manifest klasifikace NKPZ nelze načíst.');
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)
            || !is_string($decoded['manifest_sha256'] ?? null)
            || !is_array($decoded['payload'] ?? null)
        ) {
            throw new \UnexpectedValueException('Manifest klasifikace NKPZ nemá očekávanou strukturu.');
        }
        /** @var array{manifest_sha256:string,payload:array<string,mixed>} $decoded */
        self::validateManifest($decoded, true);
        $source = $decoded['payload']['source'];
        $sourcePath = $directory . DIRECTORY_SEPARATOR . basename((string) $source['filename']);
        $hash = is_file($sourcePath) ? hash_file('sha256', $sourcePath) : false;
        if (!is_string($hash) || !hash_equals((string) $source['sha256'], $hash)
            || filesize($sourcePath) !== $source['byte_length']
        ) {
            throw new \UnexpectedValueException('Export NKPZ neodpovídá manifestu.');
        }

        $leaves = [];
        foreach ($decoded['payload']['entries'] as $entry) {
            if (preg_match('/\A\d{4}\z/D', (string) $entry['code']) === 1
                && $entry['valid_from'] <= self::APPLIES_FROM
                && ($entry['valid_to'] === null || $entry['valid_to'] >= self::APPLIES_FROM)
            ) {
                $leaves[(string) $entry['code']] = (string) $entry['label'];
            }
        }
        if ($leaves === []) {
            throw new \UnexpectedValueException('Klasifikace NKPZ nemá žádný čtyřmístný kód.');
        }
        ksort($leaves, SORT_STRING);
        if ($resourceRoot === null) {
            self::$leaves = $leaves;
        }

        return $leaves;
    }

    /** @param array{manifest_sha256:string,payload:array<string,mixed>} $manifest */
    public static function validateManifest(array $manifest, bool $requirePinnedHash = false): void
    {
        $payload = $manifest['payload'];
        $actual = hash('sha256', CanonicalJson::encode($payload));
        if (!hash_equals($manifest['manifest_sha256'], $actual)
            || ($requirePinnedHash && !hash_equals(self::MANIFEST_SHA256, $actual))
        ) {
            throw new \UnexpectedValueException('Manifest klasifikace NKPZ nemá připnutý SHA-256.');
        }
        if (($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($payload['package_key'] ?? null) !== self::PACKAGE_KEY
            || ($payload['applies_from'] ?? null) !== self::APPLIES_FROM
            || !is_array($payload['source'] ?? null)
        ) {
            throw new \UnexpectedValueException('Manifest klasifikace NKPZ má neznámou identitu.');
        }
        $entries = $payload['entries'] ?? null;
        if (!is_array($entries) || !array_is_list($entries)
            || ($payload['counts']['entries'] ?? null) !== count($entries)
            || !hash_equals(
                is_string($payload['content_hash'] ?? null) ? $payload['content_hash'] : '',
                hash('sha256', CanonicalJson::encode(['entries' => $entries])),
            )
        ) {
            throw new \UnexpectedValueException('Obsah klasifikace NKPZ neodpovídá otisku v manifestu.');
        }
        $codes = [];
        foreach ($entries as $ordinal => $entry) {
            if (!is_array($entry)) {
                throw new \UnexpectedValueException('Klasifikace NKPZ obsahuje neplatný řádek.');
            }
            $code = $entry['code'] ?? null;
            $withoutHash = $entry;
            unset($withoutHash['row_hash']);
            if (!is_string($code) || preg_match('/\A\d{1,4}\z/D', $code) !== 1 || isset($codes[$code])
                || ($entry['ordinal'] ?? null) !== $ordinal + 1
                || ($entry['level'] ?? null) !== strlen($code)
                || !is_string($entry['label'] ?? null) || $entry['label'] === ''
                || !is_string($entry['valid_from'] ?? null)
                || !is_string($entry['row_hash'] ?? null)
                || !hash_equals($entry['row_hash'], hash('sha256', CanonicalJson::encode($withoutHash)))
            ) {
                throw new \UnexpectedValueException('Klasifikace NKPZ má neplatnou položku ' . (is_string($code) ? $code : '?') . '.');
            }
            $codes[$code] = true;
        }
    }
}
