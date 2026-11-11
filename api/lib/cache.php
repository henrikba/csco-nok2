<?php
declare(strict_types=1);

require_once __DIR__ . '/yahoo.php';
require_once __DIR__ . '/market.php';

const CACHE_DIR = __DIR__ . '/../../cache';
const CACHE_RETRY_AFTER_FAILURE = 60;

/**
 * fetchDays: how far back Yahoo serves this interval (used on the first fetch).
 * keepDays:  how long bars are kept locally, also after they leave Yahoo's window.
 * overlap:   how much of the cached tail is re-fetched, so live/partial bars get replaced.
 */
const CACHE_INTERVALS = [
    '1m' => ['fetchDays' => 7, 'keepDays' => 14, 'overlap' => 86400],
    '5m' => ['fetchDays' => 59, 'keepDays' => 90, 'overlap' => 86400],
    '30m' => ['fetchDays' => 59, 'keepDays' => 400, 'overlap' => 86400],
    '1d' => ['fetchDays' => 3670, 'keepDays' => null, 'overlap' => 7 * 86400],
];

/**
 * Returns cached bars for a symbol/interval, refreshing them from Yahoo when they have expired.
 * If Yahoo fails, the old cache is returned with stale = true.
 *
 * @return array{symbol: string, interval: string, tz: string, fetchedAt: int, marketTime: int,
 *               rows: list<array{int, float, float, float, float, int}>, stale: bool}
 * @throws RuntimeException when there is neither cache nor data from Yahoo
 */
function cache_series(string $symbol, string $interval): array
{
    $file = cache_path($symbol, $interval);
    $data = cache_read($file);
    if ($data !== null && !cache_is_expired($data, $interval)) {
        return $data + ['stale' => false];
    }

    if (!is_dir(CACHE_DIR) && !@mkdir(CACHE_DIR, 0775, true)) {
        throw new RuntimeException('Kan ikke opprette cache-mappen');
    }
    $lock = @fopen($file . '.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Cache-mappen er ikke skrivbar');
    }
    flock($lock, LOCK_EX);
    try {
        // Another request may have refreshed the file while we waited for the lock.
        $data = cache_read($file);
        if ($data !== null && !cache_is_expired($data, $interval)) {
            return $data + ['stale' => false];
        }
        if ($data !== null && time() - ($data['failedAt'] ?? 0) < CACHE_RETRY_AFTER_FAILURE) {
            return $data + ['stale' => true];
        }

        try {
            $fresh = cache_refresh($symbol, $interval, $data);
            cache_write($file, $fresh);
            return $fresh + ['stale' => false];
        } catch (RuntimeException $e) {
            if ($data === null) {
                throw $e;
            }
            error_log('csco_nok: ' . $e->getMessage());
            $data['failedAt'] = time();
            cache_write($file, $data);
            return $data + ['stale' => true];
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function cache_path(string $symbol, string $interval): string
{
    $name = preg_replace('/[^A-Za-z0-9]+/', '_', $symbol) . '_' . $interval;
    return CACHE_DIR . '/' . $name . '.json';
}

function cache_read(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) && isset($data['rows'], $data['fetchedAt']) ? $data : null;
}

function cache_write(string $file, array $data): void
{
    unset($data['stale']);
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES)) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Kunne ikke skrive cache');
    }
}

function cache_is_expired(array $data, string $interval): bool
{
    $now = time();
    $age = $now - (int) $data['fetchedAt'];
    $daily = $interval === '1d';

    if (us_market_is_open($now)) {
        return $age >= ($daily ? 300 : 60);
    }
    // Keep refreshing shortly after the close, and once more afterwards, so the final bars are picked up.
    $settled = us_last_close($now) + 15 * 60;
    if ($now < $settled) {
        return $age >= 60;
    }
    if ((int) $data['fetchedAt'] < $settled) {
        return true;
    }
    return $age >= ($daily ? 3600 : 6 * 3600);
}

/**
 * Fetches only the tail that is missing (plus an overlap) and merges it into the cached rows.
 */
function cache_refresh(string $symbol, string $interval, ?array $old): array
{
    $cfg = CACHE_INTERVALS[$interval];
    $now = time();
    $rows = $old['rows'] ?? [];

    $earliest = $now - $cfg['fetchDays'] * 86400;
    $period1 = $rows ? max($earliest, $rows[count($rows) - 1][0] - $cfg['overlap']) : $earliest;

    $chart = yahoo_fetch_chart($symbol, $interval, $period1, $now);
    $fresh = $chart['rows'];
    if ($fresh) {
        // Yahoo also returns the bar containing period1, which starts before it.
        $cutoff = min($period1, $fresh[0][0]);
        $byTime = [];
        foreach ($rows as $r) {
            if ($r[0] < $cutoff) {
                $byTime[$r[0]] = $r;
            }
        }
        foreach ($fresh as $r) {
            $byTime[$r[0]] = $r;
        }
        ksort($byTime);
        $rows = array_values($byTime);
    }
    if ($cfg['keepDays'] !== null) {
        $keepFrom = $now - $cfg['keepDays'] * 86400;
        $rows = array_values(array_filter($rows, static fn(array $r): bool => $r[0] >= $keepFrom));
    }

    return [
        'symbol' => $symbol,
        'interval' => $interval,
        'tz' => (string) ($chart['meta']['exchangeTimezoneName'] ?? $old['tz'] ?? 'UTC'),
        'fetchedAt' => $now,
        'marketTime' => (int) ($chart['meta']['regularMarketTime'] ?? $old['marketTime'] ?? $now),
        'rows' => $rows,
    ];
}
