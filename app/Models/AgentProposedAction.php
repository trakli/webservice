<?php

namespace App\Models;

use Whilesmart\AgentActions\Models\AgentAction;

/**
 * A write the agent wants to perform, awaiting the user's confirmation. Backed
 * by the shared agent_actions ledger (whilesmart/eloquent-agent-actions). The
 * chat it belongs to is the inherited polymorphic `source` (a ChatSession); the
 * assistant message that rendered the card and the proposing tool live in
 * `metadata`. Nothing mutates the user's data until it is confirmed and executed.
 */
class AgentProposedAction extends AgentAction
{
}
