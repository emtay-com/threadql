<?php

declare(strict_types=1);

namespace App\CommandHandlers;

use App\Command\GenerateAppManifestCommand;
use App\Command\GenerateAppManifestResponse;
use App\Infrastructure\Command\DomainCommandHandler;

/**
 * Handler for generating Slack App Manifests
 */
class GenerateAppManifestCommandHandler implements DomainCommandHandler
{
    /**
     * Handle the generate app manifest command
     */
    public function __invoke(GenerateAppManifestCommand $command): GenerateAppManifestResponse
    {
        try {
            // Load the PHP array from the template file
            $templatePath = resource_path('manifest/app_manifest.php');
            if (! file_exists($templatePath)) {
                return GenerateAppManifestResponse::failure('App manifest template not found.');
            }

            $manifestTemplate = require $templatePath;

            // Recursively replace placeholders
            $manifest = $this->replacePlaceholders($manifestTemplate, [
                '{{tenant_uuid}}' => $command->tenantUuid,
                '{{base_url}}' => rtrim($command->baseUrl, '/'),
                '{{app_name}}' => $command->appName,
                '{{bot_display_name}}' => $command->botDisplayName,
                '{{slash_command}}' => $command->slashCommand,
            ]);

            // Return pretty-printed JSON
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return GenerateAppManifestResponse::failure('Failed to encode manifest as JSON.');
            }

            return GenerateAppManifestResponse::success($json);
        } catch (\Throwable $e) {
            return GenerateAppManifestResponse::failure('Error generating manifest: '.$e->getMessage());
        }
    }

    /**
     * Recursively replace placeholders in arrays and strings
     */
    private function replacePlaceholders(mixed $data, array $replacements): mixed
    {
        if (is_string($data)) {
            return str_replace(array_keys($replacements), array_values($replacements), $data);
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->replacePlaceholders($value, $replacements);
            }

            return $result;
        }

        return $data;
    }
}
