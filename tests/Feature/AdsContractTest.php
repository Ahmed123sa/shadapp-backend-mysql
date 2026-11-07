<?php

namespace Tests\Feature;

use App\Mail\ContractSentMail;
use App\Models\Client;
use App\Models\Contract;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceTaxSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ads contracts: the agency's management fee is a share of the contract value
 * (inside it, not on top). It only splits the value for display, so the value,
 * the tax and the total must come out exactly as they do for a normal contract.
 */
class AdsContractTest extends TestCase
{
    use RefreshDatabase;

    private User $am;
    private Client $client;
    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::setValue('corporate_tax_percentage', 14);
        $this->am = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create([
            'manager_id' => $this->am->id,
            'client_type' => 'business',
            'tax_percentage' => 14,
        ]);
        $this->ws = Workspace::factory()->create(['client_id' => $this->client->id, 'manager_id' => $this->am->id, 'status' => 'inactive']);
        Sanctum::actingAs($this->am);
    }

    private function create(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/workspaces/{$this->ws->id}/contracts", array_merge([
            'title' => 'ad cc',
            'value' => 10000,
        ], $extra));
    }

    private function contract(string $status, array $extra = []): Contract
    {
        return Contract::factory()->create(array_merge([
            'workspace_id' => $this->ws->id,
            'created_by' => $this->am->id,
            'status' => $status,
            'value' => 10000,
        ], $extra))->fresh();
    }

    public function test_an_ads_contract_splits_the_value_into_the_fee_and_the_budget(): void
    {
        $json = $this->create(['ads_percentage' => 10])->assertCreated()->json('contract');

        $this->assertEquals(10, $json['ads_percentage']);
        $this->assertEquals(1000, $json['ads_amount']);
        $this->assertEquals(9000, $json['ads_budget']);
        $this->assertEquals(10000, $json['value']);
    }

    public function test_a_normal_contract_has_no_ads_figures(): void
    {
        $json = $this->create()->assertCreated()->json('contract');

        $this->assertNull($json['ads_percentage']);
        $this->assertNull($json['ads_amount']);
        $this->assertNull($json['ads_budget']);
    }

    public function test_an_extra_contract_can_be_an_ads_contract_too(): void
    {
        $this->ws->update(['status' => 'active']);

        $json = $this->create(['ads_percentage' => 20])->assertCreated()->json('contract');

        $this->assertSame('additional', $json['contract_type']);
        $this->assertEquals(2000, $json['ads_amount']);
        $this->assertEquals(8000, $json['ads_budget']);
    }

    public function test_the_percentage_must_be_between_the_limits(): void
    {
        $this->create(['ads_percentage' => 0])->assertStatus(422);
        $this->create(['ads_percentage' => 101])->assertStatus(422);
        $this->create(['ads_percentage' => 'abc'])->assertStatus(422);
        $this->create(['ads_percentage' => 100])->assertCreated();
        $this->create(['ads_percentage' => 0.01])->assertCreated();
    }

    public function test_the_fee_is_rounded_to_two_decimals(): void
    {
        $json = $this->create(['value' => 1000.50, 'ads_percentage' => 10])->assertCreated()->json('contract');

        $this->assertEquals(100.05, $json['ads_amount']);
        $this->assertEquals(900.45, $json['ads_budget']);
    }

    public function test_a_draft_can_be_turned_into_an_ads_contract_and_back(): void
    {
        $c = $this->contract('draft');

        $this->putJson("/api/contracts/{$c->id}", ['ads_percentage' => 15])->assertOk();
        $this->assertEquals(15, $c->fresh()->ads_percentage);
        $this->assertEquals(1500, $c->fresh()->ads_amount);

        $this->putJson("/api/contracts/{$c->id}", ['ads_percentage' => null])->assertOk();
        $this->assertNull($c->fresh()->ads_percentage);
        $this->assertNull($c->fresh()->ads_amount);
    }

    public function test_an_update_that_does_not_mention_it_leaves_the_percentage_alone(): void
    {
        $c = $this->contract('draft', ['ads_percentage' => 10]);

        $this->putJson("/api/contracts/{$c->id}", ['title' => 'renamed'])->assertOk();

        $this->assertEquals(10, $c->fresh()->ads_percentage);
    }

    public function test_the_percentage_cannot_change_once_the_contract_was_sent(): void
    {
        foreach (['sent', 'client_approved', 'company_approved', 'completed'] as $status) {
            $c = $this->contract($status, ['ads_percentage' => 10]);

            $this->putJson("/api/contracts/{$c->id}", ['ads_percentage' => 50])->assertStatus(422);

            $this->assertEquals(10, $c->fresh()->ads_percentage, "changed while $status");
        }
    }

    public function test_the_tax_and_the_total_are_the_same_with_or_without_the_percentage(): void
    {
        $plain = $this->contract('company_approved');
        $ads = $this->contract('company_approved', ['ads_percentage' => 10]);

        $this->assertEquals(1400, $plain->tax_amount);
        $this->assertEquals(1400, $ads->tax_amount);
        $this->assertEquals(11400, $plain->total_with_tax);
        $this->assertEquals(11400, $ads->total_with_tax);
        $this->assertEquals($plain->value, $ads->value);
    }

    public function test_the_workspace_summary_keeps_its_totals_and_adds_the_split(): void
    {
        $this->contract('company_approved', ['ads_percentage' => 10]);
        $this->contract('company_approved', ['value' => 5000]);

        $sum = WorkspaceTaxSummary::for($this->ws->fresh());

        $this->assertEquals(15000, $sum['contracts_total']);
        $this->assertEquals(2100, $sum['tax_amount']);
        $this->assertEquals(17100, $sum['grand_total']);
        $this->assertEquals(1000, $sum['contracts'][0]['ads_amount']);
        $this->assertEquals(9000, $sum['contracts'][0]['ads_budget']);
        $this->assertNull($sum['contracts'][1]['ads_percentage']);
        $this->assertNull($sum['contracts'][1]['ads_amount']);
    }

    public function test_the_payments_endpoint_carries_the_split(): void
    {
        $this->contract('company_approved', ['ads_percentage' => 10]);

        $res = $this->getJson("/api/workspaces/{$this->ws->id}/payments")->assertOk();

        $this->assertEquals(11400, $res->json('tax_summary.grand_total'));
        $this->assertEquals(1000, $res->json('tax_summary.contracts.0.ads_amount'));
        $this->assertEquals(9000, $res->json('tax_summary.contracts.0.ads_budget'));
    }

    public function test_the_pdf_shows_the_fee_and_the_budget_only_for_an_ads_contract(): void
    {
        $ads = $this->contract('client_approved', ['ads_percentage' => 10]);
        $plain = $this->contract('client_approved');

        $withAds = $this->renderPdfView($ads);
        $this->assertStringContainsString('نسبة إدارة الإعلانات (10%)', $withAds);
        $this->assertStringContainsString('1,000.00', $withAds);
        $this->assertStringContainsString('ميزانية الإعلانات', $withAds);
        $this->assertStringContainsString('9,000.00', $withAds);

        $without = $this->renderPdfView($plain);
        $this->assertStringNotContainsString('إدارة الإعلانات', $without);
        $this->assertStringNotContainsString('ميزانية الإعلانات', $without);
    }

    public function test_the_contract_sent_email_shows_the_split_in_both_languages(): void
    {
        $ads = $this->contract('sent', ['ads_percentage' => 10]);
        $plain = $this->contract('sent');

        $html = (new ContractSentMail($ads->load('requiredDocuments')))->render();
        $this->assertStringContainsString('نسبة إدارة الإعلانات (10%): 1,000.00', $html);
        $this->assertStringContainsString('ميزانية الإعلانات: 9,000.00', $html);
        $this->assertStringContainsString('Ads management fee (10%): 1,000.00', $html);
        $this->assertStringContainsString('Ad budget: 9,000.00', $html);

        $html = (new ContractSentMail($plain->load('requiredDocuments')))->render();
        $this->assertStringNotContainsString('الإعلانات', $html);
        $this->assertStringNotContainsString('Ad budget', $html);
    }

    private function renderPdfView(Contract $contract): string
    {
        return view('pdf.contract', [
            'contract' => $contract,
            'client' => $this->client,
            'manager' => $this->am,
            'clientSignature' => null,
            'clientSignatureIsImage' => false,
            'clientImagePath' => null,
            'companySignature' => null,
            'companySignatureIsImage' => false,
            'companyImagePath' => null,
            'requiredDocuments' => collect(),
            'taxPercentage' => $contract->tax_percentage,
            'taxAmount' => $contract->tax_amount,
            'currencyLabel' => 'ر.س',
        ])->render();
    }
}
