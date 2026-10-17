<?php

declare(strict_types=1);

namespace Workbench\App\Discovery\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Discovery fixture (Arch 8.2): a queued mailable (no handle()). Found.
 */
final class WelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Welcome</p>');
    }
}
