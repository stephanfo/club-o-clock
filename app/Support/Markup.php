<?php

namespace App\Support;

use DOMDocument;
use DOMNode;
use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\MarkdownConverter;
use League\HTMLToMarkdown\HtmlConverter;

// Texte enrichi WYSIWYG (PRD §4.12.1). Stockage markdown, SANITISATION SERVEUR FAISANT FOI.
// Périmètre figé : gras/italique/barré, listes (puces/num.), liens externes, titres h2/h3, citation.
// Hors V1 : tableaux, images inline, couleurs, code, vidéos, alignement, tâches.
//
// Sécurité : le rendu passe par CommonMark (HTML brut RETIRÉ, liens non sûrs refusés) PUIS par
// une allowlist DOM qui RECONSTRUIT la sortie (jamais d'echo de l'entrée). Le markdown du client
// (TipTap) n'est jamais traité par un sanitizer HTML maison : il transite par le parseur durci.
class Markup
{
    /** Balises conservées telles quelles (hors void / hors <a> traité à part). */
    private const ALLOWED = ['p', 'strong', 'em', 'del', 'ul', 'ol', 'li', 'h2', 'h3', 'blockquote'];

    /** Balises void conservées. */
    private const VOID_ALLOWED = ['br'];

    /** Renommages : titres hors périmètre repliés sur h2/h3 ; alias d'inline. */
    private const RENAME = [
        'h1' => 'h2', 'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3',
        'b' => 'strong', 'i' => 'em', 's' => 'del', 'strike' => 'del',
    ];

    /** Balises supprimées avec leur contenu (jamais émises par notre markdown, défense en profondeur). */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'img', 'hr', 'video', 'audio', 'link', 'meta', 'form', 'input'];

    /** Rendu HTML sûr du markdown stocké (à insérer via {!! !!}). */
    public static function render(?string $markdown): HtmlString
    {
        return new HtmlString(self::toSafeHtml((string) $markdown));
    }

    /**
     * Normalise le markdown reçu pour le STOCKAGE : il est rendu en HTML sûr (allowlist) puis
     * reconverti en markdown canonique → la valeur stockée ne contient que le sous-ensemble autorisé.
     * Renvoie null si le contenu est visuellement vide.
     */
    public static function clean(?string $markdown): ?string
    {
        $safeHtml = self::toSafeHtml((string) $markdown);

        // Vide = aucun texte ET aucun lien.
        if (trim(strip_tags($safeHtml)) === '' && ! str_contains($safeHtml, '<a ')) {
            return null;
        }

        $converter = new HtmlConverter([
            'strip_tags' => true,
            'header_style' => 'atx',
            'hard_break' => true,
        ]);
        // Barré : la lib ne convertit pas <del> → on ajoute le converter `~~…~~` (cf. render()).
        $converter->getEnvironment()->addConverter(new StrikethroughConverter);

        $markdown = trim($converter->convert($safeHtml));

        return $markdown === '' ? null : mb_substr($markdown, 0, 20000);
    }

    /** markdown → HTML sûr : CommonMark durci + reconstruction par allowlist DOM. */
    private static function toSafeHtml(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        $env = new Environment([
            'html_input' => 'strip',         // HTML brut retiré (rendu neutre, §4.12.1)
            'allow_unsafe_links' => false,   // refuse javascript:, data:, etc.
            'max_nesting_level' => 20,
        ]);
        $env->addExtension(new CommonMarkCoreExtension);
        $env->addExtension(new StrikethroughExtension);

        $html = (string) (new MarkdownConverter($env))->convert($markdown);

        return self::allowlist($html);
    }

    /** Reconstruit une chaîne HTML ne contenant que les balises/attributs autorisés. */
    private static function allowlist(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $wrapper = $doc->getElementsByTagName('div')->item(0);

        return $wrapper ? self::emitChildren($wrapper) : '';
    }

    private static function emitChildren(DOMNode $node): string
    {
        $out = '';
        foreach (iterator_to_array($node->childNodes) as $child) {
            $out .= self::emitNode($child);
        }

        return $out;
    }

    private static function emitNode(DOMNode $n): string
    {
        if ($n->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($n->nodeValue, ENT_QUOTES, 'UTF-8');
        }
        if ($n->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tag = strtolower($n->nodeName);
        $tag = self::RENAME[$tag] ?? $tag;

        if (in_array($tag, self::DROP, true)) {
            return '';
        }
        if (in_array($tag, self::VOID_ALLOWED, true)) {
            return '<'.$tag.'>';
        }

        if ($tag === 'a') {
            $inner = self::emitChildren($n);
            $href = self::safeHref($n->getAttribute('href'));
            if ($href === null) {
                return $inner; // lien non sûr → on garde le texte, on retire l'ancre.
            }

            return '<a href="'.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').'" target="_blank" rel="noopener noreferrer">'.$inner.'</a>';
        }

        if (in_array($tag, self::ALLOWED, true)) {
            return '<'.$tag.'>'.self::emitChildren($n).'</'.$tag.'>';
        }

        // Balise inconnue / hors périmètre (code, pre, span, table…) → on déballe le contenu.
        return self::emitChildren($n);
    }

    /** Schémas autorisés pour les liens : http(s) et mailto uniquement. */
    private static function safeHref(string $href): ?string
    {
        $href = trim($href);

        return preg_match('#^(https?://|mailto:)#i', $href) === 1 ? $href : null;
    }
}
