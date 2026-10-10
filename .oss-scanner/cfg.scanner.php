<?php

// Konfigurace testovací instance v image pro OSS Scanner. Shodná s CI (.github/workflows/ci.yml),
// klíče jsou syntetické a slouží jen pro běh testů.
return [
    'app' => [
        'env'   => 'production',
        'debug' => false,
        'url'   => 'http://localhost:8080',
        'pepper'                => 'Y2ktcGVwcGVyLWZvci10ZXN0cy1vbmx5ISEhISEhISE=',
        'secret_encryption_key' => 'Y2ktc2VjcmV0LWtleS1mb3ItdGVzdHMhISEhISEhISE=',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'myinvoice_ci',
        'user' => 'root',
        'pass' => 'ci',
    ],
    'redis' => [
        'enabled' => true,
        'host'    => '127.0.0.1',
        'port'    => 6379,
        'prefix'  => 'myinvoice:ci:',
    ],
    'captcha' => ['provider' => 'none'],
    'varsymbol' => [
        'templates' => [
            'invoice'     => '{YY}{MM}{CCC}',
            'proforma'    => '9{YY}{MM}{CCC}',
            'credit_note' => '7{YY}{MM}{CCC}',
        ],
    ],
];
