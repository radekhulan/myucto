<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Náhled agendy Money S3 ze zálohy — co průvodce ukáže před importem: firma, verze
 * Money, účetní roky a kolik čeho v nich je. Nic nezapisuje.
 */
final class AgendaInfo
{
    /** Verze Money, na kterých je čtení formátu ověřené proti sestavám z Money. */
    public const VERIFIED_VERSIONS = ['26.600'];

    /**
     * @param list<array{dir:string,fiscal_year:?int,journal_rows:int,opening_rows:int,first_date:?string,last_date:?string,purchase_invoices:int,issued_invoices:int,cash_documents:int,bank_documents:int}> $years
     * @param list<array{code:string,message:string}> $warnings
     */
    public function __construct(
        public readonly string $name,
        public readonly string $ico,
        public readonly string $dic,
        public readonly string $street,
        public readonly string $city,
        public readonly string $zip,
        public readonly string $version,
        public readonly string $backupAt,
        public readonly array $years,
        public readonly int $partners,
        public readonly array $warnings,
    ) {}

    public static function fromBackup(Ms3Backup $backup): self
    {
        $ini = $backup->agendaInfoIni();
        $company = $backup->agendaCompany();
        $warnings = [];

        $years = [];
        foreach ($backup->yearDirs() as $dir) {
            $journal = $backup->table('UcDenik', $dir);
            $rows = $journal !== null && $journal->hasData() ? iterator_to_array($journal->rows(), false) : [];
            $opening = 0;
            $dates = [];
            foreach ($rows as $r) {
                if (Ms3Journal::isOpening($r)) {
                    $opening++;
                } elseif (($r['Datum'] ?? null) !== null) {
                    $dates[] = (string) $r['Datum'];
                }
            }
            sort($dates);
            $years[] = [
                'dir' => basename($dir),
                'fiscal_year' => $rows === [] ? null : Ms3Journal::fiscalYear($rows),
                'journal_rows' => count($rows),
                'opening_rows' => $opening,
                'first_date' => $dates[0] ?? null,
                'last_date' => $dates === [] ? null : $dates[count($dates) - 1],
                'purchase_invoices' => self::count($backup, 'PFaktury', $dir),
                'issued_invoices' => self::count($backup, 'VFaktury', $dir),
                'cash_documents' => self::count($backup, 'PoklKnih', $dir),
                'bank_documents' => self::count($backup, 'BankKnih', $dir),
            ];
        }

        $partners = 0;
        $address = $backup->table('AdresarF');
        if ($address !== null && $address->hasData()) {
            foreach ($address->rows() as $_) {
                $partners++;
            }
        }

        $ico = $company['ico'] !== '' ? $company['ico'] : $ini['ico'];
        if ($years === []) {
            $warnings[] = ['code' => 'no_years', 'message' => 'Záloha neobsahuje žádný účetní rok.'];
        }
        foreach ($years as $y) {
            if ($y['journal_rows'] === 0) {
                $warnings[] = ['code' => 'empty_year', 'message' => "Rok v adresáři {$y['dir']} nemá účetní deník."];
            }
        }
        $seen = [];
        foreach ($years as $y) {
            if ($y['fiscal_year'] !== null && isset($seen[$y['fiscal_year']])) {
                $warnings[] = ['code' => 'duplicate_year', 'message' => "Účetní rok {$y['fiscal_year']} je v záloze dvakrát."];
            }
            $seen[$y['fiscal_year']] = true;
        }
        if ($ini['version'] !== '' && !in_array($ini['version'], self::VERIFIED_VERSIONS, true)) {
            $warnings[] = [
                'code' => 'version_not_verified',
                'message' => 'Záloha je z Money S3 ' . $ini['version'] . '. Čtení je ověřené na verzi '
                    . implode(', ', self::VERIFIED_VERSIONS) . '; výsledek o to pečlivěji porovnejte se sestavami z Money.',
            ];
        }

        return new self(
            $company['name'] !== '' ? $company['name'] : $ini['name'],
            $ico,
            strtoupper(str_replace(' ', '', $company['dic'])),
            $company['street'],
            $company['city'],
            str_replace(' ', '', $company['zip']),
            $ini['version'],
            $ini['backup_at'],
            $years,
            $partners,
            $warnings,
        );
    }

    /** @return list<int> */
    public function fiscalYears(): array
    {
        $out = [];
        foreach ($this->years as $y) {
            if ($y['fiscal_year'] !== null) {
                $out[] = (int) $y['fiscal_year'];
            }
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'ico' => $this->ico,
            'dic' => $this->dic,
            'street' => $this->street,
            'city' => $this->city,
            'zip' => $this->zip,
            'version' => $this->version,
            'version_verified' => $this->version === '' || in_array($this->version, self::VERIFIED_VERSIONS, true),
            'backup_at' => $this->backupAt,
            'years' => $this->years,
            'partners' => $this->partners,
            'warnings' => $this->warnings,
        ];
    }

    private static function count(Ms3Backup $backup, string $table, string $dir): int
    {
        $t = $backup->table($table, $dir);
        if ($t === null || !$t->hasData()) {
            return 0;
        }
        $n = 0;
        foreach ($t->rows() as $_) {
            $n++;
        }
        return $n;
    }
}
