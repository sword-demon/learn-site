<?php
declare(strict_types=1);

// Kept as a small deploy-time reference; the runtime scheduler reads the
// equivalent row inserted by ScheduledTaskSeeder.
return ['ops-inbox-sweep' => '0 * * * * *'];
