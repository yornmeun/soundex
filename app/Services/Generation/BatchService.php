<?php

namespace App\Services\Generation;

use App\Models\GenerationBatch;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\VoiceProfile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Creates one generation per uploaded SRT, all sharing the same voice/output settings. */
class BatchService
{
    public function __construct(private readonly GenerationService $generations) {}

    public function create(User $user, array $data, VoiceProfile $voice): GenerationBatch
    {
        return DB::transaction(function () use ($user, $data, $voice) {
            $shared = Arr::except($data, ['items', 'name']);

            $batch = GenerationBatch::query()->create([
                'user_id' => $user->id,
                'folder_id' => $data['folder_id'] ?? null,
                'name' => $data['name'],
                'settings' => Arr::except($shared, ['speakers']),
                'total_items' => count($data['items']),
            ]);

            foreach ($data['items'] as $item) {
                $upload = UploadedFile::query()->where('user_id', $user->id)->findOrFail($item['uploaded_file_id']);

                $this->generations->create($user, [
                    ...$shared,
                    'batch_id' => $batch->id,
                    'project_name' => $item['project_name'],
                    'script' => (string) $upload->extracted_script,
                    'segment_texts' => null, // batch uses the extracted text unchanged
                ], $voice, $upload);
            }

            return $batch;
        });
    }
}
