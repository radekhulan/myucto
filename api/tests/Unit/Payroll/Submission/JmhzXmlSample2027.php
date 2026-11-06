<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

/**
 * Ručně sestavené měsíční hlášení ve tvaru XSD 1.5.0.3 (verze 2027,
 * jmenné prostory `.../2027`). Serializér 2027 zatím neexistuje, kontroly
 * verze 2027 se proto zkoušejí nad těmito vzorky; každý vzorek, který test
 * použije, ověří {@see JmhzScenario1Control2027Test} proti XSD.
 */
final class JmhzXmlSample2027
{
    public static function document(
        string $forms,
        int $formCount = 1,
        string $souhrn = '',
        string $pvpoj = '',
        int $month = 4,
    ): string {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <jmhz xmlns="http://schemas.cssz.cz/JMHZ/podani/2027" xmlns:so="http://schemas.cssz.cz/JMHZ/souhrn/2027" xmlns:pvpoj="http://schemas.cssz.cz/JMHZ/PVPOJ/2027" xmlns:form="http://schemas.cssz.cz/JMHZ/form/2027" verze="1.5.0">
              <hlavicka>
                <idPodani>0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E0F</idPodani>
                <typPodani>R</typPodani>
                <variabilniSymbol>1234567890</variabilniSymbol>
                <mesic>{$month}</mesic>
                <rok>2027</rok>
                <datumVyplneni>2027-05-05T09:30:00Z</datumVyplneni>
                <balikPoradi>1</balikPoradi>
                <balikyPocet>1</balikyPocet>
                <formularePocetVBaliku>{$formCount}</formularePocetVBaliku>
                <formularePocetCelkem>{$formCount}</formularePocetCelkem>
              </hlavicka>{$souhrn}{$pvpoj}
              <formulareZamestnani>
            {$forms}
              </formulareZamestnani>
            </jmhz>
            XML;
    }

    public static function form(string $body, bool $primary = true, string $guidSuffix = '10'): string
    {
        $flag = $primary ? 'true' : 'false';

        return <<<XML
                <form:formularZamestnani>
                  <form:hlavicka>
                    <form:idFormulare>0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E{$guidSuffix}</form:idFormulare>
                    <form:typFormulare>R</form:typFormulare>
                    <form:primarniPpv>{$flag}</form:primarniPpv>
                    <form:CustomAtribut1>interní označení</form:CustomAtribut1>
                  </form:hlavicka>
            {$body}
                </form:formularZamestnani>
            XML;
    }

