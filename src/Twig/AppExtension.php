<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * AppExtension.
 */
class AppExtension extends AbstractExtension
{
    private $appHost;

    /**
     * @param string $appHost
     */
    public function __construct(string $appHost)
    {
        $this->appHost = $appHost;
    }

    /** {@inheritDoc} */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('appHost', [$this, 'getAppHost']),
        ];
    }

    /**
     * @return string
     */
    public function getAppHost(): string
    {
        return $this->appHost;
    }
}
