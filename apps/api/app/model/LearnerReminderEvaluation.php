<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/** Stores only reminder evaluation/throttle state, never learning state. */
final class LearnerReminderEvaluation extends Model
{
    protected string $table = 'learner_reminder_evaluations';
    protected string $pk = 'id';

    /** @var list<string> */
    protected array $field = [
        'learner_id',
        'rule_code',
        'candidate_key',
        'resource_type',
        'resource_id',
        'evaluation_day',
        'evaluation_status',
        'reason_code',
        'last_evaluated_at',
        'first_sent_at',
        'last_sent_at',
        'send_count',
        'notification_id',
        'suppressed_at',
        'error_message',
        'created_at',
        'updated_at',
    ];
}
