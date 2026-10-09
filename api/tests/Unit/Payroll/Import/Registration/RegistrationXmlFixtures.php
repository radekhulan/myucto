<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Registration;

/**
 * Syntetické registrace ČSSZ podle připnutých schémat REGZEC25 a PREZEC26.
 * Jména, rodná čísla, OIČ i ID PPV jsou vymyšlená; rodné číslo i OIČ projdou
 * kontrolou dělitelnosti jedenácti.
 */
final class RegistrationXmlFixtures
{
    /** @param array<string,string|null> $options */
    public static function regzecA1(array $options = []): string
    {
        $o = $options + [
            'bno' => self::birthNumber('1990-01-15', 'female', 1),
            'first' => 'Jana',
            'last' => 'Testovací',
            'tit' => 'Ing.',
            'birth_date' => '1990-01-15',
            'birth_surname' => 'Zkušební',
            'birth_place' => 'Testov',
            'sex' => 'Ž',
            'start' => '2026-07-01',
            'rel' => '1',
            'detail' => '1',
            'clas' => '43111',
            'workplace' => 'Hlavní město Praha',
            'municode' => '554782',
            'insurer' => '111',
            'street' => 'Zkušební',
            'num' => '12',
            'pnu' => '11000',
            'city' => 'Praha',
            'ikmpsv' => null,
            'oid' => null,
            'vs' => '1234567890',
            'nvs' => null,
            'ona' => null,
            'vcp' => null,
            'sme' => 'N',
            'fdr' => null,
            // Celý element `pens` (pobíraný důchod), např. `<pens typ="1" tak="2026-03-01" early="A"/>`.
            'pens' => null,
            'highedu' => 'M',
        ];
        $fdr = $o['fdr'] === null ? '' : '<fdr str="Pobytová" num="7" pnu="' . $o['fdr'] . '" cit="Brno"/>';
        $pens = $o['pens'] ?? '';
        $client = self::attributes(['bno' => $o['bno'], 'ikmpsv' => $o['ikmpsv'], 'vcp' => $o['vcp']]);
        $name = self::attributes(['sur' => $o['last'], 'ona' => $o['ona'], 'fir' => $o['first'], 'tit' => $o['tit']]);
        $birth = self::attributes([
            'dat' => $o['birth_date'],
            'nam' => $o['birth_surname'],
            'cit' => $o['birth_place'],
            'stat' => 'CZ',
        ]);
        $address = self::attributes([
            'str' => $o['street'],
            'num' => $o['num'],
            'pnu' => $o['pnu'],
            'cit' => $o['city'],
            'cnt' => 'CZ',
        ]);
        $job = self::attributes([
            'oid' => $o['oid'],
            'fro' => $o['start'],
            'rel' => $o['rel'],
            'relDetail' => $o['detail'],
            'sme' => $o['sme'],
            'contractplace' => 'Praha',
            'cit' => $o['workplace'],
            'municode' => $o['municode'],
        ]);
        $insurer = $o['insurer'] === null ? '' : '<insh cnr="' . $o['insurer'] . '"/>';
        $comp = self::attributes(['vs' => $o['vs'], 'nvs' => $o['nvs'], 'nam' => 'Syntetický zaměstnavatel']);

        return self::regzec(<<<XML
            <employee sqnr="1" dep="111" act="1" dat="2026-07-02">
              <client{$client}>
                <name{$name}/>
                <birth{$birth}/>
                <stat mal="{$o['sex']}" cnt="CZ"/>
                <adr{$address}/>
                {$fdr}
              </client>
              <comp{$comp}/>
              <job{$job}>
                <prof clas="{$o['clas']}"/>
                <position name="Účetní"/>
              </job>
              {$pens}
              {$insurer}
              <fact highedu="{$o['highedu']}"/>
            </employee>
            XML);
    }

