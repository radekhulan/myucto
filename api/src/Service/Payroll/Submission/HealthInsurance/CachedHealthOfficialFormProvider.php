<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

use MyInvoice\Bootstrap;

/**
 * Připnuté úřední tiskopisy zdravotních pojišťoven.
 *
 * ## Proč JEDEN tiskopis pro víc pojišťoven
 *
 * Vydání 2026 obou zaměstnavatelských tiskopisů je **jednotné**: v souboru je
 * `ID:HOZ_2026_v1.0_UNI` resp. `ID:PPZ_2026_v1.0_UNI`, na patce stojí
 * `UNI 73.51/2026` a `UNI 76.51/2026`, hlavička neobsahuje logo ani kód
 * pojišťovny a text mluví o „pracovníku pojišťovny", ne o konkrétní pojišťovně.
 * Čísla tiskopisů `73.51` a `76.51` používá i VoZP na svých poučeních
 * (`…vozp-73.51_2019…`, `…vozp-76.51_2019…`) — jde tedy o tentýž tiskopis
 * v novém, značkou nepodepsaném vydání.
 *
 * Datová část je napříč pojišťovnami shodná dlouhodobě: XDP šablona, kterou
 * VZP zveřejňuje „pro hromadné vyplňování z účetních systémů", je pro HOZ
 * bajt po bajtu stejná jako XDP šablona VoZP (liší se jen `<pdf href>`),
 * a jména polí tiskopisu jsou přesně jména prvků té šablony
 * (`ZamNaz`, `ZamIC`, `PojCis_1`, …). Vyplňování se proto adresuje JMÉNEM
 * pole, ne pořadím — nová pojišťovna, která tentýž tiskopis zveřejní, se
 * napojí bez řádku kódu (viz {@see HealthOfficialFormCatalog}).
 *
 * ## Proč připnutá kopie NENÍ bajtově zveřejněný soubor
 *
 * Publikovaný soubor je šifrovaný (AES-256, prázdné uživatelské heslo,
 * `change=0`) a navíc má objekty v `ObjStm`. V té podobě ho neotevře ani
 * `setasign/fpdi` (odmítá šifrovaná PDF), ani `dragonofmercy/phppdf`
 * (nedešifruje object streamy). Připnutá kopie je proto stejný dokument bez
 * toho obalu; obsah stránky, fonty ani definice polí se nemění. Postup je
 * deterministický a přezkoumatelný:
 *
 * ```
 * python -c "from pypdf import PdfReader, PdfWriter; \
 *   r=PdfReader('stazeny.pdf'); r.decrypt(''); \
 *   w=PdfWriter(clone_from=r); w.write(open('pripnuty.pdf','wb'))"
 * ```
 *
 * Shodu vykresleného obrazu proti staženému originálu drží
 * {@see \MyInvoice\Tests\Unit\Payroll\Submission\HealthOfficialFormTemplateTest}
 * přes otisky obou souborů; nová verze tiskopisu je tak vždy vědomá změna
 * repozitáře — vyměnit soubor, přepsat oba otisky, projít testy. Stejná brzda
 * jako u připnutých XSD.
 *
 * ## Co v připnutých souborech zůstalo vyplněné
 *
 * Nic osobního. Autor tiskopisu nechal v poli `DatVyp` datum vydání
 * `01.07.2026` a v přepínači `Typ` stav `/2`; obojí je artefakt sazby.
 * Do výstupu se to nedostane ani omylem: {@see \MyInvoice\Service\Pdf\HealthOfficialFormFiller}
 * přebírá ze šablony jen obsah stránky, ne anotace polí, takže vytištěný
 * tiskopis je prázdný a jediné hodnoty na něm jsou ty, které vykreslíme sami.
 */
final class CachedHealthOfficialFormProvider implements HealthOfficialFormProvider
{
    public const FORM_BULK_NOTIFICATION = 'hoz-uni-2026';
    public const FORM_PAYMENT_OVERVIEW = 'ppz-uni-2026';

    /** Pole tiskopisu HOZ: identifikace zaměstnavatele + čtyři věty + datum. */
    private const BULK_NOTIFICATION_FIELDS = [
        'ObdPoj',
        'ZamNaz', 'ZamUli', 'ZamCpCo', 'ZamIC', 'ZamPSC', 'ZamObe', 'ZamTel',
        'PojKod_1', 'PojCis_1', 'PojDatZme_1', 'PojPri_1', 'PojJme_1',
        'PojUli_1', 'PojCpCo_1', 'PojPSC_1', 'PojObe_1',
        'PojKod_2', 'PojCis_2', 'PojDatZme_2', 'PojPri_2', 'PojJme_2',
        'PojUli_2', 'PojCpCo_2', 'PojPSC_2', 'PojObe_2',
        'PojKod_3', 'PojCis_3', 'PojDatZme_3', 'PojPri_3', 'PojJme_3',
        'PojUli_3', 'PojCpCo_3', 'PojPSC_3', 'PojObe_3',
        'PojKod_4', 'PojCis_4', 'PojDatZme_4', 'PojPri_4', 'PojJme_4',
        'PojUli_4', 'PojCpCo_4', 'PojPSC_4', 'PojObe_4',
        'DatVyp',
    ];

