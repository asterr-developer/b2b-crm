<?php

namespace App\Services;

use App\Models\DealActivity;

class TaskCalendarFile
{
    public function render(DealActivity $task): string
    {
        $instance = substr(hash('sha256', config('app.url')), 0, 12);
        $date = $task->due_at->copy()->utc();
        $title = preg_replace('/\s+/u', ' ', trim($task->body));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//B2B CRM//Sales Tasks//RU',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:crm-task-'.$task->id.'@'.$instance.'.crm.local',
            'DTSTAMP:'.$task->updated_at->copy()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$date->format('Ymd\THis\Z'),
            'DURATION:PT15M',
            'SUMMARY:'.$this->escape(mb_strimwidth($title, 0, 120, '…')),
            'DESCRIPTION:'.$this->escape($task->deal->title."\n".$task->body),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    private function escape(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return strtr($value, ["\r\n" => '\n', "\r" => '\n', "\n" => '\n', '\\' => '\\\\', ',' => '\,', ';' => '\;']);
    }

    private function fold(string $line): string
    {
        $segments = [];
        $limit = 75;
        while (strlen($line) > $limit) {
            $segment = mb_strcut($line, 0, $limit, 'UTF-8');
            $segments[] = $segment;
            $line = substr($line, strlen($segment));
            $limit = 74;
        }
        $segments[] = $line;

        return implode("\r\n ", $segments);
    }
}
