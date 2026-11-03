<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\MobileNotificationToken;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ContractSentNotification;
use App\Notifications\FcmChannel;
use App\Services\FirebaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every notification carries Arabic and English text, and a push goes out in
 * the language of the device it is sent to.
 */
class NotificationLocaleTest extends TestCase
{
    use RefreshDatabase;

    /** Notification classes that do not produce an in-app/push text (mail only). */
    private const MAIL_ONLY = ['ResetPasswordNotification.php', 'FcmChannel.php', 'BaseNotification.php'];

    public function test_every_notification_has_english_text_next_to_the_arabic(): void
    {
        $checked = 0;
        foreach (glob(app_path('Notifications/*.php')) as $file) {
            if (in_array(basename($file), self::MAIL_ONLY, true)) {
                continue;
            }
            $src = file_get_contents($file);
            if (str_contains($src, 'function toDatabase')) {
                $this->assertStringContainsString("'title_en'", $src, basename($file) . ' toDatabase has no title_en');
                $this->assertStringContainsString("'message_en'", $src, basename($file) . ' toDatabase has no message_en');
            }
            if (str_contains($src, 'function toFcm')) {
                $this->assertStringContainsString("'body_en'", $src, basename($file) . ' toFcm has no body_en');
            }
            $checked++;
        }
        $this->assertGreaterThan(25, $checked);
    }

    public function test_a_push_goes_out_in_the_language_of_each_device(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $ws = Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $manager->id]);
        $contract = Contract::factory()->create(['workspace_id' => $ws->id, 'created_by' => $manager->id, 'title' => 'Villa']);

        foreach (['ar-token' => 'ar', 'en-token' => 'en'] as $token => $locale) {
            MobileNotificationToken::create([
                'token' => $token, 'tokenable_id' => $client->id, 'tokenable_type' => Client::class,
                'device_type' => 'android', 'locale' => $locale,
            ]);
        }
        // A token from before the column existed defaults to Arabic.
        MobileNotificationToken::create([
            'token' => 'old-token', 'tokenable_id' => $client->id, 'tokenable_type' => Client::class, 'device_type' => 'android',
        ]);

        $sent = [];
        $this->mock(FirebaseService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('sendMessage')->andReturnUsing(function (string $token, array $n) use (&$sent) {
                $sent[$token] = $n;

                return true;
            });
        });

        (new FcmChannel())->send($client, new ContractSentNotification($contract));

        $this->assertSame('عقد جديد', $sent['ar-token']['title']);
        $this->assertSame('عقد جديد', $sent['old-token']['title']);
        $this->assertSame('New contract', $sent['en-token']['title']);
        $this->assertSame('Contract Villa was sent', $sent['en-token']['body']);
    }

    public function test_registering_a_token_stores_its_language_and_an_old_app_keeps_it(): void
    {
        $client = Client::factory()->create();
        $token = $client->createToken('t')->plainTextToken;
        $this->app->make('auth')->forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/notifications/register-token', ['token' => 'dev1', 'device_type' => 'android', 'locale' => 'en'])
            ->assertOk();
        $this->assertSame('en', MobileNotificationToken::where('token', 'dev1')->value('locale'));

        $this->app->make('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/notifications/register-token', ['token' => 'dev1', 'device_type' => 'android'])
            ->assertOk();
        $this->assertSame('en', MobileNotificationToken::where('token', 'dev1')->value('locale'));

        $this->app->make('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/notifications/register-token', ['token' => 'dev1', 'device_type' => 'android', 'locale' => 'fr'])
            ->assertStatus(422);
    }
}
