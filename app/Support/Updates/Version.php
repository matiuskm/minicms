<?php

namespace App\Support\Updates;

/** Plain MAJOR.MINOR.PATCH versions. Anything else (pre-releases, junk from a remote API) is never "newer". */
class Version
{
    public const PATTERN = '/^\d{1,4}\.\d{1,4}\.\d{1,4}$/';

    public static function valid(?string $v): bool
    {
        return is_string($v) && preg_match(self::PATTERN, $v) === 1;
    }

    /** Strips a leading "v" from a tag name; null if the rest is not a plain version. */
    public static function fromTag(?string $tag): ?string
    {
        $v = is_string($tag) ? ltrim($tag, 'vV') : null;

        return self::valid($v) ? $v : null;
    }

    public static function newer(?string $candidate, ?string $current): bool
    {
        return self::valid($candidate) && self::valid($current) && version_compare($candidate, $current, '>');
    }
}
