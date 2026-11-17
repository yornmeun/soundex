# HeyGen API Integration

Verified against the official HeyGen developer documentation (developers.heygen.com, HeyGen Voice 1) on 2026‑11‑17.
All calls live in `app/Services/HeyGen/HeyGenService.php`.

## Authentication

Every request sends the header `X-Api-Key: <key>` to `https://api.heygen.com`.
The key comes from the encrypted `settings` row `heygen.api_key`, falling back to `HEYGEN_API_KEY` in `.env`.
It is never logged, never rendered in HTML or JavaScript, and only displayed masked (`sk_l••••••••CDEF`).

## Endpoints used

| Purpose | Request | Notes |
| --- | --- | --- |
| Connection test | `GET /v3/users/me` | Returns `data.username`, `data.email`, `data.billing_type`. No credits used. |
| List workspace voices | `GET /v3/models/audio/voices?type=private&limit=100[&token=…]` | Returns `data[]` with `voice_id, mode (instant\|professional), name, language, status (PENDING\|ACTIVE\|FAILED), created_at, preview_audio_url`, plus `has_more` / `next_token`. |
| Read one voice | `GET /v3/models/audio/voices/{voice_id}` | Used to verify a manually entered voice ID and to get a fresh `preview_audio_url` (these URLs expire, so they are not stored). |
| Text to speech | `POST /v3/models/audio/tts` | **Synchronous**: the request stays open until audio is ready, then returns `data.audio_url` and `data.duration`. Response header `X-Request-Id` is stored per chunk. |
| Download audio | `GET <audio_url>` | Pre-signed URL. The API key is **not** sent. Saved to `storage/app/private/generations/{id}/chunks/NNNN.mp3`. |

The app never calls `POST /v3/models/audio/voices`, so it cannot create, clone or design voices.
Re-mixing music and sound effects, SRT exports, folders and batch bookkeeping never call HeyGen.

## TTS request body

```json
{
  "voice_id": "<kikitoons voice id>",
  "text": "<chunk text, 1–10,000 characters>",
  "model": "heygen-voice-1",
  "language": "en",
  "output_format": { "encoding": "mp3", "sample_rate_hertz": 44100, "bitrate_bps": 128000 },
  "speed": 1.0
}
```

| Field | When sent | Documented constraint |
| --- | --- | --- |
| `language` | Omitted for "Auto-detect" | ISO 639-1 lowercase from HeyGen's fixed list. Regional tags such as `en-US` are rejected. Khmer (`km`) is **not** supported, so Khmer scripts use auto-detect. |
| `output_format` | MP3 output only | MP3 at 44.1 kHz allows 64000/96000/128000/192000 bps. Omitting it returns WAV (PCM16, 44.1 kHz, mono), which the app uses for WAV output. |
| `speed` | Professional clones only | 0.75–1.25. HeyGen ignores it for instant clones, so the UI hides it. |
| `model` | Always | `heygen-voice-1`, `heygen-voice-1-turbo`, `heygen-voice-1-turbo-free`. Professional clones only work with `heygen-voice-1` (the UI blocks other combinations). |

| `pronunciation_dictionary` | Terms from your dictionary that occur in the chunk | Object of term → IPA (`"/ˈkiːki/"`). Up to 100 entries, keys 1–128 characters, values 3–512 characters, case-insensitive whole-term matching, at most 100 occurrences per request. Applies to instant voices on `heygen-voice-1` and `heygen-voice-1-turbo` only, so it is omitted for professional clones and Turbo Free. |
| `with_timestamps` | Always `true` | Adds `data.original_words[] {text, start_time, end_time}`, stored per chunk and used for narration-timed SRT export. |

Unknown fields are rejected by HeyGen (`additionalProperties: false`), so no other fields are sent.

### Character voices

Each chunk carries its own voice: chunks are split at speaker turns, so one request never mixes two voices.
The per-chunk `voice_id` comes from the character mapping, or falls back to the generation's main voice.
`speed` is sent only for chunks whose voice is a professional clone.

### Free-model fallback

When a request fails with `402 insufficient_credit` and the chunk's voice is an instant clone, the processor retries once on `heygen-voice-1-turbo-free` (`HEYGEN_FREE_MODEL_FALLBACK`, default `true`). Pronunciations are not sent on that model.

## Long text

- Hard limit: **10,000 characters** per request. `HeyGenService::synthesize()` refuses longer text locally.
- Default chunk size: 3,000 characters (General Settings, 500–10,000). Smaller chunks make a failed chunk cheaper to retry.
- `TextChunker` splits at paragraph/sentence ends (`. ! ? …`, Khmer `។ ៕`, CJK `。！？`), then at clauses (`, ; :`), then words, and as a last resort grapheme clusters. It never splits inside a Khmer cluster. Joining the chunks gives back the original text exactly, apart from whitespace at chunk edges.
- Chunks are stored in `generation_chunks` with their index and are always generated and merged in index order.

## Errors and retries

`HeyGenException` classifies each failure:

| HTTP / `error.code` | Category | Behaviour |
| --- | --- | --- |
| 429 `rate_limit_exceeded` | `rate_limit` | Retried. Waits for `Retry-After` when present, else exponential backoff (5 s, 10 s, 20 s … max 120 s, plus jitter). |
| 500, 502 `voice_provider_error`, 503 `service_unavailable`, 504 `gateway_timeout`, network timeout | `transient` | Retried with backoff, up to `HEYGEN_MAX_ATTEMPTS` (default 4) per chunk. |
| 402 `usage_limit_reached`, `insufficient_credit`, `plan_upgrade_required` | `quota` | **Not retried.** The generation fails at once with a clear message. |
| 401 `unauthorized`, 403 | `auth` | Not retried. |
| 400 `invalid_parameter`, `voice_expired`; 409 `voice_not_ready`, `voice_training_failed` | `invalid` | Not retried. |

Additional safeguards:

- A client-side limiter (`RateLimiter`, 30 requests/minute) keeps all workers under HeyGen's documented per-member rate limit.
- After a failure, **Retry failed chunks** only resubmits chunks that did not complete. Completed chunk audio is reused.
- The queue job itself has `tries = 1`, so a crashed job is never blindly re-run, which could double-bill. Retries happen per chunk inside the job.
- A dropped connection after HeyGen finished a request can't be detected by the client. In that rare case the retry generates that one chunk again.

## Logging

`Log::info('HeyGen TTS completed')` records character count, model, duration, HeyGen request ID and elapsed time.
Errors log HTTP status, error code, category and request ID. The API key and script text are never logged.

## Testing without quota

Tests use `Http::fake()` and `Http::preventStrayRequests()`, with `HEYGEN_BASE_URL=https://heygen.test` set in `phpunit.xml`.
No automated test can reach the real API.
