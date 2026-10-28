import { usePage } from '@inertiajs/react';
import { AlertCircle } from 'lucide-react';

/**
 * Errors of actions that have no field of their own on the page (status
 * changes, stock checks), shown as a red banner.
 */
export function PageErrors({
    keys = ['status', 'stock', 'items', 'amount'],
}: {
    keys?: string[];
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const messages = keys
        .map((key) => errors[key])
        .filter((message): message is string => !!message);

    if (messages.length === 0) {
        return null;
    }

    return (
        <div className="flex gap-2 rounded-md border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
            <AlertCircle className="mt-0.5 size-4 shrink-0" />
            <div className="grid gap-1">
                {messages.map((message) => (
                    <p key={message}>{message}</p>
                ))}
            </div>
        </div>
    );
}
