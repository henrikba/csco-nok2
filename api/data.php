<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/cache.php';
require_once __DIR__ . '/lib/merge.php';

ini_set('display_errors', '0');
date_default_timezone_set('UTC');

const STOCK_SYMBOL = 'CSCO';
const FX_SYMBOL = 'NOK=X';

/** days: number of trading days shown. months: calendar months back from the latest trading day. */
const RANGES = [
    '1d' => ['interval' => '1m', 'days' => 1],
    '1w' => ['interval' => '5m', 'days' => 5],
    '1m' => ['interval' => '30m', 'months' => 1],
    '6m' => ['interval' => '1d', 'months' => 6],
    '1y' => ['interval' => '1d', 'months' => 12],
    '3y' => ['interval' => '1d', 'months' => 36],
    '5y' => ['interval' => '1d', 'months' => 60],
    '10y' => ['interval' => '1d', 'months' => 120],
];

if (!ini_get('zlib.output_compression') && extension_loaded('zlib')) {
    ob_start('ob_gzhandler');
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$range = (string) ($_GET['range'] ?? '1d');
if (!isset(RANGES[$range])) {
    respond(400, ['error' => 'Ugyldig periode']);
}

try {
    respond(200, build_response($range, RANGES[$range]));
} catch (Throwable $e) {
    error_log('csco_nok: ' . $e->getMessage());
    respond(502, ['error' => 'Klarte ikke å hente kursdata. Prøv igjen om litt.']);
}

function build_response(string $range, array $cfg): array
{
    $stockDaily = cache_series(STOCK_SYMBOL, '1d');
    $fxDailySeries = cache_series(FX_SYMBOL, '1d');
    $fxDaily = new FxDaily($fxDailySeries['rows'], $fxDailySeries['tz']);
    $dailyPoints = merge_daily($stockDaily['rows'], $stockDaily['tz'], $fxDaily);
    $stale = $stockDaily['stale'] || $fxDailySeries['stale'];
    $asOf = $stockDaily['marketTime'];

    if ($cfg['interval'] === '1d') {
        $points = select_months($dailyPoints, $cfg['months']);
    } else {
        $stock = cache_series(STOCK_SYMBOL, $cfg['interval']);
        $fx = cache_series(FX_SYMBOL, $cfg['interval']);
        $stale = $stale || $stock['stale'] || $fx['stale'];
        $all = merge_intraday($stock['rows'], $stock['tz'], $fx['rows'], $fxDaily);
        $points = isset($cfg['days']) ? select_days($all, $cfg['days']) : select_months($all, $cfg['months']);
        if ($points) {
            $asOf = max($asOf, $points[count($points) - 1]['t']);
        }
    }

    if (!$points) {
        throw new RuntimeException("Ingen data for $range");
    }

    return [
        'range' => $range,
        'interval' => $cfg['interval'],
        'stale' => $stale,
        'points' => array_map('public_point', $points),
        'summary' => build_summary($range, $points, $dailyPoints, $asOf, $stockDaily['tz']),
    ];
}

function build_summary(string $range, array $points, array $dailyPoints, int $asOf, string $tz): array
{
    $last = $points[count($points) - 1];
    $sessionDate = $last['d'];
    $lastNok = $last['nok'];

    $prev = null;
    foreach ($dailyPoints as $p) {
        if ($p['d'] < $sessionDate) {
            $prev = $p;
        }
    }
    $prevCloseNok = $prev['nok'] ?? null;

    $rangeLow = min(array_column($points, 'lowNok'));
    $rangeHigh = max(array_column($points, 'highNok'));

    $yearAgo = (new DateTimeImmutable($sessionDate))->modify('-1 year')->format('Y-m-d');
    $year = array_filter($dailyPoints, static fn(array $p): bool => $p['d'] > $yearAgo && $p['d'] <= $sessionDate);
    $w52Low = $year ? min(array_column($year, 'lowNok')) : $rangeLow;
    $w52High = $year ? max(array_column($year, 'highNok')) : $rangeHigh;

    $base = $range === '1d' && $prevCloseNok !== null ? $prevCloseNok : $points[0]['nok'];
    $now = time();
    $lastDailyDate = $dailyPoints ? $dailyPoints[count($dailyPoints) - 1]['d'] : '';

    return [
        'lastNok' => round($lastNok, 4),
        'changeNok' => round($lastNok - $base, 4),
        'changePct' => $base > 0 ? round(($lastNok / $base - 1) * 100, 4) : 0,
        'baseNok' => round($base, 4),
        'prevCloseNok' => $prevCloseNok !== null ? round($prevCloseNok, 4) : null,
        'rangeLowNok' => round(min($rangeLow, $lastNok), 4),
        'rangeHighNok' => round(max($rangeHigh, $lastNok), 4),
        'w52LowNok' => round(min($w52Low, $lastNok), 4),
        'w52HighNok' => round(max($w52High, $lastNok), 4),
        'lastCsco' => round($last['csco'], 4),
        'lastFx' => round($last['fx'], 5),
        'asOf' => $asOf,
        'marketOpen' => us_market_is_open($now) && $lastDailyDate === day_key($now, $tz),
        'sessionEnd' => $range === '1d' ? us_session_close($sessionDate) : null,
    ];
}

/** Points from the last $days distinct trading days. */
function select_days(array $points, int $days): array
{
    $dates = array_values(array_unique(array_column($points, 'd')));
    if (!$dates) {
        return [];
    }
    $from = $dates[max(0, count($dates) - $days)];
    return array_values(array_filter($points, static fn(array $p): bool => $p['d'] >= $from));
}

/** Points from the last $months calendar months, counted from the latest trading day. */
function select_months(array $points, int $months): array
{
    if (!$points) {
        return [];
    }
    $lastDate = $points[count($points) - 1]['d'];
    $from = (new DateTimeImmutable($lastDate))->modify("-$months months")->format('Y-m-d');
    return array_values(array_filter($points, static fn(array $p): bool => $p['d'] >= $from));
}

function public_point(array $p): array
{
    $out = [
        't' => $p['t'],
        'nok' => round($p['nok'], 4),
        'csco' => round($p['csco'], 4),
        'fx' => round($p['fx'], 5),
        'fxSource' => $p['fxSource'],
    ];
    if ($p['fxDate'] !== $p['d']) {
        $out['fxDate'] = $p['fxDate'];
    }
    return $out;
}

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
