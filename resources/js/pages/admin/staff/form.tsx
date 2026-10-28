import { Head, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCan } from '@/hooks/use-can';

/** `grantable` is false for what the current user may not hand out. */
type RoleOption = { id: number; name: string; grantable: boolean };
type BranchOption = {
    id: number;
    code: string;
    name: string;
    grantable: boolean;
};
type DataPermission = { name: string; label: string; grantable: boolean };

type Assignment = { role_id: number | null; branch_id: number | null };

type Member = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    status: string;
    is_owner: boolean;
    branch_ids: number[];
    assignments: Assignment[];
    data_permissions: string[];
};

const ALL = 'all';

/** Staff page like Sapo: roles are given per branch ("Vai trò × Chi nhánh"). */
export default function StaffForm({
    member,
    locked,
    roles,
    branches,
    dataPermissions,
}: {
    member: Member | null;
    locked: string | null;
    roles: RoleOption[];
    branches: BranchOption[];
    dataPermissions: DataPermission[];
}) {
    const can = useCan();
    const form = useForm({
        name: member?.name ?? '',
        email: member?.email ?? '',
        phone: member?.phone ?? '',
        password: '',
        password_confirmation: '',
        status: member?.status ?? 'active',
        branch_ids:
            member?.branch_ids ??
            (branches.length === 1 ? [branches[0].id] : ([] as number[])),
        assignments: member?.assignments ?? ([] as Assignment[]),
        data_permissions: member?.data_permissions ?? ([] as string[]),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const hadRole = (id: number) =>
        member?.assignments.some((row) => row.role_id === id) ?? false;
    const hadBranch = (id: number) => member?.branch_ids.includes(id) ?? false;

    const toggleBranch = (id: number) => {
        const on = !form.data.branch_ids.includes(id);
        form.setData((data) => ({
            ...data,
            branch_ids: on
                ? [...data.branch_ids, id]
                : data.branch_ids.filter((value) => value !== id),
            // A role given only for a branch the member leaves goes too.
            assignments: on
                ? data.assignments
                : data.assignments.filter((row) => row.branch_id !== id),
        }));
    };

    const setAssignment = (index: number, patch: Partial<Assignment>) =>
        form.setData(
            'assignments',
            form.data.assignments.map((row, i) =>
                i === index ? { ...row, ...patch } : row,
            ),
        );

    const toggleData = (name: string) =>
        form.setData(
            'data_permissions',
            form.data.data_permissions.includes(name)
                ? form.data.data_permissions.filter((item) => item !== name)
                : [...form.data.data_permissions, name],
        );

    const submit = () => {
        if (member) {
            form.put(`/staff/${member.id}`);
        } else {
            form.post('/staff');
        }
    };

    const title = member ? `Sửa nhân viên ${member.name}` : 'Thêm nhân viên';
    const workBranches = branches.filter((branch) =>
        form.data.branch_ids.includes(branch.id),
    );

    return (
        <>
            <Head title={title} />
            <DocumentForm
                title={title}
                backHref="/staff"
                backLabel="Quay lại danh sách nhân viên"
                onSubmit={submit}
                processing={form.processing}
                readOnly={locked}
                actions={
                    member &&
                    !member.is_owner &&
                    can('staff.delete') && (
                        <ConfirmDelete
                            url={`/staff/${member.id}`}
                            title={`Xóa nhân viên ${member.name}?`}
                            description="Nếu chỉ tạm nghỉ, hãy chuyển trạng thái sang Ngừng hoạt động thay vì xóa."
                            trigger={
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                >
                                    Xóa nhân viên
                                </Button>
                            }
                        />
                    )
                }
                aside={
                    <>
                        <FormSection title="Trạng thái" className="gap-2">
                            <Select
                                value={form.data.status}
                                onValueChange={(value) =>
                                    form.setData('status', value)
                                }
                                disabled={member?.is_owner}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="active">
                                        Đang hoạt động
                                    </SelectItem>
                                    <SelectItem value="inactive">
                                        Ngừng hoạt động (khóa đăng nhập)
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.status} />
                        </FormSection>
                        <FormSection
                            title="Chi nhánh làm việc *"
                            className="gap-2"
                        >
                            {branches.map((branch) => (
                                <label
                                    key={branch.id}
                                    className="flex items-center gap-2 text-sm has-disabled:opacity-50"
                                >
                                    <Checkbox
                                        checked={form.data.branch_ids.includes(
                                            branch.id,
                                        )}
                                        disabled={
                                            !branch.grantable &&
                                            !hadBranch(branch.id)
                                        }
                                        onCheckedChange={() =>
                                            toggleBranch(branch.id)
                                        }
                                    />
                                    {branch.name}
                                </label>
                            ))}
                            <InputError message={form.errors.branch_ids} />
                        </FormSection>
                        <FormSection
                            title="Phân quyền dữ liệu"
                            description="Áp dụng ở mọi chi nhánh của nhân viên."
                            className="gap-2"
                        >
                            {dataPermissions.map((permission) => (
                                <label
                                    key={permission.name}
                                    className="flex items-center gap-2 text-sm has-disabled:opacity-50"
                                >
                                    <Checkbox
                                        checked={form.data.data_permissions.includes(
                                            permission.name,
                                        )}
                                        disabled={
                                            !permission.grantable ||
                                            member?.is_owner
                                        }
                                        onCheckedChange={() =>
                                            toggleData(permission.name)
                                        }
                                    />
                                    {permission.label}
                                </label>
                            ))}
                            <InputError
                                message={form.errors.data_permissions}
                            />
                        </FormSection>
                    </>
                }
            >
                <FormSection
                    title="Thông tin nhân viên"
                    className="sm:grid-cols-2"
                >
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="name">Họ tên *</Label>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            autoFocus
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="email">Email đăng nhập *</Label>
                        <Input
                            id="email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                        />
                        <InputError message={form.errors.email} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="phone">Điện thoại</Label>
                        <Input
                            id="phone"
                            value={form.data.phone}
                            onChange={(e) =>
                                form.setData('phone', e.target.value)
                            }
                        />
                        <InputError message={form.errors.phone} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="password">
                            {member
                                ? 'Mật khẩu mới (để trống nếu không đổi)'
                                : 'Mật khẩu *'}
                        </Label>
                        <Input
                            id="password"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                        />
                        <InputError message={form.errors.password} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">
                            Nhập lại mật khẩu
                        </Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.password_confirmation}
                            onChange={(e) =>
                                form.setData(
                                    'password_confirmation',
                                    e.target.value,
                                )
                            }
                        />
                    </div>
                </FormSection>

                <FormSection
                    title="Vai trò nhân viên"
                    description="Mỗi dòng là một vai trò ở một chi nhánh hoặc ở toàn bộ chi nhánh làm việc. Khi nhân viên chuyển chi nhánh, quyền đổi theo."
                >
                    {member?.is_owner ? (
                        <p className="text-sm text-muted-foreground">
                            Chủ cửa hàng luôn có toàn quyền ở mọi chi nhánh.
                        </p>
                    ) : (
                        <>
                            {form.data.assignments.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    Chưa có vai trò: nhân viên đăng nhập được
                                    nhưng không dùng được chức năng nào.
                                </p>
                            )}
                            {form.data.assignments.map((row, index) => (
                                <div key={index} className="grid gap-1">
                                    <div className="grid grid-cols-[1fr_1fr_auto] items-center gap-2">
                                        <Select
                                            value={
                                                row.role_id
                                                    ? String(row.role_id)
                                                    : undefined
                                            }
                                            onValueChange={(value) =>
                                                setAssignment(index, {
                                                    role_id: Number(value),
                                                })
                                            }
                                        >
                                            <SelectTrigger
                                                className="w-full"
                                                aria-label="Vai trò"
                                            >
                                                <SelectValue placeholder="Chọn vai trò" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {roles.map((role) => (
                                                    <SelectItem
                                                        key={role.id}
                                                        value={String(role.id)}
                                                        disabled={
                                                            !role.grantable &&
                                                            !hadRole(role.id)
                                                        }
                                                    >
                                                        {role.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <Select
                                            value={
                                                row.branch_id
                                                    ? String(row.branch_id)
                                                    : ALL
                                            }
                                            onValueChange={(value) =>
                                                setAssignment(index, {
                                                    branch_id:
                                                        value === ALL
                                                            ? null
                                                            : Number(value),
                                                })
                                            }
                                        >
                                            <SelectTrigger
                                                className="w-full"
                                                aria-label="Chi nhánh"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={ALL}>
                                                    Toàn bộ chi nhánh
                                                </SelectItem>
                                                {workBranches.map((branch) => (
                                                    <SelectItem
                                                        key={branch.id}
                                                        value={String(
                                                            branch.id,
                                                        )}
                                                    >
                                                        {branch.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Bỏ vai trò này"
                                            onClick={() =>
                                                form.setData(
                                                    'assignments',
                                                    form.data.assignments.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <X className="size-4" />
                                        </Button>
                                    </div>
                                    <InputError
                                        message={
                                            errors[
                                                `assignments.${index}.role_id`
                                            ] ??
                                            errors[
                                                `assignments.${index}.branch_id`
                                            ]
                                        }
                                    />
                                </div>
                            ))}
                            <div>
                                <Button
                                    type="button"
                                    variant="link"
                                    className="h-auto px-0"
                                    onClick={() =>
                                        form.setData('assignments', [
                                            ...form.data.assignments,
                                            { role_id: null, branch_id: null },
                                        ])
                                    }
                                >
                                    <Plus /> Thêm vai trò
                                </Button>
                            </div>
                            <InputError message={errors.assignments} />
                        </>
                    )}
                </FormSection>
            </DocumentForm>
        </>
    );
}

StaffForm.layout = {
    breadcrumbs: [
        { title: 'Nhân viên', href: '/staff' },
        { title: 'Chi tiết', href: '#' },
    ],
};
