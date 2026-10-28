import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Input } from '@/components/ui/input';

/** Search box that reloads the list (server-side) 300ms after typing stops. */
export function SearchInput({
    value,
    placeholder = 'Tìm kiếm...',
    param = 'search',
}: {
    value: string;
    placeholder?: string;
    param?: string;
}) {
    const [text, setText] = useState(value);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const timer = setTimeout(() => {
            const query = Object.fromEntries(
                new URLSearchParams(window.location.search),
            );
            delete query.page;

            router.get(
                window.location.pathname,
                { ...query, [param]: text || undefined },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [text, param]);

    return (
        <div className="relative w-full max-w-sm">
            <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
                value={text}
                onChange={(event) => setText(event.target.value)}
                placeholder={placeholder}
                className="pl-8"
            />
        </div>
    );
}
