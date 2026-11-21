<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exception\ValidationException;
use App\Service\ImageUploader;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[CoversClass(ImageUploader::class)]
final class ImageUploaderTest extends TestCase
{
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/inkwell-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/src', 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/{src,out}/*', GLOB_BRACE) ?: []);
        @rmdir($this->dir . '/src');
        @rmdir($this->dir . '/out');
        @rmdir($this->dir);
    }

    public function test_rejects_file_with_disallowed_mime(): void
    {
        $path = $this->dir . '/src/note.png';
        file_put_contents($path, 'just some text, not an image');
        $file = new UploadedFile($path, (int) filesize($path), UPLOAD_ERR_OK, 'note.png', 'image/png');
        $uploader = $this->uploader();

        $this->expectException(ValidationException::class);

        $uploader->store($file);
    }

    public function test_stores_valid_png_with_random_name(): void
    {
        $path = $this->dir . '/src/pixel.png';
        file_put_contents($path, base64_decode(self::PNG_BASE64, true));
        $file = new UploadedFile($path, (int) filesize($path), UPLOAD_ERR_OK, 'evil.php', 'image/png');
        $uploader = $this->uploader();

        $publicPath = $uploader->store($file);

        self::assertMatchesRegularExpression('~^/uploads/[0-9a-f]{32}\.png$~', $publicPath);
        self::assertFileExists($this->dir . '/out/' . basename($publicPath));
    }

    public function test_delete_ignores_paths_that_are_not_stored_uploads(): void
    {
        mkdir($this->dir . '/out', 0755, true);
        $keep = $this->dir . '/out/keep.png';
        file_put_contents($keep, 'x');
        $uploader = $this->uploader();

        $uploader->delete('/uploads/../uploads/keep.png');
        $uploader->delete('/uploads/keep.png');

        self::assertFileExists($keep);
    }

    private function uploader(): ImageUploader
    {
        return new ImageUploader($this->dir . '/out', '/uploads/', 1048576, ['image/png', 'image/jpeg']);
    }
}