    /**
     * @param array{
     *     birthDate?:string,
     *     souhrnDataZec?:string,
     *     eldp?:string,
     *     workplace?:string,
     *     rozpad?:string,
     *     dan?:string,
     *     hours?:string,
     * } $o
     */
    public static function bezPriznaku(array $o = []): string
    {
        // Identifikace je volba: buď IK MPSV a ID PPV, nebo jméno, datum
        // narození a nástupu a druh činnosti (bez IK MPSV).
        $identity = isset($o['birthDate'])
            ? '<form:prijmeni>Nováková</form:prijmeni><form:jmeno>Jana</form:jmeno>'
                . "<form:datumNarozeni>{$o['birthDate']}</form:datumNarozeni>"
                . '<form:datumNastupu>2025-09-01</form:datumNastupu><form:druhCinnosti>1</form:druhCinnosti>'
            : '<form:ikMpsv>1000000001</form:ikMpsv><form:idPpv>2000000000000000000001</form:idPpv>';
        $souhrn = $o['souhrnDataZec'] ?? '';
        $eldp = $o['eldp'] ?? self::eldp();
        $workplace = $o['workplace']
            ?? '<form:cisloPopisne>1</form:cisloPopisne><form:obec>Brno</form:obec>'
                . '<form:kodObce>582786</form:kodObce><form:psc>60200</form:psc><form:kodStatu>CZ</form:kodStatu>';
        $hours = $o['hours'] ?? '184.000';
        $rozpad = $o['rozpad'] ?? '';
        $dan = $o['dan'] ?? '';

        return <<<XML
                  <form:bezPriznaku>
                    <form:identifikace>{$identity}</form:identifikace>{$souhrn}
                    <form:pojisteni>
                      <form:trvani>
                        <form:pojisteniOd>2027-04-01</form:pojisteniOd>
                        <form:pojisteniDo>2027-04-30</form:pojisteniDo>
                      </form:trvani>
                      <form:eldpSeznam>{$eldp}</form:eldpSeznam>
                      <form:slevaZamestnance>
                        <form:slevaZamestnanceEvidovana>false</form:slevaZamestnanceEvidovana>
                        <form:slevaZamestnanceOvoZelEvidovana>false</form:slevaZamestnanceOvoZelEvidovana>
                      </form:slevaZamestnance>
                      <form:slevaZamestnavatele>
                        <form:slevaZamestnavateleEvidovana>false</form:slevaZamestnavateleEvidovana>
                      </form:slevaZamestnavatele>
                    </form:pojisteni>
                    <form:vykonavanaPozice>
                      <form:mistoVykonuPrace>{$workplace}</form:mistoVykonuPrace>
                      <form:uplatnujiPrispevekApz>false</form:uplatnujiPrispevekApz>
                      <form:funkcniPozitky>false</form:funkcniPozitky>
                      <form:docasnePrideleniEvidovano>false</form:docasnePrideleniEvidovano>
                      <form:fondPracovniDoby>
                        <form:stanovenyFond>176.000</form:stanovenyFond>
                        <form:sjednanyFond>176.000</form:sjednanyFond>
                        <form:stanovenaTydenniDoba>40.00</form:stanovenaTydenniDoba>
                      </form:fondPracovniDoby>
                    </form:vykonavanaPozice>
                    <form:prubehZamestnani>
                      <form:odpracovaneDny>
                        <form:dnyEvidencniStav>30</form:dnyEvidencniStav>
                      </form:odpracovaneDny>
                      <form:odpracovaneHodiny>
                        <form:pocet>{$hours}</form:pocet>{$rozpad}
                      </form:odpracovaneHodiny>
                    </form:prubehZamestnani>
                    <form:prijem>{$dan}</form:prijem>
                    <form:mzda>
                      <form:mzdaZuctovana>30000</form:mzdaZuctovana>
                      <form:vydelek>
                        <form:vydelekPrumernyHod>275.50</form:vydelekPrumernyHod>
                      </form:vydelek>
                    </form:mzda>
                  </form:bezPriznaku>
            XML;
    }

    /**
     * Formulář činnosti K až S (`cinnostKS`) se státním zástupcem (10587).
     * `$withDetails` doplní dny v evidenčním stavu, odpracované hodiny,
     * tarifní mzdu a průměrný výdělek, které kontrola 388 vyžaduje.
     */
    public static function cinnostKs(bool $stateAttorney, bool $withDetails): string
    {
        $flag = $stateAttorney ? 'true' : 'false';
        $days = $withDetails
            ? '<form:odpracovaneDny><form:dnyEvidencniStav>30</form:dnyEvidencniStav></form:odpracovaneDny>'
                . '<form:odpracovaneHodiny><form:pocet>176.000</form:pocet></form:odpracovaneHodiny>'
            : '';
        $wage = $withDetails
            ? '<form:mzda><form:mzdaRozpad><form:tarif>60000</form:tarif>'
                . '</form:mzdaRozpad><form:vydelek><form:vydelekPrumernyHod>340.90</form:vydelekPrumernyHod>'
                . '</form:vydelek></form:mzda>'
            : '';
        $eldp = self::eldp('K++', '2027-04-01', '2027-04-30', 30, 60000);

        return <<<XML
                  <form:cinnostKS>
                    <form:identifikace>
                      <form:ikMpsv>1000000001</form:ikMpsv>
                      <form:idPpv>2000000000000000000001</form:idPpv>
                    </form:identifikace>
                    <form:pojisteni>
                      <form:trvani>
                        <form:pojisteniOd>2027-04-01</form:pojisteniOd>
                        <form:pojisteniDo>2027-04-30</form:pojisteniDo>
                      </form:trvani>
                      <form:eldpSeznam>{$eldp}</form:eldpSeznam>
                      <form:slevaZamestnance>
                        <form:slevaZamestnanceEvidovana>false</form:slevaZamestnanceEvidovana>
                        <form:slevaZamestnanceOvoZelEvidovana>false</form:slevaZamestnanceOvoZelEvidovana>
                      </form:slevaZamestnance>
                    </form:pojisteni>
                    <form:vykonavanaPozice>
                      <form:mistoVykonuPrace><form:cisloPopisne>1</form:cisloPopisne><form:obec>Brno</form:obec><form:kodObce>582786</form:kodObce><form:psc>60200</form:psc><form:kodStatu>CZ</form:kodStatu></form:mistoVykonuPrace>
                      <form:uplatnujiPrispevekApz>false</form:uplatnujiPrispevekApz>
                      <form:funkcniPozitky>false</form:funkcniPozitky>
                      <form:docasnePrideleniEvidovano>false</form:docasnePrideleniEvidovano>
                      <form:fondPracovniDoby>
                        <form:stanovenyFond>176.000</form:stanovenyFond>
                        <form:sjednanyFond>176.000</form:sjednanyFond>
                        <form:stanovenaTydenniDoba>40.00</form:stanovenaTydenniDoba>
                      </form:fondPracovniDoby>
                    </form:vykonavanaPozice>
                    <form:prubehZamestnani><form:statniZastupce>{$flag}</form:statniZastupce>{$days}</form:prubehZamestnani>
                    <form:prijem/>{$wage}
                  </form:cinnostKS>
            XML;
    }

