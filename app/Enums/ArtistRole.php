<?php

namespace App\Enums;

enum ArtistRole: string
{
    case Admin = 'artist_admin';
    case User = 'artist_user';
}
