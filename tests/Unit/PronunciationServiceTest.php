<?php

namespace Tests\Unit;

use App\Services\PronunciationService;
use PHPUnit\Framework\TestCase;

class PronunciationServiceTest extends TestCase
{
    public function test_only_whole_term_case_insensitive_matches_are_sent(): void
    {
        $service = new PronunciationService;
        $dict = ['Kiki' => '/ˈkiːki/', 'Momo' => '/ˈmoʊmoʊ/', 'Zorb' => '/zɔːrb/'];

        $this->assertSame(['Kiki' => '/ˈkiːki/'], $service->forText($dict, 'KIKI met the kikitoons crew.'));
        $this->assertSame(['Kiki' => '/ˈkiːki/', 'Momo' => '/ˈmoʊmoʊ/'], $service->forText($dict, 'kiki and Momo!') + []);
        $this->assertSame([], $service->forText($dict, 'Nobody here.'));
    }

    public function test_occurrence_budget_of_100_is_respected(): void
    {
        $service = new PronunciationService;
        $text = str_repeat('Kiki ', 80).str_repeat('Momo ', 30);

        $out = $service->forText(['Kiki' => '/a/', 'Momo' => '/b/'], $text);

        $this->assertSame(['Kiki' => '/a/'], $out, 'Adding Momo would exceed 100 occurrences.');
    }

    public function test_support_matrix_follows_heygen_docs(): void
    {
        $service = new PronunciationService;

        $this->assertTrue($service->isSupported('instant', 'heygen-voice-1'));
        $this->assertTrue($service->isSupported('instant', 'heygen-voice-1-turbo'));
        $this->assertFalse($service->isSupported('instant', 'heygen-voice-1-turbo-free'));
        $this->assertFalse($service->isSupported('professional', 'heygen-voice-1'));
    }
}
