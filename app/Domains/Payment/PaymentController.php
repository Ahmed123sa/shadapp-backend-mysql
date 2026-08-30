<?php

namespace App\Domains\Payment;

use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\AuditLog;
use App\Events\PaymentCreated;
use App\Events\PaymentScheduleChanged;
use App\Events\PaymentReviewed;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\ReviewPaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Contract;
use App\Events\ContractCompanyApproved;
use App\Models\SystemSetting;
use App\Notifications\PaymentScheduledNotification;
use App\Support\UploadRules;
use Carbon\Carbon;

class PaymentController extends Controller
{
    const BUSINESS_METHODS = ['bank_transfer', 'swift', 'corporate_account'];
    const INDIVIDUAL_METHODS = ['instapay', 'vodafone_cash', 'mobile_wallet'];

    public function allPayments(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $user = $request->user();
        $query = Payment::with(['workspace.client', 'contract']);

        if ($user->isAccountManager()) {
            $clientIds = $user->managedClients()->pluck('id');
            $query->whereIn('client_id', $clientIds);
        }

        return response()->json([
            'payments' => $query->latest()->paginate($request->input('per_page', 30)),
        ]);
    }

    public function pending(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $user = $request->user();
        $query = Payment::with(['workspace.client', 'contract'])
            ->where('status', 'pending');

        if ($user->isAccountManager()) {
            $clientIds = $user->managedClients()->pluck('id');
            $query->whereIn('client_id', $clientIds);
        }

        return response()->json([
            'payments' => $query->latest()->paginate(30),
        ]);
    }

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $client = $workspace->client;
        $methods = $client->client_type === 'individual' ? self::INDIVIDUAL_METHODS : self::BUSINESS_METHODS;

        $contractsTotal = $workspace->contracts()
            ->whereIn('status', ['company_approved', 'completed'])
            ->sum('value');

        $taxPercentage = 0;
        $isBusiness = $client->client_type === 'business';
        try {
            $taxPercentage = (float) SystemSetting::getValue('corporate_tax_percentage', 0);
        } catch (\Exception $e) {
            $taxPercentage = 0;
        }
        $taxAmount = $isBusiness ? ($contractsTotal * $taxPercentage / 100) : 0;

