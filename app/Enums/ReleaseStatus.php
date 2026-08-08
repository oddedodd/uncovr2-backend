<?php

namespace App\Enums;

enum ReleaseStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Archived = 'archived';
}
