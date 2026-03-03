<?php
/**
 * Telegram Web Fetcher
 * 
 * Отримує останні повідомлення через веб-версію Telegram каналу
 * Викликається:
 * - З blackout.php як primary джерело даних
 * - Автоматично кожні 5 хвилин при запиті від клієнта
 * 
 * Функції:
 * - fetchFromTelegram() - отримання та парсинг повідомлень
 * - fetchTelegramHTML() - завантаження HTML сторінки
 * - parseTelegramHTML() - парсинг DOM структури
 * - extractMessageData() - витягування даних з одного повідомлення
 */

// Завантажуємо модулі
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/parser.php';

/**
 * Отримує останні повідомлення з Telegram та парсить графіки
 * @param int $limit Кількість повідомлень для обробки при першому запуску
 * @return array|false Дані графіку або false
 */
function fetchFromTelegram($limit = 10) {
    // Отримуємо останній оброблений ID
    $lastKnownId = getLastTelegramId();
    
    // Завантажуємо HTML
    $html = fetchTelegramHTML(TELEGRAM_CHANNEL_URL);
    
    if ($html === false) {
        error_log("Telegram fetcher: Не вдалося завантажити HTML");
        return false;
    }
    
    // Парсимо HTML та отримуємо повідомлення (лише нові, якщо lastKnownId задано)
    $messages = parseTelegramHTML($html, $lastKnownId);
    
    // Якщо нових немає — один раз перепарсити останні повідомлення (актуалізувати schedules.json після деплою)
    if (($messages === false || empty($messages)) && $lastKnownId !== null) {
        $messages = parseTelegramHTML($html, null);
        if ($messages !== false && !empty($messages)) {
            $messages = array_slice($messages, 0, max($limit, 20));
        }
    }
    
    if ($messages === false || empty($messages)) {
        error_log("Telegram fetcher: Не вдалося розпарсити HTML або немає нових повідомлень");
        return false;
    }
    
    // Обмежуємо кількість: при першому запуску — limit, після retry (багато повідомлень) — до 20
    $maxMessages = (count($messages) > $limit && $lastKnownId !== null) ? 20 : $limit;
    if (count($messages) > $maxMessages) {
        $messages = array_slice($messages, 0, $maxMessages);
    }
    
    $count = count($messages);
    
    if ($count === 0) {
        error_log("Telegram fetcher: Немає нових повідомлень");
        return false;
    }
    
    // Збираємо графіки по кожній унікальній даті та визначаємо ГАВ/СГАВ
    $schedulesByDate = []; // date => parsed (найновіше повідомлення для кожної дати)
    $emergencyModeDetected = false;
    $emergencyModeTime = 0;
    $maxMessageId = $lastKnownId ?? 0;
    
    foreach ($messages as $messageData) {
        if ($messageData['message_num'] > $maxMessageId) {
            $maxMessageId = $messageData['message_num'];
        }
        
        $itemEmergencyMode = detectEmergencyMode($messageData['text']);
        $itemTime = strtotime($messageData['datetime']);
        
        if ($itemEmergencyMode !== null && $itemTime > $emergencyModeTime) {
            $emergencyModeDetected = $itemEmergencyMode;
            $emergencyModeTime = $itemTime;
        }
        
        $parsed = parseScheduleMessage($messageData['text']);
        
        if (function_exists('logSourceContent')) {
            logSourceContent('telegram', $messageData['text'], [
                'message_id' => $messageData['message_num'],
                'datetime' => $messageData['datetime'],
                'link' => $messageData['link'] ?? '',
            ]);
        }
        
        saveTelegramMessage([
            'id' => $messageData['id'],
            'message_num' => $messageData['message_num'],
            'text' => $messageData['text'],
            'datetime' => $messageData['datetime'],
            'link' => $messageData['link'],
            'parsed' => $parsed !== false,
            'has_schedule' => $parsed !== false && !empty($parsed['queues'])
        ]);
        
        if ($parsed && !empty($parsed['queues'])) {
            $dateKey = $parsed['date'];
            if (!isset($schedulesByDate[$dateKey])) {
                $schedulesByDate[$dateKey] = $parsed;
            } else {
                $existingKey = dateToKey($schedulesByDate[$dateKey]['date']);
                $newKey = dateToKey($parsed['date']);
                if ($newKey !== false && $existingKey !== false) {
                    $schedulesByDate[$dateKey] = $parsed;
                }
            }
        }
    }
    
    if ($maxMessageId > ($lastKnownId ?? 0)) {
        saveLastTelegramId($maxMessageId);
    }
    
    // Зберігаємо графіки для кожної знайденої дати
    $lastSaved = null;
    foreach ($schedulesByDate as $parsed) {
        $em = $parsed['emergency_mode'];
        if ($emergencyModeTime > 0) {
            $em = $emergencyModeDetected;
        }
        $saved = saveSchedules(
            $parsed['queues'],
            $parsed['date'],
            $em,
            SOURCE_TELEGRAM,
            ''
        );
        if ($saved) {
            $lastSaved = [
                'date' => $parsed['date'],
                'emergency_mode' => $em,
                'queues' => $parsed['queues']
            ];
        }
    }
    
    // Повертаємо графік для актуальної дати (найближча дата >= сьогодні), а не останній з циклу.
    // Інакше при наявності 02.03 і 03.03 API міг віддавати 02.03 і показувати застарілі відключення.
    $todayKey = date('Y-m-d');
    $sortedKeys = [];
    foreach (array_keys($schedulesByDate) as $dateDMY) {
        $k = dateToKey($dateDMY);
        if ($k !== false) {
            $sortedKeys[] = $k;
        }
    }
    sort($sortedKeys);
    foreach ($sortedKeys as $key) {
        if ($key >= $todayKey) {
            $dateDMY = keyToDate($key);
            if ($dateDMY !== false && isset($schedulesByDate[$dateDMY])) {
                $parsed = $schedulesByDate[$dateDMY];
                $em = $parsed['emergency_mode'];
                if ($emergencyModeTime > 0) {
                    $em = $emergencyModeDetected;
                }
                return [
                    'date' => $parsed['date'],
                    'emergency_mode' => $em,
                    'queues' => $parsed['queues']
                ];
            }
        }
    }
    // Немає дати >= сьогодні — повертаємо останнє збережене (найпізніший графік з кешу)
    if ($lastSaved) {
        return $lastSaved;
    }
    
    // Якщо графіку немає, але є зміна статусу ГАВ/СГАВ — оновлюємо поточний
    if (empty($schedulesByDate) && $emergencyModeTime > 0) {
        $currentData = getSchedules();
        if ($currentData && !empty($currentData['queues'])) {
            $saved = saveSchedules(
                $currentData['queues'],
                $currentData['date'],
                $emergencyModeDetected,
                SOURCE_TELEGRAM,
                ''
            );
            if ($saved) {
                return [
                    'date' => $currentData['date'],
                    'emergency_mode' => $emergencyModeDetected,
                    'queues' => $currentData['queues']
                ];
            }
        }
    }
    
    return false;
}

