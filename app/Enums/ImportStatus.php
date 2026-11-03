<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Previewed = 'previewed';
    case Committed = 'committed';
    case Failed = 'failed';
}
