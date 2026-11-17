<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('soundex:recover-stale')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('soundex:prune-uploads')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
