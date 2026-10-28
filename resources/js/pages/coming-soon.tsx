import { Head } from '@inertiajs/react';
import { Construction } from 'lucide-react';
import { PageTitle } from '@/components/app/topbar';
import { Card, CardContent } from '@/components/ui/card';

export default function ComingSoon({
    title,
    phase,
}: {
    title: string;
    phase: string;
}) {
    return (
        <>
            <Head title={title} />
            <PageTitle>
                <h1 className="truncate text-xl font-medium">{title}</h1>
            </PageTitle>
            <div className="px-4 pt-5 pb-10 md:px-8">
                <Card>
                    <CardContent className="flex flex-col items-center gap-3 py-16 text-center">
                        <Construction className="size-10 text-muted-foreground" />
                        <h2 className="text-lg font-medium">{title}</h2>
                        <p className="max-w-md text-sm text-muted-foreground">
                            Chức năng này đang được xây dựng ở giai đoạn {phase}
                            . Quyền truy cập đã được áp dụng, nên bạn có thể
                            phân quyền cho nhân viên ngay từ bây giờ.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
