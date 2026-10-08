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
        'message' => $notAnnounced ? 'Графіки відключення не оголошені' : null,
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
