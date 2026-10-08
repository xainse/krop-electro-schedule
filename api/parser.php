<?php
/**
 * Модуль для парсингу повідомлень про графіки відключень
 * 
 * Підтримує формати:
 * - Telegram: "Черга 1.1: 00-02, 04-07, 08-10"
 * - HTML з сайту kiroe.com.ua
 * 
 * Функції:
 * - parseScheduleMessage() - головна функція парсингу
 * - detectEmergencyMode() - виявлення ГАВ
 * - extractDate() - витягування дати
 * - extractQueues() - витягування черг та графіків
 * - normalizeSchedule() - нормалізація формату часу
 * - validateSchedule() - валідація графіку
 */

/**
 * Парсить повідомлення про графік відключень
 * @param string $text Текст повідомлення
 * @return array|false Масив з даними або false при помилці
 * Повертає: ['date' => 'DD.MM.YYYY', 'emergency_mode' => bool, 'queues' => [...]]
 */
function parseScheduleMessage($text, $referenceTime = null) {
    if (empty($text)) {
        return false;
    }
    
    // Витягуємо дату
    $date = extractDate($text, $referenceTime);
    if (!$date) {
        return false; // Без дати не можемо визначити графік
    }
    
    // Виявляємо ГАВ
    $emergencyMode = detectEmergencyMode($text);
    
    // Витягуємо черги та графіки
    $queues = extractQueues($text);
    if (empty($queues)) {
        return false;
    }
    
    return [
        'date' => $date,
        'emergency_mode' => $emergencyMode,
        'queues' => $queues
    ];
}

/** Only an explicit, dated source statement establishes that no schedule was announced. */
function parseNoScheduleNotice($text, $referenceTime = null) {
    $date = extractDate($text, $referenceTime);
    if (!$date || !preg_match('/графік(?:и|ів)?[^.!?\n]{0,80}(?:не\s+(?:оголошен[іо]|застосовуватимуться|застосовуються)|відсутн[іій])/ui', $text)) return false;
    return ['date' => $date, 'queues' => [], 'not_announced' => true, 'notice_verified' => true, 'emergency_mode' => detectEmergencyMode($text)];
}

/**
 * Виявляє чи активний графік аварійних відключень (ГАВ)
 * @param string $text Текст повідомлення
 * @return bool|null true = активний, false = скасований, null = не визначено
 */
function detectEmergencyMode($text) {
    // Спочатку перевіряємо чи ГАВ/СГАВ скасовано
    $cancellationPatterns = [
        '/дію\s+графіка\s+аварійних\s+відключень\s*\(?\s*ГАВ\s*\)?\s+скасовано/ui',
        '/скасовано\s+дію\s+графіка\s+аварійних\s+відключень/ui',
        '/ГАВ\s+скасовано/ui',
        '/скасовано\s+ГАВ/ui',
        '/графік\s+аварійних\s+відключень\s*\(?\s*ГАВ\s*\)?\s+скасовано/ui',
        '/дію\s+спеціального\s+графіка\s+аварійних\s+відключень\s*\(?\s*СГАВ\s*\)?\s+скасовано/ui',
        '/скасовано\s+дію\s+спеціального\s+графіка\s+аварійних\s+відключень/ui',
        '/СГАВ\s+скасовано/ui',
        '/скасовано\s+СГАВ/ui',
        '/спеціальний\s+графік\s+аварійних\s+відключень\s*\(?\s*СГАВ\s*\)?\s+скасовано/ui'
    ];
    
    foreach ($cancellationPatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return false; // ГАВ скасовано
        }
    }
    
    // Шукаємо текст про введення ГАВ або СГАВ (спільна логіка для Telegram і сайту)
    $activationPatterns = [
        '/графік\s+аварійних\s+відключень/ui',
        '/(?<!ГП)ГАВ(?!\p{L})/u',  // ГАВ як окреме слово, не ГПВ
        '/введено\s+в\s+дію\s+графік\s+аварійних/ui',
        '/введено\s+в\s+дію\s+спеціальний\s+графік\s+аварійних/ui',
        '/спеціальний\s+графік\s+аварійних\s+відключень/ui',
        '/введено\s+в\s+дію\s+спеціальний\s+графік\s+аварійних\s+відключень\s+(СГАВ)/ui'
    ];
    foreach ($activationPatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }
    // «графік» + «аварій» поруч (до 50 символів)
    if (preg_match('/графік.{0,50}аварій|аварій.{0,50}графік/ui', $text)) {
        return true;
    }
    return null; // Повідомлення не стосується зміни статусу ГАВ/СГАВ
}

/**
 * Витягує дату з тексту
 * @param string $text Текст повідомлення
 * @param int|null $referenceTime Unix timestamp для обчислення "сьогодні"/"завтра" (null = time())
 * @return string|false Дата в форматі DD.MM.YYYY або false
 */
