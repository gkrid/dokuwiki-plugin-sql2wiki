<?php

namespace dokuwiki\plugin\sql2wiki;

class Csv
{
    /**
     * Escapes and wraps the given string
     *
     * @param string $str
     * @return string
     */
    static function escape($str) {
        $backslashes_escaped = str_replace("\\", "\\\\", $str);
        $nl_escaped = str_replace(["\n", "\r"], ["\\n", "\\r"], $backslashes_escaped);
        return '"' . htmlspecialchars($nl_escaped, ENT_COMPAT) . '"';
    }

    /**
     * Unescapes given string
     *
     * @param string $str
     * @return string
     */
    static function unescape($str) {
        $unescaped_htmlspecialchars = htmlspecialchars_decode($str, ENT_COMPAT);
        $unescaped_nl = str_replace(["\\n", "\\r"], ["\n", "\r"], $unescaped_htmlspecialchars);
        $unescaped_backshlashes = str_replace("\\\\", "\\", $unescaped_nl);
        return $unescaped_backshlashes;
    }

    /**
     * Converts two-dimensional array into csv string that can be written to dokuwiki page content.
     *
     * @param array $array
     * @return string
     */
    static function arr2csv($array) {
        $csv = "";
        foreach ($array as $row) {
            $csv_row = join(',', array_map('self::escape', $row));
            $csv .= $csv_row . "\n";
        }
        return $csv;
    }

    /**
     * Converts csv string to two-dimensional array.
     *
     * @param string $csv
     * @return array
     */

    static function csv2arr($csv) {
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv)));
        $array = array_map('str_getcsv', $lines);
        $unescaped_array = array_map(function ($row) {
            return array_map('self::unescape', $row);
        }, $array);
        return $unescaped_array;
    }
}