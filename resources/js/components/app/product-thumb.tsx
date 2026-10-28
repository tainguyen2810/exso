import { ImageOff } from 'lucide-react';
import { cn } from '@/lib/utils';

/** Small product image, or a placeholder when there is none. */
export function ProductThumb({
    url,
    className,
}: {
    url: string | null | undefined;
    className?: string;
}) {
    return url ? (
        <img
            src={url}
            alt=""
            loading="lazy"
            className={cn(
                'size-10 shrink-0 rounded border object-cover',
                className,
            )}
        />
    ) : (
        <div
            className={cn(
                'flex size-10 shrink-0 items-center justify-center rounded border bg-muted text-muted-foreground',
                className,
            )}
        >
            <ImageOff className="size-4" />
        </div>
    );
}
