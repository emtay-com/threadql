<?php

declare(strict_types=1);

namespace App\Infrastructure\Slack;

use App\Exceptions\SlackApiException;
use App\Models\Tenant;
use App\Slack\Formatting\ResponseFormatter;
use Exception;
use Illuminate\Support\Facades\Log;
use JoliCode\Slack\Api\Client;
use JoliCode\Slack\Exception\SlackErrorResponse;

/**
 * Slack Messenger Service
 *
 * Wrapper around JoliCode Slack PHP API for sending messages.
 */
class SlackMessenger
{
    public function __construct(
        private readonly ?Client $client = null,
        private readonly ?SlackClientFactory $factory = null,
        private readonly ?ResponseFormatter $responseFormatter = null
    ) {
    }

    /**
     * Get the appropriate Slack client for the given tenant
     */
    private function getClientForTenant(Tenant $tenant): Client
    {
        // If we have a pre-configured client, use it (for backward compatibility)
        if ($this->client) {
            return $this->client;
        }

        // Otherwise, create a tenant-specific client
        if (! $this->factory) {
            throw new Exception('No Slack client or factory configured');
        }

        $botToken = $tenant->slack_bot_token;
        if (! $botToken) {
            throw new Exception('Tenant does not have a Slack bot token configured');
        }

        return $this->factory->create($botToken);
    }

    /**
     * Reply in a Slack thread
     *
     * @param Tenant $tenant The tenant for which to send the message
     * @param string $channelId The Slack channel ID
     * @param string $threadTs The thread timestamp (root message ts)
     * @param string $text The message text to send
     * @return array{ts: string}|null Returns the message timestamp or null on error
     */
    public function replyInThread(Tenant $tenant, string $channelId, string $threadTs, string $text): ?array
    {
        // Use ResponseFormatter if available, otherwise fall back to MarkdownBlocks
        if ($this->responseFormatter) {
            $blocks = $this->responseFormatter->format($text);
        } else {
            $blocks = new MarkdownBlocks($text)
                ->toArray();
        }

        return $this->replyInThreadWithBlocks($tenant, $channelId, $threadTs, $text, $blocks);
    }

    /**
     * Reply in a Slack thread with blocks
     *
     * @param Tenant $tenant The tenant for which to send the message
     * @param string $channelId The Slack channel ID
     * @param string $threadTs The thread timestamp (root message ts)
     * @param string $text The message text for fallback
     * @param array $blocks The blocks array to send
     * @return array{ts: string}|null Returns the message timestamp or null on error
     */
    public function replyInThreadWithBlocks(
        Tenant $tenant,
        string $channelId,
        string $threadTs,
        string $text,
        array $blocks
    ): ?array {
        try {
            $client = $this->getClientForTenant($tenant);
            $response = $client->chatPostMessage([
                'channel' => $channelId,
                'thread_ts' => $threadTs,
                'text' => $text,
                'blocks' => json_encode($blocks),
            ]);

            if ($response->getOk() && $response->getTs()) {
                return [
                    'ts' => $response->getTs(),
                ];
            }

            Log::warning('Slack blocks message sent but response was not OK', [
                'channel_id' => $channelId,
                'thread_ts' => $threadTs,
                'response_ok' => $response->getOk(),
            ]);

            return null;
        } catch (SlackErrorResponse $e) {
            Log::error('Slack API error when sending blocks message', [
                'channel_id' => $channelId,
                'thread_ts' => $threadTs,
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ]);

            throw new SlackApiException('Failed to send Slack blocks message: '.$e->getMessage(), $e);
        } catch (Exception $e) {
            Log::error('Unexpected error when sending Slack blocks message', [
                'channel_id' => $channelId,
                'thread_ts' => $threadTs,
                'error' => $e->getMessage(),
            ]);

            throw new SlackApiException('Unexpected error when sending Slack blocks message: '.$e->getMessage(), $e);
        }
    }

