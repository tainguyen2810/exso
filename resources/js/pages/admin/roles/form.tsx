import { Head, useForm } from '@inertiajs/react';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCan } from '@/hooks/use-can';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type Module = { key: string; label: string; actions: string[] };

type Props = {
    role: { id: number; name: string; permissions: string[] } | null;
    modules: Module[];
    actions: Record<string, string>;
};

export default function RoleForm({ role, modules, actions }: Props) {
    const can = useCan();
    const form = useForm({
        name: role?.name ?? '',
        permissions: role?.permissions ?? ([] as string[]),
    });

    const has = (name: string) => form.data.permissions.includes(name);

    const toggle = (names: string[], on: boolean) => {
        const next = new Set(form.data.permissions);
        names.forEach((name) => (on ? next.add(name) : next.delete(name)));
        form.setData('permissions', [...next]);
    };

    const all = modules.flatMap((module) =>
        module.actions.map((action) => `${module.key}.${action}`),
    );

    const submit = () => {
        if (role) {
            form.put(`/roles/${role.id}`);
        } else {
            form.post('/roles');
        }
    };

    const title = role ? `Sửa vai trò ${role.name}` : 'Thêm vai trò';

    return (
        <>
            <Head title={title} />
            <DocumentForm
                title={title}
                backHref="/roles"
                backLabel="Quay lại danh sách vai trò"
                onSubmit={submit}
                actions={
                    role &&
                    can('roles.delete') && (
                        <ConfirmDelete
                            url={`/roles/${role.id}`}
                            title={`Xóa vai trò ${role.name}?`}
                            description="Chỉ xóa được vai trò chưa gán cho nhân viên nào."
                            trigger={
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                >
                                    Xóa vai trò
                                </Button>
                            }
                        />
                    )
                }
                processing={form.processing}
            >
                <FormSection
                    title="Thông tin vai trò"
                    className="max-w-md gap-2"
                >
                    <Label htmlFor="name">Tên vai trò *</Label>
                    <Input
                        id="name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder="VD: Thu ngân ca tối"
                        autoFocus
                    />
                    <InputError message={form.errors.name} />
                </FormSection>
                <Card className="gap-0 py-0">
                    <CardHeader className="flex flex-row items-center justify-between border-b px-5 py-4">
                        <CardTitle>Phân quyền</CardTitle>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={all.every(has)}
                                onCheckedChange={(checked) =>
                                    toggle(all, checked === true)
                                }
                            />
                            Chọn tất cả
                        </label>
                    </CardHeader>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Chức năng</TableHead>
                                {Object.entries(actions).map(([key, label]) => (
                                    <TableHead
                                        key={key}
                                        className="w-20 text-center"
                                    >
                                        {label}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {modules.map((module) => {
                                const names = module.actions.map(
                                    (action) => `${module.key}.${action}`,
                                );

                                return (
                                    <TableRow key={module.key}>
                                        <TableCell>
                                            <label className="flex items-center gap-2 font-medium">
                                                <Checkbox
                                                    checked={names.every(has)}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        toggle(
                                                            names,
                                                            checked === true,
                                                        )
                                                    }
                                                    aria-label={`Tất cả quyền ${module.label}`}
                                                />
                                                {module.label}
                                            </label>
                                        </TableCell>
                                        {Object.keys(actions).map((action) => {
                                            const name = `${module.key}.${action}`;

                                            return (
                                                <TableCell
                                                    key={action}
                                                    className="text-center"
                                                >
                                                    {module.actions.includes(
                                                        action,
                                                    ) && (
                                                        <Checkbox
                                                            checked={has(name)}
                                                            onCheckedChange={(
                                                                checked,
                                                            ) =>
                                                                toggle(
                                                                    [name],
                                                                    checked ===
                                                                        true,
                                                                )
                                                            }
                                                            aria-label={`${actions[action]} ${module.label}`}
                                                        />
                                                    )}
                                                </TableCell>
                                            );
                                        })}
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </Card>
                <InputError message={form.errors.permissions} />
            </DocumentForm>
        </>
    );
}

RoleForm.layout = {
    breadcrumbs: [
        { title: 'Vai trò & phân quyền', href: '/roles' },
        { title: 'Chi tiết', href: '#' },
    ],
};
