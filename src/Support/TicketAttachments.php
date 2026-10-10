<?php

namespace App\Support;

use App\Entity\SupportTicket;
use App\Service\ImageUploadProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class TicketAttachments
{
    public const MAX_FILES = 3;
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'video/mp4' => 'mp4', 'application/pdf' => 'pdf'];

    public function __construct(#[Autowire('%kernel.project_dir%/var/support')] private readonly string $directory, private readonly ImageUploadProcessor $images)
    {
    }

    public function validate(array $files): bool
    {
        if (count($files) > self::MAX_FILES) return false;
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() < 1
                || $file->getSize() > self::MAX_BYTES || !isset(self::TYPES[$file->getMimeType() ?? ''])) return false;
        }

        return true;
    }

    public function store(SupportTicket $ticket, array $files): array
    {
        $stored = [];
        try {
            foreach ($files as $file) {
                $mime = $file->getMimeType();
                $name = mb_substr(preg_replace('/[\\x00-\\x1f\\x7f\\/\\\\\\\\]/u', '_', $file->getClientOriginalName()) ?? 'attachment', 0, 180);
                $folder = $this->directory.'/'.$ticket->getTrackingToken();
                if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
                    throw new \RuntimeException('Unable to create attachment storage.');
                }
                $compress = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
                $storedName = bin2hex(random_bytes(16)).'.'.($compress ? 'webp' : self::TYPES[$mime]);
                if ($compress) {
                    // Keep screenshots readable, proportional and uncropped. GIFs retain animation.
                    $size = $this->images->writeWebp($file->getPathname(), $folder.'/'.$storedName, 2560, $mime === 'image/jpeg' ? 85 : null);
                    $name = (pathinfo($name, PATHINFO_FILENAME) ?: 'attachment').'.webp';
                    $mime = 'image/webp';
                } else {
                    $size = $file->getSize();
                    $file->move($folder, $storedName);
                }
                $stored[] = ['name' => $name, 'storedName' => $storedName, 'mime' => $mime, 'size' => $size];
                chmod($folder.'/'.$storedName, 0600);
            }
        } catch (\Throwable $error) {
            $this->remove($ticket, $stored);
            throw $error;
        }

        return $stored;
    }

    public function path(SupportTicket $ticket, array $file): string
    {
        return $this->directory.'/'.$ticket->getTrackingToken().'/'.$file['storedName'];
    }

    public function remove(SupportTicket $ticket, array $files): void
    {
        foreach ($files as $file) {
            $path = $this->path($ticket, $file);
            if (is_file($path)) unlink($path);
        }
    }

    public function removeTicketFiles(string $token): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('Invalid attachment directory.');
        }
        (new \Symfony\Component\Filesystem\Filesystem())->remove($this->directory.'/'.$token);
    }
}
