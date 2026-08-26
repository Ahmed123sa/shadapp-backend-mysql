<?php

namespace App\Domains\Client;

use App\Models\Client;
use App\Models\SubUser;
use App\Models\User;
use App\Models\AuditLog;
use App\Models\Workspace;
use App\Events\ClientCreated;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Client::class);

        $user = $request->user();
        $clients = Client::with('workspace', 'subUsers', 'payments')
            ->when($user->isAccountManager(), fn($q) => $q->where('manager_id', $user->id))
            ->when($request->filled('q'), function ($q) use ($request) {
                $search = $request->q;
                $q->where(function ($q) use ($search) {
                    $q->where('contact_person', 'like', "%{$search}%")
                      ->orWhere('company_name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhereDate('date_of_birth', $search);
                });
            })
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('client_type'), fn($q) => $q->where('client_type', $request->client_type))
            ->when($request->filled('manager_id') && $user->isSuperAdmin(), fn($q) => $q->where('manager_id', $request->manager_id))
            ->latest()
            ->paginate(30);

        return response()->json(['clients' => $clients]);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {

        $password = $request->password ?? Str::random(12);

        $client = Client::create([
            'company_name' => $request->company_name,
            'contact_person' => $request->contact_person,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => $password,
            'manager_id' => $request->user()->id,
            'contract_value' => $request->contract_value ?? 0,
            'country' => $request->country,
            'industry' => $request->industry,
            'client_type' => $request->client_type ?? 'business',
            'notes' => $request->notes,
            'address' => $request->address,
            'maps_url' => $request->maps_url,
            'date_of_birth' => $request->date_of_birth,
        ]);

        Workspace::create([
            'client_id' => $client->id,
            'manager_id' => $request->user()->id,
            'status' => 'inactive',
        ]);

        if ($request->boolean('send_email')) {
            ClientCreated::dispatch($client, $password);
        }

        AuditLog::create([
            'auditable_type' => Client::class,
            'auditable_id' => $client->id,
            'user_id' => $request->user()->id,
            'action' => 'client.created',
            'metadata' => ['email' => $client->email],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'client' => $client->load('workspace'),
        ], 201);
    }

    public function show(Request $request, Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        $client->load('workspace.contracts', 'workspace.payments', 'subUsers', 'payments');

        return response()->json(['client' => $client]);
    }

    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {

        $fillableFields = ['company_name', 'contact_person', 'phone', 'country', 'industry', 'notes', 'status', 'date_of_birth', 'email', 'client_type', 'address', 'maps_url'];
        if ($request->filled('password')) {
            $fillableFields[] = 'password';
        }

        $client->update($request->only($fillableFields));

        return response()->json(['client' => $client->fresh()->load('workspace')]);
    }

    public function sign(Request $request, Client $client): JsonResponse
    {
        $this->authorize('update', $client);

        if ($request->hasFile('signature_image')) {
            $request->validate(['signature_image' => UploadRules::image(required: true)]);
            $path = $request->file('signature_image')->store('signatures', 'public');
            $signatureData = Storage::url($path);
        } else {
            $request->validate(['signature' => 'required|string']);
            $signatureData = $request->signature;
        }

        $client->update([
            'signature_data' => $signatureData,
            'signed_at' => now(),
        ]);

        return response()->json(['client' => $client->fresh()]);
    }

    public function deleteSign(Client $client): JsonResponse
    {
        $this->authorize('update', $client);

        $client->update([
            'signature_data' => null,
            'signed_at' => null,
        ]);
        return response()->json(['client' => $client->fresh()]);
    }

    public function updateLocation(Request $request, Client $client): JsonResponse
    {
        $this->authorize('update', $client);

        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'address' => 'nullable|string|max:500',
        ]);

        // `address` here is the same free-text field the client edit forms and
        // profile card read/write (`clients.address`) — NOT the separate legacy
        // `location_address` column, which nothing ever displayed (profile()
        // always read `client->address`, so anything saved into
        // `location_address` here used to vanish on the next page load). We
        // still update `location_address` alongside it for backward
        // compatibility with anything else that might reference it, but it is
        // no longer the source of truth. Omit `address` entirely (e.g. a plain
        // GPS check-in) to leave the existing address untouched.
        $client->update([
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'address' => $request->filled('address') ? $request->address : $client->address,
            'location_address' => $request->filled('address') ? $request->address : $client->location_address,
            'location_updated_at' => now(),
            'location_updated_by_ip' => $request->ip(),
        ]);

        AuditLog::create([
            'auditable_type' => Client::class,
            'auditable_id' => $client->id,
            'user_id' => $request->user()->id,
            'action' => 'client.location_updated',
            'metadata' => ['latitude' => $request->latitude, 'longitude' => $request->longitude],
            'ip_address' => $request->ip(),
        ]);

        $fresh = $client->fresh();

        return response()->json([
            'location' => [
                'latitude' => $fresh->latitude,
                'longitude' => $fresh->longitude,
                'address' => $fresh->address,
                'updated_at' => $fresh->location_updated_at,
                'updated_by_ip' => $fresh->location_updated_by_ip,
                'maps_url' => $fresh->latitude && $fresh->longitude
                    ? sprintf('https://www.google.com/maps?q=%s,%s', $fresh->latitude, $fresh->longitude)
                    : null,
            ],
        ]);
    }

    public function profile(Request $request, Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        $client->load('manager', 'workspace', 'subUsers');

        $workspace = $client->workspace;
        $contracts = $workspace?->contracts()->with('clauses')->get() ?? collect();
        $payments = $workspace?->payments()->get() ?? collect();
        $meetings = $workspace?->meetings()->get() ?? collect();
        $approvals = $workspace?->approvals()->get() ?? collect();

        return response()->json([
            'client' => $client,
            'stats' => [
                'total_contracts' => $contracts->count(),
                'draft_contracts' => $contracts->where('status', 'draft')->count(),
                'sent_contracts' => $contracts->whereIn('status', ['sent', 'edit_requested', 'client_approved', 'company_approved'])->count(),
                'completed_contracts' => $contracts->where('status', 'completed')->count(),
                'total_contract_value' => (float) $contracts->sum('value'),
                'total_paid' => (float) $payments->where('status', 'approved')->sum('amount'),
                'pending_payments' => (float) $payments->whereIn('status', ['pending', 'scheduled', 'overdue'])->sum('amount'),
                'payments_count' => $payments->count(),
                'meetings_count' => $meetings->count(),
                'approvals_count' => $approvals->count(),
            ],
            'location' => [
                'latitude' => $client->latitude,
                'longitude' => $client->longitude,
                'address' => $client->address,
                'maps_url' => $this->buildMapsUrl($client),
                'updated_at' => $client->location_updated_at,
                'updated_by_ip' => $client->location_updated_by_ip,
            ],
        ]);
    }

    public function activity(Request $request, Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        $workspace = $client->workspace;
        $contracts = $workspace?->contracts()->get() ?? collect();
        $payments = $workspace?->payments()->get() ?? collect();
        $approvals = $workspace?->approvals()->get() ?? collect();
        $meetings = $workspace?->meetings()->get() ?? collect();

        $events = collect();

        foreach ($contracts as $contract) {
            $events->push([
                'id' => 'contract.created.'.$contract->id,
                'kind' => 'contract_created',
                'timestamp' => $contract->created_at?->toIso8601String(),
                'ref_type' => 'contract',
                'ref_id' => $contract->id,
                'title' => $contract->title,
            ]);

            if ($contract->client_signed_at) {
                $events->push([
                    'id' => 'contract.client_signed.'.$contract->id,
                    'kind' => 'contract_client_signed',
                    'timestamp' => $contract->client_signed_at->toIso8601String(),
                    'ref_type' => 'contract',
                    'ref_id' => $contract->id,
                    'title' => $contract->title,
                ]);
            }

            if ($contract->company_signed_at) {
                $events->push([
                    'id' => 'contract.company_signed.'.$contract->id,
                    'kind' => 'contract_company_signed',
                    'timestamp' => $contract->company_signed_at->toIso8601String(),
                    'ref_type' => 'contract',
                    'ref_id' => $contract->id,
                    'title' => $contract->title,
                ]);
            }

            if ($contract->status === 'completed') {
                $events->push([
                    'id' => 'contract.completed.'.$contract->id,
                    'kind' => 'contract_completed',
                    'timestamp' => $contract->updated_at?->toIso8601String(),
                    'ref_type' => 'contract',
                    'ref_id' => $contract->id,
                    'title' => $contract->title,
                ]);
            }
        }

        foreach ($payments as $payment) {
            $events->push([
                'id' => 'payment.created.'.$payment->id,
                'kind' => 'payment_created',
                'timestamp' => $payment->created_at?->toIso8601String(),
                'ref_type' => 'payment',
                'ref_id' => $payment->id,
                'title' => $payment->installment_label ?? 'payment',
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
            ]);

            if ($payment->reviewed_at && in_array($payment->status, ['approved', 'rejected'])) {
                $events->push([
                    'id' => 'payment.'.$payment->status.'.'.$payment->id,
                    'kind' => $payment->status === 'approved' ? 'payment_approved' : 'payment_rejected',
                    'timestamp' => $payment->reviewed_at->toIso8601String(),
                    'ref_type' => 'payment',
                    'ref_id' => $payment->id,
                    'title' => $payment->installment_label ?? 'payment',
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency,
                ]);
            }
        }

        foreach ($approvals as $approval) {
            $events->push([
                'id' => 'approval.created.'.$approval->id,
                'kind' => 'approval_created',
                'timestamp' => $approval->created_at?->toIso8601String(),
                'ref_type' => 'approval',
                'ref_id' => $approval->id,
                'title' => $approval->title,
            ]);

            if ($approval->responded_at) {
                $events->push([
                    'id' => 'approval.responded.'.$approval->id,
                    'kind' => $approval->status === 'approved' ? 'approval_approved' : 'approval_rejected',
                    'timestamp' => $approval->responded_at->toIso8601String(),
                    'ref_type' => 'approval',
                    'ref_id' => $approval->id,
                    'title' => $approval->title,
                ]);
            }
        }

        foreach ($meetings as $meeting) {
            $events->push([
                'id' => 'meeting.created.'.$meeting->id,
                'kind' => 'meeting_created',
                'timestamp' => $meeting->created_at?->toIso8601String(),
                'ref_type' => 'meeting',
                'ref_id' => $meeting->id,
                'title' => $meeting->title,
            ]);
        }

        $activity = $events
            ->filter(fn ($event) => !empty($event['timestamp']))
            ->sortByDesc('timestamp')
            ->values()
            ->take(30)
            ->all();

        return response()->json(['activity' => $activity]);
    }

    private function buildMapsUrl(Client $client): ?string
    {
        if ($client->maps_url) {
            return $client->maps_url;
        }
        if ($client->latitude && $client->longitude) {
            return sprintf('https://www.google.com/maps?q=%s,%s', $client->latitude, $client->longitude);
        }
        if ($client->address) {
            return 'https://www.google.com/maps/search/?api=1&query='.urlencode($client->address);
        }
        return null;
    }

    public function profileUpdate(Request $request, Client $client): JsonResponse
    {
        $this->authorize('update', $client);

        $request->validate([
            'contact_person' => 'sometimes|string|max:255',
            'avatar' => UploadRules::image(),
            'date_of_birth' => 'nullable|date',
        ]);

        $updateData = $request->only(['contact_person', 'date_of_birth']);

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $updateData['avatar_url'] = \Illuminate\Support\Facades\Storage::url($path);
        }

        $client->update($updateData);

        return response()->json(['client' => $client->fresh()]);
    }

    public function destroy(Request $request, Client $client): JsonResponse
    {
        $this->authorize('delete', $client);

        $client->delete();

        AuditLog::create([
            'auditable_type' => Client::class,
            'auditable_id' => $client->id,
            'user_id' => $request->user()->id,
            'action' => 'client.deleted',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'تم حذف العميل']);
    }

    public function subUsers(Request $request, Client $client): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof SubUser && $user->client_id !== $client->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user instanceof Client && $user->id !== $client->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json(['sub_users' => $client->subUsers]);
    }
}
