<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Earlier versions created a bot-scope row with default values for every
        // bot, which permanently shadowed the account-wide limits. Those rows
        // were never editable, so removing them restores user-level control.
        DB::table('risk_limits')->where('scope', 'bot')->delete();
    }

    public function down(): void
    {
        // The shadow rows cannot be reconstructed meaningfully; limits fall back
        // to the user-scope row by design.
    }
};
