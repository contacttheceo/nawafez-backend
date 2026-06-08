<?php

use Illuminate\Support\Facades\Schedule;

// Run every day at 02:00 (Asia/Riyadh) to expire listings and send notifications
Schedule::command('listings:expire')->dailyAt('02:00');

// Daily activity digest to all admins at 08:00 Asia/Riyadh.
// Numbers cover the previous 24h.
Schedule::command('admin:daily-digest')->dailyAt('08:00');
