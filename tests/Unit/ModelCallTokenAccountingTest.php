<?php

namespace Tests\Unit;

use App\Ai\Billing\ModelCallGate;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\Usage;

class ModelCallTokenAccountingTest extends TestCase
{
    public function test_anthropic_cached_input_is_included(): void
    {
        $usage = new Usage(promptTokens: 100, completionTokens: 25, cacheWriteInputTokens: 200, cacheReadInputTokens: 300);
        $this->assertSame(625, ModelCallGate::totalTokens($usage, 'anthropic'));
    }

    public function test_openai_cached_input_is_included_and_reasoning_is_not_counted_twice(): void
    {
        $usage = new Usage(promptTokens: 100, completionTokens: 25, cacheReadInputTokens: 80, thoughtTokens: 15);
        $this->assertSame(205, ModelCallGate::totalTokens($usage, 'openai'));
    }

    public function test_gemini_thought_output_is_included(): void
    {
        $usage = new Usage(promptTokens: 100, completionTokens: 25, thoughtTokens: 15);
        $this->assertSame(140, ModelCallGate::totalTokens($usage, 'gemini'));
    }
}
