<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Notifications\Services\NotificationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notifications:flush')]
#[Description('Deliver due notification outbox rows')]
class FlushNotifications extends Command
{
    public function handle(NotificationDispatcher $dispatcher): int
    {
        $sent = $dispatcher->flush();
        $this->info("Delivered {$sent} notification(s).");

        return self::SUCCESS;
    }
}