    public static function eldp(
        string $code = '1++',
        string $from = '2027-04-01',
        string $to = '2027-04-30',
        int $days = 30,
        ?int $base = 30000,
    ): string {
        $baseXml = $base === null ? '' : "<form:vymerovaciZaklad>{$base}</form:vymerovaciZaklad>";

        return "<form:eldp><form:kod>{$code}</form:kod><form:platnostOd>{$from}</form:platnostOd>"
            . "<form:platnostDo>{$to}</form:platnostDo><form:pocetDnu>{$days}</form:pocetDnu>{$baseXml}</form:eldp>";
    }

    /** Riziková práce: hodiny, počet směn (null = neuvádí se) a kategorie. */
    public static function risk(string $hours, ?int $shifts, string $category): string
    {
        $shiftsXml = $shifts === null ? '' : "<form:smenyOdpracovanePocet>{$shifts}</form:smenyOdpracovanePocet>";

        return "<form:riziko><form:hodinyOdpracovanePocet>{$hours}</form:hodinyOdpracovanePocet>{$shiftsXml}"
            . "<form:kategorizaceRizika>{$category}</form:kategorizaceRizika></form:riziko>";
    }

    public static function rozpad(string ...$risks): string
    {
        return '<form:rozpad><form:prescas>0.000</form:prescas>' . implode('', $risks) . '</form:rozpad>';
    }

    /**
     * @param array{
     *     savings?:int,
     *     multiMonth?:bool,
     *     declaration?:bool,
     *     credits?:string,
     *     withheld?:int,
     *     withheldPar6?:int,
     *     annual?:string,
     * } $o
     */
    public static function souhrnDataZec(array $o = []): string
    {
        $savings = isset($o['savings'])
            ? "<form:prispevekZelSporeniOsvob>{$o['savings']}</form:prispevekZelSporeniOsvob>"
            : '';
        $multiMonth = ($o['multiMonth'] ?? false) ? 'true' : 'false';
        $declaration = ($o['declaration'] ?? true) ? 'true' : 'false';
        $credits = $o['credits'] ?? '';
        $withheld = isset($o['withheld']) ? "<form:danZalohaPoSleve>{$o['withheld']}</form:danZalohaPoSleve>" : '';
        $withheldPar6 = isset($o['withheldPar6'])
            ? "<form:srazenaDanPar6>{$o['withheldPar6']}</form:srazenaDanPar6>"
            : '';
        $annual = $o['annual'] ?? '';

        return <<<XML

                    <form:souhrnDataZec>
                      <form:prijmy>
                        <form:zuctovanoCelkem>30000</form:zuctovanoCelkem>{$savings}
                      </form:prijmy>
                      <form:zalohaNaDan>
                        <form:zalohaPar38>{$multiMonth}</form:zalohaPar38>{$withheld}{$withheldPar6}
                        <form:prohlaseniPoplatnika>{$declaration}</form:prohlaseniPoplatnika>{$credits}
                      </form:zalohaNaDan>{$annual}
                      <form:mzdaCista>
                        <form:mzdaCista>22000</form:mzdaCista>
                        <form:srazkyZeMzdyEvidovany>false</form:srazkyZeMzdyEvidovany>
                      </form:mzdaCista>
                      <form:zdravPojZamestnavatel>
                        <form:zdravotniPojisteni>2700</form:zdravotniPojisteni>
                      </form:zdravPojZamestnavatel>
                      <form:zdravPojZamestnanec>
                        <form:zdravotniPojisteni>1350</form:zdravotniPojisteni>
                      </form:zdravPojZamestnanec>
                    </form:souhrnDataZec>
            XML;
    }

