<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\ProductionConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_responses_include_security_headers(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-XSS-Protection', '0')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    }

    public function test_production_csp_uses_a_request_nonce_instead_of_unsafe_inline_scripts(): void
    {
        $originalEnvironment = $this->app->environment();

        try {
            $this->app->detectEnvironment(fn () => 'production');
            $response = $this->get('/login')->assertOk();
            $policy = (string) $response->headers->get('Content-Security-Policy');

            $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
            $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([^']+)'/", $policy);
            preg_match("/script-src 'self' 'nonce-([^']+)'/", $policy, $matches);
            $response->assertSee('nonce="'.$matches[1].'"', false);
        } finally {
            $this->app->detectEnvironment(fn () => $originalEnvironment);
        }
    }

    public function test_role_boundaries_protect_sensitive_web_routes(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $coordinator = User::factory()->role(UserRole::Coordinator)->create();
        $witness = User::factory()->role(UserRole::Witness)->create();

        $this->actingAs($admin)->get(route('admin.events.index'))->assertOk();
        $this->actingAs($coordinator)->get(route('admin.events.index'))->assertForbidden();
        $this->actingAs($witness)->get(route('admin.events.index'))->assertForbidden();
        $this->actingAs($witness)->get(route('map.index'))->assertForbidden();
    }

    public function test_readiness_checks_database_and_cache_without_exposing_details(): void
    {
        $this->getJson('/ready')
            ->assertOk()
            ->assertExactJson(['status' => 'ready']);
    }

    public function test_export_endpoint_is_rate_limited_per_admin(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($admin)->get(route('admin.exports.results'))->assertRedirect();
        }

        $this->actingAs($admin)->get(route('admin.exports.results'))->assertTooManyRequests();
    }

    public function test_unsafe_production_configuration_is_reported(): void
    {
        $original = [
            'app.debug' => config('app.debug'),
            'app.url' => config('app.url'),
            'app.key' => config('app.key'),
            'database.default' => config('database.default'),
            'cache.default' => config('cache.default'),
            'cache.limiter' => config('cache.limiter'),
            'queue.default' => config('queue.default'),
            'queue.connections.redis.after_commit' => config('queue.connections.redis.after_commit'),
            'session.driver' => config('session.driver'),
            'session.encrypt' => config('session.encrypt'),
            'session.secure' => config('session.secure'),
            'filesystems.evidence' => config('filesystems.evidence'),
            'filesystems.disks.s3.bucket' => config('filesystems.disks.s3.bucket'),
            'filesystems.disks.s3.region' => config('filesystems.disks.s3.region'),
        ];

        try {
            config()->set([
                'app.debug' => true,
                'app.url' => 'http://localhost',
                'app.key' => null,
                'database.default' => 'sqlite',
                'cache.default' => 'array',
                'cache.limiter' => 'array',
                'queue.default' => 'sync',
                'session.driver' => 'array',
                'session.encrypt' => false,
                'session.secure' => false,
                'filesystems.evidence' => 'local',
                'filesystems.disks.s3.bucket' => null,
            ]);

            $errors = app(ProductionConfiguration::class)->errors();
            $this->assertGreaterThanOrEqual(12, count($errors));
            $this->assertContains('APP_DEBUG wajib false.', $errors);
            $this->assertContains('DB_CONNECTION production wajib pgsql.', $errors);
            $this->assertContains('EVIDENCE_DISK production wajib s3.', $errors);
        } finally {
            config()->set($original);
        }
    }
}