/**
 * Завантажує HTML сторінку Telegram каналу
 * @param string $url URL каналу
 * @return string|false HTML або false
 */
function fetchTelegramHTML($url) {
    // Спочатку пробуємо через curl
    if (function_exists('curl_init')) {
        $ch = curl_init();
        if ($ch !== false) {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: uk-UA,uk;q=0.9,en;q=0.8',
                'Accept-Encoding: gzip, deflate, br',
                'Cache-Control: no-cache',
                'Pragma: no-cache'
            ]);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            // curl_close не потрібен в PHP 8.0+
            
            if ($content !== false && $httpCode == 200 && strlen($content) > 0) {
                return $content;
            } else {
                error_log("Telegram fetcher: cURL error - HTTP {$httpCode}, {$error}");
            }
        }
    }
    
    // Fallback на file_get_contents
    if (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language: uk-UA,uk;q=0.9,en;q=0.8'
                ],
                'timeout' => 15,
                'follow_location' => 1,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $content = @file_get_contents($url, false, $context);
        if ($content !== false && strlen($content) > 0) {
            return $content;
        } else {
            error_log("Telegram fetcher: file_get_contents failed");
        }
    }
    
    return false;
}

/**
 * Парсить HTML Telegram каналу та витягує повідомлення
 * @param string $html HTML код
 * @param int|null $lastKnownId Останній оброблений ID повідомлення
 * @return array|false Масив повідомлень або false
 */
