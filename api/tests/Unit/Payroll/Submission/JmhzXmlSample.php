<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

/**
 * Ukázková podání pro testy promítnutí atributů a vykonávacích kontrol.
 *
 * Všechny hodnoty jsou smyšlené. IK MPSV splňuje modulo 11 (`1000000001`
 * a `1000000012`), aby kontrola 37 procházela, dokud ji test výslovně
 * neporuší. Bajtová shoda se zlatým souborem serializéru se tu netestuje —
 * od toho je `JmhzScenario1XmlSerializerTest`.
 */
final class JmhzXmlSample
{
    public static function minimal(): string
    {
        return self::document(self::form('1000000001', '2000000000000000000001'));
    }

    public static function twoForms(): string
    {
        return self::document(
            self::form('1000000001', '2000000000000000000001')
                . self::form('1000000012', '2000000000000000000002', primary: false),
            formCount: 4,
        );
    }

    public static function twoEldpSections(): string
    {
        $sections = <<<'XML'
                      <form:eldp>
                        <form:kod>1++</form:kod>
                        <form:platnostOd>2026-07-01</form:platnostOd>
                        <form:platnostDo>2026-07-15</form:platnostDo>
                        <form:pocetDnu>15</form:pocetDnu>
                        <form:vymerovaciZaklad>500</form:vymerovaciZaklad>
                      </form:eldp>
                      <form:eldp>
                        <form:kod>2++</form:kod>
                        <form:platnostOd>2026-07-16</form:platnostOd>
                        <form:platnostDo>2026-07-31</form:platnostDo>
                        <form:pocetDnu>16</form:pocetDnu>
                        <form:vymerovaciZaklad>500</form:vymerovaciZaklad>
                      </form:eldp>
            XML;

        return self::document(
            self::form('1000000001', '2000000000000000000001', eldp: $sections),
        );
    }

    public static function withPvpoj(string $pvpoj): string
    {
        return self::document(
            self::form('1000000001', '2000000000000000000001'),
            pvpoj: $pvpoj,
        );
    }

    /**
     * Podání s uplatněnou slevou zaměstnavatele podle § 7a. Sleva je 5 %
     * z vyměřovacího základu 1 000 Kč zaokrouhlených nahoru, tedy 50 Kč,
     * a o tutéž částku klesá pojistné k úhradě.
     */
    public static function withEmployerDiscount(
        string $reason = 'A',
        ?string $shorterWorkingTime = '20.00',
    ): string {
        return self::document(
            self::form(
                '1000000001',
                '2000000000000000000001',
                discount: self::discountBlock($reason, $shorterWorkingTime),
            ),
            pvpoj: self::discountPvpoj(),
        );
    }

    public static function discountPvpoj(int $headcount = 1, int $base = 1_000, int $discount = 50): string
    {
        $payable = 319 - $discount;

        return <<<XML
                <pvpoj:pojistne>
                  <pvpoj:zakladZamestnavateleA>1000</pvpoj:zakladZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleA>248</pvpoj:pojistneZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleCelkem>248</pvpoj:pojistneZamestnavateleCelkem>
                  <pvpoj:pojistneZamestnance>71</pvpoj:pojistneZamestnance>
                  <pvpoj:pojistneCelkem>319</pvpoj:pojistneCelkem>
                </pvpoj:pojistne>
                <pvpoj:slevaZamestnavatele>
                  <pvpoj:pocetZamestnancu>{$headcount}</pvpoj:pocetZamestnancu>
                  <pvpoj:uhrnVymerovacichZakladu>{$base}</pvpoj:uhrnVymerovacichZakladu>
                  <pvpoj:pojistneSleva>{$discount}</pvpoj:pojistneSleva>
                </pvpoj:slevaZamestnavatele>
                <pvpoj:pojistneUhrada>{$payable}</pvpoj:pojistneUhrada>
            XML;
    }

