<?php

namespace App\Services;

class TrafficDetectionService
{
    public function classify($destination, $bytes = 0, $upload = false)
    {
        $destination = strtolower($destination);

        // REAL-TIME APPS
        if (
            str_contains($destination, 'zoom') ||
            str_contains($destination, 'teams') ||
            str_contains($destination, 'meet')
        ) {
            return 'REAL_TIME';
        }

        // STREAMING
        if (
            str_contains($destination, 'youtube') ||
            str_contains($destination, 'netflix') ||
            str_contains($destination, 'tiktok')
        ) {
            return 'STREAMING';
        }

        // UPLOADS
        if ($upload) {
            return 'DATA_TRANSFER';
        }

        // LARGE DOWNLOADS
        if ($bytes > 50000000) {
            return 'BULK';
        }

        return 'NORMAL';
    }
}
