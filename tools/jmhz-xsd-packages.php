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
 *     xsd_root?:string
 * }>
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
];
