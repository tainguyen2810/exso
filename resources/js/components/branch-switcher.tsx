import { router, usePage } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

/** Chooses the branch the user is working in (kept in the session). */
export function BranchSwitcher() {
    const { branchContext: branches } = usePage().props;

    if (branches.available.length === 0) {
        return null;
    }

    if (branches.available.length === 1) {
        return (
            <div className="hidden items-center gap-1.5 text-sm text-muted-foreground md:flex">
                <Building2 className="size-4" />
                {branches.available[0].name}
            </div>
        );
    }

    return (
        <Select
            value={branches.current ? String(branches.current) : undefined}
            onValueChange={(value) =>
                router.post(
                    '/branch/switch',
                    { branch_id: Number(value) },
                    { preserveScroll: true },
                )
            }
        >
            <SelectTrigger
                size="sm"
                className="w-44 gap-1.5"
                aria-label="Chi nhánh"
            >
                <Building2 className="size-4 text-muted-foreground" />
                <SelectValue placeholder="Chọn chi nhánh" />
            </SelectTrigger>
            <SelectContent align="end">
                {branches.available.map((branch) => (
                    <SelectItem key={branch.id} value={String(branch.id)}>
                        {branch.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
