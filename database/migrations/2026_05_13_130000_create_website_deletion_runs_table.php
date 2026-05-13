<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_deletion_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitored_website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('domain')->index();
            $table->string('subdomain');
            $table->string('parent_domain');
            $table->string('project_path');
            $table->string('system_user');
            $table->string('database_name');
            $table->string('status')->default('pending')->index();
            $table->string('current_step')->nullable()->index();
            $table->longText('steps_payload');
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_deletion_runs');
    }
};
