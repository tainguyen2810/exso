import { Check, ChevronsUpDown, Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

/**
 * Pick a name from a list or type a new one ("+ Thêm ..."); the server
 * creates new names on save.
 */
export function NameCombobox({
    value,
    onChange,
    options,
    placeholder,
    addLabel = 'Thêm',
    disabled,
    id,
}: {
    value: string;
    onChange: (value: string) => void;
    options: string[];
    placeholder: string;
    addLabel?: string;
    disabled?: boolean;
    id?: string;
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const exists = options.some(
        (option) => option.toLowerCase() === search.trim().toLowerCase(),
    );

    const pick = (name: string) => {
        onChange(name === value ? '' : name);
        setOpen(false);
        setSearch('');
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    role="combobox"
                    disabled={disabled}
                    className={cn(
                        'w-full justify-between font-normal',
                        !value && 'text-muted-foreground',
                    )}
                >
                    <span className="truncate">{value || placeholder}</span>
                    <ChevronsUpDown className="opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-(--radix-popover-trigger-width) p-0">
                <Command>
                    <CommandInput
                        placeholder="Tìm hoặc nhập tên mới"
                        value={search}
                        onValueChange={setSearch}
                    />
                    <CommandList>
                        <CommandEmpty>Không có kết quả.</CommandEmpty>
                        <CommandGroup>
                            {search.trim() !== '' && !exists && (
                                <CommandItem
                                    value={`__new__${search}`}
                                    onSelect={() => pick(search.trim())}
                                >
                                    <Plus />
                                    {addLabel} "{search.trim()}"
                                </CommandItem>
                            )}
                            {options.map((option) => (
                                <CommandItem
                                    key={option}
                                    value={option}
                                    onSelect={() => pick(option)}
                                >
                                    <Check
                                        className={cn(
                                            value === option
                                                ? 'opacity-100'
                                                : 'opacity-0',
                                        )}
                                    />
                                    {option}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
