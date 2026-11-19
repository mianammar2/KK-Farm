<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-354: a test command for a gun, sent with its next report; the gun answers with a result. */
class GunCommand extends Model
{
    /** What the Administrator can ask a gun to do. Reading tags only: nothing here writes, locks or kills a tag. */
    public const COMMANDS = [
        'send_logs' => 'Send its log',
        'test_read' => 'Test read (3 seconds)',
        'reopen_reader' => 'Restart the reader',
        'reload' => 'Reload the page',
        'check_update' => 'Check for an update',
    ];

    protected $guarded = [];

    protected $casts = ['sent_at' => 'datetime', 'done_at' => 'datetime'];
}
