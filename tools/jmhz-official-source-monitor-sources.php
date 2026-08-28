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
    'cssz-jmhz-eportal' => [
        'label' => 'ePortál ČSSZ — Jednotné měsíční hlášení zaměstnavatele',
        'index_url' => 'https://eportal.cssz.cz/web/portal/-/sluzby/jednotne-mesicni-hlaseni-zamestnavatele',
        'document_hosts' => ['eportal.cssz.cz', 'www-in.cssz.cz', 'www.cssz.cz'],
        'document_path_prefixes' => ['/documents/'],
        'document_extensions' => ['pdf', 'xlsx', 'xsd', 'zip'],
    ],
];
