<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A config naming certificate files that were never written stops nginx from
 * restarting, for every site on the server. The check finds one and has Forge
 * remove the certificate, which makes Forge rewrite the config without it.
 */
class CheckNginxCertificatesTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://forge.example.test/api/orgs/acme/servers/10/sites/99';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.forge.base_url', 'https://forge.example.test/api/orgs/acme');
        config()->set('services.forge.token', 'forge-token');
        config()->set('services.forge.server_id', '10');
        config()->set('services.forge.site_id', '99');

        $this->root = storage_path('framework/testing/nginx-'.uniqid());
        File::ensureDirectoryExists("{$this->root}/sites-enabled");
        config()->set('services.forge.nginx_config_paths', ["{$this->root}/sites-enabled", "{$this->root}/does-not-exist"]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response([
                'data' => [['id' => '55', 'type' => 'domainRecords', 'attributes' => ['name' => 'broken.example']]],
                'meta' => ['next_cursor' => null],
            ]),
            self::BASE.'/domains/*/certificates/*' => Http::response(null, 202),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * A config for one domain naming the given certificate directory, written
     * long enough ago to be past the grace period unless told otherwise.
     */
    private function config(string $name, string $certificateDirectory, int $ageSeconds = 600): void
    {
        $path = "{$this->root}/sites-enabled/{$name}";

        File::put($path, <<<NGINX
            server {
                listen 443 ssl;
                server_name {$name};

                # FORGE SSL (DO NOT REMOVE!)
                ssl_certificate {$certificateDirectory}/server.crt;
                ssl_certificate_key {$certificateDirectory}/server.key;
            }
            NGINX);

        touch($path, time() - $ageSeconds);
    }

    public function test_a_certificate_whose_files_are_missing_is_removed_in_forge(): void
    {
        $this->config('broken.example', "{$this->root}/ssl/domains/55/900");

        $this->artisan('sites:check-nginx-certificates')
            ->expectsOutputToContain('Removed certificate 900 for broken.example.')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === self::BASE.'/domains/55/certificates/900');
        Http::assertSentCount(2);
    }

    public function test_certificates_whose_files_exist_are_left_alone(): void
    {
        $directory = "{$this->root}/ssl/domains/55/900";
        File::ensureDirectoryExists($directory);
        File::put("{$directory}/server.crt", 'cert');
        File::put("{$directory}/server.key", 'key');
        $this->config('fine.example', $directory);

        File::put("{$this->root}/sites-enabled/commented.example", "    # ssl_certificate;\n    # ssl_certificate_key;\n");
        touch("{$this->root}/sites-enabled/commented.example", time() - 600);

        $this->artisan('sites:check-nginx-certificates')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_config_forge_is_still_writing_is_given_time(): void
    {
        $this->config('installing.example', "{$this->root}/ssl/domains/55/900", ageSeconds: 10);

        $this->artisan('sites:check-nginx-certificates')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_another_forge_sites_certificate_is_never_deleted(): void
    {
        // Domain record 77 belongs to some other site on the server.
        $this->config('someone-else.example', "{$this->root}/ssl/domains/77/900");

        $this->artisan('sites:check-nginx-certificates')
            ->expectsOutputToContain('does not belong to this Forge site')
            ->assertFailed();

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_a_missing_certificate_outside_forges_layout_is_reported_not_touched(): void
    {
        $this->config('custom.example', "{$this->root}/custom-ssl");

        $this->artisan('sites:check-nginx-certificates')
            ->expectsOutputToContain('custom-ssl/server.crt')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_the_check_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('sites:check-nginx-certificates');
    }
}
