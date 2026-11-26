<?php

namespace Tests\Feature\Reporting;

use App\Ashby\Game\ReferenceTeams;
use App\Ashby\Reporting\ReportingViews;
use App\Models\Enrolment;
use App\Models\ReportingCall;
use App\Models\ReportingConnection;
use App\Models\Section;
use App\Models\TableMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Ashby\MakesAClass;
use Tests\TestCase;

/**
 * The read-only reporting connector, copied from Corporate Finance. Claude signs in with an administrator's
 * account (OAuth, PKCE), reads six views over MCP, and gets operational facts only.
 */
class ReportingConnectorTest extends TestCase
{
    use MakesAClass;
    use RefreshDatabase;

    private const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    private User $admin;

    private User $faculty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->administrator()->create(['email' => 'admin@example.com']);
        $this->faculty = User::factory()->faculty()->create();
    }

    public function test_claude_finds_the_sign_in_from_a_401(): void
    {
        $this->postJson('/mcp/reporting', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp/reporting').'", scope="reporting:read"');

        $this->getJson('/.well-known/oauth-protected-resource/mcp/reporting')->assertOk()
            ->assertJson(['resource' => url('/mcp/reporting'), 'authorization_servers' => [url('/')]]);
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJson([
            'issuer' => url('/'),
            'authorization_endpoint' => url('/oauth/reporting/authorize'),
            'token_endpoint' => url('/oauth/reporting/token'),
            'registration_endpoint' => url('/oauth/reporting/register'),
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'client_id_metadata_document_supported' => true,
        ]);
        $this->get('/mcp/reporting')->assertStatus(405);
    }

    public function test_an_admin_connects_and_claude_reads_every_view(): void
    {
        $tokens = $this->connect();

        $init = $this->mcp($tokens['access_token'], 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'claude-ai', 'version' => '1']])->assertOk();
        $this->assertSame('2025-06-18', $init->json('result.protocolVersion'));
        $this->assertSame('flexee-reporting', $init->json('result.serverInfo.name'));
        $this->withToken($tokens['access_token'])->postJson('/mcp/reporting', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);

        $tools = $this->mcp($tokens['access_token'], 'tools/list')->assertOk()->json('result.tools');
        $this->assertSame(['report_courses', 'report_quarters', 'report_closes', 'report_requests', 'report_ai_use', 'report_health'], array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint']);
            $this->assertStringContainsString('Columns:', $tool['description']);
        }

        $health = $this->mcp($tokens['access_token'], 'tools/call', ['name' => 'report_health', 'arguments' => (object) []])->assertOk();
        $row = $health->json('result.structuredContent.rows.0');
        $this->assertSame('future_chro', $row['sim']);
        $this->assertSame('v1', $row['contract']);
        $this->assertSame(0, $row['migrations_pending']);
        $this->assertFalse($row['scheduler_ok'], 'no heartbeat yet');
        $this->assertSame(1, $row['connections_active']);
        $this->assertSame(['rows' => [$row]], json_decode($health->json('result.content.0.text'), true));

        $this->assertSame(-32602, $this->mcp($tokens['access_token'], 'tools/call', ['name' => 'drop_tables'])->json('error.code'));

        // Logged without the rows.
        $call = ReportingCall::query()->where('tool', 'report_health')->firstOrFail();
        $this->assertSame(['ok', 1], [$call->status, $call->rows]);
    }

    public function test_the_views_hold_operational_facts_only_and_leave_out_sample_classes(): void
    {
        $sample = Section::query()->create(['name' => 'Sample class (rapid)', 'future' => 'rapid', 'open_through' => 2031, 'closed_through' => 2031]);
        ['section' => $section, 'team' => $team, 'seats' => $seats] = $this->makeClass('complement', 2029);
        $section->update(['name' => 'Tuesday workshop', 'closed_through' => 2027]);
        foreach ($seats as $user) {
            Enrolment::query()->create(['section_id' => $section->id, 'user_id' => $user->id, 'status' => Enrolment::ACTIVE]);
        }
        Enrolment::query()->where('user_id', $seats['governance']->id)->update(['status' => Enrolment::PAUSED]);
        $this->actingAs($seats['chro'])->post('/play/2027/confirm', ReferenceTeams::cards('builder')[2027])->assertRedirect();
        $this->post('/play/2027/paragraph', ['paragraph' => 'Our private plan for the graduates.']);
        TableMessage::query()->create(['team_id' => $team->id, 'user_id' => $seats['chro']->id, 'year' => 2027, 'person' => 'claire', 'question' => 'A private question?', 'answer' => 'A private answer.', 'source' => 'ai', 'input_tokens' => 1000000, 'output_tokens' => 100000, 'model' => 'claude']);
        TableMessage::query()->create(['team_id' => $team->id, 'user_id' => $seats['chro']->id, 'year' => 2027, 'person' => 'claire', 'question' => 'Another?', 'answer' => 'Fixed.', 'source' => 'fixed', 'dropped_reason' => 'Banned word.']);
        Cache::forever(ReportingViews::SCHEDULER_KEY, now()->subMinute()->getTimestamp());

        $tokens = $this->connect();
        $all = [];
        foreach (array_keys(ReportingViews::tools()) as $tool) {
            $all[$tool] = $this->mcp($tokens['access_token'], 'tools/call', ['name' => $tool])->assertOk()->json('result.structuredContent.rows');
        }

        $this->assertCount(1, $all['report_courses']);
        $course = $all['report_courses'][0];
        $this->assertSame(['Tuesday workshop', null, 'future_chro_slice', 'running', 2029, 3, 1, 4, 3], [$course['course'], $course['institution'], $course['product'], $course['status'], $course['quarter_now'], $course['quarters'], $course['firms'], $course['students'], $course['students_confirmed']]);
        $y27 = collect($all['report_quarters'])->firstWhere('quarter', 2027);
        $this->assertSame(['closed', false, 1, 0, 1], [$y27['state'], $y27['warm_up'], $y27['firms_submitted'], $y27['firms_carried_forward'], $y27['explanations_submitted']]);
        $this->assertSame('open', collect($all['report_quarters'])->firstWhere('quarter', 2029)['state']);
        $this->assertSame('locked', collect($all['report_quarters'])->firstWhere('quarter', 2031)['state']);
        $this->assertCount(1, $all['report_closes']);
        $this->assertSame([], $all['report_requests']);
        $ai = collect($all['report_ai_use'])->keyBy('feature');
        $this->assertSame([2, 1, 1, 1000000, 100000, 4.5], [$ai['table']['calls'], $ai['table']['failures'], $ai['table']['sentences_dropped'], $ai['table']['input_tokens'], $ai['table']['output_tokens'], $ai['table']['cost_usd']]);
        $health = $all['report_health'][0];
        $this->assertTrue($health['scheduler_ok']);
        $this->assertSame([1, 0, 0, 4.5, false], [$health['courses_running'], $health['requests_waiting'], $health['closes_failed'], $health['ai_spent_this_month_usd'], $health['mail_enabled']]);

        $json = (string) json_encode($all);
        $this->assertStringNotContainsString('Sample class', $json);
        $this->assertStringNotContainsString('"course_id":'.$sample->id.',', $json);
        foreach (['private plan', 'private question', 'private answer', 'Banned word', 'Team 1', 'profit'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        foreach (User::query()->get() as $user) {
            $this->assertStringNotContainsString($user->email, $json);
            $this->assertStringNotContainsString('"'.$user->name.'"', $json);
        }
    }

    public function test_pkce_codes_and_return_addresses_are_checked(): void
    {
        $client = $this->register();
        // Only Claude's return addresses can be registered.
        $this->postJson('/oauth/reporting/register', ['redirect_uris' => ['https://evil.example/callback']])->assertStatus(400)->assertJson(['error' => 'invalid_redirect_uri']);

        [$query, $verifier] = $this->authorizeQuery($client);
        $this->actingAs($this->admin)->get('/oauth/reporting/authorize?'.http_build_query(['redirect_uri' => 'https://claude.ai/elsewhere'] + $query))->assertStatus(400)->assertSee('return address that wasn', false);
        $this->get('/oauth/reporting/authorize?'.http_build_query(['client_id' => 'flexee-unknown'] + $query))->assertStatus(400);
        $plain = ['code_challenge_method' => 'plain'] + $query;
        $this->assertStringContainsString('error=invalid_request', $this->get('/oauth/reporting/authorize?'.http_build_query($plain))->headers->get('Location'));

        $code = $this->approve($query);
        $this->post('/oauth/reporting/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client, 'redirect_uri' => self::CALLBACK, 'code_verifier' => Str::random(64)])
            ->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
        // The failed attempt used the code up.
        $this->post('/oauth/reporting/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client, 'redirect_uri' => self::CALLBACK, 'code_verifier' => $verifier])
            ->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
        $this->assertSame(0, ReportingConnection::query()->count());

        // Cancel sends Claude away with nothing.
        $this->assertStringContainsString('error=access_denied', $this->post('/oauth/reporting/authorize', $query + ['decision' => 'deny'])->headers->get('Location'));
    }

    public function test_only_admins_can_connect(): void
    {
        $client = $this->register();
        [$query] = $this->authorizeQuery($client);

        // A guest signs in first, and an admin comes back to the consent page.
        $this->get('/oauth/reporting/authorize?'.http_build_query($query))->assertRedirect(route('login'));
        $sorted = $query;
        ksort($sorted);
        $this->post(route('login.store'), ['email' => 'admin@example.com', 'password' => 'password'])
            ->assertRedirect('/oauth/reporting/authorize?'.http_build_query($sorted));
        auth()->logout();

        $faculty = $this->faculty;
        $this->actingAs($faculty)->get('/oauth/reporting/authorize?'.http_build_query($query))->assertForbidden()->assertSee('Only One Smarter admins can connect');
        $this->assertStringContainsString('error=access_denied', $this->actingAs($faculty)->post('/oauth/reporting/authorize', $query + ['decision' => 'approve'])->headers->get('Location'));

        // An admin's connection stops working the moment they stop being an admin.
        $tokens = $this->connect();
        $this->mcp($tokens['access_token'], 'ping')->assertOk();
        $this->admin->update(['role' => User::ROLE_FACULTY]);
        $this->mcp($tokens['access_token'], 'ping')->assertUnauthorized();
    }

    public function test_refresh_tokens_rotate_and_revoke_ends_the_connection(): void
    {
        $tokens = $this->connect();
        $client = ReportingConnection::query()->firstOrFail()->client->client_id;

        $next = $this->post('/oauth/reporting/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client])->assertOk()->json();
        $this->assertNotSame($tokens['refresh_token'], $next['refresh_token']);
        $this->post('/oauth/reporting/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
        $this->mcp($tokens['access_token'], 'ping')->assertUnauthorized();
        $this->mcp($next['access_token'], 'ping')->assertOk();

        $this->travel(61)->minutes();
        $this->mcp($next['access_token'], 'ping')->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->travelBack();

        $connection = ReportingConnection::query()->firstOrFail();
        $this->actingAs($this->admin)->get(route('admin.home'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporting.url', url('/mcp/reporting'))
            ->where('reporting.connections.0.active', true));
        $this->actingAs($this->faculty)->post(route('admin.reporting.revoke', ['connection' => $connection->id]))->assertForbidden();
        $this->actingAs($this->admin)->post(route('admin.reporting.revoke', ['connection' => $connection->id]))->assertSessionHasNoErrors();
        $this->post('/oauth/reporting/token', ['grant_type' => 'refresh_token', 'refresh_token' => $next['refresh_token'], 'client_id' => $client])->assertStatus(400);
        $this->assertNotNull($connection->fresh()->revoked_at);
    }

    public function test_claudes_published_identity_is_read_from_its_metadata_document(): void
    {
        $id = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
        Http::fake([$id => Http::response(['client_id' => $id, 'client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK, 'https://evil.example/cb']])]);

        [$query, $verifier] = $this->authorizeQuery($id);
        $this->actingAs($this->admin)->get('/oauth/reporting/authorize?'.http_build_query($query))->assertOk()->assertSee('Claude is asking')->assertSee('claude.ai');
        $code = $this->approve($query);
        $this->post('/oauth/reporting/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $id, 'redirect_uri' => self::CALLBACK, 'code_verifier' => $verifier])->assertOk();
        $this->assertSame([self::CALLBACK], ReportingConnection::query()->firstOrFail()->client->redirect_uris, 'only Claude\'s return addresses are kept');

        // A metadata document on any other host isn't read.
        [$other] = $this->authorizeQuery('https://evil.example/client.json');
        $this->get('/oauth/reporting/authorize?'.http_build_query($other))->assertStatus(400);
        Http::assertSentCount(1);
    }

    public function test_the_admin_page_shows_what_claude_would_get_and_calls_are_rate_limited(): void
    {
        $this->actingAs($this->admin)->get(route('admin'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reporting.enabled', true)
            ->where('reporting.connections', [])
            ->where('reporting.url', url('/mcp/reporting'))
            ->where('words.faculty.admin.reporting.none', 'No one has connected yet.'));

        config(['ashby.reporting.calls_per_minute' => 2]);
        $tokens = $this->connect();
        $this->mcp($tokens['access_token'], 'ping')->assertOk();
        $this->mcp($tokens['access_token'], 'ping')->assertOk();
        $this->mcp($tokens['access_token'], 'ping')->assertStatus(429);

        config(['ashby.reporting.enabled' => false]);
        $this->getJson('/.well-known/oauth-authorization-server')->assertNotFound();
    }

    private function register(): string
    {
        return $this->postJson('/oauth/reporting/register', ['client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK], 'token_endpoint_auth_method' => 'none'])
            ->assertCreated()->json('client_id');
    }

    /** @return array{0: array<string, string>, 1: string} */
    private function authorizeQuery(string $client): array
    {
        $verifier = Str::random(64);

        return [[
            'response_type' => 'code',
            'client_id' => $client,
            'redirect_uri' => self::CALLBACK,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'state' => 'st-'.Str::random(8),
            'scope' => 'reporting:read',
            'resource' => url('/mcp/reporting'),
        ], $verifier];
    }

    /** @param  array<string, string>  $query */
    private function approve(array $query): string
    {
        $location = $this->actingAs($this->admin)->post('/oauth/reporting/authorize', $query + ['decision' => 'approve'])->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::CALLBACK.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $back);
        $this->assertSame([$query['state'], url('/')], [$back['state'], $back['iss']]);

        return $back['code'];
    }

    /** @return array<string, mixed> */
    private function connect(): array
    {
        $client = $this->register();
        [$query, $verifier] = $this->authorizeQuery($client);
        $this->actingAs($this->admin)->get('/oauth/reporting/authorize?'.http_build_query($query))->assertOk()->assertSee('Allow read-only access');
        $code = $this->approve($query);

        return $this->post('/oauth/reporting/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client, 'redirect_uri' => self::CALLBACK, 'code_verifier' => $verifier])
            ->assertOk()->assertJson(['token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'reporting:read'])->assertHeader('Cache-Control')->json();
    }

    /** @param  array<string, mixed>  $params */
    private function mcp(string $token, string $method, array $params = []): TestResponse
    {
        static $id = 0;

        return $this->withToken($token)->postJson('/mcp/reporting', ['jsonrpc' => '2.0', 'id' => ++$id, 'method' => $method, 'params' => (object) $params]);
    }
}
