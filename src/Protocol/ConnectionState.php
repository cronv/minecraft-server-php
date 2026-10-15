<?php

declare(strict_types=1);

namespace Hub\Protocol;

/**
 * Enum состояний соединения Minecraft-протокола (замена «магических» строк).
 */
enum ConnectionState: string
{
    case Handshake = 'handshake';
    case Status    = 'status';
    case Login     = 'login';
    case Play      = 'play';

    /**
     * Маппинг поля next_state из пакета Handshake в состояние соединения.
     */
    public static function fromNextState(int $nextState): ?self
    {
        return match ($nextState) {
            1       => self::Status,
            2       => self::Login,
            default => null,
        };
    }
}
