<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PartnerCompanyLogo
{
    public const MAX_KILOBYTES = 2048;
    public const DISK = 'partner_logos';

    /** @return array{logoKey: string, logoUrl: string} */
    public function store(string $profileId, UploadedFile $file): array
    {
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        };
        $disk = Storage::disk(self::DISK);
        $key = $disk->putFileAs($this->folder($profileId), $file, Str::random(40).'.'.$extension, [
            'visibility' => 'public',
            'ContentType' => $file->getMimeType(),
        ]);
        if ($key === false) {
            throw new \RuntimeException('Company logo could not be written.');
        }

        $config = config('filesystems.disks.'.self::DISK);

        return ['logoKey' => $key, 'logoUrl' => rtrim($config['endpoint'], '/').'/'.$config['bucket'].'/'.$key];
    }

    public function remove(string $profileId, ?string $key): void
    {
        if ($key === null) {
            return;
        }
        $folder = $this->folder($profileId).'/';
        if (! str_starts_with($key, $folder)
            || ! preg_match('/\A[A-Za-z0-9]{40}\.(?:jpg|png|webp)\z/', substr($key, strlen($folder)))) {
            return;
        }

        try {
            Storage::disk(self::DISK)->delete($key);
        } catch (\Throwable $error) {
            report($error);
        }
    }

    private function folder(string $profileId): string
    {
        return 'partner-logos/'.strtolower($profileId);
    }
}
