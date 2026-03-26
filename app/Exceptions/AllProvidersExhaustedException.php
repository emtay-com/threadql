<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\LlmProvider;
use Exception;

/**
 * Thrown when all configured LLM providers have been tried and failed.
 */
class AllProvidersExhaustedException extends Exception implements UnrecoverableJobException
{
    /**
     * @param LlmProvider[] $providersTried List of providers that were attempted
     * @param \Throwable $lastException The last exception encountered
     */
    public function __construct(
        private readonly array $providersTried,
        \Throwable $lastException
    ) {
        $providerNames = array_map(fn (LlmProvider $p) => $p->name, $this->providersTried);

        parent::__construct(
            sprintf(
                'All LLM providers exhausted (%s): %s',
                implode(', ', $providerNames),
                $lastException->getMessage()
            ),
            previous: $lastException
        );
    }

    /**
     * Get the list of providers that were tried.
     *
     * @return LlmProvider[]
     */
    public function getProvidersTried(): array
    {
        return $this->providersTried;
    }
}
