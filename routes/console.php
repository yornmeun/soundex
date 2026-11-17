<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('kikitoons:recover-stale')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('kikitoons:prune-uploads')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
