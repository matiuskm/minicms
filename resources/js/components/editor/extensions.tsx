import { embedSrc } from '@/lib/video';
import { mergeAttributes, Node, NodeViewWrapper, ReactNodeViewRenderer, type NodeViewProps } from '@tiptap/react';

function CaptionedImageView({ node, updateAttributes, selected }: NodeViewProps) {
    return (
        <NodeViewWrapper className={`my-4 rounded-md border p-2 ${selected ? 'ring-primary ring-2' : ''}`} data-drag-handle>
            <img src={node.attrs.src} alt={node.attrs.alt ?? ''} className="mx-auto max-h-80 rounded" />
            <input
                aria-label="Caption"
                className="text-muted-foreground mt-2 w-full border-0 bg-transparent text-center text-sm outline-none"
                placeholder="Add a caption (optional)"
                value={node.attrs.caption ?? ''}
                onChange={(e) => updateAttributes({ caption: e.target.value })}
            />
        </NodeViewWrapper>
    );
}

/** <figure><img><figcaption></figure>: the "Image" block of the design reference. */
export const CaptionedImage = Node.create({
    name: 'captionedImage',
    group: 'block',
    atom: true,
    draggable: true,

    addAttributes() {
        return { src: { default: null }, alt: { default: '' }, caption: { default: '' } };
    },

    parseHTML() {
        return [
            {
                tag: 'figure',
                getAttrs: (el) => {
                    const img = el.querySelector('img');
                    return img
                        ? {
                              src: img.getAttribute('src'),
                              alt: img.getAttribute('alt') ?? '',
                              caption: el.querySelector('figcaption')?.textContent ?? '',
                          }
                        : false;
                },
            },
            { tag: 'img[src]', getAttrs: (el) => ({ src: el.getAttribute('src'), alt: el.getAttribute('alt') ?? '', caption: '' }) },
        ];
    },

    renderHTML({ node }) {
        const children: unknown[] = [['img', { src: node.attrs.src, alt: node.attrs.alt }]];
        if (node.attrs.caption) children.push(['figcaption', {}, node.attrs.caption]);
        return ['figure', {}, ...children] as never;
    },

    addNodeView() {
        return ReactNodeViewRenderer(CaptionedImageView);
    },
});

function VideoView({ node, selected }: NodeViewProps) {
    const { provider, id } = node.attrs;
    return (
        <NodeViewWrapper className={`my-4 aspect-video overflow-hidden rounded-md border ${selected ? 'ring-primary ring-2' : ''}`} data-drag-handle>
            <iframe
                title={`${provider} video`}
                src={embedSrc({ provider, id })}
                sandbox="allow-scripts allow-same-origin allow-presentation"
                className="pointer-events-none size-full"
                loading="lazy"
            />
        </NodeViewWrapper>
    );
}

/** Stored as <div data-embed data-id>; the server turns it into a sandboxed iframe at render time. */
export const VideoEmbed = Node.create({
    name: 'videoEmbed',
    group: 'block',
    atom: true,
    draggable: true,

    addAttributes() {
        return { provider: { default: 'youtube' }, id: { default: '' } };
    },

    parseHTML() {
        return [{ tag: 'div[data-embed]', getAttrs: (el) => ({ provider: el.getAttribute('data-embed'), id: el.getAttribute('data-id') }) }];
    },

    renderHTML({ node, HTMLAttributes }) {
        return ['div', mergeAttributes(HTMLAttributes, { 'data-embed': node.attrs.provider, 'data-id': node.attrs.id })] as never;
    },

    addNodeView() {
        return ReactNodeViewRenderer(VideoView);
    },
});
