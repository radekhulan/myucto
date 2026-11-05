<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Ukázkový profil „Vzor PAMICA" — docházka exportovaná pro mzdový program
 * PAMICA: XML „Docházka zaměstnanců" nebo denní CSV z téhož docházkového
 * systému. Oba exporty převede čtečka na list „PAMICA RRRR-MM"
 * ({@see AttendancePamicaExport}), pravidla míří jen na něj.
 *
 * Hodiny jdou do evidence docházky, mzdové složky exportu (úkolová mzda,
 * prémie, osobní ohodnocení, korunové příplatky, stravenkový paušál) na složky
 * výchozího číselníku; osobní ohodnocení číselník nemá, vzor ho deklaruje.
 * Neznámé sloupce i ostatní listy se ignorují, složka podle hlavičky nevzniká.
 *
 * Při každé změně pravidel nebo složek zvyšte {@see self::VERSION}.
 */
final class AttendancePamicaSampleProfile
{
    public const KEY = 'pamica';
    public const NAME = 'Vzor PAMICA';
    /*
     * 2: celé schéma `pamica/dochazka.xsd`: rodné číslo, středisko, nadúvazek,
     *    hodiny noci, víkendu, svátku a pracovní cesty a mzdové složky.
     */
    public const VERSION = 2;

    /** @return list<array{sheet:?string,header:string,meaning:string,unit:?string,component_code:?string}> */
    public static function rules(): array
    {
        $sheet = AttendanceText::normalize(AttendancePamicaExport::SHEET_PREFIX) . '*';
        $rules = [];
        foreach (AttendancePamicaExport::columns() as $column) {
            $meaning = $column['meaning'];
            $rules[] = [
                'sheet' => $sheet,
                'header' => $column['header'],
                'meaning' => $meaning,
                'unit' => match (true) {
                    AttendanceMeaning::isHours($meaning) => AttendanceMeaning::UNIT_HOURS,
                    AttendanceMeaning::isMoney($meaning) => AttendanceMeaning::UNIT_AMOUNT,
                    default => null,
                },
                'component_code' => $column['component_code'],
            ];
        }
        $rules[] = ['sheet' => '*', 'header' => '*', 'meaning' => AttendanceMeaning::IGNORE, 'unit' => null, 'component_code' => null];

        return $rules;
    }

    /** @return list<array{code:string,name:string,kind:string}> */
    public static function components(): array
    {
        return [
            // Odměna: zda pravidelná (10330), nebo nepravidelná (10331), rozhodne účetní jednou za firmu.
            ['code' => AttendancePamicaExport::PERSONAL_RATING, 'name' => 'Osobní ohodnocení', 'kind' => 'bonus'],
        ];
    }
}
