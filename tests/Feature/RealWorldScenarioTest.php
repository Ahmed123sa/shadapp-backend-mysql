<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RealWorldScenarioTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;


    /** @var array<string, array{user: User, clients: array}> */
    private array $managers = [];

    /** @var array<int, array{client: Client, workspace: Workspace, contract?: Contract, expectedStage: int}> */
    private array $allClients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'name' => 'مؤسس شاد آب',
            'email' => 'admin@shadapp.com',
        ]);
    }

    private function actingAsSA(): self
    {
        $this->resetAuth();
        $this->defaultHeaders = [];
        return $this->actingAs($this->superAdmin, 'sanctum');
    }

    private function actingAsAM(User $user): self
    {
        $this->resetAuth();
        $this->defaultHeaders = [];
        return $this->actingAs($user, 'sanctum');
    }

    private function resetAuth(): void
    {
        $this->app->make('auth')->forgetGuards();
    }

    public function test_am_creation_with_manual_password(): void
    {
        $response = $this->actingAsSA()
            ->postJson('/api/account-managers', [
                'name' => 'محمد القحطاني',
                'email' => 'mohammed@am.com',
                'password' => 'mysecret123',
                'phone' => '+966501234599',
            ]);

        $response->assertStatus(201);
        $this->assertEquals('mysecret123', $response->json('credentials.password'));
        $this->assertEquals('+966501234599', $response->json('manager.phone'));

        $response = $this->actingAsSA()
            ->postJson('/api/account-managers', [
                'name' => 'نورة الدوسري',
                'email' => 'noura@am.com',
                'phone' => '+966501234598',
            ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('credentials.password'));
        $this->assertEquals(12, strlen($response->json('credentials.password')));
        $this->assertEquals('+966501234598', $response->json('manager.phone'));

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'mohammed@am.com',
            'password' => 'mysecret123',
        ]);
        $loginResponse->assertStatus(200);
    }

    public function test_full_real_world_scenario(): void
    {
        $this->createAccountManagers();
        $this->verifySaSeesAllManagers();
        $this->eachManagerCreatesClients();
        $this->verifyAmSeesOnlyTheirClients();
        $this->verifySaSeesAllManagersWithClientCounts();
        $this->processClientWorkflows();
        $this->verifyStageLocking();
        $this->verifyAutoAdvanceBehavior();
        $this->verifySaSeesAllContracts();
        $this->verifyRbacEnforcement();
    }

    private function createAccountManagers(): void
    {
        $managerData = [
            ['name' => 'أحمد محمد', 'email' => 'ahmed@am.com', 'password' => 'pass1234', 'phone' => '+966501234561'],
            ['name' => 'سارة علي', 'email' => 'sara@am.com', 'password' => 'pass1234', 'phone' => '+966501234562'],
            ['name' => 'خالد عمر', 'email' => 'khaled@am.com', 'password' => 'pass1234', 'phone' => '+966501234563'],
        ];

        foreach ($managerData as $data) {
            $response = $this->actingAsSA()
                ->postJson('/api/account-managers', $data);

            $response->assertStatus(201);
            $this->assertNotNull($response->json('manager.id'), 'Manager not created');
            $response->assertJsonStructure(['manager' => ['id', 'name', 'email', 'phone']]);
            $this->assertEquals($data['phone'], $response->json('manager.phone'));
            $this->assertEquals($data['name'], $response->json('manager.name'));
            $this->assertEquals($data['email'], $response->json('manager.email'));

            $user = User::where('email', $data['email'])->first();
            $this->managers[$data['name']] = [
                'user' => $user,
                'clients' => [],
                'data' => $data,
            ];
        }

        $this->assertCount(3, $this->managers);
    }

    private function verifySaSeesAllManagers(): void
    {
        $response = $this->actingAsSA()->getJson('/api/account-managers');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'managers');
        $this->assertEquals('أحمد محمد', $response->json('managers.0.name'));
    }

    private function eachManagerCreatesClients(): void
    {
        $clientsByManager = [
            'أحمد محمد' => [
                ['company_name' => 'تك إكسبريس', 'contact_person' => 'نايف الرشيد', 'industry' => 'تقنية', 'targetStage' => 6],
                ['company_name' => 'ديجيتال هب', 'contact_person' => 'فهد المالكي', 'industry' => 'تقنية', 'targetStage' => 4],
                ['company_name' => 'كلود ويف', 'contact_person' => 'سامي الحربي', 'industry' => 'تقنية', 'targetStage' => 3],
                ['company_name' => 'داتا لينك', 'contact_person' => 'ماجد العتيبي', 'industry' => 'تقنية', 'targetStage' => 2],
                ['company_name' => 'سايبر شيلد', 'contact_person' => 'عبدالله الزهراني', 'industry' => 'تقنية', 'targetStage' => 1],
            ],
            'سارة علي' => [
                ['company_name' => 'بناء المستقبل', 'contact_person' => 'هند القحطاني', 'industry' => 'عقارات', 'targetStage' => 6],
                ['company_name' => 'العقارية المتحدة', 'contact_person' => 'لمى الشمري', 'industry' => 'عقارات', 'targetStage' => 4],
                ['company_name' => 'تاون بروبرتيز', 'contact_person' => 'نورة الدوسري', 'industry' => 'عقارات', 'targetStage' => 3],
                ['company_name' => 'سكن للاستثمار', 'contact_person' => 'منال الغامدي', 'industry' => 'عقارات', 'targetStage' => 2],
                ['company_name' => 'إعمار العقارية', 'contact_person' => 'أمل الزهراني', 'industry' => 'عقارات', 'targetStage' => 1],
            ],
            'خالد عمر' => [
                ['company_name' => 'التجارة الذكية', 'contact_person' => 'بدر السلمي', 'industry' => 'تجارة', 'targetStage' => 6],
                ['company_name' => 'السوق المباشر', 'contact_person' => 'تركي المطيري', 'industry' => 'تجارة', 'targetStage' => 4],
                ['company_name' => 'توزيع الشرق', 'contact_person' => 'ياسر البلوي', 'industry' => 'تجارة', 'targetStage' => 3],
                ['company_name' => 'الإمداد السريع', 'contact_person' => 'سعود الشهراني', 'industry' => 'تجارة', 'targetStage' => 2],
                ['company_name' => 'المستودعات المتطورة', 'contact_person' => 'مشعل الدوسري', 'industry' => 'تجارة', 'targetStage' => 1],
            ],
        ];

        $emailIndex = 1;
        foreach ($clientsByManager as $managerName => $clients) {
            $manager = $this->managers[$managerName];

            foreach ($clients as $clientData) {
                $email = "client{$emailIndex}@test.com";
                $emailIndex++;

                $response = $this->actingAsAM($manager['user'])
                    ->postJson('/api/clients', [
                        'company_name' => $clientData['company_name'],
                        'contact_person' => $clientData['contact_person'],
                        'email' => $email,
                        'phone' => '96650' . str_pad((string)$emailIndex, 7, '0', STR_PAD_LEFT),
                        'industry' => $clientData['industry'],
                        'password' => 'ClientPass1',
                        'contract_value' => 50000,
                    ]);

                $response->assertStatus(201);
                $this->assertEquals($clientData['company_name'], $response->json('client.company_name'));

                $client = Client::where('email', $email)->first();
                $this->assertNotNull($client);
                $this->assertEquals($manager['user']->id, $client->manager_id);
                $this->assertTrue(Hash::check('ClientPass1', $client->password));

                $workspace = Workspace::where('client_id', $client->id)->first();
                $this->assertNotNull($workspace);
                $this->assertEquals('inactive', $workspace->status);

                $this->allClients[] = [
                    'client' => $client,
                    'workspace' => $workspace,
                    'managerName' => $managerName,
                    'targetStage' => $clientData['targetStage'],
                ];

                $this->managers[$managerName]['clients'][] = $client;
            }
        }

        $this->assertCount(15, $this->allClients);
    }

    private function verifyAmSeesOnlyTheirClients(): void
    {
        foreach ($this->managers as $name => $manager) {
            $response = $this->actingAsAM($manager['user'])->getJson('/api/clients');

            $response->assertStatus(200);
            $response->assertJsonCount(5, 'clients.data');

            foreach ($response->json('clients.data') as $c) {
                $this->assertEquals($manager['user']->id, $c['manager_id']);
            }
        }
    }

    private function verifySaSeesAllManagersWithClientCounts(): void
    {
        $response = $this->actingAsSA()->getJson('/api/account-managers');

        $response->assertStatus(200);
        foreach ($response->json('managers') as $m) {
            $this->assertEquals(5, $m['managed_clients_count']);
        }

        $ahmed = User::where('email', 'ahmed@am.com')->first();
        $response = $this->actingAsSA()
            ->getJson("/api/account-managers/{$ahmed->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(5, 'clients');
        $this->assertEquals('أحمد محمد', $response->json('manager.name'));
    }

    private function processClientWorkflows(): void
    {
        foreach ($this->allClients as $entry) {
            $client = $entry['client'];
            $workspace = $entry['workspace'];
            $targetStage = $entry['targetStage'];
            $managerName = $entry['managerName'];
            $amUser = $this->managers[$managerName]['user'];

            // Stage 1: Client signs (use client token)
            $clientToken = $client->createToken('test')->plainTextToken;
            $this->resetAuth();
            $this->defaultHeaders = [];
            $response = $this->withHeaders(['Authorization' => 'Bearer ' . $clientToken])
                ->postJson("/api/clients/{$client->id}/sign", ['signature' => "توقيع {$client->company_name}"]);

            $response->assertStatus(200);
            $client->refresh();
            $this->assertNotNull($client->signed_at);
            $this->assertEquals("توقيع {$client->company_name}", $client->signature_data);

            if ($targetStage < 2) continue;

            // Stage 2: AM creates and sends contract
            $response = $this->actingAsAM($amUser)
                ->postJson("/api/workspaces/{$workspace->id}/contracts", [
                    'title' => "عقد {$client->company_name}",
                    'value' => 50000,
                    'currency' => 'SAR',
                    'clauses' => [
                        ['content' => 'بند أول: مدة العقد سنة كاملة', 'type' => 'fixed'],
                        ['content' => 'بند ثاني: الدفع على دفعتين', 'type' => 'fixed'],
                    ],
                ]);

            $response->assertStatus(201);
            $contractId = $response->json('contract.id');
            $contract = Contract::find($contractId);
            $this->assertEquals('draft', $contract->status);

            $response = $this->actingAsAM($amUser)
                ->postJson("/api/contracts/{$contractId}/send");
            $response->assertStatus(200);
            $this->assertEquals('sent', $contract->fresh()->status);

            if ($targetStage < 3) continue;

            // Stage 3: Client approves contract (AM proxies for client)
            $this->actingAsAM($amUser);
            $response = $this->call('POST', "/api/contracts/{$contractId}/client-action", [], [], [], [
                'CONTENT_TYPE' => 'application/json',
            ], json_encode(['action' => 'approved']));
            $response->assertStatus(200);
            $this->assertEquals('client_approved', $contract->fresh()->status);

            if ($targetStage < 4) continue;

            // Stage 4: SA/AM company approves
            $response = $this->actingAsSA()
                ->postJson("/api/contracts/{$contractId}/company-approve");

            $response->assertStatus(200);
            $this->assertEquals('company_approved', $contract->fresh()->status);

            if ($targetStage < 5) continue;

            // Stage 5: Client submits payment
            $this->resetAuth();
            $this->defaultHeaders = [];
            $response = $this->withHeaders(['Authorization' => 'Bearer ' . $clientToken])
                ->postJson("/api/workspaces/{$workspace->id}/payments", [
                    'amount' => 25000,
                    'method_type' => 'bank_transfer',
                ]);

            $response->assertStatus(201);
            $paymentId = $response->json('payment.id');
            $this->assertEquals('pending', $response->json('payment.status'));

            // AM reviews and approves payment
            $response = $this->actingAsAM($amUser)
                ->postJson("/api/payments/{$paymentId}/review", ['action' => 'approved']);

            $response->assertStatus(200);
            $this->assertEquals('approved', Payment::find($paymentId)->status);

            if ($targetStage < 6) continue;

            // Stage 6: Workspace activates (auto when company_approved + payment approved)
            $workspace->refresh();
            $this->assertEquals('active', $workspace->status);
            $this->assertNotNull($workspace->activated_at);
        }

        foreach ($this->allClients as $entry) {
            $client = $entry['client']->fresh();
            $workspace = $entry['workspace']->fresh();
            $targetStage = $entry['targetStage'];

            match ($targetStage) {
                1 => $this->assertStage1($client, $workspace),
                2 => $this->assertStage2($client, $workspace),
                3 => $this->assertStage3($client, $workspace),
                4 => $this->assertStage4($client, $workspace),
                6 => $this->assertStage6($client, $workspace),
                default => true,
            };
        }
    }

    private function assertStage1(Client $client, Workspace $workspace): void
    {
        $this->assertNotNull($client->signed_at);
        $this->assertCount(0, $workspace->contracts);
        $this->assertEquals('inactive', $workspace->status);
    }

    private function assertStage2(Client $client, Workspace $workspace): void
    {
        $this->assertNotNull($client->signed_at);
        $contracts = $workspace->contracts;
        $this->assertGreaterThan(0, $contracts->count());
        $this->assertEquals('sent', $contracts->first()->status);
    }

    private function assertStage3(Client $client, Workspace $workspace): void
    {
        $this->assertEquals('client_approved', $workspace->contracts->first()->status);
    }

    private function assertStage4(Client $client, Workspace $workspace): void
    {
        $this->assertEquals('company_approved', $workspace->contracts->first()->status);
    }

    private function assertStage6(Client $client, Workspace $workspace): void
    {
        $this->assertContains($workspace->contracts->first()->status, ['completed', 'company_approved']);
        $payments = $workspace->payments;
        $this->assertGreaterThan(0, $payments->count());
        $this->assertEquals('approved', $payments->first()->status);
        $this->assertEquals('active', $workspace->status);
    }

    private function verifyStageLocking(): void
    {
        $tabRequiredStages = [0 => 1, 1 => 4, 2 => 1, 3 => 2, 4 => 1, 5 => 1, 6 => 0, 7 => 1];

        foreach ($this->allClients as $entry) {
            $client = $entry['client']->fresh();
            $workspace = $entry['workspace']->fresh();
            $stage = $this->computeStage($client, $workspace);

            foreach ($tabRequiredStages as $tab => $requiredStage) {
                $isLocked = $stage < $requiredStage;
                if ($requiredStage === 0) {
                    $this->assertFalse($isLocked, "Tab {$tab} should always be open");
                }
                if ($requiredStage > 0) {
                    if ($stage < $requiredStage) {
                        $this->assertTrue($isLocked, "Tab {$tab} should be locked at stage {$stage} (needs {$requiredStage})");
                    } else {
                        $this->assertFalse($isLocked, "Tab {$tab} should be unlocked at stage {$stage} (needs {$requiredStage})");
                    }
                }
            }
        }
    }

    private function computeStage(Client $client, Workspace $workspace): int
    {
        if ($workspace->status === 'active') return 6;
        if ($workspace->payments()->where('status', 'approved')->exists()) return 5;
        $contracts = $workspace->contracts;
        if ($contracts->whereIn('status', ['company_approved', 'completed'])->count() > 0) return 4;
        if ($contracts->where('status', 'client_approved')->count() > 0) return 3;
        if ($contracts->where('status', 'sent')->count() > 0) return 2;
        if ($client->signed_at !== null) return 1;
        return 0;
    }

    private function verifyAutoAdvanceBehavior(): void
    {
        $fullClient = null;
        foreach ($this->allClients as $entry) {
            if ($entry['targetStage'] === 6) {
                $fullClient = $entry;
                break;
            }
        }
        $this->assertNotNull($fullClient);

        $client = $fullClient['client']->fresh();
        $workspace = $fullClient['workspace']->fresh();
        $stage = $this->computeStage($client, $workspace);
        $stageToTab = [1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 1, 6 => 1];
        $expectedTab = $stageToTab[$stage] ?? 0;

        $this->assertEquals(1, $expectedTab, "Full-flow client should be on payments tab (stage 6)");
    }

    private function verifySaSeesAllContracts(): void
    {
        $response = $this->actingAsSA()->getJson('/api/all-contracts');

        $response->assertStatus(200);
        $totalWithContracts = 0;
        foreach ($this->allClients as $entry) {
            if ($entry['targetStage'] >= 2) $totalWithContracts++;
        }
        $response->assertJsonCount($totalWithContracts, 'contracts.data');

        foreach ($response->json('contracts.data') as $c) {
            $this->assertArrayHasKey('title', $c);
            $this->assertArrayHasKey('status', $c);
            $this->assertArrayHasKey('value', $c);
            $this->assertArrayHasKey('workspace', $c);
            $this->assertArrayHasKey('client', $c['workspace']);
            $this->assertNotEmpty($c['workspace']['client']['company_name']);
        }
    }

    private function verifyRbacEnforcement(): void
    {
        $ahmed = $this->managers['أحمد محمد']['user'];
        $sara = $this->managers['سارة علي']['user'];

        // SA cannot create clients
        $response = $this->actingAsSA()
            ->postJson('/api/clients', [
                'company_name' => 'اختبار',
                'contact_person' => 'شخص',
                'email' => 'test@test.com',
                'phone' => '966500000000',
            ]);
        $response->assertStatus(403);

        // AM cannot see other AM's clients via index
        $saraClients = Client::where('manager_id', $sara->id)->pluck('id')->toArray();
        $response = $this->actingAsAM($ahmed)->getJson('/api/clients');
        $returnedIds = collect($response->json('clients.data'))->pluck('id')->toArray();
        foreach ($saraClients as $scid) {
            $this->assertNotContains($scid, $returnedIds);
        }

        // AM update has phone field
        $response = $this->actingAsSA()
            ->putJson("/api/account-managers/{$ahmed->id}", [
                'name' => 'أحمد محمد المحدث',
                'phone' => '+966501234567',
            ]);
        $response->assertStatus(200);
        $this->assertEquals('أحمد محمد المحدث', $response->json('manager.name'));
        $this->assertEquals('+966501234567', $response->json('manager.phone'));
    }

    public function test_client_full_api_flow(): void
    {
        $response = $this->actingAsSA()
            ->postJson('/api/account-managers', [
                'name' => 'أمين اختبار',
                'email' => 'amin@am.com',
                'phone' => '+966501234500',
            ]);
        $response->assertStatus(201);
        $amUser = User::where('email', 'amin@am.com')->first();

        $response = $this->actingAsAM($amUser)->postJson('/api/clients', [
            'company_name' => 'شركة الاختبار الشامل',
            'contact_person' => 'مختبر شامل',
            'email' => 'fulltest@test.com',
            'phone' => '966500001111',
            'password' => 'ClientPass1',
            'contract_value' => 100000,
            'industry' => 'تقنية',
            'country' => 'السعودية',
        ]);
        $response->assertStatus(201);
        $clientId = $response->json('client.id');

        $response = $this->actingAsAM($amUser)->getJson("/api/clients/{$clientId}");
        $response->assertStatus(200);

        $response = $this->actingAsSA()->getJson("/api/clients/{$clientId}");
        $response->assertStatus(200);
        $this->assertEquals('شركة الاختبار الشامل', $response->json('client.company_name'));

        $response = $this->actingAsAM($amUser)->putJson("/api/clients/{$clientId}", [
            'contact_person' => 'مختبر محدث',
            'phone' => '966500002222',
        ]);
        $response->assertStatus(200);
        $this->assertEquals('966500002222', $response->json('client.phone'));
    }

    public function test_clientAction_multiple_contracts(): void
    {
        $ahmed = User::factory()->create([
            'role' => User::ROLE_ACCOUNT_MANAGER,
            'name' => 'أحمد محمد',
            'email' => 'ahmed@test.com',
            'phone' => '+966501234561',
        ]);

        // Create two clients
        $client1 = Client::factory()->create(['manager_id' => $ahmed->id, 'company_name' => 'الشركة الأولى']);
        $client2 = Client::factory()->create(['manager_id' => $ahmed->id, 'company_name' => 'الشركة الثانية']);
        Workspace::factory()->create(['client_id' => $client1->id, 'manager_id' => $ahmed->id]);
        Workspace::factory()->create(['client_id' => $client2->id, 'manager_id' => $ahmed->id]);

        $ws1 = $client1->workspace;
        $ws2 = $client2->workspace;

        // Sign both clients
        foreach ([$client1, $client2] as $client) {
            $token = $client->createToken('test')->plainTextToken;
            $this->resetAuth();
            $this->defaultHeaders = [];
            $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                ->postJson("/api/clients/{$client->id}/sign", ['signature' => "توقيع {$client->company_name}"])
                ->assertStatus(200);
        }

        // Process client 1 full flow (contract + company + payment)
        $c1Token = $client1->createToken('test')->plainTextToken;
        $response = $this->actingAsAM($ahmed)
            ->postJson("/api/workspaces/{$ws1->id}/contracts", [
                'title' => "عقد {$client1->company_name}", 'value' => 50000, 'currency' => 'SAR',
            ]);
        $response->assertStatus(201);
        $c1ContractId = $response->json('contract.id');
        $this->actingAsAM($ahmed)->postJson("/api/contracts/{$c1ContractId}/send")->assertStatus(200);
        $this->actingAsAM($ahmed);
        $this->call('POST', "/api/contracts/{$c1ContractId}/client-action", [], [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode(['action' => 'approved']))->assertStatus(200);
        $this->actingAsSA()->postJson("/api/contracts/{$c1ContractId}/company-approve")->assertStatus(200);
        $this->resetAuth();
        $this->defaultHeaders = [];
        $this->withHeaders(['Authorization' => 'Bearer ' . $c1Token])
            ->postJson("/api/workspaces/{$ws1->id}/payments", ['amount' => 10000, 'method_type' => 'bank_transfer'])->assertStatus(201);
        $paymentId = Payment::where('workspace_id', $ws1->id)->first()->id;
        $this->actingAsAM($ahmed)->postJson("/api/payments/{$paymentId}/review", ['action' => 'approved'])->assertStatus(200);

        // Now process client 2
        $c2Token = $client2->createToken('test')->plainTextToken;
        $this->resetAuth();
        $this->defaultHeaders = [];
        $this->withHeaders(['Authorization' => 'Bearer ' . $c2Token])
            ->postJson("/api/clients/{$client2->id}/sign", ['signature' => "توقيع {$client2->company_name}"])->assertStatus(200);

        $response = $this->actingAsAM($ahmed)
            ->postJson("/api/workspaces/{$ws2->id}/contracts", [
                'title' => "عقد {$client2->company_name}", 'value' => 50000, 'currency' => 'SAR',
            ]);
        $response->assertStatus(201);
        $c2ContractId = $response->json('contract.id');

        $response = $this->actingAsAM($ahmed)->postJson("/api/contracts/{$c2ContractId}/send");
        $response->assertStatus(200);
        $this->assertEquals('sent', $response->json('contract.status'));

        // client 2 clientAction
        $this->actingAsAM($ahmed);
        $response = $this->call('POST', "/api/contracts/{$c2ContractId}/client-action", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['action' => 'approved']));
        $response->assertStatus(200);
        $this->assertEquals('client_approved', $response->json('contract.status'));
    }

    public function test_basic_sa_unauthenticated(): void
    {
        $this->assertTrue(true);
    }

    public function test_sa_all_contracts_and_meetings_endpoints()
    {
        $this->actingAsSA()->getJson('/api/all-contracts')->assertStatus(200);
        $this->actingAsSA()->getJson('/api/all-meetings')->assertStatus(200);

        $this->actingAsSA()->postJson('/api/account-managers', [
            'name' => 'مدير اختبار', 'email' => 'mgr@test.com', 'phone' => '+966500000001',
        ]);
        $amUser = User::where('email', 'mgr@test.com')->first();

        $this->actingAsAM($amUser)->postJson('/api/clients', [
            'company_name' => 'شركة اختبار',
            'contact_person' => 'شخص',
            'email' => 'co@test.com',
            'phone' => '966500000002',
            'password' => 'ClientPass1',
            'contract_value' => 10000,
        ])->assertStatus(201);
        $client = Client::where('email', 'co@test.com')->first();
        $workspace = Workspace::where('client_id', $client->id)->first();

        $this->actingAsAM($amUser)->postJson("/api/workspaces/{$workspace->id}/contracts", [
            'title' => 'عقد اختبار',
            'value' => 10000,
        ])->assertStatus(201);

        $this->actingAsAM($amUser)->postJson("/api/workspaces/{$workspace->id}/meetings", [
            'title' => 'اجتماع اختبار',
            'scheduled_at' => now()->addDays(1)->toIso8601String(),
            'duration_minutes' => 30,
        ])->assertStatus(201);

        $this->actingAsSA()->getJson('/api/all-contracts')
            ->assertJsonCount(1, 'contracts.data');
        $this->actingAsSA()->getJson('/api/all-meetings')
            ->assertJsonCount(1, 'meetings.data');
    }
}
