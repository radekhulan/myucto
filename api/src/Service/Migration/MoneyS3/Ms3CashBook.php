<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Peněžní deník daňové evidence Money S3 jednoho roku (`UcPenDen`) s číselníky, ze kterých
 * se čte: typy pohybů se sloupcem peněžního deníku (`UcPohyby`), předkontace daňové
 * evidence (`UcPrKntJ`) a bankovní účty a pokladny roku s počátečními stavy (`SzUcPokl`).
 *
 * Peněžní deník je v Money PROJEKCE bankovních, pokladních a interních dokladů: doklad má
 * jeden nebo víc řádků (základ podle předkontace, DPH zvlášť) a řádek nese vazbu na uhrazený
 * doklad (`UZdroj` + `URok` + `URadek` = vydaná a přijatá faktura, ostatní pohledávka
 * a závazek). Převod ho proto nepřidává jako další platby: kontroluje jím doklady a bere
 * z něj vazby úhrad a zařazení pohybů.
 *
 * Částky jsou se znaménkem peněžního toku: příjem (`Typ` P) kladně, výdaj (V) záporně.
 */
final class Ms3CashBook
{
    public const FIELDS = ['Druh', 'Datum', 'Zdroj', 'ZRadek', 'URok', 'UZdroj', 'URadek', 'Doklad', 'Typ', 'Pohyb', 'Castka', 'Mena', 'Kurz'];

    /** Zařazení DPH: daň z dokladu, odvod DPH a vratka DPH. */
    public const VAT = 'vat';

    /**
     * Sloupec peněžního deníku Money (`UcPohyby.Sloupec`) → zařazení v peněžním deníku MyÚčta
     * ({@see \MyInvoice\Repository\MovementClassificationRepository::TAX_BUCKETS}), {@see VAT}
     * = DPH. Jediné místo překladu; sloupec, který tu není, se nezařadí odhadem.
     */
    public const COLUMN_BUCKETS = [
        // Příjmy: prodej služeb a zboží a ostatní daňové příjmy (úroky, zálohy, kurzové zisky).
        'PSlu' => 'income_taxable', 'PZbo' => 'income_taxable', 'POst' => 'income_taxable',
        // Ostatní nedaňové příjmy (už zdaněné úroky, příjmy mimo základ daně).
        'POsN' => 'income_nontax',
        // Vklad podnikatele a osobní spotřeba.
        'PVkl' => 'private', 'VOso' => 'private',
        // Průběžné položky (převody mezi pokladnou a účtem).
        'PPrP' => 'transfer', 'VPrP' => 'transfer',
        // Daňové výdaje: zboží, materiál, režie a služby, mzdy, daň z mezd, pojistné zaměstnanců, drobný majetek.
        'VZbo' => 'expense_taxable', 'VMat' => 'expense_taxable', 'VRez' => 'expense_taxable', 'VMzd' => 'expense_taxable',
        'VDMz' => 'expense_taxable', 'VSoP' => 'expense_taxable', 'VZdP' => 'expense_taxable', 'VDrM' => 'expense_taxable',
        // Nedaňové výdaje: pojistné OSVČ, daň z příjmů, dary, investiční majetek (do výdajů jde odpisy), ostatní.
        'VOzc' => 'expense_nontax', 'VDzP' => 'expense_nontax', 'VDar' => 'expense_nontax', 'VHIM' => 'expense_nontax', 'VOsN' => 'expense_nontax',
        'PDPH' => self::VAT, 'VDPH' => self::VAT,
    ];

    /** Zdroje deníku, které převod zná: banka, pokladna, interní doklad. */
    public const SOURCES = ['BK', 'PK', 'ID'];

    /** Tabulky uhrazených dokladů podle `UZdroj`. */
    public const LINK_TABLES = ['FV' => 'VFaktury', 'FP' => 'PFaktury', 'KZ' => 'KnihZav', 'KP' => 'KnihPohl'];

    /**
     * @param array<string,array{source:string,number:int,document_no:string,date:?string,amount:float,non_cash:bool,currency:string,rate:float,parts:list<array{movement:string,column:?string,bucket:?string,amount:float,link:?array{table:string,dir:string,number:int}}>}> $movements klíč {@see key()}
     * @param array<string,array{type:string,column:string}> $movementTypes `UcPohyby.Zkrat` => typ, sloupec
     * @param array<string,array{base:string,vat:string}> $rules `UcPrKntJ.Zkrat` => pohyb základu, pohyb DPH
     * @param array<string,array{kind:string,opening:float,currency:string}> $accounts `SzUcPokl.Zkrat` => P pokladna / U účet, počáteční stav
     */
    private function __construct(
        public readonly string $dir,
        public readonly array $movements,
        public readonly array $movementTypes,
        public readonly array $rules,
        public readonly array $accounts,
    ) {}

