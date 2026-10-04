<?php

namespace Tests\Feature;

use App\Mail\SupportRequestMail;
use App\Models\Client;
use App\Models\SubUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * POST /api/support — public contact form (App Store Support URL).
 */
class SupportFormTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Visitor',
            'email' => 'visitor@example.com',
            'subject' => 'Help',
            'message' => 'I need help with my account please.',
        ], $over);
    }

    public function test_unknown_visitor_message_goes_to_support_only(): void
    {
        Mail::fake();
        config(['support.email' => 'support@test.com']);

        $this->postJson('/api/support', $this->payload())->assertOk();

        Mail::assertSent(SupportRequestMail::class, function ($m) {
            return $m->hasTo('support@test.com') && empty($m->cc) && $m->hasReplyTo('visitor@example.com');
        });
    }

    public function test_known_client_copies_their_account_manager(): void
    {
        Mail::fake();
        config(['support.email' => 'support@test.com']);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'is_active' => true, 'official_email' => 'am@company.com']);
        Client::factory()->create(['manager_id' => $manager->id, 'email' => 'Client@Test.com']);

        $this->postJson('/api/support', $this->payload(['email' => 'client@test.com']))->assertOk();

        Mail::assertSent(SupportRequestMail::class, fn ($m) => $m->hasTo('support@test.com') && $m->hasCc('am@company.com'));
    }

    public function test_sub_user_copies_the_parent_clients_manager(): void
    {
        Mail::fake();
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'is_active' => true]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        SubUser::factory()->create(['client_id' => $client->id, 'email' => 'sub@test.com']);

        $this->postJson('/api/support', $this->payload(['email' => 'sub@test.com']))->assertOk();

        Mail::assertSent(SupportRequestMail::class, fn ($m) => $m->hasCc($manager->email));
    }

    public function test_inactive_manager_is_not_copied(): void
    {
        Mail::fake();
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'is_active' => false]);
        Client::factory()->create(['manager_id' => $manager->id, 'email' => 'c@test.com']);

        $this->postJson('/api/support', $this->payload(['email' => 'c@test.com']))->assertOk();

        Mail::assertSent(SupportRequestMail::class, fn ($m) => empty($m->cc));
    }

    public function test_response_is_identical_for_known_and_unknown_senders(): void
    {
        Mail::fake();
        Client::factory()->create(['email' => 'known@test.com']);

        $a = $this->postJson('/api/support', $this->payload(['email' => 'known@test.com']));
        $b = $this->postJson('/api/support', $this->payload(['email' => 'nobody@test.com']));

        $this->assertSame($a->getContent(), $b->getContent());
    }

    public function test_validation_rejects_bad_input(): void
    {
        Mail::fake();

        $this->postJson('/api/support', $this->payload(['email' => 'nope']))->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/support', $this->payload(['message' => 'short']))->assertStatus(422)->assertJsonValidationErrors('message');
        $this->postJson('/api/support', $this->payload(['name' => '']))->assertStatus(422)->assertJsonValidationErrors('name');

        Mail::assertNothingSent();
    }

    public function test_filled_honeypot_is_rejected(): void
    {
        Mail::fake();

        $this->postJson('/api/support', $this->payload(['website' => 'http://spam']))->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_it_is_rate_limited(): void
    {
        Mail::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/support', $this->payload())->assertOk();
        }
        $this->postJson('/api/support', $this->payload())->assertStatus(429);
    }

    public function test_mail_failure_returns_500_not_success(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->postJson('/api/support', $this->payload())->assertStatus(500)->assertJsonStructure(['message']);
    }
}
