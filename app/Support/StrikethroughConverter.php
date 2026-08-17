<?php

namespace App\Support;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

// Convertit le barré HTML (<del>/<s>/<strike>) en markdown `~~…~~`. La lib html-to-markdown ne
// fournit pas ce converter ; sans lui, Markup::clean() perdait le barré au stockage (le texte
// restait, le balisage disparaissait). Sortie symétrique du StrikethroughExtension de CommonMark
// utilisé au rendu (Markup::render).
class StrikethroughConverter implements ConverterInterface
{
    public function convert(ElementInterface $element): string
    {
        $value = $element->getValue();

        // Texte vide / blanc : pas de balisage (cohérent avec EmphasisConverter).
        if (! \trim($value)) {
            return $value;
        }

        $prefix = \ltrim($value) !== $value ? ' ' : '';
        $suffix = \rtrim($value) !== $value ? ' ' : '';

        return $prefix.'~~'.\trim($value).'~~'.$suffix;
    }

    /** @return string[] */
    public function getSupportedTags(): array
    {
        return ['del', 's', 'strike'];
    }
}
