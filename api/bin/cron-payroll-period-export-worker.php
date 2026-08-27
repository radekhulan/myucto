<?php

declare(strict_types=1);

define('MYINVOICE_CRON_SCRIPT', 'cron-payroll-period-export-worker');
require __DIR__ . '/payroll-period-export-worker.php';
