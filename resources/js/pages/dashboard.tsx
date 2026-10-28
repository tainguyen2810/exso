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
        .filter((item) => item.href !== '/dashboard')
        .map((item) => ({
            ...item,
            href: item.children?.[0]?.href ?? item.href,
        }));

    return (
        <>
            <Head title="Tổng quan" />
            <div className="space-y-4 px-4 pt-5 pb-10 md:px-8">
                <div>
                    <h2 className="text-lg font-medium">
                        Xin chào, {auth.user.name}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {store.name}. Số liệu bán hàng hôm nay sẽ hiển thị ở đây
                        từ giai đoạn G4.
                    </p>
                </div>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {shortcuts.map((item) => (
                        <Link key={item.title} href={item.href} prefetch>
                            <Card className="py-5 transition-shadow hover:shadow-md">
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