    public static function discountBlock(
        string $reason = 'A',
        ?string $shorterWorkingTime = '20.00',
    ): string {
        $hours = $shorterWorkingTime === null
            ? ''
            : "\n                          <form:pracovniDobaKratsi>{$shorterWorkingTime}</form:pracovniDobaKratsi>";

        return <<<XML
                      <form:slevaZamestnavatele>
                        <form:slevaZamestnavateleEvidovana>true</form:slevaZamestnavateleEvidovana>
                        <form:slevaZamestnavateleRozpad>{$hours}
                          <form:duvodUplatneni>{$reason}</form:duvodUplatneni>
                        </form:slevaZamestnavateleRozpad>
                      </form:slevaZamestnavatele>
            XML;
    }

    public static function defaultPvpoj(): string
    {
        return <<<'XML'
                <pvpoj:pojistne>
                  <pvpoj:zakladZamestnavateleA>1000</pvpoj:zakladZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleA>248</pvpoj:pojistneZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleCelkem>248</pvpoj:pojistneZamestnavateleCelkem>
                  <pvpoj:pojistneZamestnance>71</pvpoj:pojistneZamestnance>
                  <pvpoj:pojistneCelkem>319</pvpoj:pojistneCelkem>
                </pvpoj:pojistne>
                <pvpoj:pojistneUhrada>319</pvpoj:pojistneUhrada>
            XML;
    }

    public static function document(
        string $forms,
        int $formCount = 3,
        ?string $pvpoj = null,
        string $month = '7',
        string $year = '2026',
    ): string {
        $pvpoj ??= self::defaultPvpoj();

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <jmhz xmlns="http://schemas.cssz.cz/JMHZ/podani/1.0" xmlns:so="http://schemas.cssz.cz/JMHZ/souhrn/1.0" xmlns:pvpoj="http://schemas.cssz.cz/JMHZ/PVPOJ/1.0" xmlns:form="http://schemas.cssz.cz/JMHZ/form/1.0" verze="1.4.3">
              <VENDOR productName="MyÚčto.cz" productVersion="5.6.0"/>
              <hlavicka>
                <idPodani>0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E0F</idPodani>
                <typPodani>R</typPodani>
                <variabilniSymbol>1234567890</variabilniSymbol>
                <mesic>{$month}</mesic>
                <rok>{$year}</rok>
                <datumVyplneni>2026-08-05T09:30:00Z</datumVyplneni>
                <balikPoradi>1</balikPoradi>
                <balikyPocet>1</balikyPocet>
                <formularePocetVBaliku>{$formCount}</formularePocetVBaliku>
                <formularePocetCelkem>{$formCount}</formularePocetCelkem>
              </hlavicka>
              <so:souhrn>
                <so:danUdajeMesic>
                  <so:danZalohaPoSleve>150</so:danZalohaPoSleve>
                  <so:danBonus>0</so:danBonus>
                </so:danUdajeMesic>
              </so:souhrn>
              <pvpoj:PVPOJ>
            {$pvpoj}
              </pvpoj:PVPOJ>
              <formulareOsob>
            {$forms}
              </formulareOsob>
            </jmhz>
            XML;
    }

