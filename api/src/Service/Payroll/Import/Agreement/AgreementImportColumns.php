<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Agreement;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMeaning;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoSampleProfile;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRules;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceText;

/**
 * Sloupce importu dohod (jen smlouvy, bez docházky): jeden řádek = jedna
 * dohoda nebo pracovní poměr. Údaje osoby a registrace ČSSZ mají stejné
 * hlavičky i nápovědu jako šablona Vzoru MyÚčto
 * ({@see AttendanceMyUctoSampleProfile::columns()}), takže firma může oba
 * sešity plnit stejně.
 */
final class AgreementImportColumns
{
    public const SHEET = 'Dohody';
    public const GUIDE_SHEET = 'Návod';

    /** Kolik hlavičkových buněk musí řádek poznat, aby byl hlavičkou. */
    private const HEADER_MIN = 3;

    /** @var list<array<string,mixed>>|null */
    private static ?array $columns = null;

    /**
     * @return list<array{
     *   header:string,patterns:list<string>,meaning:string,format:string,required:bool,help:string,
     *   examples:array{0:string|int|float|null,1:string|int|float|null}
     * }>
     */
    public static function columns(): array
    {
        if (self::$columns !== null) {
            return self::$columns;
        }
        $sample = [];
        foreach (AttendanceMyUctoSampleProfile::columns() as $column) {
            $sample[$column['meaning']] ??= $column;
        }
        $column = static fn (
            string $header,
            array $patterns,
            string $meaning,
            string $format,
            string $help,
            array $examples,
            bool $required = false,
        ): array => [
            'header' => $header,
            'patterns' => $patterns,
            'meaning' => $meaning,
            'format' => $format,
            'required' => $required,
            'help' => $help,
            'examples' => $examples,
        ];
        $from = static fn (string $meaning, ?string $help = null, ?array $examples = null): array => [
            'header' => $sample[$meaning]['header'],
            'patterns' => $sample[$meaning]['patterns'],
            'meaning' => $meaning,
            'format' => $sample[$meaning]['format'],
            'required' => false,
            'help' => $help ?? $sample[$meaning]['help'],
            'examples' => $examples ?? $sample[$meaning]['examples'],
        ];

        $registration = [];
        foreach (AttendanceMeaning::REGISTRATION as $meaning) {
            if (isset($sample[$meaning])) {
                $registration[] = $from($meaning);
            }
        }

        return self::$columns = [
            $from('personal_number', 'Vaše označení osoby. Nepovinné; nová dohoda ho dostane jako kód vztahu a import docházky podle něj osobu pozná.'),
            $column('Příjmení', ['příjmení'], 'last_name', AttendanceMyUctoSampleProfile::FORMAT_TEXT,
                'Povinné (nebo sloupec Příjmení a jméno).', ['Ukázková', 'Příkladný'], true),
            $column('Jméno', ['jméno', 'křestní jméno'], 'first_name', AttendanceMyUctoSampleProfile::FORMAT_TEXT,
                'Povinné (nebo sloupec Příjmení a jméno).', ['Eva', 'Adam'], true),
            $column('Příjmení a jméno', ['příjmení a jméno', 'jméno a příjmení'], 'person_name', AttendanceMyUctoSampleProfile::FORMAT_TEXT,
                'Místo dvou sloupců výše lze jméno zapsat do jednoho: nejdřív příjmení, pak jméno.', [null, null]),
            $from('birth_number', 'S lomítkem nebo bez, jako text. Podle rodného čísla import pozná osobu, která už v evidenci je; nové osobě se uloží na kartu.', ['0153150008', null]),
            $from('birth_date', 'Jen u osoby bez rodného čísla (typicky cizinec). S jménem pak import nabídne shodu s osobou v evidenci k potvrzení.', [null, '1998-11-02']),
            $from('relation_label', 'Povinné. DPP = dohoda o provedení práce, DPČ = dohoda o pracovní činnosti, HPP = pracovní poměr.', ['DPP', 'DPČ']),
            $from('start_date', 'Povinné. Den nástupu podle smlouvy. Jedna osoba může mít v souboru víc řádků s různými dohodami.', ['2026-12-01', '2026-12-15']),
            $from('end_date', 'Sjednaný konec dohody nebo pracovního poměru na dobu určitou. Prázdný = na dobu neurčitou.', ['2026-12-31', null]),
            $from('hourly_rate', 'Sjednaná hodinová odměna v Kč (např. 180). Uloží se do podmínek vztahu jako pravděpodobný výdělek.', [180, null]),
            $column('Měsíční odměna (Kč)', ['měsíční odměna*', 'měsíční mzda*', 'sjednaná odměna*'], 'monthly_wage', AttendanceMyUctoSampleProfile::FORMAT_AMOUNT,
                'Sjednaná měsíční odměna nebo mzda v celých Kč (u DPČ rozhoduje o zaměstnání malého rozsahu).', [null, 4000]),
            $from('weekly_hours', 'Sjednaná týdenní pracovní doba v hodinách jen u pracovního poměru.', [null, null]),
            $from('health_insurer_code', 'Kód zdravotní pojišťovny. Použije se jen u nové osoby.'),
            $from('bank_account', 'Účet pro výplatu. Uloží se jen nové osobě; před první výplatou ho na kartě osoby ověřte.'),
            ...$registration,
        ];
    }

    /**
     * Sloupce listu podle hlavičky: první řádek, který pozná aspoň tři
     * sloupce. Neznámé sloupce se ignorují.
     *
     * @param array<int,array<int,string>> $rows řádek → sloupec → text
     * @return array{row:int,columns:array<int,string>}|null sloupec → význam
     */
    public static function header(array $rows): ?array
    {
        foreach ($rows as $rowNumber => $cells) {
            if ($rowNumber > 20) {
                break;
            }
            $found = [];
            foreach ($cells as $columnNumber => $text) {
                $meaning = self::meaning($text);
                if ($meaning !== null && !in_array($meaning, $found, true)) {
                    $found[$columnNumber] = $meaning;
                }
            }
            if (count($found) >= self::HEADER_MIN) {
                return ['row' => $rowNumber, 'columns' => $found];
            }
        }

        return null;
    }

    public static function meaning(string $header): ?string
    {
        $normalized = AttendanceText::normalize($header);
        if ($normalized === '') {
            return null;
        }
        foreach (self::columns() as $column) {
            foreach ($column['patterns'] as $pattern) {
                if (AttendanceRules::like($pattern, $normalized)) {
                    return $column['meaning'];
                }
            }
        }

        return null;
    }
}
