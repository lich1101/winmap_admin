<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_websites', function (Blueprint $table) {
            $table->text('config_endpoint_url')->nullable()->after('usage_endpoint_url');
            $table->unsignedTinyInteger('warning_threshold_percent')->default(85)->after('quota_bytes');
            $table->string('last_sync_status')->default('pending')->index()->after('last_status');
            $table->text('last_sync_error')->nullable()->after('last_error');
            $table->boolean('last_is_blocked')->default(false)->index()->after('last_usage_percent');
            $table->boolean('last_is_warning')->default(false)->index()->after('last_is_blocked');
            $table->timestamp('last_synced_at')->nullable()->after('last_checked_at');
            $table->string('discovery_root')->nullable()->after('notes');
            $table->string('discovery_conf_path')->nullable()->after('discovery_root');
        });
    }

    public function down(): void
    {
        Schema::table('monitored_websites', function (Blueprint $table) {
            $table->dropColumn([
                'config_endpoint_url',
                'warning_threshold_percent',
                'last_sync_status',
                'last_sync_error',
                'last_is_blocked',
                'last_is_warning',
                'last_synced_at',
                'discovery_root',
                'discovery_conf_path',
            ]);
        });
    }
};
