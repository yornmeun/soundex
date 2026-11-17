# Database Structure

Migrations: `database/migrations/`. All text columns use the connection charset. Use **utf8mb4** on MySQL so Khmer and emoji are stored correctly.

```
users ─┬─< uploaded_files ─┐
       │                   │ (nullOnDelete)
       └─< generations >───┘
              │  └─ regenerated_from_id → generations.id (nullOnDelete)
              └─< generation_chunks (cascadeOnDelete)

settings          (key/value, secrets encrypted)
voice_profiles    (cache of HeyGen workspace voices)
notifications     (Laravel database notifications)
jobs, failed_jobs, job_batches, cache, sessions  (Laravel framework tables)
```

## `settings`

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| key | varchar unique | e.g. `heygen.api_key`, `voice.default_voice_id`, `generation.chunk_max_chars` |
| value | text null | JSON-encoded value, or Laravel `Crypt` ciphertext when `is_encrypted` |
| is_encrypted | bool | `true` for the HeyGen API key |
| timestamps | | |

## `voice_profiles`

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | The ID used by the browser (HeyGen voice IDs never reach JavaScript) |
| voice_id | varchar unique | HeyGen `voice_id` |
| name | varchar, indexed | e.g. `kikitoons` |
| mode | varchar null | `instant` / `professional` |
| language, gender | varchar null | |
| status | varchar | `PENDING` / `ACTIVE` / `FAILED` |
| failure_reason | varchar null | |
| is_default | bool | Mirrors the `voice.default_voice_id` setting |
| is_available | bool | `false` when the last refresh no longer returned the voice |
| heygen_created_at, last_synced_at | timestamp null | |
| timestamps | | Index: (`status`, `is_available`) |

## `uploaded_files`

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| user_id | FK → users, cascade | |
| original_filename | varchar | |
| stored_path | varchar | `uploads/srt/{uuid}.srt` on the private disk |
| mime_type, size_bytes, detected_encoding | | |
| subtitle_count, word_count | uint | |
| extracted_script | longtext | Script exactly as extracted (before user edits) |
| cues | json | `[{index, start_ms, end_ms, text}]`, used to build synchronized segments |
| timestamps | | Index: (`user_id`, `created_at`) |

Unused uploads older than 7 days are deleted by `kikitoons:prune-uploads` (daily).

## `generations`

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| user_id | FK → users, cascade | Owner. Every route checks it via `GenerationPolicy` |
| uploaded_file_id | FK → uploaded_files, null on delete | |
| regenerated_from_id | FK → generations, null on delete | |
| project_name | varchar | |
| original_filename | varchar null | |
| script | longtext | The (possibly user-edited) script that was submitted |
| mode | varchar | `continuous` / `segments` |
| voice_id, voice_name, voice_mode | | HeyGen voice used (snapshot) |
| language | varchar null | ISO 639-1, `null` = auto-detect |
| model | varchar | HeyGen model |
| output_format | varchar | `mp3` / `wav` |
| mp3_bitrate | uint null | |
| speed | decimal(4,2) null | Professional clones only |
| silence_between_ms | uint | Pause inserted between chunks |
| normalize_loudness, trim_chunk_silence | bool | |
| total_words, total_chunks, completed_chunks | uint | |
| audio_path | varchar null | Final audio (continuous) or timeline preview (segments) |
| bundle_path | varchar null | Segments ZIP (clips + timing report) |
| timing_report | json null | Segments mode: per-segment window vs. measured length |
| duration_seconds | decimal(10,3) null | Measured with ffprobe |
| status | varchar, indexed | `pending` / `processing` / `completed` / `failed` / `cancelled` |
| stage | varchar null | `queued`, `preparing`, `splitting`, `generating`, `processing`, `merging`, `completed` |
| progress | tinyint | 0–100 |
| error_message | text null | |
| started_at, completed_at, cancel_requested_at | timestamp null | |
| timestamps | | Indexes: (`user_id`, `status`), (`user_id`, `created_at`), (`status`) |

## `generation_chunks`

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| generation_id | FK → generations, cascade | |
| chunk_index | uint | 0-based order. **Unique** with `generation_id` |
| text | text | Exact text sent to HeyGen |
| start_ms, end_ms | uint null | Segments mode: original subtitle timing |
| subtitle_indices | json null | Which SRT cues this segment covers |
| audio_path | varchar null | `generations/{id}/chunks/NNNN.{mp3\|wav}` |
| duration_seconds | decimal(10,3) null | Measured with ffprobe |
| heygen_request_id | varchar null | `X-Request-Id` for HeyGen support |
| status | varchar | `pending` / `processing` / `completed` / `failed` / `cancelled` |
| attempts | tinyint | Number of HeyGen requests made for this chunk |
| error_message | text null | |
| timestamps | | Index: (`generation_id`, `status`) |

## Files on disk (`storage/app/private`)

```
uploads/srt/{uuid}.srt
generations/{id}/chunks/0000.mp3 …
generations/{id}/narration.mp3          (continuous)
generations/{id}/timeline.mp3           (segments)
generations/{id}/segments.zip           (segments)
```

Files are served only through authorized controller routes (`/generations/{id}/audio|download|bundle`). Public serving of the private disk is disabled. Deleting a generation deletes its folder.
