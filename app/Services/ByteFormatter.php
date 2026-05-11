<?php

namespace App\Services;

class ByteFormatter
{
    public static function human(int|float|null $bytes): string
    {
        if ($bytes === null) {
            return 'Unavailable';
        }

        $bytes = max(0, (float) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = 0;

        while ($bytes >= 1024 && $power < count($units) - 1) {
            $bytes /= 1024;
            $power++;
        }

        return ($power === 0 ? number_format($bytes, 0) : number_format($bytes, 2)).' '.$units[$power];
    }
}