    /** Pole tiskopisu PPZ; `Typ:/0` je řádný, `Typ:/1` opravný přehled. */
    private const PAYMENT_OVERVIEW_FIELDS = [
        'Typ:/0', 'Typ:/1',
        'ZamNaz', 'ZamUli', 'ZamCpCo', 'ZamIC', 'ZamPSC', 'ZamObe', 'ZamTel',
        'ObdHla', 'PocZam', 'VymZak', 'SumPoj',
        'DatVyp',
    ];

    /**
     * @var array<string,array{
     *     path:string,
     *     source_url:string,
     *     source_sha256:string,
     *     sha256:string,
     *     form_number:string,
     *     row_capacity:int,
     *     fields:list<string>,
     * }>
     */
    private const FORMS = [
        self::FORM_BULK_NOTIFICATION => [
            'path' => 'api/xsd/zp/formulare/hromadne-oznameni-zamestnavatele-uni-2026.pdf',
            'source_url' => 'https://www.vzp.cz/formulare/hromadne-oznameni-zamestnavatele.pdf',
            'source_sha256' => '2b9740a41627ac22a363677e6ec88c4f9c3843eda0921d6dbb9845d041f63628',
            'sha256' => 'eaf737529f956bd64d4d52a97ab818bbb0735b3cc29bacd4a243864a8a8d5da9',
            'form_number' => 'UNI 73.51/2026',
            // Tiskopis má čtyři bloky vět a natištěné „1/1“ v poli
            // „Číslo listu/počet listů“, které NENÍ vyplnitelné. Pátá věta se
            // na list nevejde a přečíslovat listy by znamenalo přetisknout
            // natištěný údaj — proto je čtyřka tvrdá kapacita, ne odhad.
            'row_capacity' => 4,
            'fields' => self::BULK_NOTIFICATION_FIELDS,
        ],
        self::FORM_PAYMENT_OVERVIEW => [
            'path' => 'api/xsd/zp/formulare/prehled-o-platbe-pojistneho-zamestnavatele-uni-2026.pdf',
            'source_url' => 'https://www.vzp.cz/formulare/prehled-o-platbe-pojistneho-zamestnavatele.pdf',
            'source_sha256' => '873f0c51df17a97ea8257461b915768fbf3e46bd3031c05bb63223b01843b4bb',
            'sha256' => 'b25602bd7eb72419b78156addf91edbb851f94c8f6d43166651b38a13744ec65',
            'form_number' => 'UNI 76.51/2026',
            'row_capacity' => 1,
            'fields' => self::PAYMENT_OVERVIEW_FIELDS,
        ],
    ];

    /** @var array<string,HealthOfficialForm> */
    private array $loaded = [];

    public function form(string $formId): HealthOfficialForm
    {
        if (isset($this->loaded[$formId])) {
            return $this->loaded[$formId];
        }
        $definition = self::FORMS[$formId] ?? null;
        if ($definition === null) {
            throw new HealthNotificationException(
                'zp_official_form_unknown',
                sprintf('Úřední tiskopis „%s" aplikace nezná.', $formId),
            );
        }

        $path = Bootstrap::rootDir() . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $definition['path']);
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-')) {
            throw new HealthNotificationException(
                'zp_official_form_unavailable',
                sprintf(
                    'Úřední tiskopis %s chybí v instalaci (%s).',
                    $definition['form_number'],
                    $definition['path'],
                ),
            );
        }
        if (!hash_equals($definition['sha256'], hash('sha256', $bytes))) {
            throw new HealthNotificationException(
                'zp_official_form_changed',
                sprintf(
                    'Úřední tiskopis %s neodpovídá připnutému otisku; '
                    . 'použijte ověřenou verzi souboru, nebo otisk aktualizujte '
                    . 'spolu s ním.',
                    $definition['form_number'],
                ),
            );
        }

        return $this->loaded[$formId] = new HealthOfficialForm(
            id: $formId,
            bytes: $bytes,
            sha256: $definition['sha256'],
            sourceUrl: $definition['source_url'],
            sourceSha256: $definition['source_sha256'],
            formNumber: $definition['form_number'],
            rowCapacity: $definition['row_capacity'],
            fieldNames: $definition['fields'],
        );
    }

    /** @return list<string> */
    public static function formIds(): array
    {
        return array_keys(self::FORMS);
    }

    /** Relativní cesta připnutého souboru — pro test, který ověřuje instalaci. */
    public static function relativePath(string $formId): string
    {
        return self::FORMS[$formId]['path'];
    }

    public static function sha256(string $formId): string
    {
        return self::FORMS[$formId]['sha256'];
    }

    public static function sourceSha256(string $formId): string
    {
        return self::FORMS[$formId]['source_sha256'];
    }
}
