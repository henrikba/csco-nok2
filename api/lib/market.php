<?php
declare(strict_types=1);

const US_MARKET_TZ = 'America/New_York';
const US_MARKET_OPEN_MINUTE = 9 * 60 + 30;
const US_MARKET_CLOSE_MINUTE = 16 * 60;

/** Regular Nasdaq hours by the clock; exchange holidays are not known here. */
function us_market_is_open(int $now): bool
{
    $ny = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(US_MARKET_TZ));
    if ((int) $ny->format('N') > 5) {
        return false;
    }
    $minute = (int) $ny->format('G') * 60 + (int) $ny->format('i');
    return $minute >= US_MARKET_OPEN_MINUTE && $minute < US_MARKET_CLOSE_MINUTE;
}

/** Timestamp of the most recent weekday 16:00 New York time at or before $now. */
function us_last_close(int $now): int
{
    $tz = new DateTimeZone(US_MARKET_TZ);
    $day = (new DateTimeImmutable('@' . $now))->setTimezone($tz);
    for ($i = 0; $i < 7; $i++) {
        $close = us_session_close($day->format('Y-m-d'));
        if ((int) $day->format('N') <= 5 && $close <= $now) {
            return $close;
        }
        $day = $day->modify('-1 day');
    }
    return $now;
}

/** Timestamp of 16:00 New York time on the given date (Y-m-d). */
function us_session_close(string $date): int
{
    return (new DateTimeImmutable($date . ' 16:00:00', new DateTimeZone(US_MARKET_TZ)))->getTimestamp();
}

/** Calendar date (Y-m-d) of a timestamp in the given time zone. */
function day_key(int $t, string $tz): string
{
    static $zones = [];
    $zones[$tz] ??= new DateTimeZone($tz);
    return (new DateTimeImmutable('@' . $t))->setTimezone($zones[$tz])->format('Y-m-d');
}
