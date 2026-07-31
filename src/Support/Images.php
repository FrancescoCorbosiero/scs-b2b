<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Immagini delle pagine pubbliche.
 *
 * Di default si usano le illustrazioni SVG in `public/img/`. Per passare a
 * FOTO REALI basta metterle in `public/img/custom/` con lo stesso nome
 * (`hero.jpg`, `cta.jpg`, `network.jpg`, `pattern.png`, …): vengono usate
 * automaticamente, senza toccare i template. L'URL porta in coda il
 * timestamp del file, così il browser non serve la versione vecchia.
 *
 * Formati accettati: jpg, jpeg, png, webp, avif, svg.
 */
final class Images
{
    /** nome logico => illustrazione di default */
    private const DEFAULTS = [
        'hero' => '/img/hero-bg.svg',        // sfondo delle intestazioni scure
        'cta' => '/img/cta-boxes.svg',       // blocchi call-to-action
        'network' => '/img/europe-network.svg', // copertura spedizioni
        'pattern' => '/img/pattern-dots.svg',   // texture leggera sezioni chiare
    ];

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'svg'];

    /** @var array<string, string>|null */
    private ?array $resolved = null;

    public function __construct(private readonly Config $config)
    {
    }

    /** @return array<string, string> nome logico => URL da usare nei template */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $images = self::DEFAULTS;
        $dir = $this->config->rootPath() . '/public/img/custom';
        foreach (array_keys(self::DEFAULTS) as $name) {
            foreach (self::EXTENSIONS as $ext) {
                $file = $dir . '/' . $name . '.' . $ext;
                if (is_file($file)) {
                    $images[$name] = '/img/custom/' . $name . '.' . $ext . '?v=' . (int) filemtime($file);
                    break;
                }
            }
        }

        return $this->resolved = $images;
    }
}