    /** @param array<string,string|null> $options `rel`, `sme`, `endbydeath` (A/N), `rsnterempl`, `vs` */
    public static function regzecA2(string $birthNumber, string $oic, string $idPpv, string $endOn, array $options = []): string
    {
        $o = $options + ['rel' => '1', 'sme' => null, 'endbydeath' => null, 'rsnterempl' => null, 'vs' => '1234567890'];
        $job = self::attributes([
            'oid' => $idPpv,
            'to' => $endOn,
            'rel' => $o['rel'],
            'relDetail' => '1',
            'sme' => $o['sme'],
            'endbydeath' => $o['endbydeath'],
        ]);
        $unemployment = $o['rsnterempl'] === null ? '' : '<unemplcomp rsnterempl="' . $o['rsnterempl'] . '"/>';

        return self::regzec(<<<XML
            <employee sqnr="1" dep="111" act="2" dat="2026-09-01">
              <client bno="{$birthNumber}" ikmpsv="{$oic}"/>
              <comp vs="{$o['vs']}" nam="Syntetický zaměstnavatel"/>
              <job{$job}/>
              {$unemployment}
            </employee>
            XML);
    }

    /** Hlášení o nenastoupení (A8) téže osoby; `oid` je ID PPV, `notstart` A/N. */
    public static function regzecA8(string $birthNumber, ?string $oid = null, string $notStart = 'A', string $date = '2026-07-20'): string
    {
        $job = self::attributes(['oid' => $oid, 'notstart' => $notStart]);

        return self::regzec(<<<XML
            <employee sqnr="1" dep="111" act="8" dat="{$date}">
              <client bno="{$birthNumber}"/>
              <comp vs="1234567890" nam="Syntetický zaměstnavatel"/>
              <job{$job}/>
            </employee>
            XML);
    }

    /** @param array<string,string|null> $options `insurer`, `oid`, `ikmpsv`, `sme`, `relDetail`, `rel`, `place` (cit pracoviště), `municode`, `vcp` */
    public static function regzecA3(?string $birthNumber, string $effectiveOn, array $options = []): string
    {
        $o = $options + [
            'insurer' => '207', 'oid' => null, 'ikmpsv' => null, 'sme' => null,
            'relDetail' => null, 'rel' => '1', 'place' => null, 'municode' => null, 'vcp' => null,
        ];
        $client = self::attributes(['bno' => $birthNumber, 'ikmpsv' => $o['ikmpsv'], 'vcp' => $o['vcp']]);
        $job = self::attributes([
            'oid' => $o['oid'],
            'rel' => $o['rel'],
            'relDetail' => $o['relDetail'],
            'sme' => $o['sme'],
            'cit' => $o['place'],
            'municode' => $o['municode'],
        ]);
        $insurer = $o['insurer'] === null ? '' : '<insh cnr="' . $o['insurer'] . '"/>';

        return self::regzec(<<<XML
            <employee sqnr="1" dep="111" act="3" dat="2026-09-02" fro="{$effectiveOn}">
              <client{$client}/>
              <comp vs="1234567890" nam="Syntetický zaměstnavatel"/>
              <job{$job}/>
              {$insurer}
            </employee>
            XML);
    }

