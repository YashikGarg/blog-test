<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ValidationException;
use Psr\Http\Message\UploadedFileInterface;

final readonly class ImageUploader
{
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    private const STORED_PATH = '~^/uploads/[a-f0-9]{32}\.(?:jpg|png|webp|gif)$~';

    /** @param list<string> $allowedMimes */
    public function __construct(
        private string $uploadDir,
        private string $publicPrefix,
        private int $maxBytes,
        private array $allowedMimes,
    ) {
    }

    /** @return string the public path, for example /uploads/ab12….png */
    public function store(UploadedFileInterface $file, string $field = 'cover'): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException([$field => 'The upload failed. Try again.']);
        }
        if ($file->getSize() === null || $file->getSize() > $this->maxBytes) {
            throw new ValidationException([$field => 'The image is too large (max ' . intdiv($this->maxBytes, 1048576) . ' MB)']);
        }

        $path = (string) $file->getStream()->getMetadata('uri');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($mime) || !in_array($mime, $this->allowedMimes, true) || !isset(self::EXTENSIONS[$mime])) {
            throw new ValidationException([$field => 'Only JPEG, PNG, WebP or GIF images are allowed']);
        }
        if (@getimagesize($path) === false) {
            throw new ValidationException([$field => 'This file is not a valid image']);
        }

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
        // Random name, extension from the verified MIME type: the client's filename is never used.
        $name = bin2hex(random_bytes(16)) . '.' . self::EXTENSIONS[$mime];
        $file->moveTo($this->uploadDir . '/' . $name);

        return $this->publicPrefix . $name;
    }

    /** Deletes a stored upload. Anything that is not exactly a name this class generated is ignored (no path from input reaches unlink). */
    public function delete(string $publicPath): void
    {
        if (preg_match(self::STORED_PATH, $publicPath) !== 1) {
            return;
        }
        $file = $this->uploadDir . '/' . basename($publicPath);
        if (is_file($file)) {
            unlink($file);
        }
    }

    /** @return list<string> every stored-upload path that appears in a markdown body */
    public function pathsIn(string $markdown): array
    {
        preg_match_all('~/uploads/[a-f0-9]{32}\.(?:jpg|png|webp|gif)~', $markdown, $m);

        return array_values(array_unique($m[0]));
    }
}
