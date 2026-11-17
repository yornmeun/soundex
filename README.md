# Soundex

Turn SRT subtitle files into professional narration using your existing HeyGen voice clone **kikitoons**.

Upload an `.srt` → review and edit the extracted script → pick your voice → generate → preview and download one MP3.
Long scripts are split into chunks at sentence boundaries, generated in order by the HeyGen API in a background queue, and merged with FFmpeg.

- **Stack:** Laravel 13 · PHP 8.3+ · MySQL (SQLite works for local dev) · Blade · Tailwind CSS 4 · Alpine.js · Vite
- **Voice API:** HeyGen Voice 1 (`POST /v3/models/audio/tts`). See [docs/API_INTEGRATION.md](docs/API_INTEGRATION.md)
- **Database:** see [docs/DATABASE.md](docs/DATABASE.md)

---

## Features

| Area | What you get |
| --- | --- |
| SRT upload | Drag & drop or browse, extension/MIME/size validation, UTF-8 / UTF-8 BOM / UTF-16 / Windows-1252, Khmer Unicode, multiline cues, tag removal (`<i>`, `<font>`, `{\an8}`) |
| Script review | Filename, subtitle count, word count, estimated duration, editable script with an "Edited" indicator and reset. Text is never changed silently |
| Voices | Loads the voices in your HeyGen workspace, auto-selects `kikitoons` the first time, preview, refresh, add by voice ID. **Never creates or clones voices** and never silently replaces your selection |
| Generation | Background queue job, ordered chunking (sentence → clause → word → grapheme), per-chunk status, retries with backoff for 429/5xx/timeouts only, **no retries for quota/billing errors**, cancellation, duplicate-job protection |
| Audio | FFmpeg merge, configurable pause between chunks, optional silence trimming, EBU R128 loudness normalisation, MP3 (64–192 kbps) or WAV, measured final duration. Never time-stretched |
| Modes | **Continuous narration** (default), or **Subtitle-synchronized segments**: one clip per subtitle group, original timestamps kept as metadata, a timeline preview, and a ZIP with clips + CSV/JSON timing report |
| Library & history | Player with waveform, download, search, status filter, sort, pagination, view details, regenerate, delete, and **retry only failed chunks** |
| Character voices | Lines starting with `Name:` or `[Name]` are detected, and you pick a voice per character. Unmapped labels are read exactly as written. Optionally don't speak the name. The stored script is never changed |
| Pronunciation | Your term → IPA dictionary, sent as HeyGen's `pronunciation_dictionary` (only the terms found in each chunk, within HeyGen's 100-term / 100-occurrence limits). "Hear it" preview. Applies to instant clones on Voice 1 / Turbo |
| Subtitle export | **Corrected SRT**: your edited text aligned onto the original timings (word-level diff); also downloadable from the wizard before generating. **Narration-timed SRT**: built from HeyGen's measured word timestamps, so it matches the generated audio |
| Music & SFX | Sound Library (MP3/WAV/OGG/M4A/AAC/FLAC, content-checked with ffprobe). Background music is looped to the narration length, faded and ducked under the voice. Sound effects go at exact times ("add at current playback time"). **Re-mixing never calls HeyGen**, and the voice-only file stays downloadable |
| Folders & batches | Project folders (filter, move, rename, delete keeps narrations). Batch generation: drop up to 50 SRTs, configure once, queue all, and follow live progress per file |
| Security | Login required, owner-only access to every generation/download, API key encrypted at rest and shown masked, voice IDs never sent to browser JavaScript, secrets never logged |

---

## Requirements

| Tool | Version | Check |
| --- | --- | --- |
| PHP | 8.3+ with `pdo_mysql` (or `pdo_sqlite`), `mbstring`, `intl`, `zip`, `fileinfo`, `curl` | `php -v && php -m` |
| Composer | 2.x | `composer --version` |
| Node.js / npm | Node 20+ | `node -v` |
| MySQL | 8.0+ (or MariaDB 10.6+) | `mysql --version` |
| FFmpeg + ffprobe | 5.0+ | `ffmpeg -version` |
| HeyGen | API key + an **ACTIVE** HeyGen Voice 1 clone named `kikitoons` | |

### Install the missing system packages (Ubuntu / Debian)

