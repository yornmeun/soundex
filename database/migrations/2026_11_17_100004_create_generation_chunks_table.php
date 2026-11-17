<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('text');
            $table->unsignedInteger('start_ms')->nullable(); // segments mode: original subtitle timing
            $table->unsignedInteger('end_ms')->nullable();
            $table->json('subtitle_indices')->nullable();
            $table->string('audio_path')->nullable();
            $table->decimal('duration_seconds', 10, 3)->nullable();
            $table->string('heygen_request_id')->nullable();
            $table->string('status', 16)->default('pending'); // pending|processing|completed|failed|cancelled
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['generation_id', 'chunk_index']);
            $table->index(['generation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_chunks');
    }
};