        return response()->json([
            'payments' => $workspace->payments()->with('contract')->latest()->get(),
            'available_methods' => $methods,
            'client_type' => $client->client_type,
            'tax_summary' => [
                'contracts_total' => (float) $contractsTotal,
                'tax_percentage' => $isBusiness ? $taxPercentage : 0,
                'tax_amount' => $taxAmount,
                'grand_total' => $contractsTotal + $taxAmount,
            ],
        ]);
    }

    public function store(StorePaymentRequest $request, Workspace $workspace): JsonResponse
    {
        $client = $workspace->client;
        $methods = $client->client_type === 'individual' ? self::INDIVIDUAL_METHODS : self::BUSINESS_METHODS;

        $proofFileUrl = [];
        if ($request->hasFile('proof_files')) {
            foreach ($request->file('proof_files') as $file) {
                $path = $file->store('payment-proofs/workspace-' . $workspace->id, 'public');
                $proofFileUrl[] = Storage::url($path);
            }
        }
        $proofFileUrl = !empty($proofFileUrl) ? $proofFileUrl : null;

        // Auto-link to the latest company_approved or completed contract
        $lastContract = $workspace->contracts()
            ->whereIn('status', ['company_approved', 'completed'])
            ->latest()
            ->first();

        // An explicit contract wins over the auto-link; fall back to the
        // latest payable contract when the payload carries no contract_id
        // (e.g. older client apps that don't pick a contract yet).
        $contract = $lastContract;
        if ($request->filled('contract_id')) {
            $contract = $workspace->contracts()
                ->where('id', $request->contract_id)
                ->whereIn('status', ['company_approved', 'completed'])
                ->first();

            if (!$contract) {
                return response()->json(['message' => 'العقد المحدد غير صالح لهذه الدفعة'], 422);
            }

            Log::info('Payment explicitly linked to contract', [
                'workspace_id' => $workspace->id,
                'client_id' => $workspace->client_id,
                'contract_id' => $contract->id,
            ]);
        }

        // Prevent duplicate pending payment for the same contract
        if ($contract && $workspace->payments()->where('contract_id', $contract->id)->where('status', 'pending')->exists()) {
            return response()->json(['message' => 'يوجد طلب دفع معلق لهذا العقد بالفعل'], 422);
        }

        $payment = $workspace->payments()->create([
            'client_id' => $workspace->client_id,
            'contract_id' => $contract?->id,
            'amount' => $request->amount,
            'currency' => $request->currency ?? 'SAR',
            'method_type' => $request->method_type,
            'proof_file_url' => $proofFileUrl,
            'notes' => $request->notes,
            'status' => 'pending',
        ]);

        PaymentCreated::dispatch($payment);

        AuditLog::create([
            'auditable_type' => Payment::class,
            'auditable_id' => $payment->id,
            'client_id' => $workspace->client_id,
            'action' => 'payment.submitted',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['payment' => $payment], 201);
    }

    public function update(Request $request, Workspace $workspace, Payment $payment): JsonResponse
    {
        if (!in_array($payment->status, ['pending', 'scheduled'])) {
            return response()->json(['message' => 'لا يمكن تعديل هذه الدفعة'], 422);
        }

        $request->validate([
            'amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'method_type' => 'nullable|string',
            // Was 'proof_file' (singular) while the upload below reads
            // 'proof_files' (plural) — so the rule matched nothing and every
            // replacement proof bypassed validation entirely, even though the
            // create path (StorePaymentRequest) restricted them properly.
            'proof_files' => 'nullable|array',
            'proof_files.*' => UploadRules::proof(required: true),
        ]);

        if ($request->has('amount')) $payment->amount = $request->amount;
        if ($request->has('currency')) $payment->currency = $request->currency;
        if ($request->has('method_type')) $payment->method_type = $request->method_type;

        if ($request->hasFile('proof_files')) {
            $proofFileUrl = [];
            foreach ($request->file('proof_files') as $file) {
                $path = $file->store('payment-proofs/workspace-' . $workspace->id, 'public');
                $proofFileUrl[] = Storage::url($path);
            }
            $payment->proof_file_url = $proofFileUrl;
        }

        if ($payment->status === 'scheduled') {
            $payment->status = 'pending';
            $payment->reviewed_by = null;
            $payment->reviewed_at = null;
        }

        $payment->save();

        return response()->json(['payment' => $payment->fresh()]);
    }

    public function review(ReviewPaymentRequest $request, Payment $payment): JsonResponse
    {
        $action = $request->input('action');

        $payment->update([
            'status' => $action === 'rejected' ? 'pending' : 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        if ($action === 'rejected') {
            PaymentReviewed::dispatch($payment, 'rejected');

            AuditLog::create([
                'auditable_type' => Payment::class,
                'auditable_id' => $payment->id,
                'user_id' => $request->user()->id,
                'action' => 'payment.rejected',
                'ip_address' => $request->ip(),
            ]);

            $workspace = $payment->workspace->load('payments', 'contracts');
            return response()->json([
                'payment' => $payment->fresh(),
                'workspace' => $workspace,
            ]);
        }

        $workspace = $payment->workspace;
        $workspace = $workspace->fresh();

        $reviewerName = $request->user()?->name ?? 'system';
        $workspace->contracts()->where('status', 'client_approved')->each(function (Contract $contract) use ($reviewerName) {
            $contract->update([
                'status' => 'company_approved',
                'company_signed_at' => now(),
                'company_signature_data' => $reviewerName,
                'company_signature_type' => 'text',
            ]);
            ContractCompanyApproved::dispatch($contract, true);
        });
        $workspace->contracts()->where('status', 'company_approved')->update(['status' => 'completed']);
        $payment->client->update(['payment_status' => 'approved']);

        $contractApproved = $workspace->contracts()->whereIn('status', ['completed', 'company_approved', 'client_approved'])->exists();
        $paymentApproved = true;

        if ($contractApproved && $paymentApproved) {
            $workspace->update(['status' => 'active', 'activated_at' => now()]);
            Log::info('Workspace activated after payment approval', ['workspace_id' => $workspace->id]);
        } else {
            Log::warning('Workspace NOT activated on payment approval', [
                'workspace_id' => $workspace->id,
                'has_approved_contracts' => $contractApproved,
                'payment_id' => $payment->id,
                'contract_statuses' => $workspace->contracts()->pluck('status')->toArray(),
            ]);
        }

        PaymentReviewed::dispatch($payment, 'approved');

        AuditLog::create([
            'auditable_type' => Payment::class,
            'auditable_id' => $payment->id,
            'user_id' => $request->user()->id,
            'action' => 'payment.approved',
            'ip_address' => $request->ip(),
        ]);

        $workspace->load('payments', 'contracts');

        return response()->json([
            'payment' => $payment->fresh(),
            'workspace' => $workspace,
        ]);
    }

    public function schedule(Request $request, Workspace $workspace): JsonResponse
    {
        $request->validate([
            'installments' => 'required|array|min:1',
            'installments.*.amount' => 'required|numeric|min:0',
            'installments.*.currency' => 'nullable|string|max:10',
            'installments.*.due_date' => 'required|date|after_or_equal:today',
            'installments.*.installment_label' => 'nullable|string|max:100',
            'installments.*.notes' => 'nullable|string|max:500',
        ]);

        $payments = [];
        foreach ($request->installments as $i => $inst) {
            $payment = $workspace->payments()->create([
                'client_id' => $workspace->client_id,
                'amount' => $inst['amount'],
                'currency' => $inst['currency'] ?? 'SAR',
                'method_type' => 'scheduled',
                'due_date' => $inst['due_date'],
                'installment_label' => $inst['installment_label'] ?? $this->arabicOrdinal($i + 1),
                'requested_by_manager' => true,
                'status' => 'scheduled',
                'notes' => $inst['notes'] ?? null,
            ]);
            $payments[] = $payment;
        }

        foreach ($payments as $payment) {
            try {
                $workspace->client->notify(new PaymentScheduledNotification($payment));
            } catch (\Exception $e) {
                Log::warning('Payment scheduled notification failed: ' . $e->getMessage());
            }
        }

        AuditLog::create([
            'auditable_type' => Payment::class,
            'auditable_id' => $payments[0]->id,
            'client_id' => $workspace->client_id,
            'action' => 'payment.scheduled',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['payments' => $payments], 201);
    }

    public function requestPayment(Request $request, Workspace $workspace): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'notes' => 'nullable|string|max:500',
        ]);

        $payment = $workspace->payments()->create([
            'client_id' => $workspace->client_id,
            'amount' => $request->amount,
            'currency' => $request->currency ?? 'SAR',
            'method_type' => 'requested',
            'requested_by_manager' => true,
            'status' => 'scheduled',
            'notes' => $request->notes,
        ]);

        try {
            $workspace->client->notify(new PaymentScheduledNotification($payment));
        } catch (\Exception $e) {
            Log::warning('Payment request notification failed: ' . $e->getMessage());
        }

        AuditLog::create([
            'auditable_type' => Payment::class,
            'auditable_id' => $payment->id,
            'client_id' => $workspace->client_id,
            'action' => 'payment.requested',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['payment' => $payment], 201);
    }

    public function updateSchedule(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->workspace->canBeAccessedBy($request->user()), 403, 'غير مصرح لك بالوصول إلى مساحة العمل هذه');

        if ($payment->status !== 'scheduled') {
            abort(422, 'لا يمكن تعديل دفعة مدفوعة أو معتمدة');
        }

        $request->validate([
            'amount' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date|after_or_equal:today',
            'installment_label' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $payment->update($request->only([
            'amount', 'due_date', 'installment_label', 'notes',
        ]));

        event(new PaymentScheduleChanged($payment->fresh(), 'updated'));

        AuditLog::create([
            'auditable_type' => Payment::class,
            'auditable_id' => $payment->id,
            'client_id' => $payment->client_id,
            'action' => 'payment.schedule_updated',
            'ip_address' => $request->ip(),
        ]);

        $this->sendScheduleNotifications($payment->fresh(), 'updated');

        return response()->json(['payment' => $payment->fresh()]);
    }

    public function deleteSchedule(Payment $payment): JsonResponse
    {
        abort_unless($payment->workspace->canBeAccessedBy(request()->user()), 403, 'غير مصرح لك بالوصول إلى مساحة العمل هذه');

        if ($payment->status !== 'scheduled') {
            abort(422, 'لا يمكن مسح دفعة مدفوعة أو معتمدة');
        }

        event(new PaymentScheduleChanged($payment, 'deleted'));

        AuditLog::create([
            'auditable_type' => Payment::class,
            'auditable_id' => $payment->id,
            'client_id' => $payment->client_id,
            'action' => 'payment.schedule_deleted',
            'ip_address' => request()->ip(),
        ]);

        $this->sendScheduleNotifications($payment, 'deleted');

        $payment->delete();

        return response()->json(['message' => 'تم مسح القسط']);
    }

    public function getSchedule(Workspace $workspace): JsonResponse
    {
        $payments = $workspace->payments()
            ->where('requested_by_manager', true)
            ->orderBy('due_date')
            ->get();

        return response()->json(['payments' => $payments]);
    }

    private function sendReminders(Request $request): JsonResponse
    {
        $now = Carbon::now();
        $sent = 0;

        $upcoming = Payment::where('status', 'scheduled')
            ->whereDate('due_date', $now->copy()->addDays(3)->toDateString())
            ->get();
        foreach ($upcoming as $payment) {
            $payment->client->notify(new \App\Notifications\PaymentReminderNotification($payment, '3_days'));
            $sent++;
        }

        $dueToday = Payment::where('status', 'scheduled')
            ->whereDate('due_date', $now->toDateString())
            ->get();
        foreach ($dueToday as $payment) {
            $payment->client->notify(new \App\Notifications\PaymentReminderNotification($payment, 'today'));
            $sent++;
        }

        $overdue = Payment::where('status', 'scheduled')
            ->whereDate('due_date', '<', $now->toDateString())
            ->get();
        foreach ($overdue as $payment) {
            $payment->update(['status' => 'overdue']);
            $payment->client->notify(new \App\Notifications\PaymentReminderNotification($payment, 'overdue'));
            $sent++;
        }

        return response()->json(['sent' => $sent]);
    }

    private function arabicOrdinal(int $n): string
    {
        $labels = ['الأول', 'الثاني', 'الثالث', 'الرابع', 'الخامس', 'السادس', 'السابع', 'الثامن', 'التاسع', 'العاشر'];
        return 'القسط ' . ($labels[$n - 1] ?? $n);
    }

    private function sendScheduleNotifications(Payment $payment, string $action): void
    {
        try {
            $client = $payment->client ?? $payment->workspace->client;
            if ($action === 'deleted') {
                $client->notify(new \App\Notifications\PaymentScheduleDeletedNotification($payment));
            } else {
                $client->notify(new \App\Notifications\PaymentScheduleUpdatedNotification($payment));
            }
        } catch (\Exception $e) {
            Log::warning("Payment schedule {$action} notification failed: " . $e->getMessage());
        }
    }
}
