<?php

declare(strict_types=1);

namespace MyInvoice\Service\Automation;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\BackgroundProcess;

final class AutomationRecommendationWorkerLauncher
{
    public function spawn(int $jobId): bool
    {
        return BackgroundProcess::spawnPhp(
            Bootstrap::rootDir() . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            RuntimePaths::log('import-worker.log'),
            Bootstrap::rootDir(),
        );
    }
}
