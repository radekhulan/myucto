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
    'cssz-jmhz-eportal' => [
        'label' => 'ePortál ČSSZ — Jednotné měsíční hlášení zaměstnavatele',
        'index_url' => 'https://eportal.cssz.cz/web/portal/-/sluzby/jednotne-mesicni-hlaseni-zamestnavatele',
        'document_hosts' => ['eportal.cssz.cz', 'www-in.cssz.cz', 'www.cssz.cz'],
        'document_path_prefixes' => ['/documents/'],
        'document_extensions' => ['pdf', 'xlsx', 'xsd', 'zip'],
    ],
];
