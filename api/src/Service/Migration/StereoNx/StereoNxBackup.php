<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use Blondak\Nx1\Nx1Database;
use ZipArchive;

/** Přímé čtení archivu bez rozbalování a bez předávání hesla procesům či protokolům. */
final class StereoNxBackup
{
    private const MAX_ENTRIES = 20000;
    private const MAX_TABLE_BYTES = 64 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 1024 * 1024 * 1024;
    private ?array $companyIdentityCache = null;

    private function __construct(
        private readonly Nx1Database $database,
        private readonly string $path,
        private readonly string $prefix,
        #[\SensitiveParameter] private readonly ?string $password,
    ) {}

    /** @return list<array{index:int,prefix:string,label:string,source_key:string}> */
    public static function companies(string $path, #[\SensitiveParameter] ?string $password = null): array
    {
        return self::inspectArchive($path, $password ?? StereoNxBackupPassword::value());
    }

    public static function open(string $path, int $companyIndex, #[\SensitiveParameter] ?string $password = null): self
    {
        $password ??= StereoNxBackupPassword::value();
        $companies = self::inspectArchive($path, $password);
        foreach ($companies as $company) {
            if ($company['index'] === $companyIndex) {
                return new self(Nx1Database::fromZip($path, $company['prefix'], $password), $path, $company['prefix'], $password);
            }
        }
        throw new StereoNxException('company_missing', 'Vybraná firma není uvedena v ObsahBck.txt.');
    }

    /** @return array<string,mixed> */
    public function companyIdentity(): array
    {
        if ($this->companyIdentityCache !== null) return $this->companyIdentityCache;
        $zip = new ZipArchive();
        if ($zip->open($this->path, ZipArchive::RDONLY) !== true) {
            throw new StereoNxException('archive_open', 'Zálohu Stereo NX nelze otevřít.');
        }
        try {
            if ($this->password !== null) $zip->setPassword($this->password);
            $stat = $zip->statName($this->prefix . 'firma.bin');
            if ($stat === false || $stat['size'] > 1048576) {
                throw new StereoNxException('company_metadata_missing', 'Chybí podporovaný soubor firma.bin.');
            }
            $bytes = @$zip->getFromName($this->prefix . 'firma.bin');
            if ($bytes === false) {
                throw new StereoNxException('company_metadata_read', 'Soubor firma.bin nelze přečíst; ověřte heslo a integritu zálohy.');
            }
            $identity = StereoNxCompanyMetadata::parse($bytes);
            $mode = $this->accountingModeEvidence();
            return $this->companyIdentityCache = $identity
                + ['accounting_mode' => $mode['mode'], 'accounting_mode_evidence' => $mode['evidence']];
        } finally {
            $zip->close();
        }
    }

    /** @return array{mode:?string,evidence:array{method:string,rows:int,with_both_accounts:int,without_accounts:int,partial_accounts:int}} */
    private function accountingModeEvidence(): array
    {
        $evidence = ['method' => 'Cdenik.UcetMD/UcetD', 'rows' => 0,
            'with_both_accounts' => 0, 'without_accounts' => 0, 'partial_accounts' => 0];
        if (!in_array('Cdenik', $this->tableNames(), true)) {
            return ['mode' => null, 'evidence' => $evidence];
        }
        foreach ($this->rows('Cdenik') as $row) {
            $evidence['rows']++;
            if (!isset($row['UcetMD'], $row['UcetD'])
                || !is_string($row['UcetMD']) || !is_string($row['UcetD'])) {
                $evidence['partial_accounts']++;
                continue;
            }
            $md = trim($row['UcetMD']) !== '';
            $dal = trim($row['UcetD']) !== '';
            if ($md && $dal) $evidence['with_both_accounts']++;
            elseif (!$md && !$dal) $evidence['without_accounts']++;
            else $evidence['partial_accounts']++;
        }
        $mode = match (true) {
            $evidence['rows'] > 0 && $evidence['with_both_accounts'] === $evidence['rows'] => 'double_entry',
            $evidence['rows'] > 0 && $evidence['without_accounts'] === $evidence['rows'] => 'tax_evidence',
            default => null,
        };
        return ['mode' => $mode, 'evidence' => $evidence];
    }

    /** @return list<string> */
    public function tableNames(): array
    {
        return $this->database->tableNames(false);
    }

    public function companyIndex(): int
    {
        return (int) substr($this->prefix, strlen('Firma_'), -1);
    }

    /** @return \Generator<array<string,mixed>> */
    public function rows(string $table): \Generator
    {
        if (!$this->database->hasTable($table)) {
            throw new StereoNxException('table_missing', 'Chybí požadovaná tabulka Stereo NX: ' . $table);
        }
        yield from $this->database->table($table)->rows();
    }

    /** Historické sazby patří programu, nikoli vybrané firmě. Starší záloha je nemusí obsahovat.
     * @return list<array<string,mixed>> */
    public function payrollRates(): array
    {
        $global = Nx1Database::fromZip($this->path, 'DataPrg/GDATA/', $this->password);
        if (!$global->hasTable('Gparrok')) return [];
        $table = $global->table('Gparrok');
        $rows = iterator_to_array($table->rows(), false);
        if (count($rows) !== $table->declaredRowCount()) {
            throw new StereoNxException('payroll_rates_decode', 'Tabulku historických mzdových sazeb nelze úplně načíst.');
        }
        return $rows;
    }

    /** Schéma a počty, nikdy hodnoty firemních řádků ani výjimky obsahující jejich obsah.
     * @return array<string,array{status:string,declared_rows:?int,decoded_rows:int,fields:array<string,string>}> */
    public function inventory(): array
    {
        $out = [];
        foreach ($this->tableNames() as $table) {
            $entry = ['status' => 'error', 'declared_rows' => null, 'decoded_rows' => 0, 'fields' => []];
            try {
                $reader = $this->database->table($table);
                foreach ($reader->schema()->fields as $field) {
                    $entry['fields'][$field->name] = $field->type;
                }
                $entry['declared_rows'] = $reader->declaredRowCount();
                foreach ($reader->rows() as $_row) {
                    $entry['decoded_rows']++;
                }
                $entry['status'] = $entry['decoded_rows'] === $entry['declared_rows'] ? 'ok' : 'row_count_mismatch';
            } catch (\Throwable) {
                // Chybu dekódování neskrýváme jako prázdnou tabulku.
                $entry['status'] = 'decode_error';
            }
            $out[$table] = $entry;
        }
        return $out;
    }

    /** @return list<array{index:int,prefix:string,label:string,source_key:string}> */
    private static function inspectArchive(string $path, #[\SensitiveParameter] ?string $password): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new StereoNxException('archive_open', 'Zálohu Stereo NX nelze otevřít.');
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new StereoNxException('archive_limit', 'Záloha obsahuje příliš mnoho souborů.');
            }
            if ($password !== null) {
                $zip->setPassword($password);
            }
            $seen = [];
            $prefixes = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new StereoNxException('archive_entry', 'Nelze přečíst seznam souborů zálohy.');
                }
                $name = $stat['name'];
                $parts = explode('/', $name);
                if (str_contains($name, '\\') || str_starts_with($name, '/') || str_contains($name, ':')
                    || in_array('..', $parts, true) || in_array('.', $parts, true)
                    || preg_match('/[\x00-\x1f]/', $name)) {
                    throw new StereoNxException('archive_path', 'Záloha obsahuje nepovolenou cestu.');
                }
                $key = strtolower($name);
                if (isset($seen[$key])) {
                    throw new StereoNxException('archive_duplicate', 'Záloha obsahuje nejednoznačná jména souborů.');
                }
                $seen[$key] = true;
                $total += $stat['size'];
                if ($total > self::MAX_TOTAL_BYTES || $stat['size'] > self::MAX_TABLE_BYTES) {
                    throw new StereoNxException('archive_limit', 'Rozbalená velikost zálohy překračuje limit.');
                }
                if (preg_match('~^(Firma_(?:0|[1-9][0-9]{0,8})/)[^/]+\.nx1$~D', $name, $match)) {
                    $prefixes[$match[1]] = true;
                }
                if ($key === 'obsahbck.txt' && ($name !== 'ObsahBck.txt' || $stat['size'] > 1048576)) {
                    throw new StereoNxException('manifest_invalid', 'Nepodporovaný seznam firem zálohy.');
                }
            }
            if (!isset($seen['obsahbck.txt'])) {
                throw new StereoNxException('manifest_missing', 'V záloze chybí ObsahBck.txt.');
            }
            $bytes = @$zip->getFromName('ObsahBck.txt');
            if ($bytes === false) {
                throw new StereoNxException('archive_password', 'Seznam firem nelze přečíst; ověřte heslo a neporušenost zálohy.');
            }
            $companies = StereoNxManifest::parse($bytes);
            foreach ($companies as $company) {
                if (!isset($prefixes[$company['prefix']])) {
                    throw new StereoNxException('company_tables_missing', 'Firma ze seznamu nemá v záloze tabulky NX1.');
                }
            }
            return $companies;
        } finally {
            $zip->close();
        }
    }
}
