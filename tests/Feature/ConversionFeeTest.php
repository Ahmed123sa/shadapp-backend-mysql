<?php

namespace Tests\Feature;

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
 * Ads contracts can carry a dollar-conversion fee: a share of the ad budget,
 * added on top of the value and never taxed. Any contract can also be taken
 * out of tax with the tax_excluded switch.
 *
 * Reference case: value 100,000, company fee 10% (10,000), ad budget 90,000,
 * conversion 5% of the budget (4,500), tax 14% on the fee only (1,400).
 */
class ConversionFeeTest extends TestCase
{
    use RefreshDatabase;

    private User $am;
    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::setValue('corporate_tax_percentage', 14);
        $this->am = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create([
            'manager_id' => $this->am->id,
            'client_type' => 'business',
            'tax_percentage' => 14,
        ]);
        $this->ws = Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $this->am->id, 'status' => 'inactive']);
        Sanctum::actingAs($this->am);
    }

    private function create(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/workspaces/{$this->ws->id}/contracts", array_merge([
            'title' => 'conv',
            'value' => 100000,
        ], $extra));
    }

    private function contract(string $status, array $extra = []): Contract
    {
        return Contract::factory()->create(array_merge([
            'workspace_id' => $this->ws->id,
            'created_by' => $this->am->id,
            'status' => $status,
            'value' => 100000,
        ], $extra))->fresh();
    }

    public function test_the_reference_case_adds_the_fee_on_top_and_taxes_only_the_company_fee(): void
    {
        $json = $this->create(['ads_percentage' => 10, 'conversion_percentage' => 5])->assertCreated()->json('contract');

        $this->assertEquals(100000, $json['value']);
        $this->assertEquals(90000, $json['ads_budget']);
        $this->assertEquals(4500, $json['conversion_amount']);
        $this->assertEquals(1400, $json['tax_amount']);
        $this->assertEquals(101400, $json['total_with_tax']);
        $this->assertEquals(105900, $json['total_due']);
    }

    public function test_switching_tax_off_zeroes_the_tax_but_keeps_the_conversion_fee(): void
    {
        $json = $this->create(['ads_percentage' => 10, 'conversion_percentage' => 5, 'tax_excluded' => true])->assertCreated()->json('contract');

        $this->assertEquals(0, $json['tax_amount']);
        $this->assertEquals(4500, $json['conversion_amount']);
        $this->assertEquals(104500, $json['total_due']);
    }

    public function test_the_tax_switch_works_on_a_normal_contract_too(): void
    {
        $json = $this->create(['tax_excluded' => true])->assertCreated()->json('contract');

        $this->assertTrue($json['tax_excluded']);
        $this->assertEquals(0, $json['tax_amount']);
        $this->assertEquals(100000, $json['total_due']);
    }

    public function test_a_conversion_percentage_of_zero_means_no_conversion_fee(): void
    {
        $json = $this->create(['ads_percentage' => 10, 'conversion_percentage' => 0])->assertCreated()->json('contract');

        $this->assertNull($json['conversion_percentage']);
        $this->assertNull($json['conversion_amount']);
        $this->assertEquals(101400, $json['total_due']);
    }

    public function test_a_conversion_fee_without_ads_is_ignored(): void
    {
        $json = $this->create(['conversion_percentage' => 5])->assertCreated()->json('contract');

        $this->assertNull($json['conversion_percentage']);
        $this->assertNull($json['conversion_amount']);
    }

    public function test_a_normal_contract_is_untouched(): void
    {
        $json = $this->create()->assertCreated()->json('contract');

        $this->assertFalse($json['tax_excluded']);
        $this->assertNull($json['conversion_amount']);
        $this->assertEquals(14000, $json['tax_amount']);
        $this->assertEquals(114000, $json['total_due']);
        $this->assertEquals($json['total_with_tax'], $json['total_due']);
    }

    public function test_the_percentage_is_validated(): void
    {
        $this->create(['ads_percentage' => 10, 'conversion_percentage' => 101])->assertUnprocessable();
        $this->create(['ads_percentage' => 10, 'conversion_percentage' => -1])->assertUnprocessable();
    }

    public function test_both_options_can_be_edited_on_a_draft(): void
    {
        $c = $this->contract('draft', ['ads_percentage' => 10]);

        $json = $this->putJson("/api/contracts/{$c->id}", ['conversion_percentage' => 5, 'tax_excluded' => true])
            ->assertOk()->json('contract');

        $this->assertEquals(5, $json['conversion_percentage']);
        $this->assertTrue($json['tax_excluded']);
        $this->assertEquals(104500, $json['total_due']);
    }

    public function test_removing_the_ads_split_removes_the_conversion_fee(): void
    {
        $c = $this->contract('draft', ['ads_percentage' => 10, 'conversion_percentage' => 5]);

        $json = $this->putJson("/api/contracts/{$c->id}", ['ads_percentage' => null])->assertOk()->json('contract');

        $this->assertNull($json['conversion_percentage']);
        $this->assertNull($json['conversion_amount']);
    }

    public function test_editing_something_else_keeps_the_conversion_fee(): void
    {
        $c = $this->contract('draft', ['ads_percentage' => 10, 'conversion_percentage' => 5]);

        $json = $this->putJson("/api/contracts/{$c->id}", ['title' => 'renamed'])->assertOk()->json('contract');

        $this->assertEquals(5, $json['conversion_percentage']);
    }

    public function test_both_options_are_locked_once_the_contract_is_sent(): void
    {
        $c = $this->contract('sent', ['ads_percentage' => 10, 'conversion_percentage' => 5]);

        $this->putJson("/api/contracts/{$c->id}", ['conversion_percentage' => 9, 'tax_excluded' => true])->assertUnprocessable();

        $c->refresh();
        $this->assertEquals(5, $c->conversion_percentage);
        $this->assertFalse($c->tax_excluded);
    }

    public function test_the_payment_summary_adds_conversion_fees_across_contracts(): void
    {
        $this->contract('company_approved', ['ads_percentage' => 10, 'conversion_percentage' => 5]);
        $this->contract('company_approved', ['value' => 10000, 'tax_excluded' => true]);

        $summary = WorkspaceTaxSummary::for($this->ws);

        $this->assertEquals(110000, $summary['contracts_total']);
        $this->assertEquals(4500, $summary['conversion_total']);
        $this->assertEquals(1400, $summary['tax_amount']);
        $this->assertEquals(115900, $summary['grand_total']);
        $this->assertEquals(105900, $summary['contracts'][0]['total_due']);
        $this->assertEquals(10000, $summary['contracts'][1]['total_due']);
    }

    public function test_the_payments_endpoint_reports_the_grand_total_with_conversion(): void
    {
        $this->contract('company_approved', ['ads_percentage' => 10, 'conversion_percentage' => 5]);

        $this->getJson("/api/workspaces/{$this->ws->id}/payments")
            ->assertOk()
            ->assertJsonPath('tax_summary.grand_total', 105900);
    }

    public function test_the_pdf_shows_the_conversion_fee_and_hides_tax_when_excluded(): void
    {
        $excluded = $this->contract('client_approved', ['ads_percentage' => 10, 'conversion_percentage' => 5, 'tax_excluded' => true]);
        $taxed = $this->contract('client_approved', ['ads_percentage' => 10, 'conversion_percentage' => 5]);

        $html = $this->renderPdfView($excluded, 0);
        $this->assertStringContainsString('رسوم التحويل الدولاري (5%', $html);
        $this->assertStringContainsString('غير شامل رسوم التحويل', $html);
        $this->assertStringContainsString('الإجمالي المستحق', $html);
        $this->assertStringContainsString('104,500.00', $html);
        $this->assertStringNotContainsString('الضريبة المضافة', $html);

        $html = $this->renderPdfView($taxed, 14);
        $this->assertStringContainsString('الضريبة المضافة (14% على نسبة إدارة الإعلانات)', $html);
        $this->assertStringContainsString('105,900.00', $html);
    }

    private function renderPdfView(Contract $contract, float $taxPercentage): string
    {
        return view('pdf.contract', [
            'contract' => $contract,
            'client' => Client::find($this->ws->client_id),
            'manager' => $this->am,
            'clientSignature' => null,
            'clientSignatureIsImage' => false,
            'clientImagePath' => null,
            'companySignature' => null,
            'companySignatureIsImage' => false,
            'companyImagePath' => null,
            'requiredDocuments' => collect(),
            'taxPercentage' => $taxPercentage,
            'taxAmount' => $contract->tax_amount,
            'currencyLabel' => 'ر.س',
        ])->render();
    }
}
