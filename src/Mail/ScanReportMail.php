<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Mail;

use CraftCms\Cms\Email\Mailables\CraftMailable;
use Illuminate\Mail\Attachment;
use Symfony\Component\Mime\Email;

/**
 * The scan report email: a summary in the body, optionally the full report as a PDF.
 *
 * A {@see CraftMailable}, so the from address, reply-to and mailer come from Craft's email
 * settings. Use a fresh instance per recipient: `to()` appends.
 */
class ScanReportMail extends CraftMailable
{
    public function __construct(
        string $subject,
        public string $htmlBody,
        public string $textBody,
        public ?string $pdfPath = null,
        public ?string $pdfName = null,
    ) {
        $this->subject = $subject;
    }

    public function build(): static
    {
        $mail = $this->html($this->htmlBody)->withSymfonyMessage(fn (Email $message) => $message->text($this->textBody));

        if ($this->pdfPath !== null && is_file($this->pdfPath)) {
            $mail->attach(Attachment::fromPath($this->pdfPath)->as($this->pdfName ?? basename($this->pdfPath))->withMime('application/pdf'));
        }

        return $mail;
    }
}
