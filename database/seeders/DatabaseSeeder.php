<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Client;
use App\Models\Workspace;
use App\Models\Contract;
use App\Models\ContractClause;
use App\Models\Payment;
use App\Models\Meeting;
use App\Models\FileEntry;
use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = User::create([
            'name' => 'المدير العام',
            'email' => 'admin@shadapp.com',
            'password' => 'password',
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $manager = User::create([
            'name' => 'أحمد المدير',
            'email' => 'manager@shadapp.com',
            'password' => 'password',
            'role' => User::ROLE_ACCOUNT_MANAGER,
            'super_admin_id' => $superAdmin->id,
        ]);

        $client = Client::create([
            'company_name' => 'Curve Firm',
            'contact_person' => 'Ali',
            'email' => 'client@shadapp.com',
            'phone' => '0555000111',
            'password' => 'password',
            'manager_id' => $manager->id,
            'status' => 'active',
            'contract_value' => 150000,
            'payment_status' => 'pending',
            'signature_data' => null,
        ]);

        $workspace = Workspace::create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
            'status' => 'active',
        ]);

        $contract = Contract::create([
            'workspace_id' => $workspace->id,
            'title' => 'عقد تطوير منصة إلكترونية',
            'status' => 'company_approved',
            'contract_type' => 'main',
            'value' => 150000,
            'created_by' => $manager->id,
            'start_date' => now()->subDays(10),
            'end_date' => now()->addDays(80),
        ]);

        $contract->clauses()->createMany([
            ['content' => 'يقدم الطرف الأول خدماته وفق الجدول الزمني المتفق عليه', 'type' => 'fixed', 'sort_order' => 0],
            ['content' => 'تسري أحكام هذا العقد لمدة سنة من تاريخ التوقيع', 'type' => 'fixed', 'sort_order' => 1],
            ['content' => 'يمكن تجديد العقد تلقائياً ما لم يخطر أحد الطرفين الآخر', 'type' => 'optional', 'sort_order' => 2],
        ]);

        // --- Payments ---
        Payment::create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'amount' => 50000,
            'method_type' => 'تحويل بنكي',
            'status' => 'pending',
        ]);

        Payment::create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'amount' => 25000,
            'method_type' => 'تحويل بنكي',
            'status' => 'approved',
        ]);

        Payment::create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'amount' => 10000,
            'method_type' => 'فودافون كاش',
            'status' => 'pending',
        ]);

        // --- Meetings ---
        Meeting::create([
            'workspace_id' => $workspace->id,
            'title' => 'اجتماع مراجعة العقد',
            'scheduled_at' => now()->subDays(5),
            'duration_minutes' => 60,
            'status' => 'completed',
            'created_by' => $manager->id,
        ]);

        Meeting::create([
            'workspace_id' => $workspace->id,
            'title' => 'اجتماع تقديم العرض',
            'scheduled_at' => now()->addDays(3),
            'duration_minutes' => 45,
            'status' => 'scheduled',
            'created_by' => $manager->id,
        ]);

        Meeting::create([
            'workspace_id' => $workspace->id,
            'title' => 'اجتماع متابعة التنفيذ',
            'scheduled_at' => now()->addDays(10),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'created_by' => $manager->id,
        ]);

        // --- Files ---
        FileEntry::create([
            'workspace_id' => $workspace->id,
            'uploaded_by_type' => User::class,
            'uploaded_by_id' => $manager->id,
            'file_url' => 'files/contract_main.pdf',
            'name' => 'عقد التطوير الأساسي.pdf',
            'type' => 'application/pdf',
            'size' => 245000,
        ]);

        FileEntry::create([
            'workspace_id' => $workspace->id,
            'uploaded_by_type' => User::class,
            'uploaded_by_id' => $manager->id,
            'file_url' => 'files/payment_proof_1.jpg',
            'name' => 'إثبات الدفعة الأولى.jpg',
            'type' => 'image/jpeg',
            'size' => 180000,
        ]);

        FileEntry::create([
            'workspace_id' => $workspace->id,
            'uploaded_by_type' => User::class,
            'uploaded_by_id' => $manager->id,
            'file_url' => 'files/national_id.png',
            'name' => 'هوية العميل.png',
            'type' => 'image/png',
            'size' => 320000,
        ]);

        $this->call(ContractClauseTemplateSeeder::class);

        // --- System Settings ---
        SystemSetting::setValue('corporate_tax_percentage', 15, 'نسبة ضريبة الشركات المقدرة (%)');

        $this->command->info('Demo data seeded successfully!');
        $this->command->info('Super Admin: admin@shadapp.com / password');
        $this->command->info('Manager: manager@shadapp.com / password');
        $this->command->info('Client: client@shadapp.com / password');
    }
}
