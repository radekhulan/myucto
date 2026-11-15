<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Anonymization\AnonymizationPolicy;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use RuntimeException;

/**
 * Šifrované mzdové údaje při obnově firmy z Kompletního exportu.
 *
 * Export nese mzdové osobní údaje tak, jak jsou uložené: kontextově zašifrované
 * (`enc:v2:`) klíčem zdrojové instalace a s kontextem z firmy a řádku zdroje, k tomu
 * vyhledávací otisk (keyed hash s firmou) a masku. Obnova do jiné firmy nebo jiné
 * instalace mění firmu, ID řádků i klíč, takže hodnotu odhalí klíčem a kontextem
 * zdroje a hned ji zapečetí pro cílovou firmu a cílový řádek aktuálním klíčem cíle.
 * Otevřená hodnota proces neopouští.
 *
 * Šifrované sloupce, jejich otisky, masky a entitu kontextu určuje
 * {@see AnonymizationPolicy::COLUMNS} (strategie `sealed:`), jediný katalog těchto
 * sloupců. Šifrovaná hodnota ve sloupci, který katalog nezná, obnovu zastaví:
 * přenesená beze změny by v cíli nešla přečíst.
 *
 * Klíče zdrojové instalace dodá správce souborem (JSON s `secret_encryption_key`,
 * volitelně `secret_encryption_previous_keys`, `payroll_hash_key`, `pepper`), nikdy
 * nejsou součástí archivu. Bez souboru se zdroj čte klíči cílové instalace (obnova
 * v téže instalaci). Je-li k dispozici hashovací klíč zdroje, obnova navíc ověří,
 * že odhalená hodnota odpovídá otisku uloženému ve zdroji.
 */
final class MyuctoImportSealedValues
{
    /** @var array<string,list<array{column:string,field:string,type_column:?string,hash:string,masked:?string,entity:string}>>|null */
    private static ?array $catalog = null;
    /**
     * Šifrované sloupce, které katalog anonymizace vede jinak než `sealed:` (anonymizace je
     * ponechává), ale zapečetěné jsou stejně vázané na firmu a řádek
     * ({@see \MyInvoice\Service\Payroll\Security\PayrollKeyRotationService::targets()}).
     */
    private const EXTRA_SEALED = [
        'payroll_institution_accounts' => ['bank_account_ciphertext' => 'sealed:bank_account:bank_account_hash:bank_account_masked:id'],
    ];

    private int $resealed = 0;

    public function __construct(
        private readonly PayrollSensitiveData $target,
        private readonly PayrollSensitiveData $source,
        private readonly bool $verifySourceHash,
    ) {}

    /**
     * @param array<string,mixed>|null $sourceKeys klíče zdrojové instalace; null = stejná instalace
     */
    public static function create(Config $targetConfig, ?array $sourceKeys): self
    {
        $targetEncryption = new SecretEncryption($targetConfig);
        $target = new PayrollSensitiveData($targetEncryption, $targetConfig);
        if ($sourceKeys === null) {
            return new self($target, $target, true);
        }
        $app = [];
        foreach (['secret_encryption_key', 'payroll_hash_key', 'pepper'] as $key) {
            $value = $sourceKeys[$key] ?? '';
            if (!is_string($value)) {
                throw new RuntimeException('Klíče zdrojové instalace: ' . $key . ' musí být řetězec.');
            }
            $app[$key] = trim($value);
        }
        $previous = $sourceKeys['secret_encryption_previous_keys'] ?? [];
        if (is_string($previous)) {
            $previous = array_values(array_filter(array_map('trim', explode(',', $previous))));
        }
        if (!is_array($previous) || array_filter($previous, static fn (mixed $v): bool => !is_string($v)) !== []) {
            throw new RuntimeException('Klíče zdrojové instalace: secret_encryption_previous_keys musí být seznam řetězců.');
        }
        $app['secret_encryption_previous_keys'] = $previous;
        if ($app['secret_encryption_key'] === '' && $app['pepper'] === '') {
            throw new RuntimeException('Klíče zdrojové instalace musí obsahovat secret_encryption_key (nebo pepper, odvozuje-li se z něj).');
        }
        $sourceConfig = new Config(['app' => $app]);
        $sourceEncryption = new SecretEncryption($sourceConfig);
        $problem = $sourceEncryption->validateKey();
        if ($problem !== null && $app['secret_encryption_key'] !== '') {
            throw new RuntimeException('Klíče zdrojové instalace: ' . $problem);
        }
        return new self($target, new PayrollSensitiveData($sourceEncryption, $sourceConfig),
            $app['payroll_hash_key'] !== '' || $app['pepper'] !== '');
    }

