<?php

namespace App\Services;

use App\Models\TaskPriority;

class TrafficDetectionService
{
    public function classify($destination, $bytes = 0, $upload = false)
    {
        $destination = strtolower((string) $destination);

        foreach (TaskPriority::matchers() as $slug => $terms) {
            if ($this->matches($destination, $terms)) {
                return $slug;
            }
        }

        if (TaskPriority::matchers() === []) {
            return $this->classifyWithDefaults($destination, $bytes, $upload);
        }

        if ($upload) {
            return 'DATA_TRANSFER';
        }

        if ($bytes > 50000000) {
            return 'BULK';
        }

        return 'NORMAL';
    }

    protected function classifyWithDefaults(string $destination, int $bytes, bool $upload): string
    {
        if ($this->matches($destination, [
            'zoom', 'teams', 'meet.google', 'meet', 'webex', 'skype', 'jitsi',
            'bigbluebutton', 'whereby',
        ])) {
            return 'REAL_TIME';
        }

        if ($this->matches($destination, [
            'moodle', 'canvas', 'blackboard', 'classroom.google', 'schoology', 'edx', 'coursera',
        ])) {
            return 'LEARNING';
        }

        if ($this->matches($destination, [
            'docs.google', 'drive.google', 'sharepoint', 'office.com', 'office365',
            'slack', 'notion', 'onedrive', 'dropbox',
        ])) {
            return 'COLLABORATION';
        }

        if ($this->matches($destination, [
            'youtube', 'netflix', 'tiktok', 'twitch', 'spotify', 'vimeo',
        ])) {
            return 'STREAMING';
        }

        if ($upload) {
            return 'DATA_TRANSFER';
        }

        if ($bytes > 50000000) {
            return 'BULK';
        }

        return 'NORMAL';
    }

    /**
     * @param  list<string>  $needles
     */
    protected function matches(string $destination, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($destination, $needle)) {
                return true;
            }
        }

        return false;
    }
}
