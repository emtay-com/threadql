<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Settings;
use App\Infrastructure\Attributes\Assignable;
use App\Infrastructure\Jobs\JobParamAssigner;
use App\Infrastructure\Slack\SlackUserSettingService;
use App\Models\Query;
use App\Slack\FeedbackMessenger;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Send feedback survey to Slack for a completed query
 */
final class SendFeedbackSurveyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use JobParamAssigner;

    public int $tries = 3;

    public int $backoff = 5;

    #[Assignable]
    private FeedbackMessenger $feedbackMessenger;

    #[Assignable]
    private SlackUserSettingService $settingService;

    public function __construct(
        public readonly int $queryId
    ) {
    }

    public function handle(FeedbackMessenger $feedbackMessenger, SlackUserSettingService $settingService): void
    {
        $this->assignParams(func_get_args());

        $query = Query::find($this->queryId);
        if (! $query) {
            Log::warning('Query not found for feedback survey', [
                'query_id' => $this->queryId,
            ]);

            return;
        }
        if ($this->hasSurveysEnabled($query)) {
            $this->postFeedbackSurvey();
        }
    }

    private function hasSurveysEnabled(Query $query): bool
    {
        $slackUser = $query->slackUser;

        $surveysOn = $slackUser
            ? $this->settingService->isEnabled($slackUser, Settings::SURVEYS)
            : (bool) config('slack-settings.defaults.surveys', true);

        return $surveysOn;
    }

    private function postFeedbackSurvey(): void
    {
        try {
            $this->feedbackMessenger->postForQuery($this->queryId);
            Log::info('Feedback survey sent successfully', [
                'query_id' => $this->queryId,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to post feedback survey', [
                'query_id' => $this->queryId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
