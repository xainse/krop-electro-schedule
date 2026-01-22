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
function parseScheduleMessage($text) {
    if (empty($text)) {
        return false;
    }
    
    // Витягуємо дату
    $date = extractDate($text);
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
    
    // Шукаємо текст про введення ГАВ або СГАВ
    $activationPatterns = [
        '/введено\s+в\s+дію\s+графік\s+аварійних/ui',
        '/введено\s+в\s+дію\s+спеціальний\s+графік\s+аварійних/ui',
        '/спеціальний\s+графік\s+аварійних\s+відключень/ui',
        '/СГАВ/u'
    ];
    
    foreach ($activationPatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }
    
    return null; // Повідомлення не стосується зміни статусу ГАВ/СГАВ
}

/**
 * Витягує дату з тексту
 * @param string $text Текст повідомлення
 * @return string|false Дата в форматі DD.MM.YYYY або false
 */
function extractDate($text) {
    // Шукаємо дату в форматі DD.MM.YYYY
    if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $text, $matches)) {
        return $matches[0]; // Повертаємо повну дату
    }
    
    return false;
}

/**
 * Витягує черги та графіки з тексту
 * @param string $text Текст повідомлення
 * @return array Асоціативний масив ['1.1' => 'schedule', ...]
 */
function extractQueues($text) {
    $queues = [];
    
    // Шукаємо всі рядки формату "Черга X.X: діапазони"
    $pattern = '/Черга\s+(\d+\.\d+)\s*:\s*(.+?)(?=\n\s*Черга\s+\d+\.\d+|\n\n|$)/uis';
    
    if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $queueNum = $match[1];
            $scheduleRaw = $match[2];
            
            // Нормалізуємо графік
            $schedule = normalizeSchedule($scheduleRaw);
            
            // Валідуємо графік
            if (validateSchedule($schedule)) {
                $queues[$queueNum] = $schedule;
            }
        }
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
    if (empty($schedule)) {
        return false;
    }
    
    // Перевіряємо чи містить хоча б один валідний діапазон часу
    // Формат: HH:MM-HH:MM
    $pattern = '/\d{2}:\d{2}-\d{2}:\d{2}/';
    
    return preg_match($pattern, $schedule) === 1;
}

/**
 * Парсить HTML з сайту kiroe.com.ua (для fallback)
 * @param string $html HTML код сторінки
 * @return array|false Масив з даними або false
 */
function parseHTMLSchedule($html) {
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
    $text = $bodyDesc->textContent;
    
    // Використовуємо основну функцію парсингу
    return parseScheduleMessage($text);
}
?>
