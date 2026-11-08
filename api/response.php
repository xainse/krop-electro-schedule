<?php
require_once __DIR__ . '/data.php';

/** Do not convert yesterday's data or an unverified legacy cache into today's forecast. */
function scheduleResponse($data, $queue = null, $now = null) {
    $now = $now ?? time();
    $date = is_array($data) ? ($data['date'] ?? null) : null;
    $current = dateToKey($date) === date('Y-m-d', $now);
    $verified = is_array($data) && isset($data['verified_at']) && is_numeric($data['verified_at']);
    $updated = $verified ? (int)$data['verified_at'] : null;
    $stale = !$current || !$verified || $updated > $now || $now - $updated >= 600;
    // v4.4 created absence markers from parsing failures; do not trust that legacy cache.
    if (!empty($data['not_announced']) && ($data['notice_verified'] ?? false) !== true) $stale = true;
    $queues = $current && $verified && is_array($data['queues'] ?? null) ? $data['queues'] : [];
    $notAnnounced = !$stale && !empty($data['not_announced']);
    $result = [
        'success' => true,
        'date' => $date,
        'updated' => $updated,
        'stale' => $stale,
        'available' => !empty($queues),
        'not_announced' => $notAnnounced,
        'message' => $notAnnounced ? 'На сьогодні немає актуального графіку' : null,
        'emergency_mode' => $current && $verified ? ($data['emergency_mode'] ?? null) : null,
        'source' => $data['source'] ?? null,
    ];
    if ($queue === null) $result['queues'] = (object)$queues;
    else {
        $result['queue'] = $queue;
        $result['schedule'] = $queues[$queue] ?? null;
        $result['available'] = array_key_exists($queue, $queues);
    }
    return $result;
}

/**
 * Відповідь для явно запитаної дати, відмінної від сьогодні.
 * Минулий збережений графік не прострочується. Майбутній лишається доступним,
 * а stale лише показує, що перевірка старша за 10 хвилин.
 */
function scheduleResponseForDate($entry, $requestedDate, $queue = null, $now = null) {
    $now = $now ?? time();
    $requestedKey = dateToKey($requestedDate);
    $canonical = $requestedKey ? keyToDate($requestedKey) : $requestedDate;
    $todayKey = date('Y-m-d', $now);
    $future = $requestedKey !== false && $requestedKey > $todayKey;
    $past = $requestedKey !== false && $requestedKey < $todayKey;
    $absence = $future
        ? 'Графік на ' . $canonical . ' ще не опубліковано'
        : 'Немає збереженого графіка на ' . $canonical;
    $queues = is_array($entry) && is_array($entry['queues'] ?? null) ? $entry['queues'] : null;

    if ($queues === null) {
        $result = [
            'success' => true,
            'date' => $canonical,
            'updated' => null,
            'stale' => false,
            'available' => false,
            'not_announced' => false,
            'message' => $absence,
            'emergency_mode' => null,
            'source' => null,
        ];
        if ($queue === null) $result['queues'] = (object)[];
        else {
            $result['queue'] = $queue;
            $result['schedule'] = null;
        }
        return $result;
    }

    $updated = null;
    if (isset($entry['verified_at']) && is_numeric($entry['verified_at'])) $updated = (int)$entry['verified_at'];
    elseif (isset($entry['timestamp']) && is_numeric($entry['timestamp'])) $updated = (int)$entry['timestamp'];
    $stale = !$past && ($updated === null || $updated > $now || $now - $updated >= 600);
    $result = [
        'success' => true,
        'date' => is_string($entry['date'] ?? null) ? $entry['date'] : $canonical,
        'updated' => $updated,
        'stale' => $stale,
        'available' => !empty($queues),
        'not_announced' => false,
        'message' => null,
        'emergency_mode' => array_key_exists('emergency_mode', $entry) ? $entry['emergency_mode'] : null,
        'source' => $entry['source'] ?? null,
    ];
    if ($queue === null) {
        $result['queues'] = (object)$queues;
        if ($queues === []) {
            $result['available'] = false;
            $result['message'] = $absence;
        }
    } else {
        $result['queue'] = $queue;
        if (!array_key_exists($queue, $queues)) {
            $result['schedule'] = null;
            $result['available'] = false;
            $result['message'] = 'Немає даних для черги ' . $queue . ' на ' . $canonical;
        } else {
            $result['schedule'] = $queues[$queue];
            $result['available'] = true;
        }
    }
    return $result;
}

/** True when payload is a verified-ready schedule for today (not a "not announced" marker). */
function isCurrentSchedulePayload($data, $now = null) {
    $now = $now ?? time();
    return is_array($data)
        && empty($data['not_announced'])
        && !empty($data['queues'])
        && dateToKey($data['date'] ?? null) === date('Y-m-d', $now);
}

/** Select only a real schedule or an explicit dated absence notice; failures preserve cache. */
function selectSourcePayload($telegram, $site, $now = null) {
    $now = $now ?? time();
    foreach ([$telegram, $site] as $item) {
        if (isCurrentSchedulePayload($item, $now)) return $item;
    }
    foreach ([$telegram, $site] as $item) {
        if (is_array($item) && ($item['not_announced'] ?? false) === true && ($item['notice_verified'] ?? false) === true && empty($item['queues'])
            && dateToKey($item['date'] ?? null) === date('Y-m-d', $now)) return $item;
    }
    return null;
}
