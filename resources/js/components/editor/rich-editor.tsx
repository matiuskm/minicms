import { CaptionedImage, VideoEmbed } from '@/components/editor/extensions';
import { MediaPicker } from '@/components/editor/media-picker';
import { Button } from '@/components/ui/button';
import { type MediaItem } from '@/lib/media';
import { parseVideoUrl } from '@/lib/video';
import { TableCell, Table as TableExt, TableHeader, TableRow } from '@tiptap/extension-table';
import TextAlign from '@tiptap/extension-text-align';
import { EditorContent, useEditor, type Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    Bold,
    Code,
    Code2,
    FileText,
    Heading2,
    Heading3,
    Heading4,
    ImageIcon,
    Italic,
    Link as LinkIcon,
    List,
    ListOrdered,
    Minus,
    Quote,
    Redo2,
    Strikethrough,
    Table,
    Underline as UnderlineIcon,
    Undo2,
    Video,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';

interface Props {
    value: string;
    onChange: (html: string) => void;
    /** Only admins may edit raw HTML; the server sanitises it either way. */
    canEditSource?: boolean;
}

function Btn({ label, icon: Icon, active, onClick }: { label: string; icon: LucideIcon; active?: boolean; onClick: () => void }) {
    return (
        <Button
            type="button"
            size="icon"
            variant={active ? 'secondary' : 'ghost'}
            className="size-8"
            aria-label={label}
            title={label}
            aria-pressed={active}
            onClick={onClick}
        >
            <Icon className="size-4" />
        </Button>
    );
}

export function stats(html: string) {
    const text = html.replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ');
    // Count CJK characters individually and other scripts by whitespace-separated word.
    const cjk = (text.match(/[぀-ヿ㐀-鿿豈-﫿가-힯]/g) ?? []).length;
    const words =
        text
            .replace(/[぀-ヿ㐀-鿿豈-﫿가-힯]/g, ' ')
            .split(/\s+/)
            .filter(Boolean).length + cjk;
    return { words, minutes: Math.max(1, Math.ceil(words / 200)) };
}

export function RichEditor({ value, onChange, canEditSource = false }: Props) {
    const [picker, setPicker] = useState<'image' | 'pdf' | null>(null);
    const [source, setSource] = useState(false);
    const [html, setHtml] = useState(value);

    const editor = useEditor({
        extensions: [
            StarterKit.configure({ heading: { levels: [2, 3, 4] }, link: { openOnClick: false, autolink: false } }),
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            TableExt.configure({ resizable: false }),
            TableRow,
            TableHeader,
            TableCell,
            CaptionedImage,
            VideoEmbed,
        ],
        content: value,
        immediatelyRender: false,
        editorProps: { attributes: { class: 'prose-editor min-h-72 px-4 py-3 outline-none', 'aria-label': 'Content' } },
        onUpdate: ({ editor }) => {
            setHtml(editor.getHTML());
            onChange(editor.getHTML());
        },
    });

    if (!editor) return null;

    const setLink = () => {
        const prev = editor.getAttributes('link').href as string | undefined;
        const url = window.prompt('Link URL (https://, mailto: or a site path)', prev ?? 'https://');
        if (url === null) return;
        if (url === '') editor.chain().focus().extendMarkRange('link').unsetLink().run();
        else editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    };

    const addVideo = () => {
        const video = parseVideoUrl(window.prompt('YouTube or Vimeo URL') ?? '');
        if (video) editor.chain().focus().insertContent({ type: 'videoEmbed', attrs: video }).run();
        else window.alert('Only YouTube and Vimeo links are supported.');
    };

    const pick = (item: MediaItem) => {
        if (picker === 'image') {
            editor
                .chain()
                .focus()
                .insertContent({ type: 'captionedImage', attrs: { src: item.url, alt: item.alt_text ?? '', caption: '' } })
                .run();
        } else {
            const label = item.alt_text || item.name;
            editor
                .chain()
                .focus()
                .insertContent({ type: 'text', text: label, marks: [{ type: 'link', attrs: { href: item.url } }] })
                .run();
        }
        setPicker(null);
    };

    const toggleSource = () => {
        if (source) editor.commands.setContent(html, { emitUpdate: true });
        setSource(!source);
    };

    const { words, minutes } = stats(html);
    const is = (name: string, attrs?: Record<string, unknown>) => editor.isActive(name, attrs);
    const run = (fn: (e: Editor) => void) => () => fn(editor);

    return (
        <div className="bg-card rounded-lg border">
            <div role="toolbar" aria-label="Formatting" className="flex flex-wrap items-center gap-0.5 border-b p-1.5">
                <Btn
                    label="Heading 2"
                    icon={Heading2}
                    active={is('heading', { level: 2 })}
                    onClick={run((e) => e.chain().focus().toggleHeading({ level: 2 }).run())}
                />
                <Btn
                    label="Heading 3"
                    icon={Heading3}
                    active={is('heading', { level: 3 })}
                    onClick={run((e) => e.chain().focus().toggleHeading({ level: 3 }).run())}
                />
                <Btn
                    label="Heading 4"
                    icon={Heading4}
                    active={is('heading', { level: 4 })}
                    onClick={run((e) => e.chain().focus().toggleHeading({ level: 4 }).run())}
                />
                <Btn label="Bold" icon={Bold} active={is('bold')} onClick={run((e) => e.chain().focus().toggleBold().run())} />
                <Btn label="Italic" icon={Italic} active={is('italic')} onClick={run((e) => e.chain().focus().toggleItalic().run())} />
                <Btn
                    label="Underline"
                    icon={UnderlineIcon}
                    active={is('underline')}
                    onClick={run((e) => e.chain().focus().toggleUnderline().run())}
                />
                <Btn label="Strikethrough" icon={Strikethrough} active={is('strike')} onClick={run((e) => e.chain().focus().toggleStrike().run())} />
                <Btn label="Inline code" icon={Code} active={is('code')} onClick={run((e) => e.chain().focus().toggleCode().run())} />
                <Btn label="Link" icon={LinkIcon} active={is('link')} onClick={setLink} />
                <Btn label="Bullet list" icon={List} active={is('bulletList')} onClick={run((e) => e.chain().focus().toggleBulletList().run())} />
                <Btn
                    label="Numbered list"
                    icon={ListOrdered}
                    active={is('orderedList')}
                    onClick={run((e) => e.chain().focus().toggleOrderedList().run())}
                />
                <Btn label="Quote" icon={Quote} active={is('blockquote')} onClick={run((e) => e.chain().focus().toggleBlockquote().run())} />
                <Btn label="Code block" icon={Code2} active={is('codeBlock')} onClick={run((e) => e.chain().focus().toggleCodeBlock().run())} />
                <Btn label="Divider" icon={Minus} onClick={run((e) => e.chain().focus().setHorizontalRule().run())} />
                <Btn
                    label="Table"
                    icon={Table}
                    onClick={run((e) => e.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run())}
                />
                <Btn
                    label="Align left"
                    icon={AlignLeft}
                    active={editor.isActive({ textAlign: 'left' })}
                    onClick={run((e) => e.chain().focus().setTextAlign('left').run())}
                />
                <Btn
                    label="Align center"
                    icon={AlignCenter}
                    active={editor.isActive({ textAlign: 'center' })}
                    onClick={run((e) => e.chain().focus().setTextAlign('center').run())}
                />
                <Btn
                    label="Align right"
                    icon={AlignRight}
                    active={editor.isActive({ textAlign: 'right' })}
                    onClick={run((e) => e.chain().focus().setTextAlign('right').run())}
                />
                <Btn label="Insert image" icon={ImageIcon} onClick={() => setPicker('image')} />
                <Btn label="Insert PDF link" icon={FileText} onClick={() => setPicker('pdf')} />
                <Btn label="Embed video" icon={Video} onClick={addVideo} />
                <Btn label="Undo" icon={Undo2} onClick={run((e) => e.chain().focus().undo().run())} />
                <Btn label="Redo" icon={Redo2} onClick={run((e) => e.chain().focus().redo().run())} />
                {canEditSource && (
                    <Button type="button" size="sm" variant={source ? 'secondary' : 'ghost'} className="ml-auto" onClick={toggleSource}>
                        {source ? 'Visual' : 'HTML'}
                    </Button>
                )}
            </div>

            {source ? (
                <textarea
                    aria-label="HTML source"
                    className="min-h-72 w-full resize-y bg-transparent p-4 font-mono text-sm outline-none"
                    value={html}
                    onChange={(e) => {
                        setHtml(e.target.value);
                        onChange(e.target.value);
                    }}
                />
            ) : (
                <EditorContent editor={editor} />
            )}

            <div className="text-muted-foreground flex justify-between border-t px-3 py-1.5 text-xs">
                <span>{words} words</span>
                <span>{minutes} min read</span>
            </div>

            <MediaPicker kind={picker ?? 'image'} open={picker !== null} onClose={() => setPicker(null)} onPick={pick} />
        </div>
    );
}