    /**
     * Reply in a Slack thread as attachment-only message
     *
     * @param Tenant $tenant The tenant for which to send the message
     * @param string $channelId The Slack channel ID
     * @param string $threadTs The thread timestamp (root message ts)
     * @param string $text The fallback text
     * @param array $attachments The attachments array
     * @return array{ts: string}|null Returns the message timestamp or null on error
     */
    public function replyInThreadAsAttachment(
        Tenant $tenant,
        string $channelId,
        string $threadTs,
        string $text,
        array $attachments
    ): ?array {
        try {
            $client = $this->getClientForTenant($tenant);
            $response = $client->chatPostMessage([
                'channel' => $channelId,
                'thread_ts' => $threadTs,
                'text' => $text,
                'attachments' => json_encode($attachments),
            ]);

            if ($response->getOk() && $response->getTs()) {
                return [
                    'ts' => $response->getTs(),
                ];
            }

            Log::warning('Slack attachment message sent but response was not OK', [
                'channel_id' => $channelId,
                'thread_ts' => $threadTs,
                'response_ok' => $response->getOk(),
            ]);

            return null;
        } catch (SlackErrorResponse $e) {
            Log::error('Slack API error when sending attachment message', [
                'channel_id' => $channelId,
                'thread_ts' => $threadTs,
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ]);

            throw new SlackApiException('Failed to send Slack attachment message: '.$e->getMessage(), $e);
        } catch (Exception $e) {
            Log::error('Unexpected error when sending Slack attachment message', [
                'channel_id' => $channelId,
                'thread_ts' => $threadTs,
                'error' => $e->getMessage(),
            ]);

            throw new SlackApiException(
                'Unexpected error when sending Slack attachment message: '.$e->getMessage(),
                $e
            );
        }
    }

