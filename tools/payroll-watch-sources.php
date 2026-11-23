<?php

declare(strict_types=1);

/**
 * Zdroje hlídače legislativy a číselníků pro mzdy (tools/watchPayrollSources.php).
 *
 * Balíky MPSV/ČSSZ se berou z připnutých manifestů repa (jmhz-codebook-sources.php,
 * jmhz-xsd-packages.php), takže hlídač ohlásí, i když web vydá jiné bajty pod stejnou adresou.
 *
 * @return array<string,array<string,mixed>>
 */

$sources = [];

foreach ((require __DIR__ . '/jmhz-codebook-sources.php')['sources'] as $name => $package) {
    if (is_string($package['url'] ?? null)) {
        $sources['pkg-codebook-' . $name] = [
            'kind' => 'file',
            'label' => 'JMHZ podklad ' . $package['filename'],
            'url' => $package['url'],
            'pinned_sha256' => $package['sha256'],
        ];
    }
}
foreach (require __DIR__ . '/jmhz-xsd-packages.php' as $name => $package) {
    $sources['pkg-xsd-' . $name] = [
        'kind' => 'file',
        'label' => 'XSD ' . $package['target'],
        'url' => $package['url'],
        'pinned_sha256' => $package['sha256'],
    ];
}

$mpsvApi = 'https://developers.mpsv.cz/api/apiversion/';
$mpsvPortal = 'https://developers.mpsv.cz/api-list/jednotne-mesicni-hlaseni-zamestnavatelu/documentation/';
$v2027 = '31f3dce2-a6c8-4ee2-86d5-2ce88133b0b8';
$v2026 = 'f94afba9-d603-4681-8b7e-87089cc13a9e';

$sources += [
    'mpsv-nv2027' => [
        'kind' => 'mpsv-page',
        'label' => 'MPSV - Dokumentace projektu JMHZ - NV2027 (přílohy, stav návrhu)',
        'url' => "{$mpsvApi}{$v2027}/documentationPage/0e0550d8-e3b4-4716-b080-14a9d8ad5e8a",
        'page_url' => $mpsvPortal . '0e0550d8-e3b4-4716-b080-14a9d8ad5e8a',
    ],
    'mpsv-nv2026' => [
        'kind' => 'mpsv-page',
        'label' => 'MPSV - Dokumentace projektu JMHZ (rok 2026)',
        'url' => "{$mpsvApi}{$v2026}/documentationPage/4589f5c6-30e8-4e2b-b341-fe8481ad4e70",
        'page_url' => $mpsvPortal . '4589f5c6-30e8-4e2b-b341-fe8481ad4e70',
    ],
    'mpsv-qa-2027' => [
        'kind' => 'mpsv-page',
        'label' => 'MPSV - Otázky a odpovědi k JMHZ (NV2027)',
        'url' => "{$mpsvApi}{$v2027}/documentationPage/c44a3b8d-fb04-4c28-acf6-01bd06a7a88a",
        'page_url' => $mpsvPortal . 'c44a3b8d-fb04-4c28-acf6-01bd06a7a88a',
    ],
    'mpsv-aktuality-2027' => [
        'kind' => 'mpsv-page',
        'label' => 'MPSV - Aktuality JMHZ',
        'url' => "{$mpsvApi}{$v2027}/documentationPage/1bf18d7e-d9b4-4943-8388-ca29804f3ecb",
        'page_url' => $mpsvPortal . '1bf18d7e-d9b4-4943-8388-ca29804f3ecb',
    ],
    'cssz-pro-vyvojare-jmhz' => [
        'kind' => 'page',
        'label' => 'ČSSZ - Pro vývojáře JMHZ (REGZEC, PREZEC, Q&A)',
        'url' => 'https://www.cssz.gov.cz/web/cz/pro-vyvojare-jmhz',
        'extract' => '~(<h1\\b.*?)<section class="portlet" id="portlet_com_liferay_journal_content_web_portlet_JournalContentPortlet_INSTANCE_footer"~is',
    ],
    'cssz-aktuality-jmhz' => [
        'kind' => 'page',
        'label' => 'ČSSZ - Aktuality JMHZ',
        'url' => 'https://www.cssz.gov.cz/aktuality-jmhz',
        'extract' => '~(<h1\\b.*?)<section class="portlet" id="portlet_com_liferay_journal_content_web_portlet_JournalContentPortlet_INSTANCE_footer"~is',
    ],
    'csu-cisob-43' => [
        'kind' => 'file',
        'label' => 'ČSÚ - číselník obcí CISOB (43), stav k 1. 1. 2027',
        'url' => 'https://apl2.czso.cz/iSMS/cisdet_prn.jsp?kodcis=43&datpohl=01.01.2027',
        'page_url' => 'https://apl2.czso.cz/iSMS/cisdet.jsp?kodcis=43&datpohl=01.01.2027',
    ],
    'csu-czem-1186' => [
        'kind' => 'file',
        'label' => 'ČSÚ - číselník států CZEMALFA (1186), stav k 1. 1. 2027',
        'url' => 'https://apl2.czso.cz/iSMS/cisdet_prn.jsp?kodcis=1186&datpohl=01.01.2027',
        'page_url' => 'https://apl2.czso.cz/iSMS/cisdet.jsp?kodcis=1186&datpohl=01.01.2027',
    ],
];

/* Kmeny slov (porovnává se bez diakritiky a velikosti písmen, takže pokryjí skloňování). */
$legislationKeywords = [
    'stravn', 'minimální mzd', 'zaručen', 'měsíčním hlášení', 'měsíčního hlášení', 'jmhz',
    'normativní nájemn', 'normativního nájemn', 'nezabavitel', 'cestovních náhrad', 'základní náhrad', 'stravenkov',
    'územní identifikac', 'rozhodného příjm', 'rozhodných příjm', 'výkon rozhodnutí', 'srážek ze mzd',
    'pojistné na sociální zabezpečení', 'nemocenské pojištění', 'nemocenského pojištění',
];

$sources['sbirka-nove-predpisy'] = [
    'kind' => 'rss',
    'label' => 'Sbírka zákonů - nově vyhlášené předpisy (RSS Zákony pro lidi)',
    'url' => 'https://www.zakonyprolidi.cz/cs/nove-predpisy.rss',
    'page_url' => 'https://www.e-sbirka.cz/',
    'keywords' => $legislationKeywords,
];
$sources['sbirka-nova-zneni'] = [
    'kind' => 'rss',
    'label' => 'Sbírka zákonů - nová znění předpisů (RSS Zákony pro lidi)',
    'url' => 'https://www.zakonyprolidi.cz/cs/nova-zneni.rss',
    'page_url' => 'https://www.e-sbirka.cz/',
    'keywords' => $legislationKeywords,
];

return $sources;
