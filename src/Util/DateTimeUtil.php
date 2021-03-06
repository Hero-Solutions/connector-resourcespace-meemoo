<?php

namespace App\Util;

class DateTimeUtil
{
    public static function formatTimestampWithTimezone($unixTimestamp = null)
    {
        if($unixTimestamp == null) {
            return gmdate("Y-m-d\TH:i:s\Z");
        } else {
            return gmdate("Y-m-d\TH:i:s\Z", $unixTimestamp);
        }
    }

    public static function formatTimestampSimple($unixTimestamp = null)
    {
        if($unixTimestamp == null) {
            return gmdate("Y-m-d H:i:s");
        } else {
            return gmdate("Y-m-d H:i:s", $unixTimestamp);
        }
    }
}
