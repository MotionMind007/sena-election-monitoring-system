<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Coordinator = 'coordinator';
    case Witness = 'witness';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Coordinator => 'Koordinator',
            self::Witness => 'Saksi',
        };
    }
}
