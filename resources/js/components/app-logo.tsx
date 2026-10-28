import { usePage } from '@inertiajs/react';

export default function AppLogo() {
    const { name, store } = usePage().props;

    return (
        <>
            {store?.logo_url ? (
                <img
                    src={store.logo_url}
                    alt=""
                    className="size-8 shrink-0 rounded-md bg-white object-contain"
                />
            ) : (
                <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sm font-bold text-sidebar-primary-foreground">
                    {name.slice(0, 1)}
                </div>
            )}
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="truncate leading-tight font-semibold text-white">
                    {name}
                </span>
                <span className="truncate text-xs text-sidebar-foreground/70">
                    {store?.name}
                </span>
            </div>
        </>
    );
}
