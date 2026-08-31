<?php

declare(strict_types=1);

namespace App\queue;

final class QueueNames
{
    public const NOTIFICATION_FAN_OUT = 'notification.fan_out';
    public const NOTIFICATION_PUSH = 'notification.push';
    public const PAYMENT_NOTIFY = 'payment.notify';
    public const SCHEDULED_TASK = 'scheduled.task';
}
