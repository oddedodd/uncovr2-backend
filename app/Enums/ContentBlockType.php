<?php

namespace App\Enums;

enum ContentBlockType: string
{
    case Heading = 'heading';
    case Text = 'text';
    case Image = 'image';
    case Gallery = 'gallery';
    case Video = 'video';
    case Quote = 'quote';
    case Lyrics = 'lyrics';
}
