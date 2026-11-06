<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class JmhzSpecPackageCatalog
{
    public const DEFAULT_PACKAGE_KEY =
        'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.11_manifest-v1';
    public const DEFAULT_MANIFEST_SHA256 =
        '39bb516b4fff204c44f0b8a6b9e0ec40601ef73af79460bdf937777e046c00d8';

    /**
     * Balík specifikace pro vykazovaná období od 01/2027 ({@see JmhzSpecVersion}).
     * Staví ho WP-1 (datový slovník 1.5.0.2, katalog kontrol 1.5.0.3). Dokud
     * {@see self::MANIFEST_SHA256_2027} zůstává `null`, verze 2027 je
     * nedostupná a sestavení hlášení za 2027 se fail-closed odmítne
     * ({@see self::requirePin()}). Integrátor sem po připnutí doplní klíč
     * (pokud se liší) a otisk manifestu; nic dalšího se přepínat nemusí.
     */
    public const PACKAGE_KEY_2027 =
        'jmhz-xsd-1.5.0.3_dictionary-1.5.0.3_controls-source-1.5.0.4_manifest-v1';
    public const MANIFEST_SHA256_2027 =
        'fc244e34b74117cfc332b62d787738754562b91c61f33ee29370197967f8e5b0';

    /**
     * Ročníky, jejichž hlášení se smí sestavit a zmrazit. Serializér XSD 1.5
     * i dokument podle jeho kontraktu už ročník 2027 umí; mzdový běh za 01/2027
     * ale dál stojí na číselnících obcí a států, které pro rok 2027 připnuté
     * nejsou (kontroly 152, 153, 335). Ročník 2027 sem přibude s nimi
     * (`PayrollJmhz2027FlowTest` hlídá, že je to poslední mezera toku).
     *
     * @var list<JmhzSpecVersion>
     */
    private const SERIALIZABLE_VERSIONS = [JmhzSpecVersion::V2026];

    /** Kód blokace sestavení hlášení verze, která není připnutá. */
    public const UNAVAILABLE_CODE = 'jmhz_spec_version_unavailable';

    private const PACKAGE_DIRECTORIES = [
        self::DEFAULT_PACKAGE_KEY => 'dictionary-1.4.1.6',
        self::PACKAGE_KEY_2027 => 'dictionary-1.5.0.3',
    ];

    /**
     * Připnutý balík pro každou verzi; `null` = verze v téhle instalaci není.
     *
     * @var array<string,array{package_key:string,manifest_sha256:?string}>
     */
    private const VERSION_PINS = [
        '2026' => [
            'package_key' => self::DEFAULT_PACKAGE_KEY,
            'manifest_sha256' => self::DEFAULT_MANIFEST_SHA256,
        ],
        '2027' => [
            'package_key' => self::PACKAGE_KEY_2027,
            'manifest_sha256' => self::MANIFEST_SHA256_2027,
        ],
    ];

    /**
     * Manifesty přibalených balíků ověřené v tomto procesu (klíč balíku => manifest).
     *
     * @var array<string, array{manifest_sha256:string,payload:array<string, mixed>}>
     */
    private static array $bundled = [];

    /**
     * Payloady, jejichž hash a obsah už {@see self::validateManifest()} v tomto procesu
     * ověřila (hash manifestu => payload).
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $validated = [];

    /**
     * @param array<string,array{package_key:string,manifest_sha256:?string}>|null $versionPins
     *        náhrada {@see self::VERSION_PINS} jen pro testy s podstrčeným manifestem
     */
    public function __construct(
        private readonly ?string $resourceRoot = null,
        private readonly ?array $versionPins = null,
    ) {}

    /**
     * Ročník specifikace balíku podle jeho klíče. Historické balíky 1.4.x
     * (např. `jmhz-xsd-1.4.3.4_…` u starších zmrazených podání) patří
     * k verzi 2026; neznámý klíč vrací `null` a volající ho nesmí přijmout.
     */
    public static function versionOf(string $packageKey): ?JmhzSpecVersion
    {
        if ($packageKey === self::PACKAGE_KEY_2027) {
            return JmhzSpecVersion::V2027;
        }

        return str_starts_with($packageKey, 'jmhz-xsd-1.4.') ? JmhzSpecVersion::V2026 : null;
    }

    /**
     * Připnutý balík verze, nebo `null`, když ho tahle instalace nemá.
     *
     * @return array{package_key:string,manifest_sha256:string}|null
     */
    public function pinFor(JmhzSpecVersion $version): ?array
    {
        $pin = ($this->versionPins ?? self::VERSION_PINS)[$version->value] ?? null;
        if (!is_array($pin) || !is_string($pin['manifest_sha256'] ?? null) || $pin['manifest_sha256'] === '') {
            return null;
        }

        return ['package_key' => $pin['package_key'], 'manifest_sha256' => $pin['manifest_sha256']];
    }

    /**
     * Brána sestavení: hlášení verze, jejíž balík není připnutý, se nesestaví.
     * Výjimka je doménová s kódem pro obrazovku (409), ne chyba serveru.
     *
     * @return array{package_key:string,manifest_sha256:string}
     */
    public function requirePin(JmhzSpecVersion $version): array
    {
        return $this->pinFor($version) ?? throw new JmhzXmlException(
            self::UNAVAILABLE_CODE,
            'Hlášení se řídí specifikací ' . $version->label() . ', kterou tahle verze'
                . ' aplikace zatím neumí sestavit (chybí připnutý balík datového slovníku'
                . ' a katalogu kontrol). Podání se proto nepřipraví. Hlášení, opravy'
                . ' i storna za období do 12/2026 připravíte jako dosud.',
        );
    }

    /**
     * Brána pro místa, která hlášení sestavují a mrazí: verze musí mít
     * připnutý balík specifikace i schéma XSD. Platí stejně pro řádné
     * hlášení, opravu i storno - o verzi rozhoduje vykazované období.
     */
    public static function requireBuildable(JmhzSpecVersion $version): void
    {
        (new self())->requirePin($version);
        if (!JmhzSchemaCatalog::isPinned($version)) {
            (new JmhzSchemaCatalog())->entryPointFor($version);
        }
        // Specifikace a schéma ročníku jsou připnuté dřív, než je hotový jeho
        // serializér. Bez téhle pojistky by se hlášení za 01/2027 sestavilo
        // serializérem 2026 a zmrazilo ve tvaru, který XSD 1.5 nepřijme.
        if (!in_array($version, self::SERIALIZABLE_VERSIONS, true)) {
            throw new JmhzXmlException(
                self::UNAVAILABLE_CODE,
                'Hlášení se řídí specifikací ' . $version->label() . '. Její schéma je'
                    . ' připnuté, ale sestavení datové věty v tomto tvaru tahle verze'
                    . ' aplikace ještě neumí. Podání se proto nepřipraví. Hlášení, opravy'
                    . ' i storna za období do 12/2026 připravíte jako dosud.',
            );
        }
    }

    /** @return array{manifest_sha256:string,payload:array<string, mixed>} */
    public function load(string $packageKey, ?string $expectedManifestHash = null): array
    {
        $version = self::versionOf($packageKey);
        if ($version !== null && $version !== JmhzSpecVersion::V2026) {
            // Nepřipnutý ročník se nenačte ani s otiskem od volajícího: manifest
            // z adresáře, který integrátor ještě nepotvrdil, není specifikace.
            $pin = $this->requirePin($version);
            if ($pin['package_key'] !== $packageKey) {
                throw new \OutOfBoundsException("Balík specifikace JMHZ {$packageKey} není připnutý.");
            }
            $expectedManifestHash ??= $pin['manifest_sha256'];
        }
        if ($this->resourceRoot === null && isset(self::$bundled[$packageKey])) {
            $manifest = self::$bundled[$packageKey];
            if ($expectedManifestHash !== null && !hash_equals($expectedManifestHash, $manifest['manifest_sha256'])) {
                throw new \UnexpectedValueException('Balík JMHZ neodpovídá požadovanému SHA-256.');
            }
            return $manifest;
        }
        $directory = self::PACKAGE_DIRECTORIES[$packageKey] ?? null;
        if ($directory === null) {
            throw new \OutOfBoundsException("Neznámý balík specifikace JMHZ {$packageKey}.");
        }
        $root = $this->resourceRoot ?? dirname(__DIR__, 5) . '/resources/payroll/jmhz';
        $packageDirectory = $root . DIRECTORY_SEPARATOR . $directory;
        $manifestPath = $packageDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
        $json = file_get_contents($manifestPath);
        if ($json === false) {
            throw new \RuntimeException("Nelze načíst manifest balíku JMHZ {$packageKey}.");
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_string($decoded['manifest_sha256'] ?? null)
            || !is_array($decoded['payload'] ?? null)
        ) {
            throw new \UnexpectedValueException('Manifest balíku JMHZ nemá očekávanou strukturu.');
        }
        self::validateManifest($decoded);
        $payload = $decoded['payload'];
        if (($payload['package_key'] ?? null) !== $packageKey) {
            throw new \UnexpectedValueException('Manifest balíku JMHZ má jiný package_key.');
        }
        $actualHash = $decoded['manifest_sha256'];
        if ($expectedManifestHash !== null && !hash_equals($expectedManifestHash, $actualHash)) {
            throw new \UnexpectedValueException('Balík JMHZ neodpovídá požadovanému SHA-256.');
        }
        $this->verifySources($packageDirectory, $payload);
        $manifest = ['manifest_sha256' => $actualHash, 'payload' => $payload];
        if ($this->resourceRoot === null) {
            self::$bundled[$packageKey] = $manifest;
        }

        return $manifest;
    }

    /** @param array{manifest_sha256:string,payload:array<string, mixed>} $manifest */
    public static function validateManifest(array $manifest): void
    {
        // Tentýž payload pod týmž hashem už prošel; porovnání polí je řádově levnější
        // než kanonický JSON a hash celého balíku.
        if ((self::$validated[$manifest['manifest_sha256']] ?? null) === $manifest['payload']) {
            return;
        }
        $actualHash = hash('sha256', CanonicalJson::encode($manifest['payload']));
        if (!hash_equals($manifest['manifest_sha256'], $actualHash)) {
            throw new \UnexpectedValueException('Manifest balíku JMHZ neodpovídá svému SHA-256.');
        }
        self::verifyContent($manifest['payload']);
        self::$validated[$manifest['manifest_sha256']] = $manifest['payload'];
    }

    /** @param array<string, mixed> $payload */
    private function verifySources(string $packageDirectory, array $payload): void
    {
        foreach (self::list($payload['sources'] ?? null, 'sources') as $source) {
            $filename = self::string($source, 'filename');
            if (basename($filename) !== $filename) {
                throw new \UnexpectedValueException('Zdroj balíku JMHZ obsahuje neplatnou cestu.');
            }
            $actual = hash_file('sha256', $packageDirectory . DIRECTORY_SEPARATOR . $filename);
            if (!is_string($actual) || !hash_equals(self::string($source, 'sha256'), $actual)) {
                throw new \UnexpectedValueException("Zdroj {$filename} neodpovídá manifestu JMHZ.");
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private static function verifyContent(array $payload): void
    {
        $attributes = self::list($payload['dictionary_attributes'] ?? null, 'dictionary_attributes');
        $codebooks = self::list($payload['codebooks'] ?? null, 'codebooks');
        $attributeIds = [];
        foreach ($attributes as $attribute) {
            $id = self::string($attribute, 'attribute_id');
            if (isset($attributeIds[$id])) {
                throw new \UnexpectedValueException("Duplicitní atribut JMHZ {$id}.");
            }
            $attributeIds[$id] = true;
            $rowHash = self::string($attribute, 'row_hash');
            $withoutHash = $attribute;
            unset($withoutHash['row_hash']);
            if (!hash_equals($rowHash, hash('sha256', CanonicalJson::encode($withoutHash)))) {
                throw new \UnexpectedValueException("Atribut JMHZ {$id} má neplatný hash.");
            }
        }

        $codebookKeys = [];
        $entryCount = 0;
        $embeddedCodebooks = 0;
        $externalCodebooks = 0;
        foreach ($codebooks as $codebook) {
            $key = self::string($codebook, 'codebook_key');
            if (isset($codebookKeys[$key])) {
                throw new \UnexpectedValueException("Duplicitní číselník JMHZ {$key}.");
            }
            $codebookKeys[$key] = true;
            $entries = self::list($codebook['entries'] ?? null, "codebooks.{$key}.entries");
            $sourceKind = self::string($codebook, 'source_kind');
            if (!in_array($sourceKind, ['embedded', 'external_reference'], true)) {
                throw new \UnexpectedValueException("Číselník JMHZ {$key} má neznámý druh zdroje.");
            }
            if (($sourceKind === 'embedded') !== ($entries !== [])) {
                throw new \UnexpectedValueException("Číselník JMHZ {$key} má neplatný druh zdroje.");
            }
            $sourceKind === 'embedded' ? ++$embeddedCodebooks : ++$externalCodebooks;
            if (($codebook['entry_count'] ?? null) !== count($entries)) {
                throw new \UnexpectedValueException("Číselník JMHZ {$key} má neplatný počet položek.");
            }
            if (!hash_equals(
                self::string($codebook, 'content_hash'),
                hash('sha256', CanonicalJson::encode(['entries' => $entries])),
            )) {
                throw new \UnexpectedValueException("Číselník JMHZ {$key} má neplatný hash.");
            }
            $codes = [];
            foreach ($entries as $entry) {
                $code = self::string($entry, 'item_code');
                if (isset($codes[$code])) {
                    throw new \UnexpectedValueException("Číselník JMHZ {$key} obsahuje duplicitní kód {$code}.");
                }
                $codes[$code] = true;
                $rowHash = self::string($entry, 'row_hash');
                $withoutHash = $entry;
                unset($withoutHash['row_hash']);
                if (!hash_equals($rowHash, hash('sha256', CanonicalJson::encode($withoutHash)))) {
                    throw new \UnexpectedValueException("Položka {$key}/{$code} má neplatný hash.");
                }
            }
            $entryCount += count($entries);
        }
        foreach ($attributes as $attribute) {
            $codebookKey = $attribute['codebook_key'] ?? null;
            if ($codebookKey !== null
                && (!is_string($codebookKey) || !isset($codebookKeys[$codebookKey]))
            ) {
                throw new \UnexpectedValueException(
                    "Atribut JMHZ {$attribute['attribute_id']} odkazuje na neznámý číselník.",
                );
            }
        }

        $counts = $payload['counts'] ?? null;
        $actualCounts = [
            'attributes' => count($attributes),
            'reporting_marker_attributes' => count(array_filter(
                $attributes,
                static fn (array $row): bool => ($row['employer_registration_marker'] ?? null) !== null
                    || ($row['employee_registration_marker'] ?? null) !== null
                    || ($row['monthly_marker'] ?? null) !== null,
            )),
            'reporting_marker_xsd_mapped_attributes' => count(array_filter(
                $attributes,
                static fn (array $row): bool => (($row['employer_registration_marker'] ?? null) !== null
                        || ($row['employee_registration_marker'] ?? null) !== null
                        || ($row['monthly_marker'] ?? null) !== null)
                    && (($row['regzec_xsd_mapping'] ?? null) !== null
                        || ($row['xsd_mapping'] ?? null) !== null),
            )),
            'monthly_attributes' => count(array_filter(
                $attributes,
                static fn (array $row): bool => ($row['monthly_marker'] ?? null) !== null,
            )),
            'monthly_xsd_mapped_attributes' => count(array_filter(
                $attributes,
                static fn (array $row): bool => ($row['monthly_marker'] ?? null) !== null
                    && ($row['xsd_mapping'] ?? null) !== null,
            )),
            'codebooks' => count($codebooks),
            'embedded_codebooks' => $embeddedCodebooks,
            'external_reference_codebooks' => $externalCodebooks,
            'codebook_entries' => $entryCount,
        ];
        if (!is_array($counts)
            || CanonicalJson::encode($counts) !== CanonicalJson::encode($actualCounts)
        ) {
            throw new \UnexpectedValueException(
                'Souhrnné počty manifestu JMHZ neodpovídají obsahu: '
                . json_encode(['declared' => $counts, 'actual' => $actualCounts], JSON_THROW_ON_ERROR),
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private static function list(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("Pole {$field} manifestu JMHZ není seznam.");
        }
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new \UnexpectedValueException("Pole {$field} manifestu JMHZ obsahuje neplatný řádek.");
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException("Pole {$field} manifestu JMHZ není neprázdný text.");
        }

        return $value;
    }
}
