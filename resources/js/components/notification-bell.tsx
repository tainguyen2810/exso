import { Bell } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/** Notification centre. Real notifications arrive with orders and stock alerts. */
export function NotificationBell() {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" aria-label="Thông báo">
                    <Bell className="size-5" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-72">
                <DropdownMenuLabel>Thông báo</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <p className="px-2 py-6 text-center text-sm text-muted-foreground">
                    Chưa có thông báo nào.
                </p>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
