<?php

declare(strict_types=1);

namespace App\Model;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * UploadGameTask.
 */
class UploadGameTask
{
    /**
     * @var string
     *
     * @Assert\NotBlank()
     * @Assert\Uuid()
     */
    private $hash;

    /**
     * @var string|null
     */
    private $regular;

    /**
     * @var UploadedFile|null
     *
     * @todo: use mime types
     *
     * @Assert\File(
     *     maxSize="1M",
     *     mimeTypesMessage="Please upload a valid PGN file"
     * )
     */
    private $file;

    /**
     * @var string
     *
     * @Assert\NotBlank()
     */
    private $recaptcha;

    public function __construct()
    {
        $this->hash = uuid_create();
    }

    /**
     * @return string
     */
    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * @return string|null
     */
    public function getRegular(): ?string
    {
        return $this->regular;
    }

    /**
     * @param string|null $regular
     *
     * @return $this
     */
    public function setRegular(?string $regular): self
    {
        $this->regular = $regular;

        return $this;
    }

    /**
     * @return UploadedFile|null
     */
    public function getFile(): ?UploadedFile
    {
        return $this->file;
    }

    /**
     * @param UploadedFile|null $file
     *
     * @return $this
     */
    public function setFile(?UploadedFile $file): self
    {
        $this->file = $file;

        return $this;
    }

    /**
     * @return string
     */
    public function getRecaptcha(): string
    {
        return $this->recaptcha;
    }

    /**
     * @param string $recaptcha
     *
     * @return $this
     */
    public function setRecaptcha(string $recaptcha): self
    {
        $this->recaptcha = $recaptcha;

        return $this;
    }
}
