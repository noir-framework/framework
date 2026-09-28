<?php

declare(strict_types=1);

namespace Noirapi\Tests;

use Noirapi\Exceptions\FileUploadValidation;
use Noirapi\Helpers\FileUpload;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class FileUploadTest extends TestCase
{
    /** A 2x3 PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAEElEQVR4nGP4z8AARAwoFABE0AX7pM/egAAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $files = [];

    #[After]
    public function removeFiles(): void
    {
        array_map(unlink(...), array_filter($this->files, is_file(...)));
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function upload(string $content, string $name, string $claimedType): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'noirapi-upload-');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return ['name' => $name, 'type' => $claimedType, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)]; //phpcs:ignore
    }

    public function testContentTypeComesFromTheContent(): void
    {
        $fake = FileUpload::validate($this->upload('<?php echo 1;', 'shell.png', 'image/png'));

        self::assertSame('image/png', $fake->getClientContentType());
        self::assertNotSame('image/png', $fake->getContentType());

        $this->expectException(FileUploadValidation::class);
        $this->expectExceptionMessage('Invalid mime type');
        $fake->allowedContentType('image/png')->process();
    }

    public function testRealImagePasses(): void
    {
        $upload = FileUpload::validate($this->upload((string) base64_decode(self::PNG), 'Photo.PNG', 'text/plain'));

        self::assertTrue($upload->allowedContentType('image/png')->extension('.png')->minDimension(1, 1)->maxDimension(2, 3)->process()); //phpcs:ignore
        self::assertSame('image/png', $upload->getContentType());
        self::assertSame('png', $upload->getExtension());
    }

    public function testWildcardType(): void
    {
        $upload = FileUpload::validate($this->upload((string) base64_decode(self::PNG), 'a.png', ''));

        self::assertTrue($upload->allowedContentType('image/*')->process());
    }

    public function testDimensions(): void
    {
        $upload = FileUpload::validate($this->upload((string) base64_decode(self::PNG), 'a.png', 'image/png'));

        $this->expectExceptionMessage('Image dimensions too big');
        $upload->maxDimension(2, 2)->process();
    }

    public function testDimensionsOnANonImage(): void
    {
        $upload = FileUpload::validate($this->upload('plain text', 'a.png', 'image/png'));

        $this->expectExceptionMessage('File is not an image');
        $upload->minDimension(1, 1)->process();
    }

    public function testExtensionIsCaseInsensitive(): void
    {
        $upload = FileUpload::validate($this->upload('x', 'REPORT.CSV', 'text/csv'));

        self::assertTrue($upload->extension('csv')->process());

        $this->expectExceptionMessage('Invalid file extension');
        FileUpload::validate($this->upload('x', 'report.txt', 'text/csv'))->extension('csv')->process();
    }

    public function testSizeLimitsAndErrors(): void
    {
        $upload = $this->upload('12345', 'a.txt', 'text/plain');

        self::assertTrue(FileUpload::validate($upload)->min(5)->max(5)->process());

        $this->expectExceptionMessage('File is too large');
        FileUpload::validate($upload)->max(4)->process();
    }

    public function testUploadError(): void
    {
        $upload = FileUpload::validate(['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]); //phpcs:ignore

        self::assertTrue($upload->isNoFile());
        self::assertSame('application/octet-stream', $upload->getContentType());

        $this->expectExceptionMessage('File Upload error');
        $upload->process();
    }
}
