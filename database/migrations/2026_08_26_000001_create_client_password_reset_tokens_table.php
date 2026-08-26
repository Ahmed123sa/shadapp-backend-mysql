<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate reset-token table for the `clients` password broker.
 *
 * Laravel's token tables are keyed by email address alone, with no notion of
 * which account type the address belongs to. Since staff Users and Clients
 * live in different tables, the same email can legitimately exist as both —
 * and sharing a single token table would mean the second reset request
 * silently invalidates the first one's link. Keeping them apart avoids that
 * entirely rather than relying on the collision never happening.
 *
 * Mirrors the shape of the framework's own password_reset_tokens table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_password_reset_tokens');
    }
};
