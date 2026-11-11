<?php
declare(strict_types=1);

const YAHOO_HOSTS = ['query1.finance.yahoo.com', 'query2.finance.yahoo.com'];
// Yahoo answers 429 to full browser UA strings and to curl's default; this short one is accepted.
const YAHOO_USER_AGENT = 'Mozilla/5.0';

/**
 * Fetches OHLCV bars for one symbol from Yahoo's v8 chart endpoint.
 *
 * @return array{meta: array, rows: list<array{int, float, float, float, float, int}>} rows sorted by time
 * @throws RuntimeException
 */
function yahoo_fetch_chart(string $symbol, string $interval, int $period1, int $period2): array
{
    $query = http_build_query([
        'interval' => $interval,
        'period1' => $period1,
        'period2' => $period2,
        'includePrePost' => 'false',
    ]);

    $lastError = 'ukjent feil';
    foreach (YAHOO_HOSTS as $host) {
        $url = 'https://' . $host . '/v8/finance/chart/' . rawurlencode($symbol) . '?' . $query;
        try {
            return yahoo_parse_chart(yahoo_http_get($url));
        } catch (RuntimeException $e) {
            $lastError = $e->getMessage();
        }
    }
    throw new RuntimeException("Yahoo $symbol/$interval: $lastError");
}

function yahoo_http_get(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => YAHOO_USER_AGENT,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);

    if ($body === false) {
        throw new RuntimeException("nettverksfeil: $error");
    }
    if ($status !== 200) {
        throw new RuntimeException("HTTP $status");
    }
    return $body;
}

/**
 * @return array{meta: array, rows: list<array{int, float, float, float, float, int}>}
 */
function yahoo_parse_chart(string $body): array
{
    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['chart'])) {
        throw new RuntimeException('ugyldig svar');
    }
    if (!empty($json['chart']['error'])) {
        $err = $json['chart']['error'];
        throw new RuntimeException(is_array($err) ? ($err['description'] ?? $err['code'] ?? 'feil') : (string) $err);
    }
    $result = $json['chart']['result'][0] ?? null;
    if (!is_array($result)) {
        throw new RuntimeException('mangler data');
    }

    $meta = $result['meta'] ?? [];
    $timestamps = $result['timestamp'] ?? [];
    $quote = $result['indicators']['quote'][0] ?? [];

    $byTime = [];
    foreach ($timestamps as $i => $t) {
        $close = $quote['close'][$i] ?? null;
        if ($close === null) {
            continue;
        }
        $byTime[(int) $t] = [
            (int) $t,
            round((float) ($quote['open'][$i] ?? $close), 6),
            round((float) ($quote['high'][$i] ?? $close), 6),
            round((float) ($quote['low'][$i] ?? $close), 6),
            round((float) $close, 6),
            (int) ($quote['volume'][$i] ?? 0),
        ];
    }
    ksort($byTime);

    return ['meta' => $meta, 'rows' => array_values($byTime)];
}
