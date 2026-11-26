<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

// Testbench finds testbench.yaml through Composer's root package. The Rector
// fixture tests (tests/Rector) register Rector's own Composer autoloader in
// front of this one, after which Composer reports Rector as the root package,
// so a run that builds those tests before any Testbench test (a --filter run)
// would boot without the package's service provider.
define('TESTBENCH_WORKING_PATH', dirname(__DIR__));
