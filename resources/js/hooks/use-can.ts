import { usePage } from '@inertiajs/react';

/**
 * Check the signed-in user's permissions, e.g. `can('staff.create')`. A list
 * passes when the user has any of them. The server enforces the same
 * permissions; this only hides UI.
 */
export function useCan(): (permission?: string | string[]) => boolean {
    const { auth } = usePage().props;
    const permissions = new Set(auth.permissions ?? []);

    return (permission?: string | string[]) =>
        permission === undefined ||
        (Array.isArray(permission)
            ? permission.some((item) => permissions.has(item))
            : permissions.has(permission));
}
