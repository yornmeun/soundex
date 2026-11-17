<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pronunciation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('term', 128);       // HeyGen: 1-128 characters
            $table->string('ipa', 512);        // HeyGen: 3-512 characters, IPA wrapped in slashes
            $table->string('note', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pronunciation_entries');
    }
};
