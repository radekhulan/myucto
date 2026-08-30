<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class JmhzExternalCodebookCatalog
{
    public const HISTORICAL_OVERLAY_KEY =
        'jmhz-external-codebooks-cisob-2026_czemalfa-2026-08-13-v1';
    public const HISTORICAL_MANIFEST_SHA256 =
        'ec79c28524b0a8e6a9102dbc879ce69fb7ec8dfdf5489873c81066f4d26b230c';
    public const AUGUST_2026_OVERLAY_KEY =
        'jmhz-external-codebooks-cisob-511-2025-through-2026-08-31_czemalfa-2026-08-13-v1';
    public const AUGUST_2026_MANIFEST_SHA256 =
        '2af12a425ccb063e8356cd8959ea2921e3693bf2dad278cc1c3276e431bfabaf';
    public const DEFAULT_OVERLAY_KEY =
        'jmhz-external-codebooks-cisob-145-2026_czemalfa-2026-08-26-v1';
    public const DEFAULT_MANIFEST_SHA256 =
        'd33b1a05add27f1da2033736f377d03b0efe4a2b34390f084f2b3922733940b6';

    /** @var array<string,array{manifest_sha256:string,directory:string,snapshot_date:string,effective_from:string,effective_to:?string,verified_through:string,selectable:bool}> */
    private const PACKAGES = [
        self::HISTORICAL_OVERLAY_KEY => [
            'manifest_sha256' => self::HISTORICAL_MANIFEST_SHA256,
            'directory' => 'external-codebooks-2026-08-13',
            'snapshot_date' => '2026-08-13',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'verified_through' => '2026-08-13',
            'selectable' => false,
        ],
        self::AUGUST_2026_OVERLAY_KEY => [
            'manifest_sha256' => self::AUGUST_2026_MANIFEST_SHA256,
            'directory' => 'external-codebooks-2026-08-31',
            'snapshot_date' => '2026-08-13',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-08-31',
            'verified_through' => '2026-08-31',
            'selectable' => true,
        ],
        self::DEFAULT_OVERLAY_KEY => [
            'manifest_sha256' => self::DEFAULT_MANIFEST_SHA256,
            'directory' => 'external-codebooks-2026-09-01',
            'snapshot_date' => '2026-08-26',
            'effective_from' => '2026-09-01',
            'effective_to' => '2026-12-31',
            'verified_through' => '2026-12-31',
            'selectable' => true,
        ],
    ];

    /** @var array<string,array{manifest:array{manifest_sha256:string,payload:array<string,mixed>},entries:array<string,array<string,array<string,mixed>>>}> */
    private array $loaded = [];

    public function __construct(
        private readonly JmhzSpecPackageCatalog $specPackages,
        private readonly ?string $resourceRoot = null,
    ) {}

    /** @return array{manifest_sha256:string,payload:array<string,mixed>} */
    public function manifest(): array
    {
        return $this->package(self::DEFAULT_OVERLAY_KEY)['manifest'];
    }

    /** @return array{manifest_sha256:string,payload:array<string,mixed>} */
    public function manifestForIdentity(string $overlayKey, string $manifestSha256): array
    {
        return $this->package($overlayKey, $manifestSha256)['manifest'];
    }

    public function hasLoadableIdentity(string $overlayKey, string $manifestSha256): bool
    {
        try {
            $this->manifestForIdentity($overlayKey, $manifestSha256);
            return true;
        } catch (JmhzCodebookUnavailableException|\UnexpectedValueException|\JsonException) {
            return false;
        }
    }

    /**
     * Obec podle kódu, bez porovnání s uloženým názvem.
     *
     * Název obce se u pracovního vztahu NEUKLÁDÁ — evidence drží jen kód
     * (`jmhz_workplace_municipality_code`, migrace 1345) a název je vlastnost
     * připnutého číselníku. {@see requireMunicipality()} je proto na něco
     * jiného: ověřuje, že název, který někdo zadal nebo odeslal, sedí s tím
     * v číselníku. Kdo název jen POTŘEBUJE ZOBRAZIT, se nemá co ptát na shodu.
     *
     * @return array<string,mixed>|null `null` = kód v číselníku k datu není
     */
    public function findMunicipality(string $code, string $validOn): ?array
    {
        try {
            $package = $this->packageForDate($validOn);
            $this->assertCovered($package['manifest'], 'obce', $validOn);
            $entry = $this->codebookEntries($package, 'obce')[$code] ?? null;
        } catch (JmhzCodebookUnavailableException|JmhzCodebookValueException|\UnexpectedValueException|\JsonException) {
            return null;
        }

        return $entry !== null && $this->entryCovers($entry, $validOn) ? $entry : null;
    }

    /** @return array<string,mixed> */
    public function requireMunicipality(string $code, string $name, string $validOn): array
    {
        $package = $this->packageForDate($validOn);
        $this->assertCovered($package['manifest'], 'obce', $validOn);
        $entry = $this->codebookEntries($package, 'obce')[$code] ?? null;
        if ($entry === null || !$this->entryCovers($entry, $validOn)) {
            throw new JmhzCodebookValueException("Kód obce {$code} není v připnutém číselníku CISOB platný.");
        }
        if (!hash_equals($entry['label'], $name)) {
            throw new JmhzCodebookValueException(
                "Název obce neodpovídá připnutému číselníku CISOB pro kód {$code}.",
            );
        }

        return $entry;
    }

    /** @return array<string,mixed> */
    public function requireCountry(string $code, string $validOn): array
    {
        $package = $this->packageForDate($validOn);
        $this->assertCovered($package['manifest'], 'stat', $validOn);
        $entry = $this->codebookEntries($package, 'stat')[$code] ?? null;
        if ($entry === null || !$this->entryCovers($entry, $validOn)) {
            throw new JmhzCodebookValueException("Kód státu {$code} není v připnutém číselníku CZEMALFA platný.");
        }

        return $entry;
    }

    /** @return array<string,mixed> */
    public function requireKnownMunicipality(string $code, string $name, string $termEffectiveOn): array
    {
        return $this->requireMunicipality($code, $name, $termEffectiveOn);
    }

    /** @return array<string,mixed> */
    public function requireKnownCountry(string $code, string $termEffectiveOn): array
    {
        return $this->requireCountry($code, $termEffectiveOn);
    }

    /** @return list<array{code:string,label:string}> */
    public function countries(string $validOn): array
    {
        $package = $this->packageForDate($validOn);
        $this->assertCovered($package['manifest'], 'stat', $validOn);
        $result = [];
        foreach ($this->codebookEntries($package, 'stat') as $entry) {
            if ($this->entryCovers($entry, $validOn)) {
                $result[] = ['code' => $entry['item_code'], 'label' => $entry['label']];
            }
        }
        usort(
            $result,
            static fn (array $left, array $right): int => strnatcasecmp($left['label'], $right['label'])
                ?: strcmp($left['code'], $right['code']),
        );

        return $result;
    }

    /** @return list<array{code:string,label:string}> */
    public function searchMunicipalities(string $query, string $validOn, int $limit): array
    {
        $package = $this->packageForDate($validOn);
        $this->assertCovered($package['manifest'], 'obce', $validOn);
        if ($limit < 1 || $limit > 50) {
            throw new \InvalidArgumentException('Limit vyhledávání obcí musí být od 1 do 50.');
        }
        $needle = mb_strtolower(trim($query), 'UTF-8');
        if (mb_strlen($needle, 'UTF-8') < 2) {
            throw new \InvalidArgumentException('Pro vyhledání obce zadejte alespoň dva znaky.');
        }
        $result = [];
        foreach ($this->codebookEntries($package, 'obce') as $entry) {
            if (!$this->entryCovers($entry, $validOn)
                || (!str_contains($entry['item_code'], $needle)
                    && !str_contains(mb_strtolower($entry['label'], 'UTF-8'), $needle))
            ) {
                continue;
            }
            $result[] = ['code' => $entry['item_code'], 'label' => $entry['label']];
        }
        usort(
            $result,
            static fn (array $left, array $right): int => strnatcasecmp($left['label'], $right['label'])
                ?: strcmp($left['code'], $right['code']),
        );

        return array_slice($result, 0, $limit);
    }

    /** @return list<array{code:string,label:string}> */
    public function searchKnownMunicipalities(string $query, int $limit): array
    {
        return $this->searchMunicipalities(
            $query,
            self::PACKAGES[self::DEFAULT_OVERLAY_KEY]['effective_from'],
            $limit,
        );
    }

    /** @return array{overlay_key:string,manifest_sha256:string,snapshot_date:string,effective_from:string,effective_to:?string,verified_through:string,base_spec_manifest_sha256:string} */
    public function provenance(): array
    {
        return $this->provenanceFromManifest($this->manifest());
    }

    /** @return array{overlay_key:string,manifest_sha256:string,snapshot_date:string,effective_from:string,effective_to:?string,verified_through:string,base_spec_manifest_sha256:string} */
    public function provenanceForDate(string $validOn): array
    {
        return $this->provenanceFromManifest($this->packageForDate($validOn)['manifest']);
    }

    /** @param array{manifest_sha256:string,payload:array<string,mixed>} $manifest */
    public static function validateManifest(array $manifest, bool $requirePinnedHash = false): void
    {
        $payload = $manifest['payload'] ?? null;
        $declaredHash = $manifest['manifest_sha256'] ?? null;
        if (!is_array($payload) || !is_string($declaredHash)) {
            throw new \UnexpectedValueException('Manifest externích číselníků JMHZ nemá očekávanou strukturu.');
        }
        $overlayKey = $payload['overlay_key'] ?? null;
        $descriptor = is_string($overlayKey) ? (self::PACKAGES[$overlayKey] ?? null) : null;
        $actualHash = hash('sha256', CanonicalJson::encode($payload));
        if (!hash_equals($declaredHash, $actualHash)
            || ($requirePinnedHash && ($descriptor === null
                || !hash_equals($descriptor['manifest_sha256'], $actualHash)))
        ) {
            throw new \UnexpectedValueException('Manifest externích číselníků JMHZ nemá připnutý SHA-256.');
        }
        if ($descriptor === null
            || ($payload['schema_version'] ?? null) !== 'jmhz-external-codebook-overlay.v1'
            || ($payload['snapshot_date'] ?? null) !== $descriptor['snapshot_date']
            || ($payload['parser_version'] ?? null) !== 1
            || ($payload['usage_policy'] ?? null) !== 'authoritative_validation_only'
        ) {
            throw new \UnexpectedValueException('Manifest externích číselníků JMHZ má neznámou identitu.');
        }
        $base = $payload['base_spec'] ?? null;
        if (!is_array($base)
            || ($base['package_key'] ?? null) !== JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY
            || ($base['manifest_sha256'] ?? null) !== JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256
        ) {
            throw new \UnexpectedValueException('Manifest externích číselníků JMHZ má neplatný základ.');
        }
        $codebooks = self::list($payload['codebooks'] ?? null, 'codebooks');
        $actualCounts = ['codebooks' => count($codebooks), 'municipalities' => 0, 'countries' => 0, 'entries' => 0];
        $keys = [];
        foreach ($codebooks as $codebook) {
            $key = self::string($codebook, 'codebook_key');
            if (!in_array($key, ['obce', 'stat'], true) || isset($keys[$key])) {
                throw new \UnexpectedValueException("Overlay obsahuje neplatný nebo duplicitní číselník {$key}.");
            }
            $keys[$key] = true;
            if (($codebook['effective_from'] ?? null) !== $descriptor['effective_from']
                || ($codebook['effective_to'] ?? null) !== $descriptor['effective_to']
                || ($codebook['verified_through'] ?? null) !== $descriptor['verified_through']
            ) {
                throw new \UnexpectedValueException("Číselník {$key} má neplatné období ověření.");
            }
            $entries = self::list($codebook['entries'] ?? null, "codebooks.{$key}.entries");
            if (($codebook['entry_count'] ?? null) !== count($entries)
                || !hash_equals(
                    self::string($codebook, 'content_hash'),
                    hash('sha256', CanonicalJson::encode(['entries' => $entries])),
                )
            ) {
                throw new \UnexpectedValueException("Číselník {$key} má neplatný počet nebo hash položek.");
            }
            $codes = [];
            foreach ($entries as $ordinal => $entry) {
                $code = self::string($entry, 'item_code');
                $pattern = $key === 'obce' ? '/\A[0-9]{6}\z/D' : '/\A[A-Z]{2}\z/D';
                if (preg_match($pattern, $code) !== 1 || isset($codes[$code])
                    || ($entry['ordinal'] ?? null) !== $ordinal + 1
                    || self::string($entry, 'label') !== trim(self::string($entry, 'label'))
                    || !self::date($entry['valid_from'] ?? null)
                    || (($entry['valid_to'] ?? null) !== null && !self::date($entry['valid_to']))
                    || (($entry['valid_to'] ?? null) !== null && $entry['valid_to'] < $entry['valid_from'])
                ) {
                    throw new \UnexpectedValueException("Číselník {$key} má neplatnou položku {$code}.");
                }
                $codes[$code] = true;
                $rowHash = self::string($entry, 'row_hash');
                $withoutHash = $entry;
                unset($withoutHash['row_hash']);
                if (!hash_equals($rowHash, hash('sha256', CanonicalJson::encode($withoutHash)))) {
                    throw new \UnexpectedValueException("Položka {$key}/{$code} má neplatný hash.");
                }
            }
            $countKey = $key === 'obce' ? 'municipalities' : 'countries';
            $actualCounts[$countKey] = count($entries);
            $actualCounts['entries'] += count($entries);
        }
        if (array_keys($keys) !== ['obce', 'stat']
            || CanonicalJson::encode($payload['counts'] ?? null) !== CanonicalJson::encode($actualCounts)
        ) {
            throw new \UnexpectedValueException('Overlay externích číselníků JMHZ nemá úplný obsah.');
        }
    }

    /** @return array{manifest:array{manifest_sha256:string,payload:array<string,mixed>},entries:array<string,array<string,array<string,mixed>>>} */
    private function package(string $overlayKey, ?string $manifestSha256 = null): array
    {
        $descriptor = self::PACKAGES[$overlayKey] ?? null;
        if ($descriptor === null
            || ($manifestSha256 !== null && !hash_equals($descriptor['manifest_sha256'], $manifestSha256))
        ) {
            throw new JmhzCodebookUnavailableException('Požadovaný balíček externích číselníků JMHZ není registrovaný.');
        }
        if (isset($this->loaded[$overlayKey])) {
            return $this->loaded[$overlayKey];
        }
        $root = $this->resourceRoot ?? dirname(__DIR__, 5) . '/resources/payroll/jmhz';
        $directory = $root . DIRECTORY_SEPARATOR . $descriptor['directory'];
        $json = file_get_contents($directory . DIRECTORY_SEPARATOR . 'manifest.json');
        if ($json === false) {
            throw new JmhzCodebookUnavailableException('Manifest externích číselníků JMHZ nelze načíst.');
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_string($decoded['manifest_sha256'] ?? null)
            || !is_array($decoded['payload'] ?? null)
        ) {
            throw new \UnexpectedValueException('Manifest externích číselníků JMHZ nemá očekávanou strukturu.');
        }
        self::validateManifest($decoded, true);
        if (!hash_equals($descriptor['manifest_sha256'], $decoded['manifest_sha256'])) {
            throw new \UnexpectedValueException('Manifest externích číselníků JMHZ neodpovídá registru.');
        }
        $base = $decoded['payload']['base_spec'];
        $spec = $this->specPackages->load($base['package_key'], $base['manifest_sha256']);
        if (!hash_equals($base['manifest_sha256'], $spec['manifest_sha256'])) {
            throw new \UnexpectedValueException('Overlay externích číselníků neodpovídá základní specifikaci JMHZ.');
        }
        $this->verifySources($directory, $decoded['payload']);
        $entries = [];
        foreach ($decoded['payload']['codebooks'] as $codebook) {
            $key = $codebook['codebook_key'];
            foreach ($codebook['entries'] as $entry) {
                $entries[$key][$entry['item_code']] = $entry;
            }
        }
        return $this->loaded[$overlayKey] = ['manifest' => $decoded, 'entries' => $entries];
    }

    /** @return array{manifest:array{manifest_sha256:string,payload:array<string,mixed>},entries:array<string,array<string,array<string,mixed>>>} */
    private function packageForDate(string $validOn): array
    {
        if (!self::date($validOn)) {
            throw new \InvalidArgumentException('Datum platnosti externího číselníku JMHZ není platné.');
        }
        foreach (self::PACKAGES as $overlayKey => $descriptor) {
            if ($descriptor['selectable']
                && $validOn >= $descriptor['effective_from']
                && ($descriptor['effective_to'] === null || $validOn <= $descriptor['effective_to'])
                && $validOn <= $descriptor['verified_through']
            ) {
                return $this->package($overlayKey);
            }
        }
        throw new JmhzCodebookUnavailableException(
            "Externí číselníky JMHZ nejsou pro datum {$validOn} právně pokryté.",
        );
    }

    /** @param array<string,mixed> $payload */
    private function verifySources(string $directory, array $payload): void
    {
        foreach (self::list($payload['sources'] ?? null, 'sources') as $source) {
            $filename = self::string($source, 'filename');
            if (basename($filename) !== $filename) {
                throw new \UnexpectedValueException('Zdroj overlay externích číselníků má neplatnou cestu.');
            }
            $path = $directory . DIRECTORY_SEPARATOR . $filename;
            $hash = hash_file('sha256', $path);
            if (!is_string($hash) || !hash_equals(self::string($source, 'sha256'), $hash)
                || ($source['byte_length'] ?? null) !== filesize($path)
            ) {
                throw new \UnexpectedValueException("Zdroj externího číselníku {$filename} neodpovídá manifestu.");
            }
        }
    }

    /** @param array{manifest_sha256:string,payload:array<string,mixed>} $manifest */
    private function assertCovered(array $manifest, string $codebookKey, string $validOn): void
    {
        foreach ($manifest['payload']['codebooks'] as $codebook) {
            if ($codebook['codebook_key'] === $codebookKey) {
                if ($validOn < $codebook['effective_from']
                    || ($codebook['effective_to'] !== null && $validOn > $codebook['effective_to'])
                    || $validOn > $codebook['verified_through']
                ) {
                    throw new JmhzCodebookUnavailableException(
                        "Číselník JMHZ {$codebookKey} není pro datum {$validOn} ověřený.",
                    );
                }
                return;
            }
        }
        throw new JmhzCodebookUnavailableException("Číselník JMHZ {$codebookKey} není v overlay dostupný.");
    }

    /**
     * @param array{manifest:array{manifest_sha256:string,payload:array<string,mixed>},entries:array<string,array<string,array<string,mixed>>>} $package
     * @return array<string,array<string,mixed>>
     */
    private function codebookEntries(array $package, string $codebookKey): array
    {
        $entries = $package['entries'][$codebookKey] ?? null;
        if (!is_array($entries)) {
            throw new JmhzCodebookUnavailableException(
                "Číselník JMHZ {$codebookKey} není v overlay dostupný.",
            );
        }
        return $entries;
    }

    /** @param array<string,mixed> $entry */
    private function entryCovers(array $entry, string $validOn): bool
    {
        return $entry['valid_from'] <= $validOn
            && ($entry['valid_to'] === null || $entry['valid_to'] >= $validOn);
    }

    /**
     * @param array{manifest_sha256:string,payload:array<string,mixed>} $manifest
     * @return array{overlay_key:string,manifest_sha256:string,snapshot_date:string,effective_from:string,effective_to:?string,verified_through:string,base_spec_manifest_sha256:string}
     */
    private function provenanceFromManifest(array $manifest): array
    {
        $payload = $manifest['payload'];
        $municipalities = $payload['codebooks'][0];

        return [
            'overlay_key' => $payload['overlay_key'],
            'manifest_sha256' => $manifest['manifest_sha256'],
            'snapshot_date' => $payload['snapshot_date'],
            'effective_from' => $municipalities['effective_from'],
            'effective_to' => $municipalities['effective_to'],
            'verified_through' => $municipalities['verified_through'],
            'base_spec_manifest_sha256' => $payload['base_spec']['manifest_sha256'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function list(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("Pole {$field} overlay externích číselníků není seznam.");
        }
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new \UnexpectedValueException("Pole {$field} obsahuje neplatný řádek.");
            }
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException("Pole {$field} overlay externích číselníků není text.");
        }

        return $value;
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }
}
