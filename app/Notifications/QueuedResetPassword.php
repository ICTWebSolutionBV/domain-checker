<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The framework's reset-password notification, queued.
 *
 * Laravel's own ResetPassword is not queueable, so both the public
 * "forgot password" form and the admin's "send password reset" action blocked
 * the request on the SMTP handshake. With a real mail host that is the slowest
 * thing either endpoint does, and a mail failure became a 500 rather than a
 * retryable job.
 */
class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
