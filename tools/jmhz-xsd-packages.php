<?php

declare(strict_types=1);

/**
 * `xsd_root` je nepovinný podadresář archivu, ze kterého se schémata berou.
 * Bez něj se bere celý archiv, což platilo pro všechny balíčky do JMHZ 1.4.3.4.
 *
 * @return array<string,array{
 *     target:string,
 *     version:string,
 *     url:string,
 *     sha256:string,
 *     xsd_count:int,
 *     entry_points:list<string>,
 *     xsd_root?:string,
 *     inner_archive?:string
 * }>
 * `inner_archive` je cesta schématového ZIPu uvnitř vnějšího archivu (JMHZ 1.5.0.3
 * veze schémata v `JMHZ_podani_1.5.0.3_xsd.zip` vedle HTML dokumentace).
 */
return [
    'jmhz' => [
        'target' => 'jmhz-1.4.3.6',
        'version' => '1.4.3.6',
        'url' => 'https://developers.mpsv.cz/assets/documents/a0ca7983-9aed-40cc-aa96-8a97c3641a88/JMHZ_pod%C3%A1n%C3%AD_1.4.3.6.zip',
        'sha256' => '79a08fc60b2cb7753a772100463a1f80259437ab99cfcee8d2e06061e220e879',
        'xsd_count' => 14,
        'entry_points' => ['jmhzPodani.xsd'],
        'xsd_root' => 'xsd_1_4_3_6/externi_xsd',
    ],
    'regzec' => [
        'target' => 'regzec-1.4.0.4',
        'version' => '1.4.0.4',
        'url' => 'https://developers.mpsv.cz/assets/documents/1929cebf-fc5e-41e9-8319-97248cb22e8e/REGZEC25_ver_1.4.0.4.zip',
        'sha256' => '0d0396fd857a6602b01a3ecf234fe02da96f00f316eea34de6e67b06e4cc2b1f',
        'xsd_count' => 2,
        'entry_points' => ['REGZEC25.xsd'],
    ],
    'prezec' => [
        'target' => 'prezec-1.2',
        'version' => '1.2',
        'url' => 'https://developers.mpsv.cz/assets/documents/893169c6-1c40-4555-a5b4-a5621d80d98c/PREZEC26_ver_1.2.zip',
        'sha256' => 'dda370c1f24ebbef1462c305b526e61fdebd6c280e97624aad8e8a6426216224',
        'xsd_count' => 2,
        'entry_points' => ['PREZEC26 1.2.xsd'],
    ],
    'regzeldopl' => [
        'target' => 'regzeldopl-1.2',
        'version' => '1.2',
        'url' => 'https://developers.mpsv.cz/assets/documents/eddd6a43-f713-43c8-91e3-eceb9b1a796f/REGZELDOPL25_v1_2.zip',
        'sha256' => '6f0eb190573336d3250130206a34d84fa228c7bc9fec2f0dd9176cb29e120dd3',
        'xsd_count' => 2,
        'entry_points' => ['REGZELDOPL25.xsd'],
    ],
    'dzmh' => [
        'target' => 'dzmh-1.1',
        'version' => '1.1',
        'url' => 'https://developers.mpsv.cz/assets/documents/85fb9c97-b3f4-40d9-98dc-1d171e21f84c/DZMH25_xsd%20v1.1.zip',
        'sha256' => '1e89ec55b56b3e00f3f6a066e92bf3e39d29b05a5e2f0f8c7be95ead65111d06',
        'xsd_count' => 2,
        'entry_points' => ['DZMH25.xsd'],
    ],
    'orezam-zrezam' => [
        'target' => 'orezam-zrezam-1.0',
        'version' => '1.0',
        'url' => 'https://developers.mpsv.cz/assets/documents/22f9953d-f0db-4578-afd4-e17ed98e0df2/OREZAM%20a%20ZREZAM%20xsd.zip',
        'sha256' => '9a153012035ac821a30bd9f5e437ea4b92b662ae058fefa319d6972dcd6c43dc',
        'xsd_count' => 3,
        'entry_points' => ['OREZAM26.xsd', 'ZREZAM26.xsd'],
    ],
    // Balíčky pro rok 2027 (vykazované období od 1. 1. 2027). Žijí vedle balíčků
    // 2026, výběr podle období dělá až katalog schémat.
    'jmhz-2027' => [
        'target' => 'jmhz-1.5.0.3',
        'version' => '1.5.0.3',
        'url' => 'https://developers.mpsv.cz/assets/documents/99dab4c2-7b7c-4422-bf9e-b5cb8192f426/JMHZ_podani_1.5.0.3.zip',
        'sha256' => 'e8fe64bb6bea0e12e921995efd2014f8e80360d6df9e3cb00189bc4a87763941',
        'xsd_count' => 15,
        'entry_points' => ['jmhzPodani.xsd'],
        'inner_archive' => 'JMHZ_podani_1.5.0.3_xsd.zip',
    ],
    'regzec-2027' => [
        'target' => 'regzec-1.5',
        'version' => '1.5',
        'url' => 'https://developers.mpsv.cz/assets/documents/c3045862-665b-4a15-bc2f-9eac5b68ab96/REGZEC27_podan%C3%AD_1.5.zip',
        'sha256' => '37a69516ac8eeb5b18f07da94355e6d6cd2b6b0967526ff10733df4a8c8068ae',
        'xsd_count' => 2,
        'entry_points' => ['REGZEC27_1_5.xsd'],
    ],
    'prezec-2027' => [
        'target' => 'prezec-1.5',
        'version' => '1.5',
        'url' => 'https://developers.mpsv.cz/assets/documents/0dcadaa1-dd30-4a8a-b95a-8a262787a007/PREZEC26_podani%CC%81_1_5.zip',
        'sha256' => 'dc509815450ea60c4763fe354e3f423e0d3c133694e54c2fcb7430cff64af3ef',
        'xsd_count' => 2,
        'entry_points' => ['PREZEC26_1_5.xsd'],
    ],
    'dzmh-2027' => [
        'target' => 'dzmh-1.5',
        'version' => '1.5',
        'url' => 'https://developers.mpsv.cz/assets/documents/d6178272-fa28-4891-b95d-f324385f6c3d/DZMH25_podani_1.5.zip',
        'sha256' => '7ebe93ea3898e88d5097ae5a9b6ef10521c27bfa87aea90b519f8ab9d4971e23',
        'xsd_count' => 2,
        'entry_points' => ['DZMH25_1_5.xsd'],
    ],
    'zrezam-2027' => [
        'target' => 'zrezam-1.5',
        'version' => '1.5',
        'url' => 'https://developers.mpsv.cz/assets/documents/6f8b270d-6669-4776-a6ae-2a6d43584b24/ZREZAM_podani_1.5.zip',
        'sha256' => '23320535b04b83a7b992456f18ffb7ff800967da5288587e8b9d932cede1e114',
        'xsd_count' => 2,
        'entry_points' => ['ZREZAM27_1_5.xsd'],
    ],
];
