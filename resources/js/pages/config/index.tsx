import { Head, Link } from '@inertiajs/react';
import { Card } from '@/components/ui/card';
import { configEntries, configGroups } from '@/config/menu';
import type { ConfigEntry } from '@/config/menu';
import { useCan } from '@/hooks/use-can';

/** Cấu hình: one card per settings page, like Sapo. */
export default function ConfigIndex() {
    const can = useCan();
    const entries = configEntries.filter((entry) => can(entry.permission));

    return (
        <>
            <Head title="Cấu hình" />
            <div className="grid gap-6 px-4 pt-5 pb-10 md:px-8">
                {configGroups.map((group) => {
                    const items = entries.filter(
                        (entry) => entry.group === group,
                    );

                    return (
                        items.length > 0 && (
                            <section key={group}>
                                <h2 className="mb-3 text-base font-medium">
                                    {group}
                                </h2>
                                <Card className="grid gap-0 py-2 sm:grid-cols-2 xl:grid-cols-3">
                                    {items.map((entry) => (
                                        <Entry key={entry.href} entry={entry} />
                                    ))}
                                </Card>
                            </section>
                        )
                    );
                })}
            </div>
        </>
    );
}

function Entry({ entry }: { entry: ConfigEntry }) {
    const body = (
        <>
            <div className="flex size-11 shrink-0 items-center justify-center rounded-md bg-accent text-primary">
                <entry.icon className="size-5" />
            </div>
            <div className="min-w-0">
                <div className="font-medium text-primary group-aria-disabled:text-muted-foreground">
                    {entry.title}
                    {entry.phase && (
                        <span className="ml-2 text-xs font-normal text-muted-foreground">
                            (sắp có)
                        </span>
                    )}
                </div>
                <p className="mt-0.5 text-sm text-muted-foreground">
                    {entry.description}
                </p>
            </div>
        </>
    );
    const className = 'group flex items-start gap-4 rounded-md p-4 m-2';

    return entry.phase ? (
        <div aria-disabled className={`${className} opacity-70`}>
            {body}
        </div>
    ) : (
        <Link
            href={entry.href}
            prefetch
            className={`${className} transition-colors hover:bg-muted`}
        >
            {body}
        </Link>
    );
}

ConfigIndex.layout = {
    breadcrumbs: [{ title: 'Cấu hình', href: '/config' }],
};
