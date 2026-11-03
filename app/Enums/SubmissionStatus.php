<?php

namespace App\Enums;

enum SubmissionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Recommended = 'recommended';
    case RevisionRequested = 'revision_requested';
    case Rejected = 'rejected';
    case Approved = 'approved';
    case Conflict = 'conflict';
}
