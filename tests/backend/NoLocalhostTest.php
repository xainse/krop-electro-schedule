<?php

use PHPUnit\Framework\TestCase;

/**
 * Перевірка відсутності localhost у файлах api/*.php.
 * Гарантує, що в продакшені немає звернень до localhost.
 */
class NoLocalhostTest extends TestCase
{
    private static function getApiDir(): string
    {
        return realpath(__DIR__ . '/../../api');
    }

    /**
     * @return list<string>
     */
    private static function listPhpFiles(string $dir): array
    {
        $files = glob($dir . '/*.php');
        return $files ?: [];
    }

    public function testApiPhpFilesDoNotContainLocalhost(): void
    {
        $apiDir = self::getApiDir();
        $this->assertNotEmpty($apiDir, 'api directory should exist');

        $phpFiles = self::listPhpFiles($apiDir);
        $this->assertNotEmpty($phpFiles, 'at least one PHP file in api/');

        $violations = [];
        foreach ($phpFiles as $filePath) {
            $content = file_get_contents($filePath);
            if ($content === false) {
                continue;
            }
            $lines = explode("\n", $content);
            foreach ($lines as $num => $line) {
                if (stripos($line, 'localhost') !== false) {
                    $relative = str_replace($apiDir . DIRECTORY_SEPARATOR, '', $filePath);
                    $violations[] = $relative . ':' . ($num + 1) . ' ' . trim($line);
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Found 'localhost' in api/*.php (not allowed):\n" . implode("\n", $violations)
        );
    }
}