```bash
sudo apt update
sudo apt install -y mysql-server php8.5-mysql ffmpeg    # use your PHP version, e.g. php8.3-mysql
```

---

## Installation

```bash
cd soundex
composer install
npm install
cp .env.example .env        # skip if .env already exists
php artisan key:generate    # skip if APP_KEY is already set
```

### Database setup (MySQL)

```bash
sudo mysql <<'SQL'
CREATE DATABASE soundex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'soundex'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
GRANT ALL PRIVILEGES ON soundex.* TO 'soundex'@'localhost';
FLUSH PRIVILEGES;
SQL
```

Then in `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=soundex
DB_USERNAME=soundex
DB_PASSWORD=choose-a-strong-password
```

`utf8mb4` is required for Khmer and emoji. For quick local testing without MySQL you can keep `DB_CONNECTION=sqlite`.

```bash
php artisan migrate
php artisan soundex:user you@example.com --name="Your Name"   # prompts for a password
npm run build
```

### FFmpeg

`sudo apt install ffmpeg` puts `ffmpeg` and `ffprobe` on your `PATH`. If they live elsewhere, set the full paths in `.env`:

```dotenv
FFMPEG_BINARY=/usr/bin/ffmpeg
FFPROBE_BINARY=/usr/bin/ffprobe
```

**API Settings → System check** shows whether FFmpeg was found. A generation checks for FFmpeg **before** calling HeyGen, so a missing FFmpeg never wastes credits.

### HeyGen API configuration

1. In HeyGen, open **Settings → API** and copy your API key.
2. In the app, open **API Settings**, paste the key and click **Save**. It is encrypted with your `APP_KEY` and only shown masked.
   (Alternatively set `HEYGEN_API_KEY` in `.env`. The key stored in the database takes priority.)
3. Click **Test connection** (`GET /v3/users/me`, no credits used).
4. Open **Voice Settings → Refresh voice list**. Your `kikitoons` voice is found by name and selected as the default.
   If it doesn't appear, paste its voice ID under **Add a voice by ID**. The app verifies it with HeyGen first.

> Keep `APP_KEY` safe and stable. If it changes, the stored API key can't be decrypted and must be entered again.

---

## Running the app (development)

You need **three processes**. Run them in three terminals:

```bash
php artisan serve                                            # web app → http://localhost:8000
php artisan queue:work --timeout=3600 --tries=1 --sleep=2    # background audio generation
npm run dev                                                  # Vite (hot reload for CSS/JS)
```

Or run all three at once with `composer start`.

> The queue worker is required. Without it, generations stay **Pending**.
> After changing PHP code, restart the worker (`php artisan queue:restart`).

The scheduler (stale-job recovery, pruning unused uploads, pruning old failed jobs) runs with:

