<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContractPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression test for the bug fixed in this change: the contract PDF's
 * company/manager signature side printed an image signature (a
 * "/storage/..." path saved from the manager's profile, see
 * ProfileAndSignatureTest::test_admin_can_save_image_signature) as raw path
 * text instead of embedding it as an <img>, unlike the client side of the
 * same document which already used App\Support\SignatureValue for this.
 */
class ContractPdfSignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Client $client;
    private Workspace $workspace;
    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create([
            'role' => User::ROLE_ACCOUNT_MANAGER,
            'name' => 'Manager User',
        ]);

        $this->client = Client::factory()->create([
            'manager_id' => $this->manager->id,
            'company_name' => 'Test Client Co',
        ]);

        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $this->manager->id,
        ]);

        $contract = Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->manager->id,
            'status' => 'client_approved',
        ]);

        // Reload from the DB rather than keeping the in-memory factory
        // instance: start_date/end_date aren't cast on the model, so a
        // freshly-created instance still holds the raw Faker DateTime
        // objects in memory, which the view's plain {{ $contract->start_date }}
        // output can't htmlspecialchars(). A real request always loads the
        // contract via a DB query (route binding, ->find(), etc.), which
        // returns those columns as plain strings instead — reproduce that
        // here so the test reflects real rendering conditions.
        $this->contract = $contract->fresh();
    }

    /**
     * Direct test of the Blade template logic (the actual bug site), with
     * the view's inputs fully controlled — independent of whether
     * ContractPdfService wires the variables correctly.
     */
    public function test_company_sig_box_renders_img_tag_when_signature_is_image(): void
    {
        $html = $this->renderContractView([
            'companySignature' => '/storage/signatures/manager.png',
            'companySignatureIsImage' => true,
            'companyImagePath' => '/tmp/fake/manager.png',
        ]);

        $this->assertStringContainsString('<img src="/tmp/fake/manager.png" class="sig-image" alt="توقيع الشركة"', $html);
        $this->assertStringNotContainsString('/storage/signatures/manager.png<', $html);
    }

    public function test_company_sig_box_still_renders_text_when_signature_is_plain_text(): void
    {
        $html = $this->renderContractView([
            'companySignature' => 'Manager Name',
            'companySignatureIsImage' => false,
            'companyImagePath' => null,
        ]);

        $this->assertStringContainsString('<div class="sig-text">Manager Name</div>', $html);
        // Not "sig-image" alone: that substring always appears in the <style>
        // block's ".sig-box .sig-image { ... }" CSS rule regardless of which
        // branch renders. The actual regression signal is whether an <img>
        // tag was emitted at all.
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_company_sig_box_still_renders_placeholder_when_no_signature(): void
    {
        $html = $this->renderContractView([
            'companySignature' => null,
            'companySignatureIsImage' => false,
            'companyImagePath' => null,
        ]);

        $this->assertStringContainsString('________________', $html);
    }

    /**
     * Integration check: ContractPdfService must actually compute and pass
     * companySignatureIsImage/companyImagePath into the view. Before this
     * fix these keys didn't exist at all, so this scenario worked "by
     * accident" (no undefined-variable error) only because the view never
     * referenced them either. Asserting a clean run here guards against the
     * service and the view drifting apart again.
     */
    public function test_service_generates_pdf_without_error_for_image_company_signature(): void
    {
        $relativePath = 'signatures/manager-' . uniqid() . '.png';
        Storage::disk('public')->put($relativePath, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        ));

        $this->contract->update([
            'status' => 'company_approved',
            'company_signature_data' => '/storage/' . $relativePath,
        ]);

        $url = app(ContractPdfService::class)->generateWithBothSignatures($this->contract->fresh());

        $this->assertNotEmpty($url);
        $this->assertNotNull($this->contract->fresh()->pdf_url);

        Storage::disk('public')->delete($relativePath);
    }

    /**
     * @param array<string, mixed> $companyOverrides
     */
    private function renderContractView(array $companyOverrides): string
    {
        return view('pdf.contract', array_merge([
            'contract' => $this->contract,
            'client' => $this->client,
            'manager' => $this->manager,
            'clientSignature' => null,
            'clientSignatureIsImage' => false,
            'clientImagePath' => null,
            'requiredDocuments' => collect(),
            'taxPercentage' => 0,
            'taxAmount' => 0,
            'currencyLabel' => 'ر.س',
        ], $companyOverrides))->render();
    }
}
