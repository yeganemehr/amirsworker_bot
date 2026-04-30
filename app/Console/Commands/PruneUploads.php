<?php

namespace App\Console\Commands;

use App\Models\Upload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('uploads:prune')]
#[Description('Delete uploads whose retention window has passed.')]
class PruneUploads extends Command
{
    public function handle(): int
    {
        $expired = Upload::where('expires_at', '<', now())->get();
        $deleted = 0;

        foreach ($expired as $upload) {
            try {
                Storage::disk($upload->disk)->delete($upload->path);
            } catch (Throwable $e) {
                $this->error("Storage delete failed for {$upload->path}: {$e->getMessage()}");

                continue;
            }
            $upload->delete();
            $deleted++;
        }

        $this->info("Pruned {$deleted} upload(s).");

        return self::SUCCESS;
    }
}
