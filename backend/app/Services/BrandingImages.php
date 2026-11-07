<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BrandingImages
{
    public const SLOTS = ['story_light', 'story_dark', 'logo_light', 'logo_dark',
        'footer_signature_light', 'footer_signature_dark'];

    public static function directory(): string
    {
        // Test and production databases can share a filesystem without sharing assets.
        return 'branding/'.hash('sha256', DB::getDatabaseName());
    }

    public static function slot(string $slot): void
    {
        abort_unless(in_array($slot, self::SLOTS, true), 404);
    }

    public function authorize(User $actor): void
    {
        abort_unless($actor->is_active && FoundationAccess::administrator($actor), 403);
        FoundationAccess::permit($actor, 'settings.update');
    }

    public function all(): array
    {
        $assets = array_fill_keys(self::SLOTS, null);
        foreach (DB::table('branding_images')->get() as $row) {
            if (array_key_exists($row->slot, $assets)) {
                $assets[$row->slot] = $this->metadata($row);
            }
        }

        return $assets;
    }

    private function metadata(object $row): array
    {
        return ['url' => '/api/branding/images/'.$row->slot.'/'.$row->version,
            'version' => $row->version, 'width' => (int) $row->width,
            'height' => (int) $row->height, 'mime_type' => $row->mime_type,
            'bytes' => (int) $row->bytes];
    }

    private function inspect(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $size = $path ? filesize($path) : false;
        $info = $path ? @getimagesize($path) : false;
        $mime = $path ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        $extensions = ['image/png' => ['png'], 'image/jpeg' => ['jpg', 'jpeg'],
            'image/webp' => ['webp']];
        if (! $file->isValid() || ! $info || ! isset($extensions[$mime])
            || ($info['mime'] ?? null) !== $mime
            || ! in_array(strtolower($file->getClientOriginalExtension()), $extensions[$mime], true)) {
            throw ValidationException::withMessages([
                'image' => 'Choose a valid PNG, JPEG or WebP image with a matching file extension.',
            ]);
        }
        if (! $size || $size > 5 * 1024 * 1024 || $info[0] < 1 || $info[1] < 1
            || $info[0] > 4096 || $info[1] > 4096) {
            throw ValidationException::withMessages([
                'image' => 'The image must be at most 5 MB and 4096 × 4096 pixels.',
            ]);
        }

        return ['mime_type' => $mime, 'bytes' => $size, 'width' => $info[0], 'height' => $info[1],
            'extension' => $extensions[$mime][0]];
    }

    public function replace(User $actor, string $slot, UploadedFile $file): array
    {
        self::slot($slot);
        $this->authorize($actor);
        $image = $this->inspect($file);
        $version = (string) Str::uuid();
        $path = self::directory().'/'.$version.'.'.$image['extension'];
        unset($image['extension']);

        // The same connection-owned mutex protects reset and all API mutations.
        return app(SystemWriteLock::class)->run(function () use ($actor, $slot, $file, $image, $version, $path) {
            try {
                return DB::transaction(function () use ($actor, $slot, $file, $image, $version, $path) {
                    DB::table('company_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
                    $this->authorize(User::findOrFail($actor->id));
                    $old = DB::table('branding_images')->where('slot', $slot)->lockForUpdate()->first();
                    $stream = fopen($file->getRealPath(), 'rb');
                    try {
                        abort_unless($stream && Storage::disk('local')->put($path, $stream),
                            500, 'The image could not be saved. Please try again.');
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                    $data = $image + ['version' => $version, 'storage_path' => $path,
                        'updated_by' => $actor->id, 'updated_at' => now()];
                    if ($old) {
                        DB::table('branding_images')->where('id', $old->id)->update($data);
                        $id = $old->id;
                    } else {
                        $id = DB::table('branding_images')->insertGetId($data + [
                            'slot' => $slot, 'created_at' => now(),
                        ]);
                    }
                    $saved = DB::table('branding_images')->find($id);
                    AuditLogger::log($old ? 'update' : 'create', 'branding_images', $id,
                        $old ? ['slot' => $slot] + $this->metadata($old) : null,
                        ['slot' => $slot] + $this->metadata($saved));
                    if ($old) {
                        DB::afterCommit(fn () => $this->removeFile($old->storage_path));
                    }

                    return $this->all();
                }, 3);
            } catch (Throwable $error) {
                $this->removeFile($path);
                throw $error;
            }
        });
    }

    public function restore(User $actor, string $slot): array
    {
        self::slot($slot);
        $this->authorize($actor);

        return app(SystemWriteLock::class)->run(fn () => DB::transaction(function () use ($actor, $slot) {
            DB::table('company_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            $this->authorize(User::findOrFail($actor->id));
            $old = DB::table('branding_images')->where('slot', $slot)->lockForUpdate()->first();
            if ($old) {
                DB::table('branding_images')->where('id', $old->id)->delete();
                AuditLogger::log('delete', 'branding_images', $old->id,
                    ['slot' => $slot] + $this->metadata($old), null);
                DB::afterCommit(fn () => $this->removeFile($old->storage_path));
            }

            return $this->all();
        }, 3));
    }

    private function removeFile(string $path): void
    {
        // Cleanup is best effort after commit; it must never undo a saved setting.
        try {
            if ($this->safePath($path) && ! Storage::disk('local')->delete($path)) {
                Log::warning('A superseded branding image needs cleanup.');
            }
        } catch (Throwable $error) {
            report($error);
        }
    }

    public function safePath(string $path): bool
    {
        return (bool) preg_match('~^'.preg_quote(self::directory(), '~')
            .'/[a-f0-9-]{36}\.(png|jpg|webp)$~D', $path);
    }

    public function image(string $slot, string $version): object
    {
        self::slot($slot);
        abort_unless(Str::isUuid($version), 404);
        $row = DB::table('branding_images')->where('slot', $slot)->where('version', $version)->first();
        abort_unless($row && $this->safePath($row->storage_path)
            && Storage::disk('local')->exists($row->storage_path), 404);

        return $row;
    }
}
