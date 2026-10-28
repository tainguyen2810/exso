import { ImageOff } from 'lucide-react';

export function ProductThumb({ url }: { url: string | null }) {
    return url ? (
        <img
            src={url}
            alt=""
            loading="lazy"
            className="size-10 shrink-0 rounded border object-cover"
        />
    ) : (
        <div className="flex size-10 shrink-0 items-center justify-center rounded border bg-muted text-muted-foreground">
            <ImageOff className="size-4" />
        </div>
    );
}
