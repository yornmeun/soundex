<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidSrtException;
use App\Http\Requests\UploadSrtRequest;
use App\Models\UploadedFile;
use App\Services\SettingsService;
use App\Services\Srt\SegmentBuilder;
use App\Services\Srt\SrtCue;
use App\Services\Srt\SrtParser;
use App\Services\Srt\SrtWriter;
use App\Services\Srt\SubtitleAligner;
use App\Services\Text\TextStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SrtUploadController extends Controller
{
    public function store(UploadSrtRequest $request, SrtParser $parser, TextStats $stats): JsonResponse
    {
        $file = $request->file('file');

        try {
            $document = $parser->parse((string) file_get_contents($file->getRealPath()));
        } catch (InvalidSrtException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422);
        }

        $script = $parser->buildScript($document->cues);
        $disk = config('soundex.storage_disk');
        $path = $file->storeAs('uploads/srt', Str::uuid().'.srt', $disk);

        $upload = UploadedFile::query()->create([
            'user_id' => $request->user()->id,
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'stored_path' => $path,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'detected_encoding' => $document->encoding,
            'subtitle_count' => $document->count(),
            'word_count' => $stats->wordCount($script),
            'extracted_script' => $script,
            'cues' => $document->toArray(),
        ]);

        return response()->json([
            'id' => $upload->id,
            'filename' => $upload->original_filename,
            'encoding' => $upload->detected_encoding,
            'subtitle_count' => $upload->subtitle_count,
            'word_count' => $upload->word_count,
            'character_count' => $stats->characterCount($script),
            'estimated_seconds' => $stats->estimateSeconds($script),
            'contains_khmer' => $stats->containsKhmer($script),
            'script' => $script,
            'first_start_ms' => $document->first()->startMs,
            'last_end_ms' => $document->last()->endMs,
        ]);
    }

    /** Download a corrected SRT: the edited script mapped onto the original subtitle timings. */
    public function export(Request $request, UploadedFile $uploadedFile, SubtitleAligner $aligner, SrtWriter $writer)
    {
        $this->authorize('view', $uploadedFile);
        $script = $request->validate(['script' => ['required', 'string', 'max:500000']])['script'];

        $cues = $aligner->align(array_map(fn ($c) => SrtCue::fromArray($c), $uploadedFile->cues ?? []), $script);
        $name = Str::of($uploadedFile->original_filename)->beforeLast('.')->append('-corrected.srt')->toString();

        return response($writer->write($cues), 200, [
            'Content-Type' => 'application/x-subrip; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.addcslashes($name, '"\\').'"',
        ]);
    }

    public function segments(Request $request, UploadedFile $uploadedFile, SegmentBuilder $builder, SettingsService $settings): JsonResponse
    {
        $this->authorize('view', $uploadedFile);

        $strategy = $request->validate([
            'strategy' => ['required', Rule::in([SegmentBuilder::STRATEGY_SENTENCE, SegmentBuilder::STRATEGY_SUBTITLE])],
        ])['strategy'];

        $rows = $builder->build(
            array_map(fn ($c) => SrtCue::fromArray($c), $uploadedFile->cues ?? []),
            $strategy,
            min((int) $settings->get('generation.chunk_max_chars'), (int) config('soundex.heygen.max_chars_per_request')),
        );

        return response()->json(['segments' => $rows]);
    }
}