    /** @return array<string,mixed> obsah souboru s klíči zdrojové instalace */
    public static function readKeyFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Soubor s klíči zdrojové instalace nejde přečíst.');
        }
        $keys = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($keys) || array_is_list($keys)) {
            throw new RuntimeException('Soubor s klíči zdrojové instalace musí být objekt JSON.');
        }
        return $keys;
    }

    /** Má tabulka šifrované sloupce, které obnova přešifruje? */
    public static function covers(string $table): bool
    {
        return isset(self::catalog()[$table]);
    }

    /** Je sloupec šifrovanou hodnotou nebo jejím otiskem či maskou (obnova je počítá znovu)? */
    public static function isDerivedColumn(string $table, string $column): bool
    {
        foreach (self::catalog()[$table] ?? [] as $spec) {
            if ($column === $spec['column'] || $column === $spec['hash'] || $column === $spec['masked']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Shoda šifrované hodnoty v cíli: šifra má náhodný nonce, takže opakovaná obnova
     * vytvoří jiný text. Porovná se odhalená hodnota obou (klíčem a kontextem cíle).
     *
     * @param array<string,mixed> $targetRow řádek cíle (firma a entita kontextu)
     */
    public function sameCiphertext(string $table, string $column, array $targetRow, mixed $expected, mixed $actual): bool
    {
        if ($expected === null || $actual === null) {
            return $expected === $actual;
        }
        foreach (self::catalog()[$table] ?? [] as $spec) {
            if ($spec['column'] !== $column) {
                continue;
            }
            $type = $spec['type_column'] !== null ? (string) ($targetRow[$spec['type_column']] ?? '') : $spec['field'];
            [$field] = AnonymizationPolicy::sealedField($type);
            $supplier = MyuctoExportReader::id($targetRow['supplier_id'] ?? null);
            $entity = MyuctoExportReader::id($targetRow[$spec['entity']] ?? null);
            try {
                return hash_equals(
                    $this->target->reveal((string) $expected, $field, $supplier, $entity, PayrollRevealPurpose::RESTORE_RESEAL),
                    $this->target->reveal((string) $actual, $field, $supplier, $entity, PayrollRevealPurpose::RESTORE_RESEAL),
                );
            } catch (\Throwable) {
                return false;
            }
        }
        return (string) $expected === (string) $actual;
    }

    /** Je sloupec šifrovanou hodnotou z katalogu (ne otiskem ani maskou)? */
    public static function isCiphertextColumn(string $table, string $column): bool
    {
        foreach (self::catalog()[$table] ?? [] as $spec) {
            if ($column === $spec['column']) {
                return true;
            }
        }
        return false;
    }

    public function resealedCount(): int
    {
        return $this->resealed;
    }

    /**
     * Přešifruje šifrované sloupce řádku pro cílovou firmu a cílový řádek.
     *
     * @param array<string,mixed> $sourceRow řádek exportu (zdrojová firma a ID)
     * @param array<string,mixed> $targetRow přemapovaný řádek (cílová firma a ID)
     * @return array<string,mixed> přemapovaný řádek s novou šifrou, otiskem a maskou
     */
    public function reseal(string $table, array $sourceRow, array $targetRow): array
    {
        foreach (self::catalog()[$table] ?? [] as $spec) {
            $ciphertext = $sourceRow[$spec['column']] ?? null;
            if ($ciphertext === null || $ciphertext === '') {
                continue;
            }
            if (!is_string($ciphertext) || !str_starts_with($ciphertext, 'enc:v2:')) {
                throw new RuntimeException('Mzdový údaj ' . $table . '.' . $spec['column'] . ' v exportu není kontextově šifrovaný.');
            }
            $type = $spec['type_column'] !== null ? (string) ($sourceRow[$spec['type_column']] ?? '') : $spec['field'];
            [$field] = AnonymizationPolicy::sealedField($type);
            $sourceSupplier = MyuctoExportReader::id($sourceRow['supplier_id'] ?? null);
            $targetSupplier = MyuctoExportReader::id($targetRow['supplier_id'] ?? null);
            try {
                $plain = $this->source->reveal($ciphertext, $field, $sourceSupplier,
                    MyuctoExportReader::id($sourceRow[$spec['entity']] ?? null), PayrollRevealPurpose::RESTORE_RESEAL);
            } catch (\Throwable) {
                throw new RuntimeException('Mzdový údaj ' . $table . '.' . $spec['column'] . ' (zdrojové ID ' . ($sourceRow['id'] ?? '?')
                    . ') nejde dešifrovat. Zadejte klíče zdrojové instalace (secret_encryption_key, případně předchozí klíče).');
            }
            if ($this->verifySourceHash && ($sourceRow[$spec['hash']] ?? null) !== null
                && !hash_equals((string) $sourceRow[$spec['hash']], $this->source->lookupHash($plain, $field, $sourceSupplier))) {
                throw new RuntimeException('Mzdový údaj ' . $table . '.' . $spec['column'] . ' (zdrojové ID ' . ($sourceRow['id'] ?? '?')
                    . ') nesedí na svůj otisk ve zdroji. Zkontrolujte payroll_hash_key a pepper zdrojové instalace.');
            }
            $sealed = $this->target->seal($plain, $field, $targetSupplier, MyuctoExportReader::id($targetRow[$spec['entity']] ?? null));
            unset($plain);
            if ($spec['masked'] !== null && ($sourceRow[$spec['masked']] ?? null) !== null
                && (string) $sourceRow[$spec['masked']] !== $sealed->masked) {
                throw new RuntimeException('Mzdový údaj ' . $table . '.' . $spec['column'] . ' (zdrojové ID ' . ($sourceRow['id'] ?? '?')
                    . ') má po přešifrování jinou masku než ve zdroji.');
            }
            $targetRow[$spec['column']] = $sealed->ciphertext;
            $targetRow[$spec['hash']] = $sealed->lookupHash;
            if ($spec['masked'] !== null) {
                $targetRow[$spec['masked']] = $sealed->masked;
            }
            $this->resealed++;
        }
        return $targetRow;
    }

    /**
     * Šifrovaná hodnota ve sloupci mimo katalog by v cíli nešla přečíst. Kontroluje se
     * každý převáděný řádek, ať se nic nepřenese potichu nečitelné.
     *
     * @param array<string,mixed> $row
     */
    public static function assertNoUnknownCiphertext(string $table, array $row): void
    {
        foreach ($row as $column => $value) {
            if (is_string($value) && (str_starts_with($value, 'enc:v1:') || str_starts_with($value, 'enc:v2:'))
                && !self::isDerivedColumn($table, (string) $column)) {
                throw new RuntimeException('Export nese šifrovanou hodnotu ' . $table . '.' . $column . ', kterou obnova neumí přešifrovat.');
            }
        }
    }

    /** @return array<string,list<array{column:string,field:string,type_column:?string,hash:string,masked:?string,entity:string}>> */
    private static function catalog(): array
    {
        if (self::$catalog === null) {
            self::$catalog = [];
            foreach (array_replace_recursive(AnonymizationPolicy::COLUMNS, self::EXTRA_SEALED) as $table => $columns) {
                foreach ($columns as $column => $strategy) {
                    if (is_string($strategy) && AnonymizationPolicy::isSealed($strategy)) {
                        self::$catalog[$table][] = ['column' => $column, ...AnonymizationPolicy::parseSealed($strategy)];
                    }
                }
            }
        }
        return self::$catalog;
    }
}
