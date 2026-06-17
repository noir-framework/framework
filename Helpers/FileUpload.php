<?php

declare(strict_types=1);

namespace Noirapi\Helpers;

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

    public function min(int $min): self
    {
        $this->min = $min;

        return $this;
    }

    public function max(int $max): self
    {
        $this->max = $max;

        return $this;
    }

    /**
     * @param string $contentType
     * @return $this
     * @noinspection PhpUnused
     */
    public function allowedContentType(string $contentType): self
    {
        $this->contentTypes[] = $contentType;

        return $this;
    }

    /**
     * @param string $extension
     * @return $this
     * @noinspection PhpUnused
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
     * @return $this
     * @noinspection PhpUnused
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
     * @return $this
     * @noinspection PhpUnused
     */
    public function maxDimension(int $width, int $height): self
    {
        $this->maxWidth = $width;
        $this->maxHeight = $height;

        return $this;
    }

    /**
     * @return true
     * @throws FileUploadValidation
     */
    public function process(): true
    {

        if ($this->file['error'] !== UPLOAD_ERR_OK) {
            throw new FileUploadValidation('File Upload error');
        }

        if ($this->min !== null && $this->file['size'] < $this->min) {
            throw new FileUploadValidation('File is too small');
        }

        /** @noinspection InsufficientTypesControlInspection */
        if ($this->max !== null && $this->file['size'] > $this->max) {
            throw new FileUploadValidation('File is too large');
        }

        if (! empty($this->contentTypes) && ! in_array($this->file['type'], $this->contentTypes, true)) {
            throw new FileUploadValidation('Invalid mime type');
        }

        if ($this->extension !== null && pathinfo($this->file['name'], PATHINFO_EXTENSION) !== $this->extension) {
            throw new FileUploadValidation('Invalid file extension');
        }

        if (in_array($this->file['type'], $this->image_types, true)) {
            $needsDimensions = $this->minWidth !== null || $this->minHeight !== null
                || $this->maxWidth !== null || $this->maxHeight !== null;

            if ($needsDimensions) {
                if (! $this->isImage()) {
                    throw new FileUploadValidation('File is not an image');
                }

                /** @noinspection InsufficientTypesControlInspection */
                $image = getimagesize($this->file['tmp_name']);

                if ($this->minWidth !== null && $image[0] < $this->minWidth) {
                    throw new FileUploadValidation('Image width too small');
                }

                if ($this->minHeight !== null && $image[1] < $this->minHeight) {
                    throw new FileUploadValidation('Image height too small');
                }

                if ($this->maxWidth !== null && $image[0] > $this->maxWidth) {
                    throw new FileUploadValidation('Image width too big');
                }

                if ($this->maxHeight !== null && $image[1] > $this->maxHeight) {
                    throw new FileUploadValidation('Image height too big');
                }
            }
        }

        return true;
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
     * @noinspection PhpUnused
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
     * @return string
     * @noinspection PhpUnused
     * @noinspection GetSetMethodCorrectnessInspection
     */
    public function getContentType(): string
    {
        return $this->file['type'];
    }

    /**
     * @return int
     */
    public function getSize(): int
    {
        return $this->file['size'];
    }

    /**
     * @return string
     * @noinspection PhpUnused
     */
    public function getFullPath(): string
    {
        return $this->file['tmp_name'];
    }

    /**
     * @return string
     * @noinspection GetSetMethodCorrectnessInspection
     * @noinspection PhpUnused
     */
    public function getExtension(): string
    {
        return strtolower(pathinfo($this->file['name'], PATHINFO_EXTENSION));
    }

    /**
     * @return bool
     * @noinspection PhpUnused
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
        return in_array($this->file['type'], $this->image_types, true);
    }
}
