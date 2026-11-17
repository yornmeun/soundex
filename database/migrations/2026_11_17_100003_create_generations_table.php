<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_file_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('regenerated_from_id')->nullable()->constrained('generations')->nullOnDelete();
            $table->string('project_name');
            $table->string('original_filename')->nullable();
            $table->longText('script');
            $table->string('mode', 24)->default('continuous'); // continuous | segments
            $table->string('voice_id');
            $table->string('voice_name')->nullable();
            $table->string('voice_mode', 32)->nullable();
            $table->string('language', 16)->nullable(); // null = auto-detect
            $table->string('model', 64);
            $table->string('output_format', 8)->default('mp3');
            $table->unsignedInteger('mp3_bitrate')->nullable();
            $table->decimal('speed', 4, 2)->nullable(); // professional clones only
            $table->unsignedInteger('silence_between_ms')->default(0);
            $table->boolean('normalize_loudness')->default(true);
            $table->boolean('trim_chunk_silence')->default(false);
            $table->unsignedInteger('total_words')->default(0);
            $table->unsignedInteger('total_chunks')->default(0);
            $table->unsignedInteger('completed_chunks')->default(0);
            $table->string('audio_path')->nullable();
            $table->string('bundle_path')->nullable();  // segments mode: zip of clips + report
            $table->json('timing_report')->nullable();  // segments mode
            $table->decimal('duration_seconds', 10, 3)->nullable();
            $table->string('status', 16)->default('pending'); // pending|processing|completed|failed|cancelled
            $table->string('stage', 32)->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
