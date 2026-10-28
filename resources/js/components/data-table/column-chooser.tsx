import { Settings } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Column } from './types';

function read(key: string): string[] | null {
    try {
        const value = window.localStorage.getItem(key);

        return value ? (JSON.parse(value) as string[]) : null;
    } catch {
        return null;
    }
}

function write(key: string, value: string[] | null) {
    try {
        if (value === null) {
            window.localStorage.removeItem(key);
        } else {
            window.localStorage.setItem(key, JSON.stringify(value));
        }
    } catch {
        // Private mode or storage blocked: the choice just isn't remembered.
    }
}

/** Visible columns of a list, remembered per browser. */
export function useVisibleColumns<T>(tableKey: string, columns: Column<T>[]) {
    const storageKey = `exso.columns.${tableKey}`;
    const defaults = columns
        .filter((column) => !column.hidden)
        .map((column) => column.key);
    const [visible, setVisible] = useState<string[]>(defaults);

    useEffect(() => {
        const saved = read(storageKey);

        if (saved) {
            setVisible(saved);
        }
    }, [storageKey]);

    const toggle = (key: string) => {
        const next = visible.includes(key)
            ? visible.filter((item) => item !== key)
            : [...visible, key];
        setVisible(next);
        write(storageKey, next);
    };

    const reset = () => {
        setVisible(defaults);
        write(storageKey, null);
    };

    const shown = columns.filter(
        (column) => column.fixed || visible.includes(column.key),
    );

    return { shown, visible, toggle, reset };
}

export function ColumnChooser<T>({
    columns,
    visible,
    onToggle,
    onReset,
}: {
    columns: Column<T>[];
    visible: string[];
    onToggle: (key: string) => void;
    onReset: () => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-7 text-muted-foreground"
                    title="Chọn cột hiển thị"
                    aria-label="Chọn cột hiển thị"
                >
                    <Settings />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
                <DropdownMenuLabel>Cột hiển thị</DropdownMenuLabel>
                {columns
                    .filter((column) => !column.fixed)
                    .map((column) => (
                        <DropdownMenuCheckboxItem
                            key={column.key}
                            checked={visible.includes(column.key)}
                            onCheckedChange={() => onToggle(column.key)}
                            onSelect={(event) => event.preventDefault()}
                        >
                            {column.header}
                        </DropdownMenuCheckboxItem>
                    ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={onReset}>
                    Về mặc định
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
