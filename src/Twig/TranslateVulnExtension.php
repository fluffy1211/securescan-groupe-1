<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\DescriptionTranslatorService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Filtre Twig `vuln_fr` : traduit une description de vulnérabilité en français.
 *
 * Usage dans les templates : {{ vuln.description|vuln_fr(vuln.title) }}
 */
class TranslateVulnExtension extends AbstractExtension
{
    public function __construct(
        private DescriptionTranslatorService $translator,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('vuln_fr', [$this, 'translateDescription']),
        ];
    }

    public function translateDescription(?string $description, ?string $checkId = null): ?string
    {
        return $this->translator->translate($description, $checkId);
    }
}