    public static function prezecP1(string $birthNumber, string $first, string $last, string $expectedStartOn): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <PREZEC xmlns="http://schemas.cssz.cz/PREZEC/2026" version="1.2" partialAccept="A">
              <employees>
                <employee sqnr="1" act="9" idform="0F8A3C2E-1B2D-4C5E-8F9A-0123456789AB" dat="2026-09-10" predat="{$expectedStartOn}">
                  <client bno="{$birthNumber}">
                    <name sur="{$last}" fir="{$first}"/>
                    <birth nam="{$last}" cit="Testov"/>
                    <stat cnt="CZ"/>
                  </client>
                  <comp vs="1234567890"/>
                </employee>
              </employees>
            </PREZEC>
            XML;
    }

    /** Ukončení předregistrace (P2) k částečnému přihlášení {@see prezecP1()}. */
    public static function prezecP2(string $birthNumber, string $date = '2026-09-20'): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <PREZEC xmlns="http://schemas.cssz.cz/PREZEC/2026" version="1.2" partialAccept="A">
              <employees>
                <employee sqnr="1" act="10" idform="0F8A3C2E-1B2D-4C5E-8F9A-0123456789AB" dat="{$date}">
                  <client bno="{$birthNumber}"/>
                  <comp vs="1234567890"/>
                </employee>
              </employees>
            </PREZEC>
            XML;
    }

    /**
     * Export zaměstnanců z ePortálu ČSSZ (kořen `ExportZamestnancu` bez jmenného
     * prostoru, s BOM jako originál). Hodnota `null` element vynechá; elementy
     * jdou v pořadí schématu MPSV. Věta s `PojistnyVztahOd` je tvar exportu
     * od 15. 10. 2026.
     *
     * @param list<array<string,string|null>> $employees
     */
    public static function csszExport(array $employees, string $generatedAt = '2026-09-20T10:15:00.123Z'): string
    {
        $order = [
            'RodneCislo', 'EvidencniCisloPojistence', 'Prijmeni', 'Jmeno', 'VariabilniSymbol',
            'PojistnyVztahOd', 'PojistnyVztahDo', 'KodDruhuCinnosti', 'NazevDruhuCinnosti',
            'KodBlizsihoUrceniCinnosti', 'NazevBlizsihoUrceniCinnosti', 'ZMR', 'IdZamestnani', 'OIC',
        ];
        $rows = '';
        foreach ($employees as $employee) {
            $e = $employee + [
                'RodneCislo' => null,
                'Prijmeni' => 'Testovací',
                'Jmeno' => 'Jana',
                'VariabilniSymbol' => '1234567890',
                'KodDruhuCinnosti' => '1',
                'NazevDruhuCinnosti' => 'Pracovní poměr',
                'ZMR' => 'N',
                'IdZamestnani' => '2000000000999',
                'OIC' => null,
            ];
            $rows .= "    <Zamestnanec>\n";
            foreach (array_merge(array_flip($order), $e) as $element => $value) {
                if (is_string($value)) {
                    $rows .= "      <{$element}>" . htmlspecialchars($value, ENT_XML1) . "</{$element}>\n";
                }
            }
            $rows .= "    </Zamestnanec>\n";
        }

        return "\xEF\xBB\xBF<ExportZamestnancu>\n  <DatumGenerovani>{$generatedAt}</DatumGenerovani>\n"
            . "  <Zamestnanci>\n{$rows}  </Zamestnanci>\n</ExportZamestnancu>\n";
    }

    /** Syntetické rodné číslo (bez lomítka), které projde kontrolou modulo 11. */
    public static function birthNumber(string $birthDate, string $sex, int $sequence): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $birthDate));
        $prefix = sprintf('%02d%02d%02d', $year % 100, $month + ($sex === 'female' ? 50 : 0), $day);
        for ($suffix = $sequence * 7; ; $suffix++) {
            $nine = $prefix . sprintf('%03d', $suffix % 1000);
            for ($digit = 0; $digit <= 9; $digit++) {
                if (((int) ($nine . $digit)) % 11 === 0) {
                    return $nine . $digit;
                }
            }
        }
    }

    /** Syntetické OIČ (10 číslic, poslední = zbytek prvních devíti po dělení 11). */
    public static function oic(int $sequence): string
    {
        for ($base = 100_000_000 + $sequence * 13; ; $base++) {
            $check = $base % 11;
            if ($check <= 9) {
                return $base . $check;
            }
        }
    }

    /**
     * Dávka A1 se dvěma větami (sqnr 1 a 2); vadná věta nese `cnt="Čes"`, jak ji
     * poslal Premier (XSD chce dvoupísmenný kód).
     */
    public static function twoA1Sentences(bool $firstInvalid, bool $secondInvalid): string
    {
        $broken = static fn (string $xml): string => (string) preg_replace('/(<adr[^>]*) cnt="CZ"/', '$1 cnt="Čes"', $xml);
        $first = self::regzecA1([
            'bno' => self::birthNumber('1990-01-15', 'female', 1),
        ]);
        $second = self::regzecA1([
            'bno' => self::birthNumber('1985-06-07', 'male', 2),
            'birth_date' => '1985-06-07',
            'sex' => 'M',
            'first' => 'Petr',
        ]);
        if ($firstInvalid) {
            $first = $broken($first);
        }
        if ($secondInvalid) {
            $second = $broken($second);
        }
        preg_match('#<employee .*</employee>#s', $second, $match);
        $employee = str_replace('<employee sqnr="1"', '<employee sqnr="2"', $match[0]);

        return str_replace('</employees>', $employee . "\n  </employees>", $first);
    }

    private static function regzec(string $employee): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025" version="1.4" partialAccept="A">
              <employees>
            {$employee}
              </employees>
            </REGZEC>
            XML;
    }

    /** @param array<string,string|null> $attributes */
    private static function attributes(array $attributes): string
    {
        $result = '';
        foreach ($attributes as $name => $value) {
            if ($value !== null) {
                $result .= ' ' . $name . '="' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES) . '"';
            }
        }

        return $result;
    }
}
