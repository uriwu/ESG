<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/CarbonEngine.php';

use App\Services\CarbonEngine;

$engine = new CarbonEngine();

$tests = [
    ['name' => 'electricity', 'actual' => $engine->calculate(100000, 0.474), 'expected' => 47.4],
    ['name' => 'diesel gases', 'actual' => $engine->calculate(5000, 2.606, 0.0001, 0.0001, 28, 265), 'expected' => 13.1765],
];

foreach ($tests as $test) {
    if (abs($test['actual'] - $test['expected']) > 0.000001) {
        fwrite(STDERR, "FAIL {$test['name']}: {$test['actual']} != {$test['expected']}\n");
        exit(1);
    }
}

$normal = $engine->anomaly(105, [100, 98, 102]);
$high = $engine->anomaly(150, [100, 98, 102]);
if ($normal['is_anomaly'] || !$high['is_anomaly']) {
    fwrite(STDERR, "FAIL anomaly threshold\n");
    exit(1);
}
echo "CarbonEngineTest passed\n";
