<?php

namespace App\Providers;

use App\Models\Approval;
use App\Models\Client;
use App\Models\Contract;
use App\Models\FileEntry;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\SubUser;
use App\Models\Workspace;
use App\Policies\ApprovalPolicy;
use App\Policies\ClientPolicy;
use App\Policies\ContractPolicy;
use App\Policies\FileEntryPolicy;
use App\Policies\MeetingPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\SubUserPolicy;
use App\Policies\WorkspacePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Workspace::class => WorkspacePolicy::class,
        Client::class => ClientPolicy::class,
        Contract::class => ContractPolicy::class,
        Payment::class => PaymentPolicy::class,
        FileEntry::class => FileEntryPolicy::class,
        Approval::class => ApprovalPolicy::class,
        Meeting::class => MeetingPolicy::class,
        SubUser::class => SubUserPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
