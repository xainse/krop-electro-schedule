<?php
/**
 * Тест логіки визначення статусу СГАВ
 */

require_once __DIR__ . '/parser.php';

echo "=== ТЕСТ ЛОГІКИ ВИЗНАЧЕННЯ СТАТУСУ СГАВ ===\n\n";

// Тестові випадки
$testCases = [
    [
        'name' => 'Скасування СГАВ (повний текст)',
        'text' => 'За розпорядженням НЕК "Укренерго" дію спеціального графіка аварійних відключень (СГАВ) скасовано.',
        'expected' => false
    ],
    [
        'name' => 'Скасування СГАВ (короткий текст)',
        'text' => 'СГАВ скасовано з 10:00',
        'expected' => false
    ],
    [
        'name' => 'Введення СГАВ (повний текст)',
        'text' => 'За розпорядженням НЕК "Укренерго" по режиму роботи ОЕС України введено в дію спеціальний графік аварійних відключень (СГАВ).',
        'expected' => true
    ],
    [
        'name' => 'Графік з СГАВ',
        'text' => 'Графік на 23.01.2026 з СГАВ. Черга 1.1: 08:00-10:00',
        'expected' => true
    ],
    [
        'name' => 'Звичайне повідомлення без зміни статусу',
        'text' => 'Графік на 23.01.2026. Черга 1.1: 08:00-10:00',
        'expected' => null
    ],
    [
        'name' => 'Скасування ГАВ (стара назва)',
        'text' => 'ГАВ скасовано за розпорядженням',
        'expected' => false
    ],
    [
        'name' => 'Введення ГАВ (стара назва)',
        'text' => 'Введено в дію графік аварійних відключень',
        'expected' => true
    ]
];

$passed = 0;
$failed = 0;

foreach ($testCases as $i => $test) {
    $result = detectEmergencyMode($test['text']);
    $status = $result === $test['expected'] ? '✅ PASS' : '❌ FAIL';
    
    if ($result === $test['expected']) {
        $passed++;
    } else {
        $failed++;
    }
    
    echo "Тест " . ($i + 1) . ": {$test['name']}\n";
    echo "  Текст: " . substr($test['text'], 0, 60) . (strlen($test['text']) > 60 ? '...' : '') . "\n";
    echo "  Очікуваний результат: " . var_export($test['expected'], true) . "\n";
    echo "  Фактичний результат: " . var_export($result, true) . "\n";
    echo "  {$status}\n\n";
}

echo "=== РЕЗУЛЬТАТИ ===\n";
echo "Пройдено: {$passed}\n";
echo "Провалено: {$failed}\n";
echo "Всього: " . count($testCases) . "\n";

if ($failed === 0) {
    echo "\n🎉 ВСІ ТЕСТИ ПРОЙДЕНО УСПІШНО!\n";
} else {
    echo "\n⚠️  Є помилки, потрібно виправити логіку\n";
}
?>
