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
    $queues = $current && $verified && is_array($data['queues'] ?? null) ? $data['queues'] : [];
    $result = [
        'success' => true,
        'date' => $date,
        'updated' => $updated,
        'stale' => $stale,
        'available' => !empty($queues),
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
