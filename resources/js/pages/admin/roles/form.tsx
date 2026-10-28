import { Head, useForm } from '@inertiajs/react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCan } from '@/hooks/use-can';

type Permission = { name: string; label: string };

type Module = { key: string; label: string; permissions: Permission[] };

type Group = { label: string; modules: Module[] };

type Props = {
    role: {
        id: number;
        name: string;
        description: string | null;
        permissions: string[];
    } | null;
    locked: string | null;
    groups: Group[];
    /** Permissions the current user may hand out (all, for the owner). */
    grantable: string[];
};

/** Role page like Sapo: name + note, then "Phân quyền chi tiết" by group. */
export default function RoleForm({ role, locked, groups, grantable }: Props) {
    const can = useCan();
    const form = useForm({
        name: role?.name ?? '',
        description: role?.description ?? '',
        permissions: role?.permissions ?? ([] as string[]),
    });

    const has = (name: string) => form.data.permissions.includes(name);

    const toggle = (names: string[], on: boolean) => {
        const next = new Set(form.data.permissions);
        names
            .filter((name) => grantable.includes(name))
            .forEach((name) => (on ? next.add(name) : next.delete(name)));
        form.setData('permissions', [...next]);
    };

    const namesOf = (modules: Module[]) =>
        modules.flatMap((module) => module.permissions.map((p) => p.name));
    const all = namesOf(groups.flatMap((group) => group.modules));

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
                processing={form.processing}
                readOnly={locked}
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
            >
                <FormSection
                    title="Thông tin vai trò"
                    className="sm:grid-cols-2"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="name">Tên vai trò *</Label>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder="VD: Thu ngân ca tối"
                            maxLength={100}
                            autoFocus
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="description">Ghi chú</Label>
                        <Input
                            id="description"
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            placeholder="VD: Nhân viên bán hàng ca tối"
                            maxLength={500}
                        />
                        <InputError message={form.errors.description} />
                    </div>
                </FormSection>

                <FormSection
                    title="Phân quyền chi tiết"
                    description="Chọn các thao tác nhân viên có vai trò này được làm. Ô bị mờ là quyền bạn không có nên không cấp được."
                    className="gap-0 p-0"
                >
                    <label className="flex items-center gap-2 border-b px-5 py-3 text-sm font-medium">
                        <Checkbox
                            checked={all.every(has)}
                            onCheckedChange={(checked) =>
                                toggle(all, checked === true)
                            }
                        />
                        Chọn tất cả quyền
                    </label>
                    <InputError
                        message={form.errors.permissions}
                        className="px-5 pt-3"
                    />
                    {groups.map((group) => {
                        const names = namesOf(group.modules);

                        return (
                            <div
                                key={group.label}
                                className="grid gap-4 border-b px-5 py-4 last:border-b-0 md:grid-cols-[200px_1fr]"
                            >
                                <label className="flex items-start gap-2 font-medium">
                                    <Checkbox
                                        className="mt-0.5"
                                        checked={names.every(has)}
                                        onCheckedChange={(checked) =>
                                            toggle(names, checked === true)
                                        }
                                        aria-label={`Tất cả quyền ${group.label}`}
                                    />
                                    {group.label}
                                </label>
                                <div className="grid gap-4">
                                    {group.modules.map((module) => (
                                        <div key={module.key}>
                                            {group.modules.length > 1 && (
                                                <div className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                    {module.label}
                                                </div>
                                            )}
                                            <div className="grid gap-x-4 gap-y-2.5 sm:grid-cols-2 xl:grid-cols-3">
                                                {module.permissions.map(
                                                    (permission) => (
                                                        <label
                                                            key={
                                                                permission.name
                                                            }
                                                            className="flex items-center gap-2 text-sm has-disabled:opacity-50"
                                                        >
                                                            <Checkbox
                                                                checked={has(
                                                                    permission.name,
                                                                )}
                                                                disabled={
                                                                    !grantable.includes(
                                                                        permission.name,
                                                                    )
                                                                }
                                                                onCheckedChange={(
                                                                    checked,
                                                                ) =>
                                                                    toggle(
                                                                        [
                                                                            permission.name,
                                                                        ],
                                                                        checked ===
                                                                            true,
                                                                    )
                                                                }
                                                            />
                                                            {permission.label}
                                                        </label>
                                                    ),
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        );
                    })}
                </FormSection>
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
