<?php

namespace App\Support;

final class NotificationText
{
    /**
     * Filament renders a notification's title and body as sanitized HTML that keeps links and styles. Every title or body
     * built from a variable goes through this, so stored or uploaded text shows as typed.
     */
    public static function plain(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