    /** Prohlášení poplatníka se slevami (`prohlaseniPoplatnikaDane`). */
    public static function credits(?int $basic = 2570, ?int $student = null, bool $doctoral = false): string
    {
        $basicXml = $basic === null ? '' : "<form:zakladniSleva>{$basic}</form:zakladniSleva>";
        $studentXml = $student === null
            ? ''
            : "<form:slevaStudent><form:vyseSlevy>{$student}</form:vyseSlevy>"
                . '<form:doktorant>' . ($doctoral ? 'true' : 'false') . '</form:doktorant></form:slevaStudent>';

        return '<form:prohlaseniPoplatnikaDane><form:datumPodpisu>2027-01-02</form:datumPodpisu>'
            . "{$basicXml}{$studentXml}</form:prohlaseniPoplatnikaDane>";
    }

    /** Roční úhrny s výsledkem ročního zúčtování. */
    public static function annual(
        int $topUp = 0,
        int $taxRefund = 0,
        int $bonusTopUp = 0,
        bool $childCredit = false,
        ?int $bonusTotal = null,
    ): string {
        $bonusTotalXml = $bonusTotal === null ? '' : "<form:uhrnBonusu>{$bonusTotal}</form:uhrnBonusu>";
        $children = $childCredit ? 'true' : 'false';

        return "<form:rocniUhrny>{$bonusTotalXml}<form:rocniZuctovaniProvedeno>true</form:rocniZuctovaniProvedeno>"
            . "<form:vysledekRocnihoZuctovani><form:doplatekRok>{$topUp}</form:doplatekRok>"
            . "<form:danPreplatekRok>{$taxRefund}</form:danPreplatekRok>"
            . "<form:danBonusPreplatekRok>{$bonusTopUp}</form:danBonusPreplatekRok>"
            . '<form:uplatnenaSlevaNaPartnera>false</form:uplatnenaSlevaNaPartnera>'
            . "<form:uplatnenoZvyhodneniNaDeti>{$children}</form:uplatnenoZvyhodneniNaDeti>"
            . '</form:vysledekRocnihoZuctovani></form:rocniUhrny>';
    }

    /**
     * Formulář odloženého příjmu. `$periods` jsou bloky `obdobi`, viz
     * {@see self::deferredPeriod()}.
     */
    public static function odlozenyPrijem(string $type, string $periods): string
    {
        return <<<XML
                  <form:odlozenyPrijem>
                    <form:typ>{$type}</form:typ>
                    <form:identifikace>
                      <form:ikMpsv>1000000001</form:ikMpsv>
                      <form:idPpv>2000000000000000000001</form:idPpv>
                    </form:identifikace>
                    <form:pojisteni>
                      <form:eldpObdobi>{$periods}</form:eldpObdobi>
                      <form:slevaZamestnance>
                        <form:slevaZamestnanceEvidovana>false</form:slevaZamestnanceEvidovana>
                        <form:slevaZamestnanceOvoZelEvidovana>false</form:slevaZamestnanceOvoZelEvidovana>
                      </form:slevaZamestnance>
                      <form:slevaZamestnavatele>
                        <form:slevaZamestnavateleEvidovana>false</form:slevaZamestnavateleEvidovana>
                      </form:slevaZamestnavatele>
                    </form:pojisteni>
                    <form:prijem/>
                  </form:odlozenyPrijem>
            XML;
    }

    public static function deferredPeriod(?int $month, ?int $year, string $code = '1P+', int $days = 0): string
    {
        $monthXml = $month === null ? '' : "<form:mesic>{$month}</form:mesic>";
        $yearXml = $year === null ? '' : "<form:rok>{$year}</form:rok>";

        return "<form:obdobi>{$monthXml}{$yearXml}<form:eldpSeznam>"
            . self::eldp($code, '2027-04-01', '2027-04-30', $days, 5000)
            . '</form:eldpSeznam></form:obdobi>';
    }

    public static function souhrn(int $withheldTotal): string
    {
        return "\n  <so:souhrn><so:danUdajeMesic><so:danZalohaPoSleve>{$withheldTotal}</so:danZalohaPoSleve>"
            . '</so:danUdajeMesic><so:kolektivniSmlouvy><so:kolektivniSmlouva>'
            . '<so:typKolektSmlouvy>1</so:typKolektSmlouvy></so:kolektivniSmlouva></so:kolektivniSmlouvy></so:souhrn>';
    }
}
