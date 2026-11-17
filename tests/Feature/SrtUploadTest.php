<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SrtUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function upload(string $name, string $content)
    {
        return $this->actingAs(User::factory()->create())
            ->postJson(route('srt.store'), ['file' => UploadedFile::fake()->createWithContent($name, $content)]);
    }

    public function test_guests_cannot_upload(): void
    {
        $this->postJson(route('srt.store'))->assertUnauthorized();
    }

    public function test_valid_srt_is_parsed_and_summarised(): void
    {
        $response = $this->upload('story.srt', $this->fixture('sample.srt'));

        $response->assertOk()
            ->assertJsonPath('filename', 'story.srt')
            ->assertJsonPath('subtitle_count', 3)
            ->assertJsonPath('word_count', 25)
            ->assertJsonPath('contains_khmer', false);
        $this->assertStringContainsString('curious little cat named Kiki.', $response->json('script'));
        $this->assertGreaterThan(0, $response->json('estimated_seconds'));
        $this->assertDatabaseHas('uploaded_files', ['original_filename' => 'story.srt', 'subtitle_count' => 3]);
    }

    public function test_khmer_srt_is_accepted(): void
    {
        $this->upload('khmer.srt', $this->fixture('khmer.srt'))
            ->assertOk()
            ->assertJsonPath('contains_khmer', true)
            ->assertJsonPath('script', 'សួស្តី! ខ្ញុំឈ្មោះគីគី។ ថ្ងៃនេះយើងនឹងរៀនអំពីព្រៃឈើ។');
    }

    public function test_wrong_extension_is_rejected(): void
    {
        $this->upload('story.txt', $this->fixture('sample.srt'))->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_binary_file_renamed_to_srt_is_rejected(): void
    {
        $this->upload('evil.srt', "\x89PNG\r\n\x1a\n\0\0\0IHDR".random_bytes(64))->assertUnprocessable();
    }

    public function test_empty_srt_is_rejected_with_a_clear_message(): void
    {
        $this->upload('empty.srt', "\n\n")->assertUnprocessable()->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'empty'));
    }

    public function test_invalid_srt_is_rejected(): void
    {
        $this->upload('bad.srt', "This is not\na subtitle file\n")->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'No valid subtitles'));
    }

    public function test_upload_size_limit_is_enforced(): void
    {
        app(SettingsService::class)->set('upload.max_kb', 16);
        $big = str_repeat("1\n00:00:01,000 --> 00:00:02,000\nA fairly long subtitle line for testing.\n\n", 600);

        $this->upload('big.srt', $big)->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_segments_endpoint_is_scoped_to_the_owner(): void
    {
        $owner = User::factory()->create();
        $id = $this->actingAs($owner)->postJson(route('srt.store'), ['file' => UploadedFile::fake()->createWithContent('a.srt', $this->fixture('sample.srt'))])->json('id');

        $this->actingAs($owner)->getJson(route('srt.segments', $id).'?strategy=subtitle')->assertOk()->assertJsonCount(3, 'segments');
        $this->actingAs(User::factory()->create())->getJson(route('srt.segments', $id).'?strategy=subtitle')->assertForbidden();
    }
}
