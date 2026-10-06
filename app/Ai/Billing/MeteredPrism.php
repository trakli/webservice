<?php

namespace App\Ai\Billing;

class MeteredPrism extends \Prism\Prism\Prism
{
    public function text(): \Prism\Prism\Text\PendingRequest
    {
        return new MeteredPendingRequest(app(ModelCallGate::class)->owner());
    }
}