```bash
php artisan schedule:work         # development
# production cron:  * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

---

## How to generate narration

1. **New Generation → Upload SRT.** Drag your `.srt` file onto the drop zone.
2. **Review script.** Check the stats, then edit the text if needed. Choose **Continuous narration** (default) or **Subtitle-synchronized segments**.
3. **Configure voice.** Pick `kikitoons`, a language (or auto-detect), the HeyGen model, MP3 quality and processing options.
   *Speed* appears only for professional clones, because HeyGen ignores it for instant clones.
4. **Generate.** Watch the stages: *Preparing script → Splitting script → Generating audio (n of N chunks) → Processing audio → Merging audio → Completed.* You can leave the page; you'll get a notification (bell icon).
5. **Preview & download.** Play it in the browser and download the MP3. It's also listed in **My Audio Library**.

If a chunk fails (e.g. out of credits), fix the cause and click **Retry failed chunks**. Completed chunks are reused, so no credits are spent twice.

### Character voices

Write each character line on its own line, starting with the name:

```
Once upon a time, Kiki met an old owl.
Owl: Who goes there?
Kiki: It's me, Kiki!
```

Step 3 lists the detected characters. Choose a voice for each one, or leave a name on **Not a character** (for false positives like `Note:`).
Narrator lines use the main voice. In SRT files, a subtitle that starts with `Name:` automatically begins a new line in the script.

### Pronunciation dictionary

**Settings → Pronunciation**: add `Kiki` → `/ˈkiːki/` (IPA between slashes; the slashes are added for you if missing) and press **Hear it**, which makes a real, tiny HeyGen request.
New generations take a snapshot of your active terms. **Regenerate** uses your *current* dictionary, which is how you apply a fixed pronunciation to an existing story.

### Music, sound effects and re-mixing

1. Upload tracks and effects in **Sound Library**.
2. Pick background music in step 3, or later on the narration's page under **Music & sound effects**.
3. On the narration page, play to a moment and press **Add at current playback time** to drop an effect there, then fine-tune the time (`0:12.500`) and volume.
4. **Apply mix** re-renders in the background from the stored voice-only audio, using **no HeyGen credits**. Remove everything to get the plain narration back.

### Subtitles

- **Download corrected SRT** (wizard step 2, or the narration page): your edited wording on the original subtitle timings. Use it when the video keeps its original timing.
- **Narration-timed SRT** (narration page): cues timed to the generated audio from HeyGen's word timestamps. Use it when the video follows the narration. It isn't available when "trim silence at chunk edges" is on, because trimming shifts the timing.

### Batch generation

**Batch Generation** → drop several `.srt` files → rename projects if needed → choose voice, folder, music, characters → **Generate**. Each file becomes a normal generation (its own retries, library entry and downloads). Batches use each file's extracted text unchanged; use **New Generation** to edit a script first.

### About subtitle-synchronized mode

Subtitle timestamps don't guarantee that speech fits each time window. This app **does not** speed up or stretch audio. Instead it:

- generates one clip per subtitle group and keeps the original start/end times as metadata,
- measures each clip with ffprobe and reports whether it fits its window or overlaps the next one,
- builds a timeline preview with each clip placed at its original start time,
- exports a ZIP with all clips, `timing_report.csv` and `timing_report.json`.

---

## Testing

```bash
php artisan test
```

The test suite covers SRT parsing (multiline, Khmer, BOM/UTF-16, empty and invalid files), script extraction, chunking and ordering, HeyGen failures (402/401/429/5xx), rate-limit retries, audio merging, status updates, duplicate-job prevention, cancellation, retrying only failed chunks, upload validation and download authorization.

All HeyGen calls are mocked: `Http::preventStrayRequests()` makes any real request fail the test, so **no API quota is ever consumed**. One test runs a real FFmpeg merge and is skipped if FFmpeg is not installed.

---

## Configuration reference (`.env`)

| Variable | Default | Purpose |
| --- | --- | --- |
| `HEYGEN_API_KEY` | – | Fallback API key (prefer the encrypted UI setting) |
| `HEYGEN_DEFAULT_VOICE_ID` | – | Optional fallback default voice ID |
| `HEYGEN_DEFAULT_VOICE_NAME` | `kikitoons` | Voice name auto-selected on the first voice refresh |
| `HEYGEN_MODEL` | `heygen-voice-1` | Default model (`heygen-voice-1`, `-turbo`, `-turbo-free`) |
| `HEYGEN_REQUEST_TIMEOUT` | `300` | Seconds to wait for one synchronous TTS request |
| `HEYGEN_REQUESTS_PER_MINUTE` | `30` | Client-side throttle (HeyGen's documented limit) |
| `HEYGEN_MAX_ATTEMPTS` | `4` | Attempts per chunk for retryable errors |
| `SOUNDEX_CHUNK_MAX_CHARS` | `3000` | Default chunk size (HeyGen's max is 10,000) |
| `SOUNDEX_MAX_UPLOAD_KB` | `2048` | Default SRT size limit (editable in General Settings) |
| `FFMPEG_BINARY` / `FFPROBE_BINARY` | `ffmpeg` / `ffprobe` | FFmpeg paths |
| `DB_QUEUE_RETRY_AFTER` | `3700` | **Must be larger than the job timeout (3600 s)** |

---

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| Generation stuck on **Pending** | The queue worker isn't running. Start `php artisan queue:work --timeout=3600 --tries=1`. |
| "FFmpeg/ffprobe is not installed" | `sudo apt install ffmpeg` or set `FFMPEG_BINARY`/`FFPROBE_BINARY`, then retry. |
| "HeyGen rejected the API key" | Re-enter the key in API Settings and use **Test connection**. |
| "Not enough HeyGen credits" / "usage limit reached" | Top up or wait for the quota to reset, then **Retry failed chunks**. These errors are deliberately not auto-retried. |
| `kikitoons` voice not in the list | Only HeyGen Voice 1 clones are listed by `/v3/models/audio/voices`. Older clones must be re-cloned in HeyGen. You can also try **Add a voice by ID**. |
| "voice_not_ready" | The clone is still processing in HeyGen (status PENDING). Wait, then refresh voices. |
| Professional clone fails with a Turbo model | Professional clones only work with `heygen-voice-1`. |
| Khmer narration sounds wrong | Khmer is not in HeyGen's supported TTS language list. The app uses auto-detect, but quality depends on HeyGen. |
| Upload rejected for size | Raise **General Settings → Max SRT size** and PHP's `upload_max_filesize`/`post_max_size`. |
| Worker killed mid-generation | `soundex:recover-stale` (scheduler) marks it failed after 75 min. Then use **Retry failed chunks**. |
| "Setting could not be decrypted" in logs | `APP_KEY` changed. Re-enter the API key. |

Logs: `storage/logs/laravel.log`, or `php artisan pail`. HeyGen request IDs are logged for support; API keys and script text are not.

---

## Production deployment

1. Server: PHP 8.3+ (FPM) with `pdo_mysql`, Nginx/Apache, MySQL 8, FFmpeg, Supervisor.
2. Deploy code, then:
   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build
   cp .env.example .env && php artisan key:generate    # first deploy only
   # set APP_ENV=production, APP_DEBUG=false, APP_URL=https://..., DB_*, (optional) HEYGEN_API_KEY
   php artisan migrate --force
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   php artisan soundex:user admin@example.com
   ```
