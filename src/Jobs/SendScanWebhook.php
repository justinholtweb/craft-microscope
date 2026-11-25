<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Jobs;

use CraftCms\Cms\Queue\Job;
use Illuminate\Support\Facades\Http;
use JustinHoltWeb\Microscope\Models\Settings;

use function CraftCms\Cms\t;

/**
 * POSTs a completed scan's summary to the webhook URL in Microscope's settings, signed with
 * `X-Microscope-Signature: sha256=<HMAC of the body>` when a secret is set. Retried with backoff.
 */
class SendScanWebhook extends Job
{
    public int $tries = 5;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {
        parent::__construct();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(Settings $settings): void
    {
        $url = $settings->webhook();

        if ($url === null) {
            return;
        }

        $body = (string) json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['User-Agent' => 'Microscope-Webhook/1'];

        if (($secret = $settings->webhookSecretValue()) !== null) {
            $headers['X-Microscope-Signature'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        Http::timeout(15)->withHeaders($headers)->withBody($body, 'application/json')->post($url)->throw();
    }

    protected function defaultDescription(): string
    {
        return t('Sending Microscope’s scan webhook', category: 'microscope');
    }
}
