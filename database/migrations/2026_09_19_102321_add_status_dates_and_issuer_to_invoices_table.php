<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('invoice_no');
            $table->date('due_at')->nullable()->after('pricing');
            $table->date('next_payment_at')->nullable()->after('due_at');
            $table->string('doc_title')->nullable()->after('next_payment_at');
            $table->json('issuer')->nullable()->after('doc_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['status', 'due_at', 'next_payment_at', 'doc_title', 'issuer']);
        });
    }
};