    public static function form(
        string $personId,
        string $employmentId,
        bool $primary = true,
        ?string $eldp = null,
        string $discount = '',
    ): string {
        $primaryFlag = $primary ? 'true' : 'false';
        $discount = $discount === '' ? '' : "\n{$discount}";
        $eldp ??= <<<'XML'
                      <form:eldp>
                        <form:kod>1++</form:kod>
                        <form:platnostOd>2026-07-01</form:platnostOd>
                        <form:platnostDo>2026-07-31</form:platnostDo>
                        <form:pocetDnu>31</form:pocetDnu>
                        <form:vymerovaciZaklad>1000</form:vymerovaciZaklad>
                      </form:eldp>
            XML;

        return <<<XML
                <formularOsoby>
                  <hlavicka>
                    <idFormulare>0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10</idFormulare>
                    <typFormulare>R</typFormulare>
                    <primarniPpv>{$primaryFlag}</primarniPpv>
                  </hlavicka>
                  <form:bezPriznaku>
                    <form:identifikace>
                      <form:ikMpsv>{$personId}</form:ikMpsv>
                      <form:idPpv>{$employmentId}</form:idPpv>
                    </form:identifikace>
                    <form:souhrnDataZec>
                      <form:prijmy>
                        <form:zuctovanoCelkem>1000</form:zuctovanoCelkem>
                      </form:prijmy>
                      <form:zalohaNaDan>
                        <form:zakladDane>1000</form:zakladDane>
                        <form:vypoctenaZaloha>150</form:vypoctenaZaloha>
                        <form:danZalohaPoSleve>150</form:danZalohaPoSleve>
                      </form:zalohaNaDan>
                      <form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>
                      <form:mzdaCista>
                        <form:mzdaCista>734</form:mzdaCista>
                        <form:srazkyZeMzdyEvidovany>false</form:srazkyZeMzdyEvidovany>
                      </form:mzdaCista>
                      <form:zdravPojZamestnavatel>
                        <form:zdravotniPojisteni>90</form:zdravotniPojisteni>
                      </form:zdravPojZamestnavatel>
                      <form:zdravPojZamestnanec>
                        <form:zdravotniPojisteni>45</form:zdravotniPojisteni>
                      </form:zdravPojZamestnanec>
                    </form:souhrnDataZec>
                    <form:pojisteni>
                      <form:trvani>
                        <form:pojisteniOd>2026-07-01</form:pojisteniOd>
                        <form:pojisteniDo>2026-07-31</form:pojisteniDo>
                      </form:trvani>
                      <form:vymerovaciZaklad>
                        <form:castkaOdvodPojistneho>1000</form:castkaOdvodPojistneho>
                      </form:vymerovaciZaklad>
                      <form:vymerovaciZakladParagraf5>
                        <form:pismenoA>1000</form:pismenoA>
                      </form:vymerovaciZakladParagraf5>
                      <form:eldpSeznam>
            {$eldp}
                      </form:eldpSeznam>
                      <form:pojisteniZamestnanec>
                        <form:socialniPojisteni>71</form:socialniPojisteni>
                      </form:pojisteniZamestnanec>
                      <form:pojisteniZamestnavatel>
                        <form:socialniPojisteni>248</form:socialniPojisteni>
                      </form:pojisteniZamestnavatel>{$discount}
                    </form:pojisteni>
                    <form:vykonavanaPozice>
                      <form:mistoVykonuPrace>
                        <form:obec>Brno</form:obec>
                        <form:kodObce>582786</form:kodObce>
                        <form:kodStatu>CZ</form:kodStatu>
                      </form:mistoVykonuPrace>
                      <form:uplatnujiPrispevekApz>false</form:uplatnujiPrispevekApz>
                      <form:funkcniPozitky>false</form:funkcniPozitky>
                      <form:docasnePrideleniEvidovano>false</form:docasnePrideleniEvidovano>
                      <form:fondPracovniDoby>
                        <form:stanovenyFond>184.000</form:stanovenyFond>
                        <form:sjednanyFond>184.000</form:sjednanyFond>
                        <form:stanovenaTydenniDoba>40.00</form:stanovenaTydenniDoba>
                      </form:fondPracovniDoby>
                    </form:vykonavanaPozice>
                    <form:prubehZamestnani>
                      <form:odpracovaneDny>
                        <form:dnyEvidencniStav>31</form:dnyEvidencniStav>
                      </form:odpracovaneDny>
                      <form:odpracovaneHodiny>
                        <form:pocet>184.000</form:pocet>
                      </form:odpracovaneHodiny>
                    </form:prubehZamestnani>
                    <form:prijem>
                      <form:dan>
                        <form:zakladDane>1000</form:zakladDane>
                      </form:dan>
                    </form:prijem>
                    <form:mzda>
                      <form:mzdaZuctovana>1000</form:mzdaZuctovana>
                      <form:mzdaRozpad>
                        <form:tarif>1000</form:tarif>
                        <form:odmenyPravidelne>0</form:odmenyPravidelne>
                        <form:odmenyNepravidelne>0</form:odmenyNepravidelne>
                      </form:mzdaRozpad>
                      <form:vydelek>
                        <form:vydelekPrumernyHod>275.50</form:vydelekPrumernyHod>
                      </form:vydelek>
                    </form:mzda>
                  </form:bezPriznaku>
                </formularOsoby>
            XML;
    }
}
