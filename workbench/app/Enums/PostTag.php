<?php

// Regression fixture for issue #60: see namespace Wrong\Place; this comment
// precedes the real declaration and must not be picked up.

namespace App\Enums;

/**
 * Post tags, mirrored to TypeScript as a
 * backed enum as well as listed in the tag picker.
 */
enum PostTag: string
{
    case Tech = 'tech';
    case Science = 'science';
    case Art = 'art';
}
