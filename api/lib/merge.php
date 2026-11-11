<?php
declare(strict_types=1);

require_once __DIR__ . '/market.php';

/** Daily USD/NOK closes keyed by calendar date, with nearest-date lookup. */
final class FxDaily
{
    /** @var list<string> sorted Y-m-d */
    private array $dates;
    /** @var list<float> */
    private array $rates;

    public function __construct(array $rows, string $tz)
    {
        $byDate = [];
        foreach ($rows as $r) {
            $byDate[day_key($r[0], $tz)] = $r[4];
        }
        ksort($byDate);
        $this->dates = array_map('strval', array_keys($byDate));
        $this->rates = array_values($byDate);
    }

    /**
     * Rate for the given date, or for the nearest date that has one (ties go to the earlier date).
     *
     * @return array{float, string} rate and the date it belongs to
     */
    public function lookup(string $date): array
    {
        $n = count($this->dates);
        if ($n === 0) {
            throw new RuntimeException('Mangler USD/NOK-dagskurser');
        }

        $lo = 0;
        $hi = $n;
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($this->dates[$mid] < $date) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        if ($lo < $n && $this->dates[$lo] === $date) {
            return [$this->rates[$lo], $date];
        }

        $before = $lo - 1;
        $after = $lo;
        if ($before < 0) {
            $i = $after;
        } elseif ($after >= $n) {
            $i = $before;
        } else {
            $target = self::dayNumber($date);
            $distBefore = $target - self::dayNumber($this->dates[$before]);
            $distAfter = self::dayNumber($this->dates[$after]) - $target;
            $i = $distAfter < $distBefore ? $after : $before;
        }
        return [$this->rates[$i], $this->dates[$i]];
    }

    private static function dayNumber(string $date): int
    {
        return intdiv((int) strtotime($date . ' 00:00:00 UTC'), 86400);
    }
}

/**
 * Converts daily CSCO bars to NOK using the USD/NOK close of the same (or nearest) date.
 *
 * @return list<array<string, mixed>>
 */
function merge_daily(array $rows, string $tz, FxDaily $fx): array
{
    $points = [];
    foreach ($rows as [$t, , $high, $low, $close]) {
        $date = day_key($t, $tz);
        [$rate, $fxDate] = $fx->lookup($date);
        $points[] = make_point($t, $date, $close, $high, $low, $rate, 'daily', $fxDate);
    }
    return $points;
}

/**
 * Converts intraday CSCO bars to NOK using the latest USD/NOK intraday bar at or before each bar
 * on the same trading day. Days without intraday rates use that day's daily rate for the whole day.
 *
 * @return list<array<string, mixed>>
 */
function merge_intraday(array $rows, string $tz, array $fxRows, FxDaily $fxDaily): array
{
    $fxDays = array_map(static fn(array $r): string => day_key($r[0], $tz), $fxRows);
    $n = count($fxRows);
    $j = -1;

    $points = [];
    foreach ($rows as [$t, , $high, $low, $close]) {
        $date = day_key($t, $tz);
        while ($j + 1 < $n && $fxRows[$j + 1][0] <= $t) {
            $j++;
        }

        if ($j >= 0 && $fxDays[$j] === $date) {
            $points[] = make_point($t, $date, $close, $high, $low, $fxRows[$j][4], 'intraday', $date);
        } elseif ($j + 1 < $n && $fxDays[$j + 1] === $date) {
            $points[] = make_point($t, $date, $close, $high, $low, $fxRows[$j + 1][4], 'intraday', $date);
        } else {
            [$rate, $fxDate] = $fxDaily->lookup($date);
            $points[] = make_point($t, $date, $close, $high, $low, $rate, 'daily', $fxDate);
        }
    }
    return $points;
}

function make_point(int $t, string $date, float $close, float $high, float $low, float $rate, string $source, string $fxDate): array
{
    return [
        't' => $t,
        'd' => $date,
        'csco' => $close,
        'fx' => $rate,
        'nok' => $close * $rate,
        'highNok' => $high * $rate,
        'lowNok' => $low * $rate,
        'fxSource' => $source,
        'fxDate' => $fxDate,
    ];
}
