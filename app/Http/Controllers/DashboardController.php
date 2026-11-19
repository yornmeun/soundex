<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\SettingsService;
use App\Services\VoiceService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SettingsService $settings, VoiceService $voices): View
    {
        $base = Generation::query()->ownedBy($request->user());

        $stats = [
            'total' => (clone $base)->count(),
            'seconds' => (float) (clone $base)->where('status', GenerationStatus::Completed)->sum('duration_seconds'),
            'completed' => (clone $base)->where('status', GenerationStatus::Completed)->count(),
            'failed' => (clone $base)->where('status', GenerationStatus::Failed)->count(),
        ];

        return view('dashboard', [
            'stats' => $stats,
            'recent' => (clone $base)->latest()->limit(8)->get(),
            'hasApiKey' => $settings->hasAnyProvider(),
            'defaultVoice' => $voices->defaultVoice(),
        ]);
    }
}
