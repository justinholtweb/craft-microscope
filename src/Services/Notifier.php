<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Services;

use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use JustinHoltWeb\Microscope\Models\Scan;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Notifications\ScanNeedsAttention;
use JustinHoltWeb\Microscope\Plugin;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Telling people about a finished scheduled scan: the report by email, a line in Slack, and a
 * control panel notification for the people who manage Microscope.
 *
 * Each channel is tried on its own and never throws: a broken mailer mustn't cost the Slack post.
 */
class Notifier
{
    public function __construct(
        private readonly Scans $scans,
        private readonly Reports $reports,
        private readonly Settings $settings,
    ) {}

    /**
     * Sends whatever the settings ask for, if this scan warrants it. Returns whether anything went.
     */
    public function scheduledScanFinished(Scan $scan): bool
    {
        if (! $this->settings->notifyEnabled || ! $this->shouldNotify($scan)) {
            return false;
        }

        $subject = $this->subjectFor($scan);
        $sent = false;

        $recipients = $this->settings->resolvedRecipients();

        if ($recipients !== []) {
            $sent = $this->attempt('email', fn () => $this->reports->email($scan, $recipients, $subject) > 0) || $sent;
        }

        if (($slack = $this->settings->slackWebhook()) !== null) {
            $sent = $this->attempt('Slack', fn () => Http::timeout(10)->post($slack, [
                'text' => sprintf('%s — %s <%s|%s>', $subject, $scan->result?->headline() ?? '', $scan->cpUrl(), t('Open the report', category: 'microscope')),
            ])->throw()->successful()) || $sent;
        }

        if ($this->settings->notifyCpUsers) {
            $sent = $this->attempt('control panel', function () use ($scan, $subject): bool {
                $users = User::find()->can(Plugin::PERMISSION_MANAGE)->status(User::STATUS_ACTIVE)->all();

                if ($users === []) {
                    return false;
                }

                Notification::send($users, new ScanNeedsAttention($scan, $subject));

                return true;
            }) || $sent;
        }

        if (! $sent && $recipients === [] && $this->settings->slackWebhook() === null && ! $this->settings->notifyCpUsers) {
            Log::channel(Plugin::LOG_CHANNEL)->warning('Notifications are on, but there are no valid recipients, Slack webhook or control panel notifications to send to.');
        }

        return $sent;
    }

    /** Whether this scan warrants a notice at all. */
    public function shouldNotify(Scan $scan): bool
    {
        return match ($this->settings->notifyOn) {
            Settings::NOTIFY_ALWAYS => true,
            Settings::NOTIFY_PROBLEMS => $scan->problemCount() > 0,
            // The default, and the one that keeps the emails worth opening: only when something
            // has actually changed for the worse.
            default => $this->hasNewProblems($scan),
        };
    }

    private function hasNewProblems(Scan $scan): bool
    {
        $comparison = $this->scans->compareWithPrevious($scan);

        // Nothing to compare against: notify, so the first scheduled scan is never silent.
        if ($comparison['previous'] === null) {
            return $scan->problemCount() > 0;
        }

        return $comparison['new'] !== [];
    }

    public function subjectFor(Scan $scan): string
    {
        $siteName = Sites::getPrimarySite()->getName();
        $previous = $this->scans->getPreviousScan($scan);

        if ($previous?->score !== null && $scan->score !== null && $previous->score !== $scan->score) {
            return $scan->score < $previous->score
                ? t('{site}: performance score dropped to {score}/100', ['site' => $siteName, 'score' => $scan->score], category: 'microscope')
                : t('{site}: performance score improved to {score}/100', ['site' => $siteName, 'score' => $scan->score], category: 'microscope');
        }

        return $this->reports->defaultSubject($scan);
    }

    /**
     * @param  callable(): bool  $send
     */
    private function attempt(string $channel, callable $send): bool
    {
        try {
            return $send();
        } catch (Throwable $e) {
            Log::channel(Plugin::LOG_CHANNEL)->error(sprintf('Could not send the scan notification by %s: %s', $channel, $e->getMessage()));

            return false;
        }
    }
}
