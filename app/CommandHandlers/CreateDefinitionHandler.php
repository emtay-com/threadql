<?php

declare(strict_types=1);

namespace App\CommandHandlers;

use App\Command\CreateDefinitionCommand;
use App\Command\CreateDefinitionResponse;
use App\Infrastructure\Command\DomainCommandHandler;
use App\Models\Definition;
use Illuminate\Support\Facades\DB;

/**
 * Handler for creating definitions
 */
class CreateDefinitionHandler implements DomainCommandHandler
{
    /**
     * Handle the create definition command
     */
    public function __invoke(CreateDefinitionCommand $command): CreateDefinitionResponse
    {
        return DB::transaction(function () use ($command) {
            // Check if definition already exists for this tenant and subject
            $existingDefinition = Definition::where('tenant_id', $command->tenantId)
                ->where('subject', $command->subject)
                ->first();

            if ($existingDefinition) {
                return CreateDefinitionResponse::duplicate(
                    $existingDefinition->subject,
                    $existingDefinition->definition
                );
            }

            // Create new definition
            $definition = Definition::create([
                'tenant_id' => $command->tenantId,
                'user_id' => $command->userId,
                'thread_id' => $command->threadId,
                'priority' => $command->priority,
                'subject' => $command->subject,
                'definition' => $command->definition,
            ]);

            return CreateDefinitionResponse::success($definition->subject, $definition->definition);
        });
    }
}
