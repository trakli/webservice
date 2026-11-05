<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Whilesmart\Agents\Contracts\HasAgentResource;
use Whilesmart\Agents\Resources\AgentResource;
use Whilesmart\Agents\Resources\ResourceField;
use Whilesmart\Agents\Resources\ResourceRelationship;
use Whilesmart\Agents\Resources\ThroughScope;
use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'DebtSettlement', properties: [
    new OA\Property(property: 'debt_transaction_id', type: 'integer'),
    new OA\Property(property: 'payment_transaction_id', type: 'integer'),
    new OA\Property(property: 'amount_applied', type: 'number'),
    new OA\Property(property: 'rate', type: 'number'),
    new OA\Property(property: 'rate_source', type: 'string', enum: ['paid', 'market']),
])]
class DebtSettlement extends Model implements HasAgentResource
{
    public function debtTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'debt_transaction_id');
    }

    public function agentPayment(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'payment_transaction_id');
    }

    public static function agentResource(): AgentResource
    {
        return new AgentResource(
            name: 'debt_settlements',
            model: self::class,
            table: 'debt_settlements',
            description: 'Amounts paid back against opening party debts, in the debt currency.',
            ownerKey: null,
            scopeThrough: new ThroughScope('debtTransaction', 'transactions', 'debt_transaction_id', 'transactions.id'),
            readable: [
                ResourceField::key(),
                ResourceField::reference('debt_transaction_id', 'transactions.id', 'Opening debt'),
                ResourceField::reference('payment_transaction_id', 'transactions.id', 'Paying-back transaction'),
                ResourceField::decimal('amount_applied', 'Amount paid back in the debt currency'),
                ResourceField::decimal('rate', 'Payment currency units per debt currency unit'),
                ResourceField::enum('rate_source', ['market', 'paid'], 'Whether the rate comes from market data or actual payment'),
                ResourceField::datetime('created_at', 'When the settlement was recorded'),
            ],
            relationships: [
                ResourceRelationship::belongsTo('settlement_debt', 'transactions', 'debt_transaction_id'),
                ResourceRelationship::belongsTo('settlement_payment', 'transactions', 'payment_transaction_id'),
            ],
        );
    }
}
