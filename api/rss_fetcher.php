<?php
/**
 * RSS Fetcher
 * 
 * Отримує останні повідомлення через RSS фід
 * Викликається:
 * - З blackout.php якщо кеш порожній або застарілий
 * - Автоматично кожні 5 хвилин при запиті від клієнта
 * 
 * Функції:
 * - fetchFromRSS() - отримання та парсинг повідомлень
 * - parseRSSItem() - парсинг окремого RSS елемента
 * - extractTextFromHTML() - витягування тексту з HTML
 */

// Завантажуємо модулі
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/parser.php';

/**
 * Отримує останні повідомлення з RSS та парсить графіки
 * @param int $limit Кількість повідомлень для обробки (за замовчуванням всі)
 * @return array|false Дані графіку або false
 */
function fetchFromRSS($limit = 100) {
    $rssContent = fetchRSSContent(RSS_URL);
    
    if ($rssContent === false) {
        error_log("RSS fetcher: Не вдалося завантажити RSS фід");
        return false;
    }
    
    // Парсимо XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($rssContent);
    libxml_clear_errors();
    
    if ($xml === false) {
        error_log("RSS fetcher: Помилка парсингу XML");
        return false;
    }
    
    // Перевіряємо чи є channel та items
    if (!isset($xml->channel) || !isset($xml->channel->item)) {
        error_log("RSS fetcher: XML не містить channel або items");
        return false;
    }
    
    $items = $xml->channel->item;
    $count = min(count($items), $limit);
    
    if ($count === 0) {
        error_log("RSS fetcher: RSS не містить жодного item");
        return false;
    }
    
    // Обробляємо items від найновішого до найстарішого
    $newestSchedule = null;
    $newestDate = null;
    $emergencyModeDetected = false;
    $emergencyModeTime = 0;
    
    for ($i = 0; $i < $count; $i++) {
        $item = $items[$i];
        
        // Парсимо item
        $itemData = parseRSSItem($item);
        
        if (!$itemData) {
            continue;
        }
        
        // Перевіряємо чи є emergency mode (ГАВ/СГАВ) в тексті
        $itemEmergencyMode = detectEmergencyMode($itemData['text']);
        $itemTime = strtotime($itemData['pub_date']);
        
        // Якщо знайдено emergency mode і це найновіше повідомлення про ГАВ/СГАВ
        if ($itemEmergencyMode && $itemTime > $emergencyModeTime) {
            $emergencyModeDetected = true;
            $emergencyModeTime = $itemTime;
        }
        
        // Зберігаємо в історію RSS повідомлень
        saveRSSMessage([
            'link' => $itemData['link'],
            'title' => $itemData['title'],
            'text' => $itemData['text'],
            'pub_date' => $itemData['pub_date'],
            'parsed' => $itemData['parsed'],
            'has_schedule' => !empty($itemData['parsed_data'])
        ]);
        
        // Якщо знайдено графік, зберігаємо найновіший
        if ($itemData['parsed_data'] && !empty($itemData['parsed_data']['queues'])) {
            // Порівнюємо дати, якщо вже є збережений графік
            if ($newestSchedule === null) {
                $newestSchedule = $itemData['parsed_data'];
                $newestDate = $itemData['parsed_data']['date'];
            } else {
                // Порівнюємо дати (формат DD.MM.YYYY)
                $currentTimestamp = strtotime(str_replace('.', '-', $itemData['parsed_data']['date']));
                $savedTimestamp = strtotime(str_replace('.', '-', $newestDate));
                
                if ($currentTimestamp > $savedTimestamp) {
                    $newestSchedule = $itemData['parsed_data'];
                    $newestDate = $itemData['parsed_data']['date'];
                }
            }
        }
    }
    
    // Якщо знайдено emergency mode окремо, оновлюємо його в графіку
    if ($newestSchedule && $emergencyModeDetected) {
        $newestSchedule['emergency_mode'] = true;
    }
    
    // Якщо знайдено графік - зберігаємо та повертаємо
    if ($newestSchedule && !empty($newestSchedule['queues'])) {
        $saved = saveSchedules(
            $newestSchedule['queues'],
            $newestSchedule['date'],
            $newestSchedule['emergency_mode'],
            SOURCE_RSS,
            '' // raw_message можна зберегти при потребі
        );
        
        if ($saved) {
            return $newestSchedule;
        }
    }
    
    return false; // Графік не знайдено в жодному повідомленні
}

/**
 * Парсить окремий RSS item
 * @param SimpleXMLElement $item RSS item
 * @return array|false Дані item або false
 */
function parseRSSItem($item) {
    // Витягуємо основні поля
    $title = isset($item->title) ? (string)$item->title : '';
    $link = isset($item->link) ? (string)$item->link : '';
    $pubDate = isset($item->pubDate) ? (string)$item->pubDate : '';
    $description = isset($item->description) ? (string)$item->description : '';
    
    if (empty($description)) {
        return false;
    }
    
    // Витягуємо текст з HTML description
    $text = extractTextFromHTML($description);
    
    if (empty($text)) {
        return false;
    }
    
    // Пробуємо розпарсити графік
    $parsed = parseScheduleMessage($text);
    
    return [
        'link' => $link,
        'title' => $title,
        'text' => $text,
        'pub_date' => $pubDate,
        'parsed' => $parsed !== false,
        'parsed_data' => $parsed
    ];
}

/**
 * Витягує чистий текст з HTML
 * @param string $html HTML код
 * @return string Чистий текст
 */
function extractTextFromHTML($html) {
    // Видаляємо CDATA якщо є
    $html = preg_replace('/<!\[CDATA\[(.*?)\]\]>/s', '$1', $html);
    
    // Замінюємо <br> на переноси рядків
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
    
    // Видаляємо всі HTML теги
    $text = strip_tags($html);
    
    // Декодуємо HTML entities
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Нормалізуємо пробіли та переноси рядків
    $text = preg_replace('/\r\n|\r/', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    
    return trim($text);
}

/**
 * Завантажує вміст RSS фіду
 * @param string $url URL RSS фіду
 * @return string|false Вміст RSS або false
 */
function fetchRSSContent($url) {
    // Спочатку пробуємо через curl
    if (function_exists('curl_init')) {
        $ch = curl_init();
        if ($ch !== false) {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; RSS Reader)');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/rss+xml, application/xml, text/xml, */*'
            ]);
            
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            // curl_close не потрібен в PHP 8.0+
            
            if ($content !== false && $httpCode == 200 && strlen($content) > 0) {
                return $content;
            } else {
                error_log("RSS fetcher: cURL error - HTTP {$httpCode}, {$error}");
            }
        }
    }
    
    // Fallback на file_get_contents
    if (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Mozilla/5.0 (compatible; RSS Reader)',
                    'Accept: application/rss+xml, application/xml, text/xml, */*'
                ],
                'timeout' => 10,
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
            error_log("RSS fetcher: file_get_contents failed");
        }
    }
    
    return false;
}

// Якщо скрипт викликається напряму (не через require_once)
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    // CLI режим - для тестування
    echo "RSS Fetcher - тестовий запуск\n\n";
    
    $result = fetchFromRSS();
    
    if ($result) {
        echo "✅ Графік завантажено успішно\n";
        echo "Дата: {$result['date']}\n";
        echo "Черг: " . count($result['queues']) . "\n";
        echo "ГАВ: " . ($result['emergency_mode'] ? 'Так' : 'Ні') . "\n";
    } else {
        echo "❌ Графік не знайдено або помилка завантаження\n";
    }
}
?>
