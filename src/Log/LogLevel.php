<?php

declare(strict_types=1);

namespace Hub\Log;

/**
 * Log verbosity levels (Enum replaces the four ad-hoc static log* methods).
 */
enum LogLevel: string
{
    case Debug     = 'DEBUG';
    case Info      = 'INFO';
    case Notice    = 'NOTICE';
    case Error     = 'ERROR';
    case Connection = 'CONN';
    case Packet    = 'PACKET';

    public function icon(): string
    {
        return match ($this) {
            self::Debug      => '🔍',
            self::Info       => 'ℹ️',
            self::Notice     => '📝',
            self::Error      => '❌',
            self::Connection => '🌐',
            self::Packet     => '📦',
        };
    }
}