3. Web root must point to `public/`. `storage/` and `bootstrap/cache/` must be writable by the web user.
4. Queue worker via Supervisor (`/etc/supervisor/conf.d/soundex-worker.conf`):
   ```ini
   [program:soundex-worker]
   command=php /var/www/soundex/artisan queue:work --timeout=3600 --tries=1 --sleep=3 --max-time=86400
   user=www-data
   autostart=true
   autorestart=true
   stopwaitsecs=3700
   numprocs=1
   redirect_stderr=true
   stdout_logfile=/var/www/soundex/storage/logs/worker.log
   ```
   One worker processes one generation at a time; chunks inside a generation are always sequential. More workers can run in parallel, but all share HeyGen's 30 requests/minute limit, which the app throttles for you.
5. Cron for the scheduler: `* * * * * cd /var/www/soundex && php artisan schedule:run >> /dev/null 2>&1`
6. After each deploy: `php artisan queue:restart`.
7. Nginx: allow uploads (`client_max_body_size 10M;`) and long requests aren't needed, since generation runs in the queue.
8. Back up the database **and** `storage/app/private/generations` (the audio files).

---

## Project structure

```
app/
  Console/Commands/      soundex:user, soundex:sync-voices, soundex:prune-uploads, soundex:recover-stale
  Enums/                 GenerationStatus, GenerationStage, GenerationMode, ChunkStatus
  Events/ Listeners/ Notifications/   completion/failure → database notification (bell)
  Http/Controllers/      thin controllers (Dashboard, SrtUpload, Generation, Settings/*)
  Http/Requests/         Form Request validation
  Jobs/ProcessGeneration.php          unique queued job
  Policies/              owner-only access
  Services/
    Srt/                 SrtParser, SegmentBuilder
    Text/                TextChunker, TextStats
    HeyGen/              HeyGenService (+ exception classification)
    Audio/AudioProcessor.php          FFmpeg / ffprobe
    Generation/          GenerationService (create/retry/regenerate/cancel), GenerationProcessor (pipeline), BatchService
    Srt/ (+)             SubtitleAligner (corrected SRT), NarrationSrtBuilder (word-timed SRT), SrtWriter
    Text/ (+)            SpeakerParser (character lines)
    Audio/ (+)           NarrationMixer (music + SFX), MixGeneration job
    PronunciationService.php
    SettingsService.php, VoiceService.php
resources/views/         Blade pages + components; resources/js wizard/status/player (Alpine)
tests/                   Unit + Feature tests (HeyGen mocked)
docs/                    API integration and database docs
```
