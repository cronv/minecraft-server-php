<?php

declare(strict_types=1);

namespace Hub\Handler;

/**
 * Handler contract for one connection state (State pattern participant).
 */
interface StateHandler
{
    public function handle(PacketCommand $command): void;
}
