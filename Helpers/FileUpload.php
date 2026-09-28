<?php

declare(strict_types=1);

namespace Noirapi\Helpers;

use finfo;
use Noirapi\Exceptions\FileUploadValidation;

/**
 * @psalm-suppress MissingConstructor
 * @psalm-api
 */
class FileUpload
{
    private array $file;
    private ?int $min = null;
    private ?int $max = null;
    private array $contentTypes = [];
    private ?string $extension = null;
    private ?int $maxWidth = null;
    private ?int $maxHeight = null;
    private ?int $minWidth = null;
    private ?int $minHeight = null;
    private ?string $detectedType = null;

    private array $image_types = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * @param array $file
     * @return self
     * @noinspection PhpUnused
     */
    public static function validate(array $file): self
    {

        $static = new self();
        $static->file = $file;

        return $static;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function min(int $min): self
    {
        $this->min = $min;

        return $this;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function max(int $max): self
    {
        $this->max = $max;

        return $this;
    }

    /**
     * @param string $contentType
     *
     * @return $this
     *
     * @noinspection PhpUnused
     *
     * @psalm-external-mutation-free
     */
    public function allowedContentType(string $contentType): self
    {
        $this->contentTypes[] = $contentType;

        return $this;
    }

    /**
     * @param string $extension
     *
     * @return $this
     *
     * @noinspection PhpUnused
     *
     * @psalm-external-mutation-free
     */
    public function extension(string $extension): self
    {
        if (str_starts_with($extension, '.')) {
            $extension = substr($extension, 1);
        }
        $this->extension = $extension;

        return $this;
    }

    /**
     * @param int $width
     * @param int $height
     *
     * @return $this
     *
     * @noinspection PhpUnused
     *
     * @psalm-external-mutation-free
     */
    public function minDimension(int $width, int $height): self
    {
        $this->minWidth = $width;
        $this->minHeight = $height;

        return $this;
    }

    /**
     * @param int $width
     * @param int $height
     *
     * @return $this
     *
     * @noinspection PhpUnused
     *
     * @psalm-external-mutation-free
     */
    public function maxDimension(int $width, int $height): self
    {
        $this->maxWidth = $width;
        $this->maxHeight = $height;

        return $this;
    }

    /**
     * @return true
     *
     * @throws FileUploadValidation
     */
    public function process(): true
    {
        $this->validateBasics();
        $this->validateContentType();

        if (array_filter([$this->minWidth, $this->minHeight, $this->maxWidth, $this->maxHeight], is_int(...)) !== []) {
            $this->validateImageDimensions();
        }

        return true;
    }

    /**
     * @return void
     * @throws FileUploadValidation
     *
     * @psalm-mutation-free
     */
    private function validateBasics(): void
    {
        if (($this->file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new FileUploadValidation('File Upload error');
        }

        if (self::below((int) $this->file['size'], $this->min)) {
            throw new FileUploadValidation('File is too small');
        }

        if (self::above((int) $this->file['size'], $this->max)) {
            throw new FileUploadValidation('File is too large');
        }

        if ($this->extension !== null && $this->getExtension() !== strtolower($this->extension)) {
            throw new FileUploadValidation('Invalid file extension');
        }
    }

    /**
     * Allowed types are checked against the file's content (finfo), never against the
     * Content-Type the browser sent. "image/*" style wildcards are accepted.
     *
     * @return void
     * @throws FileUploadValidation
     */
    private function validateContentType(): void
    {
        if (empty($this->contentTypes)) {
            return;
        }

        $detected = $this->getContentType();

        foreach ($this->contentTypes as $allowed) {
            $allowed = strtolower($allowed);
            if ($allowed === $detected || (str_ends_with($allowed, '/*') && str_starts_with($detected, substr($allowed, 0, -1)))) { //phpcs:ignore
                return;
            }
        }

        throw new FileUploadValidation('Invalid mime type');
    }

    /**
     * @return void
     * @throws FileUploadValidation
     */
    private function validateImageDimensions(): void
    {
        if (! $this->isImage()) {
            throw new FileUploadValidation('File is not an image');
        }

        $image = getimagesize($this->file['tmp_name']);
        if ($image === false) {
            throw new FileUploadValidation('File is not an image');
        }

        [$width, $height] = $image;

        if (self::below($width, $this->minWidth) || self::below($height, $this->minHeight)) {
            throw new FileUploadValidation('Image dimensions too small');
        }

        if (self::above($width, $this->maxWidth) || self::above($height, $this->maxHeight)) {
            throw new FileUploadValidation('Image dimensions too big');
        }
    }

    /**
     * @psalm-pure
     */
    private static function below(int $value, ?int $limit): bool
    {
        return $limit !== null && $value < $limit;
    }

    /**
     * @psalm-pure
     */
    private static function above(int $value, ?int $limit): bool
    {
        return $limit !== null && $value > $limit;
    }

    /**
     * @param string $path
     * @return bool
     * @noinspection PhpUnused
     */
    public function moveTo(string $path): bool
    {
        return move_uploaded_file($this->file['tmp_name'], $path);
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     *
     * @psalm-mutation-free
     */
    public function getFileName(): string
    {
        return $this->file['name'];
    }

    /**
     * @return string
     * @throws FileUploadValidation
     */
    public function getFile(): string
    {
        $file = file_get_contents($this->file['tmp_name']);
        return is_string($file) ? $file : throw new FileUploadValidation('File is not readable');
    }

    /**
     * The MIME type detected from the file's content (finfo). "application/octet-stream" when
     * it can't be read.
     *
     * @return string
     *
     * @noinspection PhpUnused
     * @noinspection GetSetMethodCorrectnessInspection
     */
    public function getContentType(): string
    {
        if ($this->detectedType !== null) {
            return $this->detectedType;
        }

        $path = (string) ($this->file['tmp_name'] ?? '');
        $type = $path !== '' && is_file($path) ? new finfo(FILEINFO_MIME_TYPE)->file($path) : false;

        return $this->detectedType = is_string($type) && $type !== '' ? strtolower($type) : 'application/octet-stream';
    }

    /**
     * The Content-Type the browser claimed. Client-controlled: never use it for decisions.
     *
     * @return string
     *
     * @psalm-mutation-free
     */
    public function getClientContentType(): string
    {
        return (string) ($this->file['type'] ?? '');
    }

    /**
     * @return int
     *
     * @psalm-mutation-free
     */
    public function getSize(): int
    {
        return $this->file['size'];
    }

    /**
     * @return string
     *
     * @noinspection PhpUnused
     *
     * @psalm-mutation-free
     */
    public function getFullPath(): string
    {
        return $this->file['tmp_name'];
    }

    /**
     * @return string
     *
     * @noinspection GetSetMethodCorrectnessInspection
     * @noinspection PhpUnused
     *
     * @psalm-mutation-free
     */
    public function getExtension(): string
    {
        return strtolower(pathinfo($this->file['name'], PATHINFO_EXTENSION));
    }

    /**
     * @return bool
     *
     * @noinspection PhpUnused
     *
     * @psalm-mutation-free
     */
    public function isNoFile(): bool
    {
        return isset($this->file['error']) && $this->file['error'] === UPLOAD_ERR_NO_FILE;
    }

    /**
     * @return bool
     */
    private function isImage(): bool
    {
        return in_array($this->getContentType(), $this->image_types, true);
    }
}
