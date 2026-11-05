<?php

namespace App\Enums;

enum ImportMatchMode: string
{
    case CONTAINS = 'contains';
    case EXACT = 'exact';
    case STARTS_WITH = 'starts_with';
}
