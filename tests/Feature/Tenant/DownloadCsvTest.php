<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Http\Controllers\Tenant\DownloadCsvController;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DownloadCsvTest extends TestCase
{
    private Tenant $tenant;

    private string $filename = 'query_abc123.csv';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        config([
            'export.disk' => 'exports',
            'export.download_secret' => 'test-secret',
        ]);

        Storage::fake('exports');
    }

    public function test_it_downloads_csv_with_valid_signature(): void
    {
        $this->putFakeFile();

        $expires = time() + 3600;
        $signature = DownloadCsvController::generateSignature($this->tenant->uuid, $this->filename, $expires);

        $response = $this->get($this->buildUrl($expires, $signature));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_it_returns_403_when_signature_is_invalid(): void
    {
        $this->putFakeFile();

        $expires = time() + 3600;

        $response = $this->get($this->buildUrl($expires, 'invalid-signature'));

        $response->assertStatus(403);
    }

    public function test_it_returns_403_when_link_has_expired(): void
    {
        $this->putFakeFile();

        $expires = time() - 1;
        $signature = DownloadCsvController::generateSignature($this->tenant->uuid, $this->filename, $expires);

        $response = $this->get($this->buildUrl($expires, $signature));

        $response->assertStatus(403);
    }

    public function test_it_returns_403_when_file_param_is_missing(): void
    {
        $expires = time() + 3600;
        $signature = DownloadCsvController::generateSignature($this->tenant->uuid, $this->filename, $expires);

        $url = "/api/{$this->tenant->uuid}/download?expires={$expires}&signature={$signature}";

        $response = $this->get($url);

        $response->assertStatus(403);
    }

    public function test_it_returns_403_when_expires_param_is_missing(): void
    {
        $signature = 'some-signature';

        $url = "/api/{$this->tenant->uuid}/download?file={$this->filename}&signature={$signature}";

        $response = $this->get($url);

        $response->assertStatus(403);
    }

    public function test_it_returns_403_when_signature_param_is_missing(): void
    {
        $expires = time() + 3600;

        $url = "/api/{$this->tenant->uuid}/download?file={$this->filename}&expires={$expires}";

        $response = $this->get($url);

        $response->assertStatus(403);
    }

    public function test_it_returns_404_when_file_does_not_exist(): void
    {
        $expires = time() + 3600;
        $signature = DownloadCsvController::generateSignature($this->tenant->uuid, $this->filename, $expires);

        $response = $this->get($this->buildUrl($expires, $signature));

        $response->assertStatus(404);
    }

    public function test_it_returns_404_for_invalid_tenant_uuid(): void
    {
        $response = $this->get(
            '/api/00000000-0000-0000-0000-000000000000/download?file=test.csv&expires=9999999999&signature=abc'
        );

        $response->assertStatus(404);
    }

    public function test_signature_is_tenant_scoped(): void
    {
        $this->putFakeFile();

        $otherTenant = Tenant::factory()->create();
        $expires = time() + 3600;
        $signature = DownloadCsvController::generateSignature($otherTenant->uuid, $this->filename, $expires);

        $response = $this->get($this->buildUrl($expires, $signature));

        $response->assertStatus(403);
    }

    public function test_signature_is_file_scoped(): void
    {
        $this->putFakeFile();

        $expires = time() + 3600;
        $signature = DownloadCsvController::generateSignature($this->tenant->uuid, 'other_file.csv', $expires);

        $response = $this->get($this->buildUrl($expires, $signature));

        $response->assertStatus(403);
    }

    public function test_signature_is_expiry_scoped(): void
    {
        $this->putFakeFile();

        $expires = time() + 3600;
        $differentExpires = $expires + 1;
        $signature = DownloadCsvController::generateSignature($this->tenant->uuid, $this->filename, $differentExpires);

        $response = $this->get($this->buildUrl($expires, $signature));

        $response->assertStatus(403);
    }

    public function test_it_does_not_require_authentication(): void
    {
        $this->putFakeFile();

        $expires = time() + 3600;
        $signature = DownloadCsvController::generateSignature($this->tenant->uuid, $this->filename, $expires);

        $response = $this->get($this->buildUrl($expires, $signature));

        $response->assertStatus(200);
    }

    private function putFakeFile(): void
    {
        Storage::disk('exports')->put($this->tenant->uuid.'/'.$this->filename, "header1,header2\nvalue1,value2\n");
    }

    private function buildUrl(int $expires, string $signature): string
    {
        return "/api/{$this->tenant->uuid}/download?file={$this->filename}&expires={$expires}&signature={$signature}";
    }
}
