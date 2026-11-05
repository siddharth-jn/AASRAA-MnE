<?php

namespace App\Console\Commands;

use App\Models\EvidenceFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('impact:migrate-evidence-storage {--to= : Destination filesystem disk; defaults to EVIDENCE_DISK} {--execute : Copy files and update evidence records}')]
#[Description('Preview or safely copy evidence files to deployment object storage without deleting source files')]
class MigrateEvidenceStorage extends Command
{
    public function handle(): int
    {
        $destination = (string) ($this->option('to') ?: config('impact.evidence_disk', 'local'));
        if (! config("filesystems.disks.{$destination}")) {
            $this->components->error("Filesystem disk [{$destination}] is not configured.");

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $statistics = [
            'selected' => 0,
            'copied' => 0,
            'already_present' => 0,
            'missing' => 0,
            'failed' => 0,
        ];

        EvidenceFile::query()
            ->where(fn ($query) => $query->whereNull('disk')->orWhere('disk', '!=', $destination))
            ->orderBy('id')
            ->chunkById(100, function ($files) use ($destination, $execute, &$statistics): void {
                foreach ($files as $file) {
                    $statistics['selected']++;
                    $sourceName = $file->disk ?: 'local';
                    $source = Storage::disk($sourceName);
                    $target = Storage::disk($destination);

                    try {
                        if (! $source->exists($file->path)) {
                            $statistics['missing']++;
                            $this->components->warn("Evidence #{$file->id} is missing from [{$sourceName}]: {$file->path}");

                            continue;
                        }

                        $sourceSize = $source->size($file->path);
                        $alreadyPresent = $target->exists($file->path) && $target->size($file->path) === $sourceSize;
                        if ($execute && ! $alreadyPresent) {
                            $stream = $source->readStream($file->path);
                            if (! is_resource($stream)) {
                                throw new \RuntimeException('The source file could not be opened.');
                            }

                            try {
                                $target->writeStream($file->path, $stream, ['visibility' => 'private']);
                            } finally {
                                fclose($stream);
                            }

                            if (! $target->exists($file->path) || $target->size($file->path) !== $sourceSize) {
                                throw new \RuntimeException('Destination verification failed after copying.');
                            }
                        }

                        if ($execute) {
                            $file->update(['disk' => $destination]);
                        }

                        $statistics[$alreadyPresent ? 'already_present' : 'copied']++;
                    } catch (Throwable $exception) {
                        report($exception);
                        $statistics['failed']++;
                        $this->components->error("Evidence #{$file->id} failed: {$exception->getMessage()}");
                    }
                }
            });

        $this->table(['Result', 'Count'], [
            ['Evidence records selected', $statistics['selected']],
            [$execute ? 'Files copied' : 'Files that would be copied', $statistics['copied']],
            ['Matching files already at destination', $statistics['already_present']],
            ['Missing source files', $statistics['missing']],
            ['Failed files', $statistics['failed']],
        ]);
        $this->components->info($execute
            ? 'Evidence migration finished. Source files were preserved.'
            : 'Preview complete. No files or database records were changed; rerun with --execute after reviewing the result.');

        return ($statistics['missing'] + $statistics['failed']) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
