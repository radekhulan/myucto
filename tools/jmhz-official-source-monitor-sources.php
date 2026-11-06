<?php

declare(strict_types=1);

/**
 * Authoritative PUBLIC indexes only. The monitor accepts document links only
 * under the listed official hosts and paths; it does not discover through a
 * search engine and does not follow arbitrary redirects.
 *
 * ČSSZ does not publish a complete machine-readable JMHZ document catalogue.
 * The ePortal page is therefore monitored for directly linked documents only;
 * unlinked or authenticated ePortal material still needs a human to add a
 * public source here after verifying it.
 */
return [
    'mpsv-jmhz-documentation' => [
        'label' => 'Vývojářský portál MPSV — dokumentace JMHZ',
        'index_url' => 'https://developers.mpsv.cz/api/apidata',
        'index_format' => 'mpsv_api',
        'api_slug' => 'jednotne-mesicni-hlaseni-zamestnavatelu',
        'documentation_title' => 'Dokumentace projektu JMHZ',
        'document_hosts' => ['developers.mpsv.cz'],
        'document_path_prefixes' => ['/assets/documents/'],
        'document_extensions' => ['csv', 'docx', 'eml', 'html', 'pdf', 'xlsx', 'xml', 'xsd', 'zip'],
    ],
    /*
     * Všechny veřejné stránky dokumentace JMHZ na vývojářském portálu MPSV.
     * Na stránce Aktuality MPSV hlásí změny kontrol v DIS, nasazení nových
     * verzí DIS, odstávky a požadavky na dodavatele mzdových systémů; katalog
     * kontrol a pokyny k vyplnění jsou samostatné stránky se záznamy.
     *
     * Samostatný zdroj, ne rozšíření `mpsv-jmhz-documentation`: klíče jeho
     * dokumentů ve stavu monitoru se tak nezmění. Přílohy se tu nestahují,
     * hlídá se jen název a odkaz, takže nová příloha hlavní dokumentace se
     * ohlásí u obou zdrojů.
     */
    'mpsv-jmhz-pages' => [
        'label' => 'Vývojářský portál MPSV - stránky JMHZ (aktuality, pokyny, katalog kontrol)',
        'index_url' => 'https://developers.mpsv.cz/api/apidata',
        'index_format' => 'mpsv_api_pages',
        'api_slug' => 'jednotne-mesicni-hlaseni-zamestnavatelu',
        'documentation_titles' => '*',
        'news_titles' => ['Aktuality'],
        'document_hosts' => ['developers.mpsv.cz'],
        'document_path_prefixes' => ['/assets/documents/'],
        'document_extensions' => ['csv', 'docx', 'eml', 'html', 'pdf', 'png', 'pptx', 'txt', 'xlsx', 'xml', 'xsd', 'zip'],
    ],
    /*
     * Dokumentace JMHZ pro rok 2027 (API verze 1.5.0, stránka "Dokumentace
     * projektu JMHZ - NV2027"): XSD 1.5, datový slovník, katalog kontrol,
     * scénáře a EDV REGZEC. Jde o finální návrh, MPSV soubory v připomínkovém
     * řízení vydává znovu (14. 10. 2026 přišly slovník 1.5.0.3 a katalog
     * 1.5.0.4), takže se u příloh hlídá obsah, ne jen název a odkaz.
     *
     * Samostatný zdroj vedle `mpsv-jmhz-documentation`, aby se klíče dokumentů
     * rokem 2026 ve stavu monitoru nezměnily.
     */
    'mpsv-jmhz-2027-documentation' => [
        'label' => 'Vývojářský portál MPSV - dokumentace JMHZ pro rok 2027 (verze 1.5.0)',
        'index_url' => 'https://developers.mpsv.cz/api/apidata',
        'index_format' => 'mpsv_api',
        'api_slug' => 'jednotne-mesicni-hlaseni-zamestnavatelu',
        'documentation_title' => 'Dokumentace projektu JMHZ - NV2027',
        'document_hosts' => ['developers.mpsv.cz'],
        'document_path_prefixes' => ['/assets/documents/'],
        'document_extensions' => ['csv', 'docx', 'eml', 'html', 'pdf', 'xlsx', 'xml', 'xsd', 'zip'],
    ],
    /*
     * Statický přehled změn od 1. 1. 2027 (kapitoly, karty 51 změnových
     * požadavků, souhrn). Stránky odkazují relativně (`mh/…`, `pozadavky/…`),
     * klíčem je cesta stránky a hlídá se otisk jejího obsahu.
     */
    'mpsv-jmhz-2027-site' => [
        'label' => 'Vývojářský portál MPSV - přehled změn JMHZ od 1. 1. 2027',
        'index_url' => 'https://developers.mpsv.cz/jmhz/2027/',
        'index_format' => 'site_pages',
        'document_hosts' => ['developers.mpsv.cz'],
        'document_path_prefixes' => ['/jmhz/2027/'],
        'document_extensions' => ['html'],
    ],
    /*
     * Provozní oznámení ČSSZ. Sem chodí vady katalogu kontrol, výpadky, posuny
     * lhůt i nové povinnosti — 28. 8. 2026 tu ČSSZ oznámila nedostatek ve
     * vyhodnocování kontrol 164, 270, 290, 291 a 333 a hromadný přepočet stavů
     * s opětovným vystavením protokolů. Nic z toho není v žádném dokumentu na
     * ePortálu ani na vývojářském portálu.
     *
     * `article_list` neotevírá jednotlivé články: signálem je, že položka
     * přibyla, a stránka článku nese volatilní obsah.
     */
    'cssz-jmhz-aktuality' => [
        'label' => 'ČSSZ — Aktuality JMHZ',
        'index_url' => 'https://www.cssz.gov.cz/aktuality-jmhz',
        'index_format' => 'article_list',
        'document_hosts' => ['www.cssz.gov.cz'],
        'document_path_prefixes' => ['/web/cz/-/'],
        'document_extensions' => [],
    ],
    /*
     * Verze písemností EPO. Pro JMHZ máme monitor, pro finanční správu do 29. 8.
     * 2026 NIC — XSD se stahují jen na vyžádání a novou verzi si nikdo nevšiml.
     * Nová verze mění `verzePis` v obálce; podání se starou projde naší validací
     * proti starému XSD a odmítne ho až podatelna, tedy až u ostrého podání.
     */
    'mfcr-epo-structures' => [
        'label' => 'Finanční správa — popisy struktur EPO',
        'index_url' => 'https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/popis_struktury_seznam.faces',
        'index_format' => 'epo_structures',
        'document_hosts' => ['adisspr.mfcr.cz'],
        'document_path_prefixes' => ['/dpr/adis/idpr_pub/epo2_info/'],
        'document_extensions' => [],
    ],
    /*
     * Definice e-Podání ČSSZ pro dávkové a evidenční tiskopisy mimo JMHZ.
     * Tady ČSSZ vystavuje definice datových vět, XSD, logické kontroly
     * a číselníky NEMPRI, HZUPN a ELDP (NEMPRI25 z 9. 3. 2026 přinesl nový
     * číselník druhů činnosti). Aplikace z nich staví podání s připnutým XSD,
     * takže nová verze bez hlídače projde naší validací a odmítne ji až ČSSZ.
     *
     * `document_links` sleduje jen odkazy: novou verzi ČSSZ vystavuje jako nový
     * soubor, a přílohy (vzory tisku, archivní verze) mají desítky MB.
     */
    'cssz-definice-nempri' => [
        'label' => 'ČSSZ — Definice e-Podání NEMPRI (a HZUPN, ZZVDPN)',
        'index_url' => 'https://www.cssz.gov.cz/web/cz/definice-e-podani-nempri',
        'index_format' => 'document_links',
        'document_hosts' => ['www.cssz.gov.cz', 'eportal.cssz.cz'],
        'document_path_prefixes' => ['/documents/'],
        'document_extensions' => ['docx', 'pdf', 'xlsx', 'xml', 'xsd', 'zip'],
    ],
    'cssz-definice-hzupn' => [
        'label' => 'ČSSZ — Definice e-Podání HZUPN',
        'index_url' => 'https://www.cssz.gov.cz/web/cz/definice-e-podani-hzupn',
        'index_format' => 'document_links',
        'document_hosts' => ['www.cssz.gov.cz', 'eportal.cssz.cz'],
        'document_path_prefixes' => ['/documents/'],
        'document_extensions' => ['docx', 'pdf', 'xlsx', 'xml', 'xsd', 'zip'],
    ],
    'cssz-definice-eldp' => [
        'label' => 'ČSSZ — Definice e-Podání ELDP',
        'index_url' => 'https://www.cssz.gov.cz/web/cz/definice-e-podani-eldp',
        'index_format' => 'document_links',
        'document_hosts' => ['www.cssz.gov.cz', 'eportal.cssz.cz'],
        'document_path_prefixes' => ['/documents/'],
        'document_extensions' => ['docx', 'pdf', 'xlsx', 'xml', 'xsd', 'zip'],
    ],
    /*
     * REGZEC (registrace zaměstnance) samostatnou stránku „Definice e-Podání"
     * nemá: ČSSZ definice, XSD a zásady vyplňování vystavuje na stránce Pro
     * vývojáře JMHZ, soubory leží na vývojářském portálu MPSV i na webu ČSSZ.
     */
    'cssz-definice-regzec' => [
        'label' => 'ČSSZ — Pro vývojáře JMHZ (REGZEC, PREZEC, REGZEL)',
        'index_url' => 'https://www.cssz.gov.cz/web/cz/pro-vyvojare-jmhz',
        'index_format' => 'document_links',
        'document_hosts' => ['www.cssz.gov.cz', 'developers.mpsv.cz'],
        'document_path_prefixes' => ['/documents/', '/assets/documents/'],
        'document_extensions' => ['csv', 'docx', 'pdf', 'xlsm', 'xlsx', 'xml', 'xsd', 'zip'],
    ],
    'cssz-jmhz-eportal' => [
        'label' => 'ePortál ČSSZ — Jednotné měsíční hlášení zaměstnavatele',
        'index_url' => 'https://eportal.cssz.cz/web/portal/-/sluzby/jednotne-mesicni-hlaseni-zamestnavatele',
        'document_hosts' => ['eportal.cssz.cz', 'www-in.cssz.cz', 'www.cssz.cz'],
        'document_path_prefixes' => ['/documents/'],
        'document_extensions' => ['pdf', 'xlsx', 'xsd', 'zip'],
    ],
];