function parseTelegramHTML($html, $lastKnownId = null) {
    if (empty($html)) {
        return false;
    }
    
    // Створюємо DOMDocument для парсингу HTML
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    
    // Завантажуємо HTML з UTF-8 кодуванням
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    
    // Створюємо XPath для пошуку
    $xpath = new DOMXPath($dom);
    
    // Знаходимо всі повідомлення
    $messageNodes = $xpath->query("//div[contains(@class, 'tgme_widget_message')]");
    
    if (!$messageNodes || $messageNodes->length === 0) {
        error_log("Telegram fetcher: Не знайдено жодного повідомлення в HTML");
        return false;
    }
    
    $messages = [];
    
    foreach ($messageNodes as $messageNode) {
        $messageData = extractMessageData($messageNode, $xpath);
        
        if ($messageData === false) {
            continue;
        }
        
        // Якщо є lastKnownId, фільтруємо тільки нові повідомлення
        if ($lastKnownId !== null && $messageData['message_num'] <= $lastKnownId) {
            continue;
        }
        
        $messages[] = $messageData;
    }
    
    // Сортуємо повідомлення від найновішого до найстарішого
    usort($messages, function($a, $b) {
        return $b['message_num'] - $a['message_num'];
    });
    
    return $messages;
}

/**
 * Витягує дані з одного повідомлення
 * @param DOMElement $messageNode DOM елемент повідомлення
 * @param DOMXPath $xpath XPath об'єкт
 * @return array|false Дані повідомлення або false
 */
function extractMessageData($messageNode, $xpath) {
    // Витягуємо ID повідомлення з атрибуту data-post
    $postId = $messageNode->getAttribute('data-post');
    
    if (empty($postId)) {
        return false;
    }
    
    // Витягуємо номер повідомлення (після слешу)
    $parts = explode('/', $postId);
    if (count($parts) !== 2 || !is_numeric($parts[1])) {
        return false;
    }
    $messageNum = (int)$parts[1];
    
    // Витягуємо текст повідомлення
    $textNodes = $xpath->query(".//div[contains(@class, 'tgme_widget_message_text')]", $messageNode);
    $text = '';
    
    if ($textNodes->length > 0) {
        $textNode = $textNodes->item(0);
        $text = trim($textNode->textContent);
    }
    
    // Якщо текст порожній, пропускаємо
    if (empty($text)) {
        return false;
    }
    
    // Витягуємо дату
    $dateNodes = $xpath->query(".//time[@datetime]", $messageNode);
    $datetime = '';
    
    if ($dateNodes->length > 0) {
        $dateNode = $dateNodes->item(0);
        if ($dateNode instanceof DOMElement) {
            $datetime = $dateNode->getAttribute('datetime');
        }
    }
    
    // Витягуємо лінк
    $linkNodes = $xpath->query(".//a[contains(@class, 'tgme_widget_message_date')]", $messageNode);
    $link = '';
    
    if ($linkNodes->length > 0) {
        $linkNode = $linkNodes->item(0);
        if ($linkNode instanceof DOMElement) {
            $link = $linkNode->getAttribute('href');
        }
    }
    
    return [
        'id' => $postId,
        'message_num' => $messageNum,
        'text' => $text,
        'datetime' => $datetime,
        'link' => $link
    ];
}

// Якщо скрипт викликається напряму (не через require_once)
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    // CLI режим - для тестування
    echo "Telegram Fetcher - тестовий запуск\n\n";
    
    $result = fetchFromTelegram();
    
    if ($result) {
        echo "✅ Графік завантажено успішно\n";
        echo "Дата: {$result['date']}\n";
        echo "Черг: " . count($result['queues']) . "\n";
        echo "ГАВ: " . ($result['emergency_mode'] ? 'Так' : 'Ні') . "\n";
        
        // Показуємо кілька черг для прикладу
        echo "\nПриклади черг:\n";
        $count = 0;
        foreach ($result['queues'] as $queue => $schedule) {
            echo "  Черга {$queue}: {$schedule}\n";
            $count++;
            if ($count >= 3) break;
        }
    } else {
        echo "❌ Графік не знайдено або помилка завантаження\n";
    }
}
?>
