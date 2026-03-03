<?php

use PHPUnit\Framework\TestCase;

class EmergencyModeTest extends TestCase
{
    // ==========================================
    // detectEmergencyMode() from parser.php
    // ==========================================

    public function testDetectActivationIntroducedGAV(): void
    {
        $text = 'Увага! Введено в дію графік аварійних відключень на 23.01.2026';
        $this->assertTrue(detectEmergencyMode($text));
    }

    public function testDetectActivationSGAV(): void
    {
        $text = 'Введено в дію спеціальний графік аварійних відключень';
        $this->assertTrue(detectEmergencyMode($text));
    }

    public function testDetectActivationSGAVKeyword(): void
    {
        $text = 'Увага! СГАВ активовано на сьогодні';
        $this->assertTrue(detectEmergencyMode($text));
    }

    public function testDetectCancellationGAV(): void
    {
        $text = 'Дію графіка аварійних відключень (ГАВ) скасовано';
        $this->assertFalse(detectEmergencyMode($text));
    }

    public function testDetectCancellationGAVReversed(): void
    {
        $text = 'Скасовано дію графіка аварійних відключень';
        $this->assertFalse(detectEmergencyMode($text));
    }

    public function testDetectCancellationGAVKeyword(): void
    {
        $text = 'ГАВ скасовано з 14:00';
        $this->assertFalse(detectEmergencyMode($text));
    }

    public function testDetectCancellationSGAV(): void
    {
        $text = 'Дію спеціального графіка аварійних відключень (СГАВ) скасовано';
        $this->assertFalse(detectEmergencyMode($text));
    }

    public function testDetectCancellationSGAVKeyword(): void
    {
        $text = 'СГАВ скасовано';
        $this->assertFalse(detectEmergencyMode($text));
    }

    public function testDetectNeutralTextReturnsNull(): void
    {
        $text = 'Графіки планових відключень на 23.01.2026';
        $this->assertNull(detectEmergencyMode($text));
    }

    public function testDetectEmptyTextReturnsNull(): void
    {
        $this->assertNull(detectEmergencyMode(''));
    }

    public function testCancellationTakesPriorityOverActivation(): void
    {
        // When cancellation patterns are checked first, cancellation wins
        $text = 'Дію графіка аварійних відключень (ГАВ) скасовано. ' .
                'Раніше було введено в дію графік аварійних відключень.';
        $this->assertFalse(detectEmergencyMode($text));
    }

    // ==========================================
    // checkEmergencyModeInHTML() from site_fetcher.php
    // ==========================================

    public function testCheckEmergencyModeDetectsGAV(): void
    {
        $html = '<div>Увага! Введено в дію графік аварійних відключень</div>';
        $this->assertTrue(checkEmergencyModeInHTML($html));
    }

    public function testCheckEmergencyModeDetectsGAVKeyword(): void
    {
        $html = '<div>Увага! Діє ГАВ на сьогодні</div>';
        $this->assertTrue(checkEmergencyModeInHTML($html));
    }

    public function testCheckEmergencyModeDetectsSGAV(): void
    {
        $html = '<div>Спеціальний графік аварійних відключень активний</div>';
        $this->assertTrue(checkEmergencyModeInHTML($html));
    }

    public function testCheckEmergencyModeDetectsSGAVKeyword(): void
    {
        $html = '<div>Увага! СГАВ введено</div>';
        $this->assertTrue(checkEmergencyModeInHTML($html));
    }

    public function testCheckEmergencyModeDetectsEmergencyPattern(): void
    {
        $html = '<p>Діє графік аварійних відключень для Кропивницького</p>';
        $this->assertTrue(checkEmergencyModeInHTML($html));
    }

    public function testCheckEmergencyModeCancellationReturnsFalse(): void
    {
        $html = '<div>дію графіка аварійних відключень (ГАВ) скасовано</div>';
        $this->assertFalse(checkEmergencyModeInHTML($html));
    }

    public function testCheckEmergencyModeCancellationSGAVReturnsFalse(): void
    {
        $html = '<div>СГАВ скасовано з 15:00</div>';
        $this->assertFalse(checkEmergencyModeInHTML($html));
    }

    public function testCheckEmergencyModeNormalHTMLReturnsFalse(): void
    {
        $html = '<div>Графіки планових відключень на 23.01.2026</div>';
        $this->assertFalse(checkEmergencyModeInHTML($html));
    }

    public function testCheckEmergencyModeEmptyHTMLReturnsFalse(): void
    {
        $this->assertFalse(checkEmergencyModeInHTML(''));
    }

    public function testCheckEmergencyModeProximityPattern(): void
    {
        $html = '<div>Діє новий графік щодо аварійного стану</div>';
        $this->assertTrue(checkEmergencyModeInHTML($html));
    }

    public function testCheckEmergencyModeCancellationBeatsActivation(): void
    {
        $html = '<div>ГАВ скасовано. Раніше діяв графік аварійних відключень.</div>';
        $this->assertFalse(checkEmergencyModeInHTML($html));
    }

    public function testCheckEmergencyModeLowercaseMatch(): void
    {
        $html = '<div>графік аварійних відключень активний</div>';
        $this->assertTrue(checkEmergencyModeInHTML($html));
    }
}
