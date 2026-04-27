<?php

use Illuminate\Support\Facades\Schedule;

// Run every day at 02:00 (Asia/Riyadh) to expire listings and send notifications
Schedule::command('listings:expire')->dailyAt('02:00');
