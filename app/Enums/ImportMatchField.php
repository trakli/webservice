<?php

namespace App\Enums;

enum ImportMatchField: string
{
    case DESCRIPTION = 'description';
    case COUNTERPARTY = 'counterparty';
    case ANY = 'any';
}