    /**
     * Send an ephemeral message to a user in a channel
     *
     * @param Tenant $tenant The tenant for which to send the message
     * @param string $channelId The Slack channel ID
     * @param string $userId The user ID to send the message to
     * @param string $text The message text to send
     * @return array{ts: string}|null Returns the message timestamp or null on error
     */
    public function sendEphemeral(
        Tenant $tenant,
        string $channelId,
        string $userId,
        string $text,
        ?string $threadTs = null
    ): ?array {
        try {
            $client = $this->getClientForTenant($tenant);
            $params = [
                'channel' => $channelId,
                'user' => $userId,
                'text' => $text,
            ];

            if ($threadTs !== null) {
                $params['thread_ts'] = $threadTs;
            }

            $response = $client->chatPostEphemeral($params);

            if ($response->getOk()) {
                return [
                    'ts' => $response->getMessageTs(),
                ];
            }

            Log::warning('Slack ephemeral message sent but response was not OK', [
                'channel_id' => $channelId,
                'user_id' => $userId,
                'response_ok' => $response->getOk(),
            ]);

            return null;
        } catch (SlackErrorResponse $e) {
            Log::error('Slack API error when sending ephemeral message', [
                'channel_id' => $channelId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ]);

            throw new SlackApiException('Failed to send Slack ephemeral message: '.$e->getMessage(), $e);
        } catch (Exception $e) {
            Log::error('Unexpected error when sending Slack ephemeral message', [
                'channel_id' => $channelId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            throw new SlackApiException(
                'Unexpected error when sending Slack ephemeral message: '.$e->getMessage(),
                $e
            );
        }
    }

    /**
     * Update a Slack message
     *
     * @param Tenant $tenant The tenant for which to update the message
     * @param string $channelId The Slack channel ID
     * @param string $ts The message timestamp to update
     * @param string $text The message text for fallback
     * @param array $blocks The blocks array to update with
     * @return bool Returns true on success, false on failure
     */
    public function updateMessage(Tenant $tenant, string $channelId, string $ts, string $text, array $blocks): bool
    {
        try {
            $client = $this->getClientForTenant($tenant);
            $response = $client->chatUpdate([
                'channel' => $channelId,
                'ts' => $ts,
                'text' => $text,
                'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE),
            ]);

            if (! $response->getOk()) {
                Log::warning('Slack message update failed', [
                    'channel_id' => $channelId,
                    'ts' => $ts,
                    'response_ok' => $response->getOk(),
                    'error' => $this->extractResponseError($response),
                ]);

                return false;
            }

            Log::info('Slack message updated successfully', [
                'channel_id' => $channelId,
                'ts' => $ts,
            ]);

            return true;
        } catch (SlackErrorResponse $e) {
            Log::error('Slack API error when updating message', [
                'channel_id' => $channelId,
                'ts' => $ts,
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ]);

            throw new SlackApiException('Failed to update Slack message: '.$e->getMessage(), $e);
        } catch (Exception $e) {
            Log::error('Unexpected error when updating Slack message', [
                'channel_id' => $channelId,
                'ts' => $ts,
                'error' => $e->getMessage(),
            ]);

            throw new SlackApiException('Unexpected error when updating Slack message: '.$e->getMessage(), $e);
        }
    }

    /**
     * Open a Slack modal view
     *
     * @param Tenant $tenant The tenant for which to open the modal
     * @param string $triggerId The trigger ID from the interactive payload
     * @param array $view The view configuration array
     * @return bool Returns true on success, false on failure
     */
    public function openModal(Tenant $tenant, string $triggerId, array $view): bool
    {
        try {
            $client = $this->getClientForTenant($tenant);
            $response = $client->viewsOpen([
                'trigger_id' => $triggerId,
                'view' => json_encode($view, JSON_UNESCAPED_UNICODE),
            ]);

            if (! $response->getOk()) {
                Log::warning('Slack modal open failed', [
                    'trigger_id' => $triggerId,
                    'response_ok' => $response->getOk(),
                    'error' => $this->extractResponseError($response),
                ]);

                return false;
            }

            Log::info('Slack modal opened successfully', [
                'trigger_id' => $triggerId,
                'view_callback_id' => $view['callback_id'] ?? null,
            ]);

            return true;
        } catch (SlackErrorResponse $e) {
            Log::error('Slack API error when opening modal', [
                'trigger_id' => $triggerId,
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ]);

            throw new SlackApiException('Failed to open Slack modal: '.$e->getMessage(), $e);
        } catch (Exception $e) {
            Log::error('Unexpected error when opening Slack modal', [
                'trigger_id' => $triggerId,
                'error' => $e->getMessage(),
            ]);

            throw new SlackApiException('Unexpected error when opening Slack modal: '.$e->getMessage(), $e);
        }
    }

    /**
     * Update a Slack message with blocks
     *
     * @param Tenant $tenant The tenant for which to update the message
     * @param string $channelId The Slack channel ID
     * @param string $messageTs The message timestamp to update
     * @param string $text The message text for fallback
     * @param array $blocks The blocks array to send
     * @return array{ts: string}|null Returns the message timestamp or null on error
     */
    public function updateMessageBlocks(
        Tenant $tenant,
        string $channelId,
        string $messageTs,
        string $text,
        array $blocks
    ): ?array {
        try {
            $client = $this->getClientForTenant($tenant);
            $response = $client->chatUpdate([
                'channel' => $channelId,
                'ts' => $messageTs,
                'text' => $text,
                'blocks' => json_encode($blocks),
            ]);

            if (! $response->getOk()) {
                Log::warning('Slack message blocks update failed', [
                    'channel_id' => $channelId,
                    'message_ts' => $messageTs,
                    'blocks_count' => count($blocks),
                    'response_ok' => $response->getOk(),
                    'error' => $this->extractResponseError($response),
                ]);

                return null;
            }

            Log::info('Slack message blocks updated successfully', [
                'channel_id' => $channelId,
                'message_ts' => $messageTs,
                'blocks_count' => count($blocks),
            ]);

            return [
                'ts' => $response->getTs(),
            ];
        } catch (SlackErrorResponse $e) {
            Log::error('Slack API error when updating message blocks', [
                'channel_id' => $channelId,
                'message_ts' => $messageTs,
                'blocks_count' => count($blocks),
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ]);

            throw new SlackApiException('Failed to update Slack message blocks: '.$e->getMessage(), $e);
        } catch (Exception $e) {
            Log::error('Unexpected error when updating Slack message blocks', [
                'channel_id' => $channelId,
                'message_ts' => $messageTs,
                'blocks_count' => count($blocks),
                'error' => $e->getMessage(),
            ]);

            throw new SlackApiException('Unexpected error when updating Slack message blocks: '.$e->getMessage(), $e);
        }
    }

    /**
     * Update a Slack message with attachments
     *
     * @param Tenant $tenant The tenant for which to update the message
     * @param string $channelId The Slack channel ID
     * @param string $messageTs The message timestamp to update
     * @param string $text The message text for fallback
     * @param array $attachments The attachments array to send
     * @return array{ts: string}|null Returns the message timestamp or null on error
     */
    public function updateMessageAttachments(
        Tenant $tenant,
        string $channelId,
        string $messageTs,
        string $text,
        array $attachments
    ): ?array {
        try {
            $client = $this->getClientForTenant($tenant);
            $response = $client->chatUpdate([
                'channel' => $channelId,
                'ts' => $messageTs,
                'text' => $text,
                'attachments' => json_encode($attachments),
            ]);

            if (! $response->getOk()) {
                Log::warning('Slack message attachments update failed', [
                    'channel_id' => $channelId,
                    'message_ts' => $messageTs,
                    'attachments_count' => count($attachments),
                    'response_ok' => $response->getOk(),
                    'error' => $this->extractResponseError($response),
                ]);

                return null;
            }

            Log::info('Slack message attachments updated successfully', [
                'channel_id' => $channelId,
                'message_ts' => $messageTs,
                'attachments_count' => count($attachments),
            ]);

            return [
                'ts' => $response->getTs(),
            ];
        } catch (SlackErrorResponse $e) {
            Log::error('Slack API error when updating message attachments', [
                'channel_id' => $channelId,
                'message_ts' => $messageTs,
                'attachments_count' => count($attachments),
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ]);

            throw new SlackApiException('Failed to update Slack message attachments: '.$e->getMessage(), $e);
        } catch (Exception $e) {
            Log::error('Unexpected error when updating Slack message attachments', [
                'channel_id' => $channelId,
                'message_ts' => $messageTs,
                'attachments_count' => count($attachments),
                'error' => $e->getMessage(),
            ]);

            throw new SlackApiException(
                'Unexpected error when updating Slack message attachments: '.$e->getMessage(),
                $e
            );
        }
    }

    /**
     * Upload a file to Slack and optionally share it in a channel/thread
     *
     * @param Tenant $tenant The tenant for which to upload the file
     * @param array $files Array of file configurations for upload
     * @param string $channelId The Slack channel ID
     * @param string|null $initialComment Initial comment to post with the file
     * @param string|null $threadTs Thread timestamp to post in (optional)
     * @return bool Returns true on success, false on failure
     */
    public function uploadFile(
        Tenant $tenant,
        array $files,
        string $channelId,
        ?string $initialComment = null,
        ?string $threadTs = null
    ): bool {
        try {
            $client = $this->getClientForTenant($tenant);
            /** @var mixed $response */
            $response = call_user_func([$client, 'filesUploadV2'], $files, $channelId, $initialComment, $threadTs);

            $responseOk = is_object($response) && method_exists($response, 'getOk') && $response->getOk();
            if (! $responseOk) {
                Log::warning('Slack file upload failed', [
                    'channel_id' => $channelId,
                    'thread_ts' => $threadTs,
                    'files_count' => count($files),
                    'response_ok' => $responseOk,
                    'error' => $this->extractResponseError($response),
                ]);

                return false;
            }

            Log::info('Slack file uploaded successfully', [
                'channel_id' => $channelId,
                'thread_ts' => $threadTs,
                'files_count' => count($files),
            ]);

            return true;
        } catch (SlackErrorResponse $e) {
            Log::error('Slack API error when uploading file', [
                'channel_id' => $channelId,
                'thread_ts' => $threadTs,
                'files_count' => count($files),
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ]);

            throw new SlackApiException('Failed to upload file to Slack: '.$e->getMessage(), $e);
        } catch (Exception $e) {
            Log::error('Unexpected error when uploading file to Slack', [
                'channel_id' => $channelId,
                'thread_ts' => $threadTs,
                'files_count' => count($files),
                'error' => $e->getMessage(),
            ]);

            throw new SlackApiException('Unexpected error when uploading file to Slack: '.$e->getMessage(), $e);
        }
    }

    private function extractResponseError(mixed $response): ?string
    {
        if (is_object($response) && method_exists($response, 'getError')) {
            $error = $response->getError();

            return is_string($error) ? $error : null;
        }

        return null;
    }
}
