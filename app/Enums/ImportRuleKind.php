<?php

namespace App\Enums;

enum ImportRuleKind: string
{
    case PARTY_ALIAS = 'party_alias';
    case CATEGORY_HINT = 'category_hint';
    case WALLET_HINT = 'wallet_hint';
    case SKIP = 'skip';
    case INSTRUCTION = 'instruction';
}
