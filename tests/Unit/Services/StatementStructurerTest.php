<?php

namespace Tests\Unit\Services;

use App\Services\StatementStructurer;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class StatementStructurerTest extends TestCase
{
    private function fakePrism(string $text): void
    {
        Prism::fake([
            new TextResponse(
                steps: collect([]),
                text: $text,
                finishReason: FinishReason::Stop,
                toolCalls: [],
                toolResults: [],
                usage: new Usage(0, 0),
                meta: new Meta('fake', 'fake'),
                messages: collect([]),
            ),
        ]);
    }

    public function test_maps_structured_rows_and_disambiguates_amount(): void
    {
        $this->fakePrism(json_encode([[
            'date' => '2026-06-15',
            'description' => 'Airtime purchase',
            'payment_type' => 'AIRTIME',
            'counterparty_name' => 'MTNC AIRTIME',
            'account' => '+237 68 11 04 87 5',
            'amount' => 1000,
            'direction' => 'expense',
            'fee' => 0,
            'tax' => 0,
            'balance' => 2516,
            'reference' => '17038916245',
            'currency' => 'XAF',
        ]]));

        $rawText = "Date & Time Payment Type Account Name Amount Transaction ID Fees Tax Balance Reference\n"
            . '9 May 2026 12:43 AIRTIME +237 68 11 04 87 5 MTNC AIRTIME -1000 17038916245 0.00 XAF 0.00 XAF 2,516 XAF -';

        $result = (new StatementStructurer())->structure($rawText);

        $this->assertCount(1, $result);
        $this->assertEquals(1000.0, $result[0]->amount);
        $this->assertEquals('expense', $result[0]->type);
        $this->assertEquals('MTNC AIRTIME', $result[0]->party);
        $this->assertEquals('+237 68 11 04 87 5', $result[0]->account);
        $this->assertEquals('XAF', $result[0]->currency);
        $this->assertEquals('2026-06-15', $result[0]->date);
    }

    public function test_returns_empty_when_llm_output_unparseable(): void
    {
        $this->fakePrism('this is not json');

        $result = (new StatementStructurer())->structure('some statement text');

        $this->assertSame([], $result);
    }

    public function test_returns_empty_for_blank_input_without_calling_llm(): void
    {
        $result = (new StatementStructurer())->structure("   \n  ");

        $this->assertSame([], $result);
    }
}
