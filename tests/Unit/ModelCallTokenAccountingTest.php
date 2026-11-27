<?php

namespace Tests\Unit;

use App\Ai\Billing\ModelCallGate;
use PHPUnit\Framework\TestCase;

class ModelCallTokenAccountingTest extends TestCase
{
    public function test_anthropic_cached_input_is_included(): void
    {
        $usage = ['prompt_tokens' => 100, 'completion_tokens' => 25, 'cache_write_input_tokens' => 200, 'cache_read_input_tokens' => 300];
        $this->assertSame(625, ModelCallGate::totalTokens($usage, 'anthropic'));
    }

    public function test_openai_cached_input_is_included_and_reasoning_is_not_counted_twice(): void
    {
        $usage = ['prompt_tokens' => 100, 'completion_tokens' => 25, 'cache_read_input_tokens' => 80, 'thought_tokens' => 15];
        $this->assertSame(205, ModelCallGate::totalTokens($usage, 'openai'));
    }

    public function test_gemini_thought_output_is_included(): void
    {
        $usage = ['prompt_tokens' => 100, 'completion_tokens' => 25, 'thought_tokens' => 15];
        $this->assertSame(140, ModelCallGate::totalTokens($usage, 'gemini'));
    }
}
