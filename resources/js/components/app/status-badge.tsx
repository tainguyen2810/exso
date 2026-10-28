import { Badge } from '@/components/ui/badge';

export function StatusBadge({ status }: { status: string }) {
    return status === 'active' ? (
        <Badge className="bg-emerald-100 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300">
            Đang hoạt động
        </Badge>
    ) : (
        <Badge variant="secondary">Ngừng hoạt động</Badge>
    );
}
