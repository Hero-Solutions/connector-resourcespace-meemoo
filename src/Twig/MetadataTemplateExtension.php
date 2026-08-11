<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class MetadataTemplateExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('normalize_metadata_text', [self::class, 'normalizeMetadataText']),
        ];
    }

    public static function normalizeMetadataText(mixed $value): string
    {
        $normalized = html_entity_decode(
            (string) ($value ?? ''),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $normalized = preg_replace(
            '/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u',
            '',
            $normalized
        ) ?? $normalized;
        $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
