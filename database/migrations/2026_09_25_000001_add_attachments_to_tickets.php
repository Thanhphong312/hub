<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Image / video attachments on ticket messages. Files are uploaded to B2
     * first (POST /uploads/file); the message stores only
     * [{url, type: image|video, name}], on the opening message (tickets) and
     * on each reply (ticket_items).
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('content');
        });
        Schema::table('ticket_items', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
        Schema::table('ticket_items', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
    }
};
