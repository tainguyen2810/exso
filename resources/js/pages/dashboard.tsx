import { Head, Link, usePage } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { filterMenu, menu } from '@/config/menu';
import { useCan } from '@/hooks/use-can';
import { dashboard } from '@/routes';

export default function Dashboard() {
    const { auth, store } = usePage().props;
    const can = useCan();
    const shortcuts = filterMenu(menu, can)
        .flatMap((group) => group.items)
        .filter((item) => item.href !== '/dashboard');

    return (
        <>
            <Head title="Tổng quan" />
            <div className="space-y-4 p-4 md:p-6">
                <div>
                    <h1 className="text-xl font-semibold">
                        Xin chào, {auth.user.name}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {store.name}. Số liệu bán hàng hôm nay sẽ hiển thị ở đây
                        từ giai đoạn G4.
                    </p>
                </div>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {shortcuts.map((item) => (
                        <Link key={item.title} href={item.href} prefetch>
                            <Card className="transition-colors hover:border-primary">
                                <CardContent className="flex items-center gap-3">
                                    {item.icon && (
                                        <div className="rounded-md bg-primary/10 p-2 text-primary">
                                            <item.icon className="size-5" />
                                        </div>
                                    )}
                                    <span className="font-medium">
                                        {item.title}
                                    </span>
                                </CardContent>
                            </Card>
                        </Link>
                    ))}
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Tổng quan',
            href: dashboard(),
        },
    ],
};