function extractDate($text, $referenceTime = null) {
    if ($referenceTime === null) {
        $referenceTime = time();
    }

    // 1. Шукаємо дату після контекстних слів ("на DD.MM.YYYY", "графік на DD.MM.YYYY")
    if (preg_match('/(?:на|від|з)\s+(\d{1,2})\.(\d{1,2})\.(\d{4})/ui', $text, $matches)) {
        $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        return checkdate((int)$month, (int)$day, (int)$matches[3]) ? $day . '.' . $month . '.' . $matches[3] : false;
    }

    // 2. Шукаємо "завтра"/"на завтра" — обчислюємо дату
    if (preg_match('/(?:на\s+)?завтра/ui', $text)) {
        return date('d.m.Y', strtotime('+1 day', $referenceTime));
    }

    // 3. Шукаємо "сьогодні"/"на сьогодні" — обчислюємо дату
    if (preg_match('/(?:на\s+)?сьогодні/ui', $text)) {
        return date('d.m.Y', $referenceTime);
    }

    // 4. Fallback: перша дата в форматі DD.MM.YYYY (або D.M.YYYY)
    if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $text, $matches)) {
        $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        return checkdate((int)$month, (int)$day, (int)$matches[3]) ? $day . '.' . $month . '.' . $matches[3] : false;
    }
    
    return false;
}

/**
 * Витягує черги та графіки з тексту
 * @param string $text Текст повідомлення
 * @return array Асоціативний масив ['1.1' => 'schedule', ...]
 */
function extractQueues($text) {
    $text = str_replace(['–', '—', '−'], '-', $text);
    $queues = [];
    preg_match_all('/Черга\s+([1-6]\.[12])\s*:[ \t]*(.*?)(?=Черга\s+\d+\.\d+|$)/uis', $text, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $raw = trim($match[2]);
        if ($raw === '' || preg_match('/^-(?:\s|$)/u', $raw)) {
            $queues[$match[1]] = '';
            continue;
        }
        // Extract the time prefix before a following advert, not arbitrary text containing digits.
        if (!preg_match('/^[0-9\s,:;-]+/u', $raw, $prefix)) continue;
        $schedule = normalizeSchedule(rtrim(trim($prefix[0]), ',;'));
        if (validateSchedule($schedule)) $queues[$match[1]] = $schedule;
    }
    return $queues;
}

/**
 * Нормалізує графік відключень
 * Конвертує формат "HH-HH" → "HH:00-HH:00"
 * @param string $schedule Сирий графік
 * @return string Нормалізований графік
 */
function normalizeSchedule($schedule) {
    $schedule = str_replace(['–', '—', '−', ';'], ['-', '-', '-', ','], $schedule);
    // Видаляємо зайві пробіли та переноси рядків
    $schedule = preg_replace('/[\s\n\r]+/', ' ', $schedule);
    $schedule = trim($schedule);
    
    // Нормалізуємо формат часу: "HH-HH" → "HH:00-HH:00"
    // Але залишаємо "HH:MM-HH:MM" без змін
    $schedule = preg_replace_callback(
        '/(\d{1,2})(?::(\d{2}))?\s*-\s*(\d{1,2})(?::(\d{2}))?/',
        function($matches) {
            $startHour = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $startMin = isset($matches[2]) && $matches[2] !== '' ? $matches[2] : '00';
            $endHour = str_pad($matches[3], 2, '0', STR_PAD_LEFT);
            $endMin = isset($matches[4]) && $matches[4] !== '' ? $matches[4] : '00';
            
            return sprintf('%s:%s-%s:%s', $startHour, $startMin, $endHour, $endMin);
        },
        $schedule
    );
    
    // Нормалізуємо коми та пробіли
    $schedule = preg_replace('/\s*,\s*/', ', ', $schedule);
    
    return $schedule;
}

/**
 * Валідує графік відключень
 * @param string $schedule Графік для перевірки
 * @return bool
 */
function validateSchedule($schedule) {
    if (!is_string($schedule) || trim($schedule) === '') return false;
    foreach (explode(',', $schedule) as $range) {
        if (!preg_match('/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/D', trim($range), $m)) return false;
        [$sh, $sm, $eh, $em] = array_map('intval', array_slice($m, 1));
        if ($sh > 23 || $sm > 59 || $eh > 24 || $em > 59 || ($eh === 24 && $em !== 0)) return false;
        if ($sh * 60 + $sm === $eh * 60 + $em) return false;
    }
    return true;
}

/** Preserve line breaks separating queues and source notices. */
function scheduleNodeText($node) {
    $text = '';
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) $text .= $child->nodeValue;
        else {
            $text .= scheduleNodeText($child);
            if (in_array(strtolower($child->nodeName), ['br', 'p', 'div', 'li'], true)) $text .= "\n";
        }
    }
    return $text;
}

/**
 * Парсить HTML з сайту kiroe.com.ua (для fallback)
 * @param string $html HTML код сторінки
 * @param string|null $extractedText Якщо передано по посиланню — сюди записується витягнутий текст (для логування)
 * @return array|false Масив з даними або false
 */
function parseHTMLSchedule($html, &$extractedText = null) {
    if (empty($html)) {
        return false;
    }
    
    // Створюємо DOMDocument для парсингу HTML
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    
    // Знаходимо елемент з ID info_popup
    $xpath = new DOMXPath($dom);
    $infoPopup = $xpath->query("//*[@id='info_popup']")->item(0);
    
    if (!$infoPopup) {
        return false;
    }
    
    // Знаходимо елемент з класом fancybox_body_desc
    $bodyDesc = $xpath->query(".//*[contains(@class, 'fancybox_body_desc')]", $infoPopup)->item(0);
    
    if (!$bodyDesc) {
        return false;
    }
    
    // Отримуємо текстовий вміст
    $text = scheduleNodeText($bodyDesc);
    $extractedText = $text;
    
    // Використовуємо основну функцію парсингу
    return parseScheduleMessage($text);
}
?>
