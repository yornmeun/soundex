<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->after('folder_id')->constrained('generation_batches')->nullOnDelete();
            // {"kiki": {"voice_id": "...", "voice_name": "...", "voice_mode": "instant", "label": "Kiki"}}
            $table->json('speaker_voices')->nullable()->after('voice_mode');
            $table->boolean('strip_speaker_labels')->default(true)->after('speaker_voices');
            $table->boolean('use_pronunciations')->default(true)->after('strip_speaker_labels');
            // Snapshot of the dictionary at creation time so retries/regenerations are consistent.
            $table->json('pronunciation_dictionary')->nullable()->after('use_pronunciations');
            $table->string('voice_audio_path')->nullable()->after('audio_path'); // narration without music/SFX
            // {"music": {...} | null, "sfx": [{asset_id, at_ms, volume_db}]}
            $table->json('mix_settings')->nullable()->after('voice_audio_path');
            $table->string('mix_status', 16)->nullable()->after('mix_settings'); // pending|processing|failed|null
            $table->text('mix_error')->nullable()->after('mix_status');
        });

        Schema::table('generation_chunks', function (Blueprint $table) {
            $table->string('speaker', 64)->nullable()->after('subtitle_indices');
            $table->string('voice_id')->nullable()->after('speaker');  // null = generation voice
            $table->string('voice_name')->nullable()->after('voice_id');
            $table->string('voice_mode', 32)->nullable()->after('voice_name');
            $table->json('words')->nullable()->after('duration_seconds'); // HeyGen word timestamps
        });
    }

    public function down(): void
    {
        Schema::table('generation_chunks', function (Blueprint $table) {
            $table->dropColumn(['speaker', 'voice_id', 'voice_name', 'voice_mode', 'words']);
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
            $table->dropConstrainedForeignId('folder_id');
            $table->dropColumn([
                'speaker_voices', 'strip_speaker_labels', 'use_pronunciations', 'pronunciation_dictionary',
                'voice_audio_path', 'mix_settings', 'mix_status', 'mix_error',
            ]);
        });
    }
};
