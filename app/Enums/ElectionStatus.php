<?php

namespace App\Enums;

enum ElectionStatus: string
{
    case Draft = 'draft';
    case Preparing = 'preparing';
    case Active = 'active';
    case FinalReview = 'final_review';
    case Closed = 'closed';
    case Archived = 'archived';
}
