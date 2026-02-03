<?php

namespace App\Util;

class HttpUtil
{
    public static function urlExists($url): bool
    {
        stream_context_set_default(array('http' => array('method' => 'HEAD')));
        $headers = get_headers($url);
        $result = false;
        if(!empty($headers)) {
            $result = str_contains($headers[0], '200 OK');
        }
        stream_context_set_default(array('http' => array('method' => 'GET')));
        return $result;
    }
}
