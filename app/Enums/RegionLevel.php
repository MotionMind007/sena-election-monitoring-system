<?php

namespace App\Enums;

enum RegionLevel: string
{
    case Province = 'province';
    case Regency = 'regency';
    case City = 'city';
    case District = 'district';
    case Village = 'village';
}
