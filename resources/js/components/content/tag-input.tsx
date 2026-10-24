import { Input } from '@/components/ui/input';
import { postJson, type Option } from '@/lib/content';
import { X } from 'lucide-react';
import { useEffect, useId, useState } from 'react';

interface Props {
    value: Option[];
    onChange: (tags: Option[]) => void;
    disabled?: boolean;
}

/** Chip input: Enter or comma adds a tag (existing names are reused server-side, new ones are created). */
export function TagInput({ value, onChange, disabled }: Props) {
    const [text, setText] = useState('');
    const [suggestions, setSuggestions] = useState<Option[]>([]);
    const listId = useId();

    useEffect(() => {
        if (!text.trim()) return setSuggestions([]);
        const t = setTimeout(() => {
            fetch(`/tags/search?q=${encodeURIComponent(text.trim())}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((r) => r.json())
                .then(setSuggestions)
                .catch(() => setSuggestions([]));
        }, 200);
        return () => clearTimeout(t);
    }, [text]);

    const add = async (name: string) => {
        const clean = name.trim();
        setText('');
        if (!clean) return;
        const { status, data } = await postJson<Option>('/tags/find-or-create', { name: clean });
        if (status < 300 && !value.some((t) => t.id === data.id)) onChange([...value, { id: data.id, name: data.name }]);
    };

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap gap-1.5">
                {value.map((t) => (
                    <span key={t.id} className="bg-accent text-accent-foreground inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs">
                        {t.name}
                        {!disabled && (
                            <button type="button" aria-label={`Remove ${t.name}`} onClick={() => onChange(value.filter((x) => x.id !== t.id))}>
                                <X className="size-3" />
                            </button>
                        )}
                    </span>
                ))}
            </div>
            {!disabled && (
                <>
                    <Input
                        aria-label="Add tag"
                        placeholder="Add tag and press Enter"
                        list={listId}
                        value={text}
                        maxLength={60}
                        onChange={(e) => setText(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' || e.key === ',') {
                                e.preventDefault();
                                void add(text);
                            }
                        }}
                    />
                    <datalist id={listId}>
                        {suggestions.map((s) => (
                            <option key={s.id} value={s.name} />
                        ))}
                    </datalist>
                </>
            )}
        </div>
    );
}
