<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('voice_id')->unique();
            $table->string('name');
            $table->string('mode', 32)->nullable();       // instant | professional
            $table->string('language', 16)->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('status', 16)->default('ACTIVE'); // PENDING | ACTIVE | FAILED
            $table->string('failure_reason')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_available')->default(true); // false when no longer returned by HeyGen
            $table->timestamp('heygen_created_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'is_available']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_profiles');
    }
};
