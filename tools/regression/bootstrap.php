<?php

declare(strict_types=1);

foreach (['Comparator', 'Safety', 'Tripwire', 'ConfigSnapshot', 'Instrumentation', 'Network', 'CaseProjection', 'Manifest', 'Runner'] as $regressionClass) {
    require_once __DIR__ . '/' . $regressionClass . '.php';
}
