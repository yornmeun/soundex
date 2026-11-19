<?php

use App\Http\Controllers\AudioAssetController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PronunciationController;
use App\Http\Controllers\Settings\ApiSettingsController;
use App\Http\Controllers\Settings\GeneralSettingsController;
use App\Http\Controllers\Settings\VoiceSettingsController;
use App\Http\Controllers\SrtUploadController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    // New generation wizard
    Route::get('/generations/new', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('/srt', [SrtUploadController::class, 'store'])->middleware('throttle:30,1')->name('srt.store');
    Route::get('/srt/{uploadedFile}/segments', [SrtUploadController::class, 'segments'])->name('srt.segments');
    Route::post('/srt/{uploadedFile}/export', [SrtUploadController::class, 'export'])->name('srt.export');
    Route::post('/generations', [GenerationController::class, 'store'])->middleware('throttle:20,1')->name('generations.store');

    // Library & history
    Route::get('/library', [GenerationController::class, 'library'])->name('library');
    Route::get('/history', [GenerationController::class, 'history'])->name('history');

    // Single generation
    Route::get('/generations/{generation}', [GenerationController::class, 'show'])->name('generations.show');
    Route::get('/generations/{generation}/status', [GenerationController::class, 'status'])->name('generations.status');
    Route::get('/generations/{generation}/audio', [GenerationController::class, 'stream'])->name('generations.stream');
    Route::get('/generations/{generation}/download', [GenerationController::class, 'download'])->name('generations.download');
    Route::get('/generations/{generation}/bundle', [GenerationController::class, 'downloadBundle'])->name('generations.bundle');
    Route::post('/generations/{generation}/cancel', [GenerationController::class, 'cancel'])->name('generations.cancel');
    Route::post('/generations/{generation}/retry', [GenerationController::class, 'retry'])->name('generations.retry');
    Route::post('/generations/{generation}/regenerate', [GenerationController::class, 'regenerate'])->name('generations.regenerate');
    Route::delete('/generations/{generation}', [GenerationController::class, 'destroy'])->name('generations.destroy');
    Route::get('/generations/{generation}/srt', [GenerationController::class, 'srt'])->name('generations.srt');
    Route::post('/generations/{generation}/mix', [GenerationController::class, 'mix'])->name('generations.mix');
    Route::patch('/generations/{generation}/folder', [FolderController::class, 'move'])->name('generations.folder');

    // Folders
    Route::get('/folders', [FolderController::class, 'index'])->name('folders.index');
    Route::post('/folders', [FolderController::class, 'store'])->name('folders.store');
    Route::put('/folders/{folder}', [FolderController::class, 'update'])->name('folders.update');
    Route::delete('/folders/{folder}', [FolderController::class, 'destroy'])->name('folders.destroy');

    // Batch generation
    Route::get('/batches', [BatchController::class, 'index'])->name('batches.index');
    Route::get('/batches/new', [BatchController::class, 'create'])->name('batches.create');
    Route::post('/batches', [BatchController::class, 'store'])->middleware('throttle:10,1')->name('batches.store');
    Route::get('/batches/{batch}', [BatchController::class, 'show'])->name('batches.show');
    Route::get('/batches/{batch}/status', [BatchController::class, 'status'])->name('batches.status');

    // Sound library (background music + sound effects)
    Route::get('/sounds', [AudioAssetController::class, 'index'])->name('sounds.index');
    Route::post('/sounds', [AudioAssetController::class, 'store'])->middleware('throttle:30,1')->name('sounds.store');
    Route::put('/sounds/{sound}', [AudioAssetController::class, 'update'])->name('sounds.update');
    Route::delete('/sounds/{sound}', [AudioAssetController::class, 'destroy'])->name('sounds.destroy');
    Route::get('/sounds/{sound}/audio', [AudioAssetController::class, 'stream'])->name('sounds.stream');

    // Pronunciation dictionary
    Route::get('/settings/pronunciations', [PronunciationController::class, 'index'])->name('pronunciations.index');
    Route::post('/settings/pronunciations', [PronunciationController::class, 'store'])->name('pronunciations.store');
    Route::post('/settings/pronunciations/preview', [PronunciationController::class, 'preview'])->middleware('throttle:10,1')->name('pronunciations.preview');
    Route::put('/settings/pronunciations/{pronunciation}', [PronunciationController::class, 'update'])->name('pronunciations.update');
    Route::delete('/settings/pronunciations/{pronunciation}', [PronunciationController::class, 'destroy'])->name('pronunciations.destroy');

    // Settings
    Route::get('/settings/voices', [VoiceSettingsController::class, 'index'])->name('settings.voices');
    Route::post('/settings/voices/refresh', [VoiceSettingsController::class, 'refresh'])->middleware('throttle:10,1')->name('settings.voices.refresh');
    Route::post('/settings/voices/refresh-azure', [VoiceSettingsController::class, 'refreshAzure'])->middleware('throttle:10,1')->name('settings.voices.refresh-azure');
    Route::post('/settings/voices/add', [VoiceSettingsController::class, 'add'])->name('settings.voices.add');
    Route::post('/settings/voices/{voice}/default', [VoiceSettingsController::class, 'setDefault'])->name('settings.voices.default');
    Route::get('/settings/voices/{voice}/preview', [VoiceSettingsController::class, 'preview'])->name('settings.voices.preview');

    Route::get('/settings/api', [ApiSettingsController::class, 'edit'])->name('settings.api');
    Route::put('/settings/api', [ApiSettingsController::class, 'update'])->name('settings.api.update');
    Route::post('/settings/api/test', [ApiSettingsController::class, 'test'])->middleware('throttle:10,1')->name('settings.api.test');
    Route::post('/settings/api/test-azure', [ApiSettingsController::class, 'testAzure'])->middleware('throttle:10,1')->name('settings.api.test-azure');

    Route::get('/settings/general', [GeneralSettingsController::class, 'edit'])->name('settings.general');
    Route::put('/settings/general', [GeneralSettingsController::class, 'update'])->name('settings.general.update');

    Route::post('/notifications/read', [NotificationController::class, 'markAllRead'])->name('notifications.read');
});
