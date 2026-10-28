import { usePage } from '@inertiajs/react';

/**
 * Check the signed-in user's permissions, e.g. `can('staff.create')`.
 * The server enforces the same permissions; this only hides UI.
 */
export function useCan(): (permission?: string) => boolean {
    const { auth } = usePage().props;
    const permissions = new Set(auth.permissions ?? []);

    return (permission?: string) =>
        permission === undefined || permissions.has(permission);
}