    /** `$dir` = cesta k adresáři ROK.nnn ({@see Ms3Backup::yearDirs()}). */
    public static function read(Ms3Backup $backup, string $dir): self
    {
        $name = basename($dir);
        $movementTypes = [];
        foreach (self::rows($backup, 'UcPohyby', $dir, ['Zkrat', 'Typ', 'Sloupec']) as $r) {
            $code = trim((string) ($r['Zkrat'] ?? ''));
            if ($code !== '') {
                $movementTypes[$code] = ['type' => strtoupper(trim((string) ($r['Typ'] ?? ''))), 'column' => trim((string) ($r['Sloupec'] ?? ''))];
            }
        }
        $rules = [];
        foreach (self::rows($backup, 'UcPrKntJ', $dir, ['Zkrat', 'PohZak', 'ZauctDPH']) as $r) {
            $code = trim((string) ($r['Zkrat'] ?? ''));
            if ($code !== '') {
                $rules[$code] = ['base' => trim((string) ($r['PohZak'] ?? '')), 'vat' => trim((string) ($r['ZauctDPH'] ?? ''))];
            }
        }
        $accounts = [];
        foreach (self::rows($backup, 'SzUcPokl', $dir, ['Zkrat', 'UcPokl', 'PocStav', 'Mena']) as $r) {
            $code = trim((string) ($r['Zkrat'] ?? ''));
            if ($code !== '' && !isset($accounts[$code])) {
                $accounts[$code] = [
                    'kind' => strtoupper(trim((string) ($r['UcPokl'] ?? ''))),
                    'opening' => round((float) ($r['PocStav'] ?? 0), 2),
                    'currency' => trim((string) ($r['Mena'] ?? '')),
                ];
            }
        }

        $movements = [];
        foreach (self::rows($backup, 'UcPenDen', $dir, self::FIELDS) as $r) {
            $source = strtoupper(trim((string) ($r['Zdroj'] ?? '')));
            $number = (int) ($r['ZRadek'] ?? 0);
            $key = self::key($source, $number);
            $sign = strtoupper(trim((string) ($r['Typ'] ?? ''))) === 'P' ? 1 : -1;
            $amount = round($sign * (float) ($r['Castka'] ?? 0), 2);
            $movement = trim((string) ($r['Pohyb'] ?? ''));
            $column = $movementTypes[$movement]['column'] ?? null;
            $linkSource = strtoupper(trim((string) ($r['UZdroj'] ?? '')));
            $linkNumber = (int) ($r['URadek'] ?? 0);
            $linkYear = trim((string) ($r['URok'] ?? ''));
            $movements[$key] ??= [
                'source' => $source,
                'number' => $number,
                'document_no' => trim((string) ($r['Doklad'] ?? '')),
                'date' => is_string($r['Datum'] ?? null) ? $r['Datum'] : null,
                'amount' => 0.0,
                // Nepeněžní pohyb (zápočet): Money ho v deníku vede, peníze se nehýbou.
                'non_cash' => (int) ($r['Druh'] ?? 0) === 1,
                'currency' => trim((string) ($r['Mena'] ?? '')),
                'rate' => (float) ($r['Kurz'] ?? 0),
                'parts' => [],
            ];
            $movements[$key]['amount'] = round($movements[$key]['amount'] + $amount, 2);
            $movements[$key]['parts'][] = [
                'movement' => $movement,
                'column' => $column,
                'bucket' => $column !== null ? self::COLUMN_BUCKETS[$column] ?? null : null,
                'amount' => $amount,
                'link' => isset(self::LINK_TABLES[$linkSource]) && $linkNumber > 0
                    ? ['table' => $linkSource, 'dir' => $linkYear !== '' ? 'ROK.' . $linkYear : $name, 'number' => $linkNumber]
                    : null,
            ];
        }
        return new self($name, $movements, $movementTypes, $rules, $accounts);
    }

    public static function key(string $source, int $number): string
    {
        return $source . '|' . $number;
    }

    /**
     * Zařazení dokladu podle jeho předkontace daňové evidence (`PrKont`, `PredKontac`):
     * předkontace → pohyb základu → sloupec. Null = předkontaci, pohyb nebo sloupec Money
     * v záloze nemá nebo převod sloupec nezná.
     */
    public function ruleBucket(string $rule): ?string
    {
        $movement = $this->rules[trim($rule)]['base'] ?? null;
        $column = $movement !== null ? $this->movementTypes[$movement]['column'] ?? null : null;
        return $column !== null ? self::COLUMN_BUCKETS[$column] ?? null : null;
    }

    /**
     * Součty roku podle zařazení, se znaménkem peněžního toku. Části bez zařazení jsou
     * pod klíčem `''`.
     *
     * @return array<string,float>
     */
    public function totals(): array
    {
        $out = [];
        foreach ($this->movements as $m) {
            foreach ($m['parts'] as $part) {
                $bucket = $part['bucket'] ?? '';
                $out[$bucket] = round(($out[$bucket] ?? 0.0) + $part['amount'], 2);
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * @param list<string> $fields
     * @return \Generator<int,array<string,mixed>>
     */
    private static function rows(Ms3Backup $backup, string $table, string $dir, array $fields): \Generator
    {
        $t = $backup->table($table, $dir);
        if ($t === null || !$t->hasData()) {
            return;
        }
        yield from $t->rows($fields);
    }
}
