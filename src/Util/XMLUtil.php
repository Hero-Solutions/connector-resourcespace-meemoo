<?php

namespace App\Util;

use DOMXPath;

class XMLUtil
{
    public static function convertXmlToArray($domDoc, $xpath, $xpathQuery, $isRootNode = false)
    {
        $jsonData = null;

        if($xpathQuery != null) {
            $results = $xpath->query($xpathQuery);
            //We really only expect 1 result
            foreach($results as $result) {
                $domDoc = $result;
            }
        }

        //Remove all comments, otherwise the document cannot be properly converted to JSON
        foreach ($xpath->query('//comment()') as $comment) {
            $comment->parentNode->removeChild($comment);
        }
        if($isRootNode) {
            foreach($domDoc->childNodes as $node) {
                $convertedData = XMLUtil::convertRecursively($node, false);
                break;
            }
        } else {
            $convertedData = XMLUtil::convertRecursively($domDoc, false);
        }

/*        $jsonData = json_encode($convertedData, JSON_PRETTY_PRINT);
        // Remove zero width spaces (no idea how they got there)
        $jsonData = str_replace( '\u200b', '', $jsonData);*/

        return $convertedData;
    }

    public static function convertRecursively($xmlNode, $parentIsArray)
    {
        $isArray = false;
        if($xmlNode->hasAttributes()) {
            foreach ($xmlNode->attributes as $attribute) {
                if ($attribute->nodeName == 'type' && $attribute->nodeValue == 'list') {
                    $isArray = true;
                    break;
                }
            }
        }
        $arr = array();
        if($xmlNode->hasChildNodes()) {
            foreach ($xmlNode->childNodes as $node) {
                $val = self::convertRecursively($node, $isArray);
                if($node->hasChildNodes()) {
                    if($isArray || $parentIsArray) {
                        $arr[$node->nodeName][] = $val;
                    } else {
                        $arr[$node->nodeName] = $val;
                    }
                } else {
                    $arr[] = $val;                        
                }
            }
        } else {
            return strval($xmlNode->nodeValue);
        }
        if(count($arr) == 1 && !$isArray) {
            return $arr[0];
        }
        return $arr;
    }
}
