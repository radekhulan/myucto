<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaMdbAccounting;

/**
 * Syntetický převod datového souboru POHODY (`89_ucetnictvi_mdb.xml`): tabulky MDB
 * tak, jak je zapíše převodník. Jen vymyšlená data, IČO a rok jako {@see SyntheticPohodaExport}.
 */
final class SyntheticPohodaMdbExport
{
    /** Popis nezaúčtovaného pohybu ve výpisu {@see writeSharedBankNumber()}. */
    public const UNBOOKED_TEXT = 'Výdaj bez zaúčtování';

    /** Tabulky, které převod z MDB vyžaduje (mohou být prázdné). */
    private const TABLES = ['pUD', 'pOS', 'sDPH', 'sDPHTp', 'pPK', 'FA', 'FApol', 'AD', 'sUcet', 'sZeme', 'sCMeny', 'sFormUh', 'BV', 'BVpol', 'HO', 'HOpol', 'pINT', 'pINTpol', 'Uhrady'];

    /**
     * Agenda s výpisem pod jediným číslem dokladu, jako {@see SyntheticPohodaExport::withSharedBankNumber()}:
     * tři pohyby BAN0010050 téhož dne, příjem 500 Kč a poplatek 20 Kč mají v deníku řádek
     * s id svého bankovního dokladu (`pUD.RelAgID`), výdaj 20 Kč s popisem {@see UNBOOKED_TEXT}
     * řádek nemá. Je ve výpisu před poplatkem a zní na stejnou částku: podle částky by se
     * zaúčtovaný pohyb poznat nedal, rozhoduje id dokladu.
     *
     * @return string složka agendy
     */
    public static function writeSharedBankNumber(string $root): string
    {
        $date = '2026-04-10T00:00:00';
        $bank = static fn (string $id, string $type, string $amount, string $text): array =>
            ['ID' => $id, 'RelTpBV' => $type, 'Cislo' => SyntheticPohodaExport::SHARED_BANK, 'Datum' => $date, 'DatPlat' => $date,
                'Kc0' => $amount, 'RefUcet' => '1', 'Vypis' => '001/1', 'SText' => $text, 'Firma' => 'Banka Test'];
        $journal = static fn (string $id, string $agenda, string $source, string $day, string $amount, string $md, string $d): array =>
            ['ID' => $id, 'RelUdAg' => $agenda, 'RelAgID' => $source, 'Cislo' => $agenda === '28' ? SyntheticPohodaExport::SHARED_BANK : '',
                'Datum' => $day, 'Kc' => $amount, 'UMD' => $md, 'UD' => $d, 'SText' => 'Řádek'];
        return self::write($root, [
            'pOS' => array_map(static fn (string $code, string $name): array => ['Ucet' => $code, 'Nazev' => $name],
                ['221001', '261000', '411000', '568000', '701000'],
                ['Bankovní účet', 'Peníze na cestě', 'Základní kapitál', 'Ostatní finanční náklady', 'Počáteční účet rozvažný']),
            'sUcet' => [['ID' => '1', 'RelJeUcet' => '2', 'IDS' => 'BAN', 'SText' => SyntheticPohodaExport::BANK_ACCOUNT,
                'KodBanky' => SyntheticPohodaExport::BANK_CODE, 'Banka' => 'Testovací banka', 'AUcet' => '221001']],
            'pUD' => [
                $journal('1', '63', '0', '2026-01-01T00:00:00', '100000', '221001', '701000'),
                $journal('2', '63', '0', '2026-01-01T00:00:00', '100000', '701000', '411000'),
                $journal('3', '28', '11', $date, '500', '221001', '261000'),
                $journal('4', '28', '13', $date, '20', '568000', '221001'),
            ],
            'BV' => [$bank('11', '1', '500', 'Převod'), $bank('12', '2', '20', self::UNBOOKED_TEXT), $bank('13', '2', '20', 'Poplatek')],
        ]);
    }

    /**
     * Zapíše tabulky do složky agendy `<kořen>/<IČO>_<rok>`.
     *
     * @param array<string,list<array<string,string>>> $data řádky tabulek
     * @return string složka agendy
     */
    public static function write(string $root, array $data): string
    {
        $dir = rtrim($root, '/\\') . '/' . SyntheticPohodaExport::ICO . '_' . SyntheticPohodaExport::YEAR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tables = array_fill_keys(self::TABLES, []);
        $tables['sKonfig'] = [['ICO' => SyntheticPohodaExport::ICO, 'Rok' => (string) SyntheticPohodaExport::YEAR]];
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'utf-8');
        $xml->startElement('pohodaMdbAccounting');
        foreach (['formatVersion' => '1', 'ico' => SyntheticPohodaExport::ICO, 'year' => (string) SyntheticPohodaExport::YEAR, 'programVersion' => 'test'] as $key => $value) {
            $xml->writeAttribute($key, $value);
        }
        foreach (array_replace($tables, $data) as $name => $rows) {
            $xml->startElement('table');
            $xml->writeAttribute('name', $name);
            foreach ($rows as $row) {
                $xml->startElement('row');
                foreach ($row as $key => $value) {
                    $xml->writeElement($key, $value);
                }
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
        file_put_contents($dir . '/' . PohodaMdbAccounting::FILE, $xml->outputMemory());
        return $dir;
    }
}
