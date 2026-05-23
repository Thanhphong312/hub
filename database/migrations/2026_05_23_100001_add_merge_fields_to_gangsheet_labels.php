<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support "merged" gangsheet labels — one master record covering N child
 * labels with a single QR + a printable PDF of all convert_labels stacked.
 *
 *   - merged_from_label_ids: JSON list of child GangsheetLabel ids that were
 *     merged into this record. Null = a normal (non-merged) record.
 *   - file_url: the merged PDF uploaded to B2 (per-page convert_label, QR at
 *     end). Same pattern as `gangsheets.file_url`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('gangsheet_labels', function (Blueprint $t) {
            $t->json('merged_from_label_ids')->nullable()->after('order_ids');
            $t->string('file_url', 500)->nullable()->after('merged_from_label_ids');
        });
    }

    public function down(): void
    {
        Schema::table('gangsheet_labels', function (Blueprint $t) {
            $t->dropColumn(['merged_from_label_ids', 'file_url']);
        });
    }
};
