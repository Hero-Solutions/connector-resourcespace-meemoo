<?php


namespace App\Util;


class DateTimeUtil
{
    public static function formatTimestamp($unixTimestamp = null)
    {
        return gmdate("Y-m-d\TH:i:s\Z", $unixTimestamp);
    }
}