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
        'open_app' => 'Open KK Farm',
        'install_update' => 'Install the latest app',
    ];

    /**
     * D-355: remote screen (the gun's Remote control switched on in Accessibility): a screenshot, a tap or swipe at a
     * point given as a fraction of the screen, a system key, text into the focused field, wake the screen.
     */
    public const REMOTE = ['screenshot', 'tap', 'swipe', 'key', 'text', 'wake'];

    public const KEYS = ['back', 'home', 'recents', 'notifications'];

    protected $guarded = [];

    protected $casts = ['sent_at' => 'datetime', 'done_at' => 'datetime', 'args' => 'array'];
}
