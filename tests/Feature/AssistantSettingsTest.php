<?php

namespace Tests\Feature;

use App\Models\AssistantQuestion;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CLIENT_ASSISTANT_PLAN.md ٥.٥ — the super admin's page for the assistant.
 */
class AssistantSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        Sanctum::actingAs($this->admin);
    }

    private function row(string $key): AssistantQuestion
    {
        return AssistantQuestion::where('key', $key)->firstOrFail();
    }

    private function faq(array $over = []): array
    {
        return array_merge([
            'question' => ['ar' => 'مواعيد العمل؟', 'en' => 'Working hours?'],
            'answer' => ['ar' => 'من 9 لـ 5', 'en' => '9 to 5'],
            'action_target' => null,
        ], $over);
    }

    public function test_the_migration_seeds_the_six_data_questions_and_the_settings_row(): void
    {
        $this->assertSame(6, AssistantQuestion::where('kind', 'data')->count());
        $this->assertSame(1, AssistantQuestion::where('kind', 'settings')->count());
        $this->assertSame(0, AssistantQuestion::where('kind', 'faq')->count());
    }

    public function test_only_the_super_admin_can_use_it(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $accountant = User::factory()->create(['role' => User::ROLE_ACCOUNTANT, 'is_active' => true]);
        $id = $this->row('balance')->id;

        foreach ([$manager, $accountant] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/assistant/questions')->assertStatus(403);
            $this->putJson("/api/assistant/questions/{$id}", ['is_enabled' => false])->assertStatus(403);
            $this->postJson('/api/assistant/questions', $this->faq())->assertStatus(403);
            $this->deleteJson("/api/assistant/questions/{$id}")->assertStatus(403);
            $this->postJson('/api/assistant/preview', ['key' => 'balance', 'state' => 'default'])->assertStatus(403);
        }

        $this->assertTrue($this->row('balance')->is_enabled);
    }

    public function test_the_list_has_the_states_with_their_placeholders_and_defaults(): void
    {
        $json = $this->getJson('/api/assistant/questions')->assertOk()->json();

        $this->assertCount(6, $json['questions']);
        $balance = collect($json['questions'])->firstWhere('key', 'balance');
        $default = collect($balance['states'])->firstWhere('state', 'default');

        $this->assertSame(['remaining', 'currency'], $default['required']);
        $this->assertContains('paid', $default['allowed']);
        $this->assertSame($default['ar'], $default['default']['ar']);
        $this->assertSame('Shad assistant', $json['settings']['name']['en']);
        $this->assertSame(20, $json['limits']['faq_max']);
    }

    public function test_it_edits_the_wording_and_the_client_sees_it_at_once(): void
    {
        $id = $this->row('balance')->id;

        $this->putJson("/api/assistant/questions/{$id}", [
            'question' => ['ar' => 'كام باقي؟', 'en' => 'What is left?'],
            'answers' => ['default' => ['ar' => 'باقي {remaining} {currency}', 'en' => '{remaining} {currency} left']],
        ])->assertOk();

        $row = $this->row('balance');
        $this->assertSame('كام باقي؟', $row->question['ar']);
        $this->assertSame('{remaining} {currency} left', $row->answers['default']['en']);
        // The other states keep their wording.
        $this->assertNotEmpty($row->answers['fully_paid']['ar']);
    }

    public function test_a_required_placeholder_cannot_be_removed(): void
    {
        $id = $this->row('balance')->id;
        $before = $this->row('balance')->answers;

        $this->putJson("/api/assistant/questions/{$id}", [
            'answers' => ['default' => ['ar' => 'باقي {currency} بس', 'en' => '{remaining} {currency} left']],
        ])->assertStatus(422)->assertJsonValidationErrors(['answers.default.ar']);

        $this->assertSame($before, $this->row('balance')->answers);
    }

    public function test_an_unknown_placeholder_is_refused_with_the_allowed_list(): void
    {
        $id = $this->row('balance')->id;

        $res = $this->putJson("/api/assistant/questions/{$id}", [
            'answers' => ['default' => ['ar' => 'باقي {remainig} {currency}', 'en' => '{remaining} {currency} left']],
        ])->assertStatus(422);

        $this->assertStringContainsString('{remainig}', $res->json('errors')['answers.default.ar'][0]);
        $this->assertStringContainsString('{remaining}', $res->json('errors')['answers.default.ar'][0]);
    }

    public function test_both_languages_are_required(): void
    {
        $id = $this->row('balance')->id;

        $this->putJson("/api/assistant/questions/{$id}", [
            'answers' => ['default' => ['ar' => 'باقي {remaining} {currency}', 'en' => '']],
        ])->assertStatus(422)->assertJsonValidationErrors(['answers.default.en']);
    }

    public function test_the_answer_length_is_limited(): void
    {
        $id = $this->row('balance')->id;

        $this->putJson("/api/assistant/questions/{$id}", [
            'answers' => ['default' => ['ar' => str_repeat('ا', 301) . ' {remaining} {currency}', 'en' => '{remaining} {currency}']],
        ])->assertStatus(422);
    }

    public function test_an_unknown_state_is_refused(): void
    {
        $id = $this->row('balance')->id;

        $this->putJson("/api/assistant/questions/{$id}", [
            'answers' => ['nonsense' => ['ar' => 'x', 'en' => 'x']],
        ])->assertStatus(422);
    }

    public function test_a_question_can_be_hidden_and_shown(): void
    {
        $id = $this->row('next_meeting')->id;

        $this->putJson("/api/assistant/questions/{$id}", ['is_enabled' => false])->assertOk();
        $this->assertFalse($this->row('next_meeting')->is_enabled);

        $this->putJson("/api/assistant/questions/{$id}", ['is_enabled' => true])->assertOk();
        $this->assertTrue($this->row('next_meeting')->is_enabled);
    }

    public function test_reset_restores_the_default_wording(): void
    {
        $row = $this->row('balance');
        $original = $row->answers;
        $this->putJson("/api/assistant/questions/{$row->id}", [
            'question' => ['ar' => 'x', 'en' => 'y'],
            'answers' => ['default' => ['ar' => 'باقي {remaining} {currency}', 'en' => '{remaining} {currency}']],
        ])->assertOk();

        $this->postJson("/api/assistant/questions/{$row->id}/reset")->assertOk();

        $fresh = $this->row('balance');
        $this->assertSame($original, $fresh->answers);
        $this->assertSame('فاضل عليّا كام؟', $fresh->question['ar']);
    }

    public function test_a_data_question_cannot_be_deleted_but_a_faq_can(): void
    {
        $this->deleteJson('/api/assistant/questions/' . $this->row('balance')->id)->assertStatus(422);
        $this->assertNotNull(AssistantQuestion::where('key', 'balance')->first());

        $id = $this->postJson('/api/assistant/questions', $this->faq())->assertCreated()->json('faq.id');
        $this->deleteJson("/api/assistant/questions/{$id}")->assertOk();
        $this->assertNull(AssistantQuestion::find($id));
    }

    public function test_reset_only_applies_to_data_questions(): void
    {
        $id = $this->postJson('/api/assistant/questions', $this->faq())->json('faq.id');

        $this->postJson("/api/assistant/questions/{$id}/reset")->assertStatus(422);
    }

    public function test_a_faq_is_created_edited_and_validated(): void
    {
        $id = $this->postJson('/api/assistant/questions', $this->faq(['action_target' => 'chat']))
            ->assertCreated()->assertJsonPath('faq.action_target', 'chat')->json('faq.id');

        $this->putJson("/api/assistant/questions/{$id}", ['answer' => ['ar' => 'من 10 لـ 6', 'en' => '10 to 6']])->assertOk();
        $this->assertSame('من 10 لـ 6', AssistantQuestion::find($id)->answers['default']['ar']);

        // A target that is not a tab of the workspace, and placeholders in a fixed answer.
        $this->postJson('/api/assistant/questions', $this->faq(['action_target' => 'https://evil.example']))->assertStatus(422);
        $this->postJson('/api/assistant/questions', $this->faq(['answer' => ['ar' => 'باقي {remaining}', 'en' => 'x']]))->assertStatus(422);
        $this->postJson('/api/assistant/questions', $this->faq(['question' => ['ar' => '', 'en' => 'x']]))->assertStatus(422);
    }

    public function test_there_are_at_most_twenty_faqs(): void
    {
        for ($i = 0; $i < 20; $i++) {
            AssistantQuestion::create(['kind' => 'faq', 'question' => ['ar' => "س{$i}", 'en' => "q{$i}"], 'answers' => ['default' => ['ar' => 'ج', 'en' => 'a']], 'sort_order' => 10 + $i]);
        }

        $this->postJson('/api/assistant/questions', $this->faq())->assertStatus(422);
    }

    public function test_reorder_sets_the_order(): void
    {
        $ids = AssistantQuestion::whereIn('kind', ['data', 'faq'])->orderBy('sort_order')->pluck('id')->reverse()->values()->all();

        $this->postJson('/api/assistant/questions/reorder', ['ids' => $ids])->assertOk();

        $this->assertSame($ids, AssistantQuestion::whereIn('kind', ['data', 'faq'])->orderBy('sort_order')->pluck('id')->all());
        $this->postJson('/api/assistant/questions/reorder', ['ids' => [999999]])->assertStatus(422);
    }

    public function test_the_name_and_welcome_are_editable_and_checked(): void
    {
        $id = AssistantQuestion::where('kind', 'settings')->value('id');

        $this->putJson("/api/assistant/questions/{$id}", [
            'name' => ['ar' => 'شاد بوت', 'en' => 'Shad bot'],
            'welcome' => ['ar' => 'يا {name}', 'en' => 'Hey {name}'],
        ])->assertOk();

        $row = AssistantQuestion::find($id);
        $this->assertSame('Shad bot', $row->question['en']);
        $this->assertSame('يا {name}', $row->answers['welcome']['ar']);

        $this->putJson("/api/assistant/questions/{$id}", [
            'welcome' => ['ar' => 'يا {nmae}', 'en' => 'Hey {name}'],
        ])->assertStatus(422)->assertJsonValidationErrors(['welcome.ar']);
    }

    public function test_the_preview_fills_sample_numbers_and_reports_problems_without_saving(): void
    {
        $ok = $this->postJson('/api/assistant/preview', [
            'key' => 'balance', 'state' => 'default',
            'ar' => 'باقي {remaining} {currency}', 'en' => '{remaining} {currency} left',
        ])->assertOk()->json();

        $this->assertSame('باقي 12,500.00 SAR', $ok['ar']['text']);
        $this->assertNull($ok['ar']['error']);

        $bad = $this->postJson('/api/assistant/preview', [
            'key' => 'balance', 'state' => 'default', 'ar' => 'باقي {currency}', 'en' => '',
        ])->assertOk()->json();

        $this->assertStringContainsString('{remaining}', $bad['ar']['error']);
        $this->assertSame('الرد مطلوب.', $bad['en']['error']);
        $this->assertNotSame('x', $this->row('balance')->answers['default']['ar']);

        $this->postJson('/api/assistant/preview', ['key' => 'balance', 'state' => 'nope', 'ar' => 'x', 'en' => 'x'])->assertStatus(422);
    }

    public function test_every_change_is_in_the_audit_log_with_before_and_after(): void
    {
        $id = $this->row('balance')->id;
        $this->putJson("/api/assistant/questions/{$id}", ['is_enabled' => false])->assertOk();
        $faq = $this->postJson('/api/assistant/questions', $this->faq())->json('faq.id');
        $this->deleteJson("/api/assistant/questions/{$faq}")->assertOk();

        $actions = AuditLog::pluck('action')->all();
        $this->assertContains('assistant.question_updated', $actions);
        $this->assertContains('assistant.faq_created', $actions);
        $this->assertContains('assistant.faq_deleted', $actions);

        $update = AuditLog::where('action', 'assistant.question_updated')->first();
        $this->assertTrue($update->metadata['before']['is_enabled']);
        $this->assertFalse($update->metadata['after']['is_enabled']);
        $this->assertSame($this->admin->id, $update->user_id);
    }

    public function test_a_deleted_fixed_row_is_recreated_instead_of_leaving_a_hole(): void
    {
        AssistantQuestion::where('key', 'balance')->delete();

        $json = $this->getJson('/api/assistant/questions')->assertOk()->json();

        $this->assertCount(6, $json['questions']);
        $this->assertNotNull(AssistantQuestion::where('key', 'balance')->first());
    }
}
