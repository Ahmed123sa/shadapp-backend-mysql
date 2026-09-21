<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Failed login attempts, deliberately in their own table rather than in
 * audit_logs.
 *
 * A failed attempt has no actor. A wrong email matches no User, no Client
 * and no SubUser, so there is nothing to point at — while audit_logs.
 * auditable is a non-nullable morph and audit_logs.user_id is a real
 * foreign key into `users`. Forcing failures in there would mean loosening
 * exactly the constraints whose absence caused the 1452 violations fixed
 * earlier in this project. Volume is the second reason: failures vastly
 * outnumber successes (typos, bots), and audit_logs is the record of
 * business actions — contracts, payments, approvals — not a place to bury
 * that under authentication noise.
 *
 * Note there are no foreign keys here at all, on purpose. The rows worth
 * having are precisely the ones that reference nothing.
 *
 * `reason` is the column that makes this useful rather than just a counter:
 * it separates "no such account" from "account exists, wrong password" from
 * "account is archived/deactivated". The API responses deliberately do NOT
 * make that distinction (they all say the same thing, so the endpoint can't
 * be used to enumerate which emails are registered) — but support needs it
 * to answer "why can't this person log in", which today is pure guesswork.
 *
 * The password is never stored, in any form. People type passwords into the
 * email field by mistake often enough that logging the attempted identifier
 * is already a mild hazard; logging the credential itself would turn this
 * table into a liability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('ip_address', 45)->nullable();
            // 'staff' (/auth/login) or 'client' (/auth/client/login).
            $table->string('endpoint', 16);
            // unknown_email | wrong_password | account_inactive | client_archived
            $table->string('reason', 32);
            $table->timestamp('created_at')->nullable();

            // The only two questions this table is ever asked: "what
            // happened to this account?" (support) and "what is this IP
            // doing?" (abuse). Both are always bounded by a time window,
            // hence the composite indexes rather than two single-column
            // ones.
            $table->index(['email', 'created_at']);
            $table->index(['ip_address', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
