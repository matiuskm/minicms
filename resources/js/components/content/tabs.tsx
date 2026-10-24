import { cn } from '@/lib/utils';
import { useState, type ReactNode } from 'react';

interface Tab {
    id: string;
    label: string;
    content: ReactNode;
}

/** Tab strip as in the design reference; every panel stays mounted so editor state survives tab switches. */
export function Tabs({ tabs }: { tabs: Tab[] }) {
    const [active, setActive] = useState(tabs[0].id);

    return (
        <div className="bg-card rounded-xl border shadow-xs">
            <div role="tablist" className="flex border-b">
                {tabs.map((t) => (
                    <button
                        key={t.id}
                        role="tab"
                        type="button"
                        aria-selected={active === t.id}
                        onClick={() => setActive(t.id)}
                        className={cn(
                            'border-b-2 px-5 py-3 text-sm font-medium',
                            active === t.id ? 'border-primary text-primary' : 'text-muted-foreground border-transparent',
                        )}
                    >
                        {t.label}
                    </button>
                ))}
            </div>
            {tabs.map((t) => (
                <div key={t.id} role="tabpanel" hidden={active !== t.id} className="space-y-4 p-4">
                    {t.content}
                </div>
            ))}
        </div>
    );
}
