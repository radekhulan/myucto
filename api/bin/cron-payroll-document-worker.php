<?php

declare(strict_types=1);

define('MYINVOICE_CRON_SCRIPT', 'cron-payroll-document-worker');
require __DIR__ . '/payroll-document-worker.php';
