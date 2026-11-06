<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tools/regression/bootstrap.php';

exit(\MyUctoRegression\Runner::main($argv));
