<?php

namespace App\Providers;

use App\Events\ContractSent;
use App\Events\ContractClientApproved;
use App\Events\ContractCompanyApproved;
use App\Events\ContractCompleted;
use App\Events\PaymentCreated;
use App\Events\PaymentReviewed;
use App\Events\MeetingCreated;
use App\Events\ApprovalResponded;
use App\Events\ClientCreated;
use App\Listeners\SendContractEmailNotification;
use App\Listeners\SendPaymentEmailNotification;
use App\Listeners\SendMeetingEmailNotification;
use App\Listeners\CreateMeetingChatMessage;
use App\Listeners\SendApprovalEmailNotification;
use App\Listeners\SendClientWelcomeEmail;
use App\Models\Meeting;
use App\Observers\MeetingObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // MySQL/MariaDB with utf8mb4 needs 4 bytes per character. A varchar(255)
        // unique/index column (e.g. users.email, clients.email) then requires a
        // key prefix longer than some MySQL/MariaDB builds allow, causing
        // "Specified key was too long" on migrate. Capping the default string
        // length avoids this for any ->string() column that doesn't specify its
        // own length. Postgres and SQLite have no such limit, so this only
        // changes behavior under MySQL.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::defaultStringLength(191);
        }

        Event::listen(ContractSent::class, [SendContractEmailNotification::class, 'handleContractSent']);
        Event::listen(ContractClientApproved::class, [SendContractEmailNotification::class, 'handleClientApproved']);
        Event::listen(ContractCompanyApproved::class, [SendContractEmailNotification::class, 'handleCompanyApproved']);
        Event::listen(ContractCompleted::class, [SendContractEmailNotification::class, 'handleCompleted']);

        Event::listen(PaymentCreated::class, [SendPaymentEmailNotification::class, 'handlePaymentCreated']);
        Event::listen(PaymentReviewed::class, [SendPaymentEmailNotification::class, 'handlePaymentReviewed']);
        Event::listen(MeetingCreated::class, [SendMeetingEmailNotification::class, 'handleMeetingCreated']);
        // This listener had no manual registration anywhere — it only ran because
        // of auto-discovery. Now that discovery is disabled (bootstrap/app.php),
        // it needs this explicit line or meeting chat messages stop being created.
        Event::listen(MeetingCreated::class, [CreateMeetingChatMessage::class, 'handleMeetingCreated']);
        Event::listen(ApprovalResponded::class, [SendApprovalEmailNotification::class, 'handleApprovalResponded']);
        Event::listen(ClientCreated::class, [SendClientWelcomeEmail::class, 'handleClientCreated']);

        Meeting::observe(MeetingObserver::class);
    }
}
