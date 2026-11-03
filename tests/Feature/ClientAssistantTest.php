<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\AssistantQuestion;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\SubUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CLIENT_ASSISTANT_PLAN.md: the assistant answers from the client's own data,
 * read only, in the words the super admin set.
 */
class ClientAssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Client $client;
    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();

        config(['assistant.enabled' => true]);

        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'name' => 'Omar Khaled']);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id, 'contact_person' => 'Ahmed', 'client_type' => 'individual']);
        $this->ws = Workspace::factory()->create(['client_id' => $this->client->id, 'manager_id' => $this->manager->id, 'status' => 'active']);
    }

    private function contract(string $status = 'company_approved', float $value = 50000, string $title = 'Services 2026'): Contract
    {
        return Contract::create([
            'workspace_id' => $this->ws->id, 'title' => $title, 'status' => $status,
            'value' => $value, 'currency' => 'SAR',
        ]);
    }

    private function payment(string $status, float $amount, ?string $due = null): Payment
    {
        return Payment::factory()->create([
            'workspace_id' => $this->ws->id, 'client_id' => $this->client->id,
            'amount' => $amount, 'status' => $status, 'due_date' => $due,
        ]);
    }

    private function ask(?Client $as = null)
    {
        return $this->actingAs($as ?? $this->client, 'client')->getJson("/api/workspaces/{$this->ws->id}/assistant");
    }

    private function answer(array $json, string $key, string $lang = 'ar'): ?string
    {
        foreach ($json['questions'] as $q) {
            if ($q['key'] === $key) {
                return $q['answer'][$lang];
            }
        }

        return null;
    }

    public function test_it_is_off_until_the_switch_is_on(): void
    {
        config(['assistant.enabled' => false]);

        $this->ask()->assertOk()->assertExactJson(['enabled' => false]);
    }

    public function test_the_balance_matches_the_payments_page(): void
    {
        $this->contract('company_approved', 50000);
        $this->payment('approved', 37500);
        $this->payment('pending', 9999); // sent but not reviewed: not paid yet

        $json = $this->ask()->assertOk()->json();

        $this->assertStringContainsString('12,500.00 SAR', $this->answer($json, 'balance'));
        $this->assertStringContainsString('12,500.00 SAR', $this->answer($json, 'balance', 'en'));

        // The payments page reads the same figure.
        $page = $this->actingAs($this->client, 'client')->getJson("/api/workspaces/{$this->ws->id}/payments")->json('tax_summary');
        $this->assertEquals(50000, $page['grand_total']);

        $balance = collect($json['questions'])->firstWhere('key', 'balance');
        $this->assertEquals(['paid' => 37500.0, 'total' => 50000.0], $balance['progress']);
        $this->assertSame([['target' => 'payments']], $balance['actions']);
    }

    public function test_the_balance_includes_tax_for_a_business_client(): void
    {
        $this->client->update(['client_type' => 'business']);
        \App\Models\SystemSetting::setValue('corporate_tax_percentage', 10);
        $this->contract('company_approved', 1000);

        $json = $this->ask()->json();

        $this->assertStringContainsString('1,100.00 SAR', $this->answer($json, 'balance'));
    }

    public function test_balance_states_fully_paid_and_no_contract(): void
    {
        $this->assertStringContainsString('لسه مفيش عقد معتمد', $this->answer($this->ask()->json(), 'balance'));

        $this->contract('company_approved', 1000);
        $this->payment('approved', 1000);

        $this->assertStringContainsString('مفيش عليك أي مبلغ متبقي', $this->answer($this->ask()->json(), 'balance'));
    }

    public function test_an_overdue_installment_comes_before_a_scheduled_one(): void
    {
        $this->payment('scheduled', 2000, now()->addDays(10)->toDateString());
        $this->payment('overdue', 5000, '2026-10-01');

        $json = $this->ask()->json();

        $text = $this->answer($json, 'next_installment', 'en');
        $this->assertStringContainsString('overdue', $text);
        $this->assertStringContainsString('5,000.00 SAR', $text);
        $this->assertStringContainsString('1 Oct 2026', $text);
    }

    public function test_a_scheduled_installment_past_its_date_counts_as_overdue(): void
    {
        $this->payment('scheduled', 700, now()->subDays(3)->toDateString());

        $this->assertStringContainsString('متأخر', $this->answer($this->ask()->json(), 'next_installment'));
    }

    public function test_the_next_installment_when_nothing_is_due(): void
    {
        $this->assertStringContainsString('مفيش أقساط', $this->answer($this->ask()->json(), 'next_installment'));

        $this->payment('scheduled', 3000, now()->addDays(5)->toDateString());
        $this->assertStringContainsString('3,000.00', $this->answer($this->ask()->json(), 'next_installment'));
    }

    public function test_the_contract_status_uses_a_readable_label(): void
    {
        $this->assertStringContainsString('لسه مفيش عقد', $this->answer($this->ask()->json(), 'contract_status'));

        $this->contract('draft', 100, 'Hidden draft');
        $this->contract('sent', 100, 'Services 2026');

        $text = $this->answer($this->ask()->json(), 'contract_status', 'en');
        $this->assertStringContainsString('Services 2026', $text);
        $this->assertStringContainsString('waiting for your approval', $text);
        $this->assertStringNotContainsString('Hidden draft', $text);
    }

    public function test_the_next_meeting_is_the_nearest_scheduled_one_in_the_business_timezone(): void
    {
        $this->assertStringContainsString('مفيش اجتماعات', $this->answer($this->ask()->json(), 'next_meeting'));

        $make = fn (string $title, string $at, string $status) => Meeting::create([
            'workspace_id' => $this->ws->id, 'title' => $title, 'scheduled_at' => $at,
            'duration_minutes' => 30, 'status' => $status, 'created_by' => $this->manager->id,
        ]);
        $make('Far', '2030-02-01 08:00:00', 'scheduled');
        $make('Cancelled', '2030-01-05 08:00:00', 'cancelled');
        $make('Review', '2030-01-10 08:00:00', 'scheduled');

        $text = $this->answer($this->ask()->json(), 'next_meeting', 'en');

        $this->assertStringContainsString('"Review"', $text);
        $this->assertStringContainsString('10 Jan 2030', $text);
        $this->assertStringContainsString('10:00 AM', $text); // 08:00 UTC is 10:00 in Cairo
    }

    public function test_the_todo_lists_what_the_client_must_do_and_where_to_go(): void
    {
        $this->contract('sent', 100);
        Approval::factory()->create(['workspace_id' => $this->ws->id, 'status' => 'pending']);
        $this->payment('rejected', 800);

        $json = $this->ask()->json();
        $todo = collect($json['questions'])->firstWhere('key', 'todo');

        $this->assertStringContainsString('3', $todo['answer']['ar']);
        $this->assertStringContainsString('1. عقد وصلك', $todo['answer']['ar']);
        $this->assertStringContainsString('اترفضت', $todo['answer']['ar']);
        $this->assertEqualsCanonicalizing(
            [['target' => 'contracts'], ['target' => 'approvals'], ['target' => 'payments']],
            $todo['actions']
        );
    }

    public function test_the_todo_when_nothing_is_needed(): void
    {
        $this->assertStringContainsString('مفيش حاجة مطلوبة', $this->answer($this->ask()->json(), 'todo'));
    }

    public function test_contact_manager_names_the_manager(): void
    {
        $json = $this->ask()->json();

        $this->assertStringContainsString('Omar Khaled', $this->answer($json, 'contact_manager'));
        $this->assertSame([['target' => 'chat']], collect($json['questions'])->firstWhere('key', 'contact_manager')['actions']);
    }

    public function test_the_welcome_uses_the_client_name(): void
    {
        $json = $this->ask()->json();

        $this->assertSame('Shad assistant', $json['assistant']['name']['en']);
        $this->assertStringContainsString('Ahmed', $json['assistant']['welcome']['ar']);
    }

    public function test_the_questions_follow_the_wording_and_order_the_super_admin_set(): void
    {
        $this->contract('company_approved', 1000);
        AssistantQuestion::where('key', 'balance')->update([
            'answers' => ['default' => ['ar' => 'باقي {remaining} {currency} بس', 'en' => 'Only {remaining} {currency} left']],
            'sort_order' => 0,
        ]);
        AssistantQuestion::where('key', 'todo')->update(['is_enabled' => false]);
        AssistantQuestion::create([
            'kind' => 'faq', 'question' => ['ar' => 'مواعيد العمل؟', 'en' => 'Hours?'],
            'answers' => ['default' => ['ar' => 'من 9 لـ 5', 'en' => '9 to 5']], 'action_target' => 'chat', 'sort_order' => 3,
        ]);

        $json = $this->ask()->json();
        $keys = array_map(fn ($q) => $q['key'] ?? 'faq', $json['questions']);

        $this->assertSame('balance', $keys[0]);
        $this->assertNotContains('todo', $keys);
        $this->assertSame('باقي 1,000.00 SAR بس', $this->answer($json, 'balance'));

        $faq = collect($json['questions'])->firstWhere('kind', 'faq');
        $this->assertSame('من 9 لـ 5', $faq['answer']['ar']);
        $this->assertSame([['target' => 'chat']], $faq['actions']);
    }

    public function test_a_missing_row_falls_back_to_the_default_wording(): void
    {
        AssistantQuestion::where('key', 'next_meeting')->delete();

        $this->assertStringContainsString('مفيش اجتماعات', $this->answer($this->ask()->json(), 'next_meeting'));
    }

    public function test_a_sub_user_only_gets_the_questions_its_permissions_allow(): void
    {
        $this->contract('company_approved', 1000);
        $sub = SubUser::factory()->create(['client_id' => $this->client->id, 'permissions' => ['can_view_contracts' => true]]);

        $json = $this->actingAs($sub, 'sub_user')->getJson("/api/workspaces/{$this->ws->id}/assistant")->assertOk()->json();
        $keys = array_column($json['questions'], 'key');

        $this->assertContains('contract_status', $keys);
        $this->assertContains('todo', $keys);
        $this->assertNotContains('balance', $keys);
        $this->assertNotContains('next_installment', $keys);
        $this->assertNotContains('next_meeting', $keys);
        $this->assertNotContains('contact_manager', $keys);
    }

    public function test_a_sub_user_without_payment_access_never_sees_payment_items_in_the_todo(): void
    {
        $this->payment('rejected', 800);
        $sub = SubUser::factory()->create(['client_id' => $this->client->id, 'permissions' => ['can_chat' => true]]);

        $json = $this->actingAs($sub, 'sub_user')->getJson("/api/workspaces/{$this->ws->id}/assistant")->json();

        $this->assertStringNotContainsString('اترفضت', $this->answer($json, 'todo'));
    }

    public function test_another_clients_workspace_is_refused(): void
    {
        $other = Client::factory()->create(['manager_id' => $this->manager->id]);

        $this->actingAs($other, 'client')
            ->getJson("/api/workspaces/{$this->ws->id}/assistant")
            ->assertStatus(403);
    }

    public function test_staff_are_refused(): void
    {
        Sanctum::actingAs($this->manager);

        $this->getJson("/api/workspaces/{$this->ws->id}/assistant")->assertStatus(403);

        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/workspaces/{$this->ws->id}/assistant")->assertStatus(403);
    }

    public function test_the_payments_response_is_unchanged(): void
    {
        $this->contract('company_approved', 1000);

        $res = $this->actingAs($this->client, 'client')->getJson("/api/workspaces/{$this->ws->id}/payments")->assertOk();

        $res->assertJsonStructure(['payments', 'available_methods', 'client_type', 'tax_summary' => ['contracts_total', 'tax_percentage', 'tax_amount', 'grand_total']]);
        $this->assertEquals(1000, $res->json('tax_summary.contracts_total'));
        $this->assertEquals(0, $res->json('tax_summary.tax_percentage'));
        $this->assertEquals(0, $res->json('tax_summary.tax_amount'));
        $this->assertEquals(1000, $res->json('tax_summary.grand_total'));
    }
}
