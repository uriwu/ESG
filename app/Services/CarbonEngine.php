<?php
declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class CarbonEngine
{
    /**
     * 係數單位為 kg gas / 活動單位，結果為 tCO2e。
     */
    public function calculate(
        float $usage,
        float $co2Factor,
        float $ch4Factor = 0.0,
        float $n2oFactor = 0.0,
        float $ch4Gwp = 28.0,
        float $n2oGwp = 265.0
    ): float {
        foreach (func_get_args() as $value) {
            if ($value < 0) {
                throw new InvalidArgumentException('活動數據、係數與 GWP 不得為負數。');
            }
        }
        $kgCo2e = $usage * ($co2Factor + ($ch4Factor * $ch4Gwp) + ($n2oFactor * $n2oGwp));
        return round($kgCo2e / 1000, 6);
    }

    public function anomaly(float $current, array $previousThree): array
    {
        $values = array_values(array_filter(array_map('floatval', $previousThree), static fn(float $v): bool => $v >= 0));
        if ($current < 0 || $values === []) {
            return ['is_anomaly' => false, 'average' => null, 'deviation_pct' => null];
        }
        $average = array_sum($values) / count($values);
        if ($average == 0.0) {
            return ['is_anomaly' => $current > 0, 'average' => 0.0, 'deviation_pct' => $current > 0 ? 100.0 : 0.0];
        }
        $deviation = (($current - $average) / $average) * 100;
        return [
            'is_anomaly' => abs($deviation) > 30.0,
            'average' => round($average, 4),
            'deviation_pct' => round($deviation, 2),
        ];
    }
}

