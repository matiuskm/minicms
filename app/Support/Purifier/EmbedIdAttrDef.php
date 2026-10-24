<?php

namespace App\Support\Purifier;

use HTMLPurifier_AttrDef;

/** data-id of a video embed: short token, no URL characters. */
class EmbedIdAttrDef extends HTMLPurifier_AttrDef
{
    public function validate($string, $config, $context)
    {
        $string = trim($string);

        return preg_match('/^[A-Za-z0-9_-]{6,32}$/', $string) ? $string : false;
    }
}
