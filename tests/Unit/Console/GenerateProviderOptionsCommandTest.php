<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Tests\TestCase;

class GenerateProviderOptionsCommandTest extends TestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputPath = sys_get_temp_dir().'/test_providerOptions_'.uniqid().'.js';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->outputPath)) {
            unlink($this->outputPath);
        }
        parent::tearDown();
    }

    public function test_it_generates_provider_options_file(): void
    {
        $this->artisan('threadql:generate-provider-options', [
            '--path' => $this->outputPath,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Provider options written to');

        $this->assertFileExists($this->outputPath);
        $content = file_get_contents($this->outputPath);

        $this->assertStringContainsString('export const providerOptions', $content);
        $this->assertStringContainsString('export const adapterOptions', $content);
        $this->assertStringContainsString('"openai"', $content);
        $this->assertStringContainsString('"anthropic"', $content);
        $this->assertStringContainsString('"organization"', $content);
        $this->assertStringContainsString('"version"', $content);
    }

    public function test_it_creates_directory_if_not_exists(): void
    {
        $dir = sys_get_temp_dir().'/nested_'.uniqid().'/subdir';
        $path = $dir.'/providerOptions.js';

        $this->artisan('threadql:generate-provider-options', [
            '--path' => $path,
        ])
            ->assertSuccessful();

        $this->assertFileExists($path);

        // Cleanup
        unlink($path);
        rmdir($dir);
        rmdir(dirname($dir));
    }
}
