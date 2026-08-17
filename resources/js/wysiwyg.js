// Îlot WYSIWYG TipTap (PRD §4.12.1). Composant Alpine `wysiwyg` (x-data dans <x-wysiwyg>).
// Périmètre figé : gras, italique, barré, listes (puces / numérotées), liens externes, titres
// h2/h3, citation. Sortie MARKDOWN poussée vers la propriété Livewire `model`. La sanitisation
// fait foi côté serveur (App\Support\Markup) — ici, édition seule.
import { Editor } from '@tiptap/core';
import Document from '@tiptap/extension-document';
import Paragraph from '@tiptap/extension-paragraph';
import Text from '@tiptap/extension-text';
import Bold from '@tiptap/extension-bold';
import Italic from '@tiptap/extension-italic';
import Strike from '@tiptap/extension-strike';
import BulletList from '@tiptap/extension-bullet-list';
import OrderedList from '@tiptap/extension-ordered-list';
import ListItem from '@tiptap/extension-list-item';
import Heading from '@tiptap/extension-heading';
import Blockquote from '@tiptap/extension-blockquote';
import HardBreak from '@tiptap/extension-hard-break';
import History from '@tiptap/extension-history';
import Link from '@tiptap/extension-link';
import { Markdown } from 'tiptap-markdown';

function wysiwyg({ model, markdown, placeholder, minHeight }) {
    // L'instance Editor vit dans cette closure, PAS dans le state Alpine. Si on la posait sur `this`
    // (ex. this.editor), Alpine la rendrait réactive (Proxy). TipTap/ProseMirror comparent leurs
    // objets par identité de référence (tr.before === state.doc) ; à travers le Proxy ces égalités
    // cassent → « Applying a mismatched transaction » au premier toggle. La closure garde l'objet brut.
    let editor = null;

    return {
        active: { bold: false, italic: false, strike: false, ul: false, ol: false, blockquote: false, h2: false, h3: false, link: false },

        init() {
            editor = new Editor({
                element: this.$refs.editor,
                extensions: [
                    Document, Paragraph, Text, Bold, Italic, Strike,
                    BulletList, OrderedList, ListItem,
                    Heading.configure({ levels: [2, 3] }),
                    Blockquote, HardBreak, History,
                    Link.configure({
                        openOnClick: false,
                        autolink: true,
                        protocols: ['http', 'https', 'mailto'],
                        HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
                    }),
                    Markdown.configure({ html: false, linkify: false, breaks: false, transformPastedText: true }),
                ],
                content: markdown || '',
                editorProps: {
                    attributes: {
                        class: 'wys-area',
                        role: 'textbox',
                        'aria-multiline': 'true',
                        'data-ph': placeholder || '',
                        style: `min-height:${minHeight || 160}px`,
                    },
                },
                onCreate: () => this.refresh(),
                onUpdate: () => { this.sync(); this.refresh(); },
                onSelectionUpdate: () => this.refresh(),
            });
        },

        destroy() {
            editor?.destroy();
            editor = null;
        },

        sync() {
            this.$wire.set(model, editor.storage.markdown.getMarkdown(), false);
        },

        refresh() {
            this.active = {
                bold: editor.isActive('bold'),
                italic: editor.isActive('italic'),
                strike: editor.isActive('strike'),
                ul: editor.isActive('bulletList'),
                ol: editor.isActive('orderedList'),
                blockquote: editor.isActive('blockquote'),
                h2: editor.isActive('heading', { level: 2 }),
                h3: editor.isActive('heading', { level: 3 }),
                link: editor.isActive('link'),
            };
        },

        cmd(name) {
            const c = editor.chain().focus();
            ({
                bold:       () => c.toggleBold(),
                italic:     () => c.toggleItalic(),
                strike:     () => c.toggleStrike(),
                ul:         () => c.toggleBulletList(),
                ol:         () => c.toggleOrderedList(),
                blockquote: () => c.toggleBlockquote(),
                h2:         () => c.toggleHeading({ level: 2 }),
                h3:         () => c.toggleHeading({ level: 3 }),
            })[name]().run();
        },

        setLink() {
            const prev = editor.getAttributes('link').href;
            const url = window.prompt('Adresse du lien (https://…)', prev || 'https://');
            if (url === null) return;
            if (url.trim() === '') {
                editor.chain().focus().unsetLink().run();
                return;
            }
            editor.chain().focus().extendMarkRange('link').setLink({ href: url.trim() }).run();
        },
    };
}

// Enregistrement du composant Alpine via `alpine:init` (émis par l'Alpine de Livewire 3 avant
// son Alpine.start()) → disponible quand Alpine évalue x-data="wysiwyg(...)".
document.addEventListener('alpine:init', () => window.Alpine.data('wysiwyg', wysiwyg));
