<?php

declare(strict_types=1);

namespace App\Services\Llm\Builders;

/**
 * Builder for constructing prompt data context arrays.
 * Provides a fluent interface for building complex data structures passed to prompt views.
 */
class PromptDataContextBuilder
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * Set the query ID
     */
    public function withQueryId(int $queryId): self
    {
        $this->data['query_id'] = $queryId;

        return $this;
    }

    /**
     * Set the user query text
     */
    public function withUserQueryText(string $text): self
    {
        $this->data['user_query_text'] = $text;

        return $this;
    }

    /**
     * Set DDLs data
     *
     * @param array<int, array{table: string, ddl: string}> $ddls
     */
    public function withDdls(array $ddls): self
    {
        if (! empty($ddls)) {
            $this->data['ddls'] = $ddls;
        }

        return $this;
    }

    /**
     * Set definitions data
     *
     * @param array<int, array{subject: string, definition: string}> $definitions
     */
    public function withDefinitions(array $definitions): self
    {
        if (! empty($definitions)) {
            $this->data['definitions'] = $definitions;
        }

        return $this;
    }

    /**
     * Set tables available data
     *
     * @param array<int, string> $tables
     */
    public function withTablesAvailable(array $tables): self
    {
        if (! empty($tables)) {
            $this->data['tables_available'] = $tables;
        }

        return $this;
    }

    /**
     * Set timezone data
     */
    public function withTimezoneData(string $tenantTimezone, string $datasourceTimezone): self
    {
        $this->data['tenant_timezone'] = $tenantTimezone;
        $this->data['datasource_timezone'] = $datasourceTimezone;

        return $this;
    }

    /**
     * Build and return the data array
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return $this->data;
    }

    /**
     * Create a new builder instance
     */
    public static function create(): self
    {
        return new self;
    }

    /**
     * Reset the builder to initial state
     */
    public function reset(): self
    {
        $this->data = [];

        return $this;
    }
}
