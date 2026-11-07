<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SystemResetFiles
{
    public function archive(string $directory): ?array
    {
        $archives = [];
        try {
            foreach (['carpets' => 'carpets', BrandingImages::directory() => 'branding', BusinessSupport::receiptDirectory() => 'expense-receipts'] as $source => $name) {
                $archive = $this->archiveDirectory($source, $directory.'/'.$name);
                if ($archive) {
                    $archives[] = $archive;
                }
            }
        } catch (Throwable $error) {
            $this->restore($archives ?: null);
            throw $error;
        }

        return $archives ?: null;
    }

    private function archiveDirectory(string $relative, string $target): ?array
    {
        $source = Storage::disk('local')->path($relative);
        if (! is_dir($source)) {
            return null;
        }
        $root = realpath(Storage::disk('local')->path(''));
        $resolved = realpath($source);
        $expected = $root ? $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative) : null;
        if (! $root || ! $resolved || is_link($source)
            || $resolved !== $expected) {
            throw new RuntimeException('The upload directory could not be verified.');
        }
        $parent = realpath(dirname($target));
        if (! $parent || str_starts_with($parent, $resolved.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The upload backup location is invalid.');
        }
        if (! rename($resolved, $target)) {
            throw new RuntimeException('Uploaded images could not be archived. No reset was performed.');
        }

        return ['source' => $resolved, 'target' => $target];
    }

    public function restore(?array $archive): void
    {
        foreach (array_reverse($archive ?? []) as $directory) {
            if (! rename($directory['target'], $directory['source'])) {
                throw new RuntimeException('Image recovery needs the private reset backup.');
            }
        }
    }
}
