import { Head, useForm } from '@inertiajs/react';
import { DocumentForm, FormSection } from '@/components/app/document-form';
import { ConfirmDelete } from '@/components/app/confirm-delete';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCan } from '@/hooks/use-can';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Option = { id: number; name: string; code?: string };

type Member = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    status: string;
    is_owner: boolean;
    role_ids: number[];
    branch_ids: number[];
};

export default function StaffForm({
    member,
    roles,
    branches,
}: {
    member: Member | null;
    roles: Option[];
    branches: Option[];
}) {
    const can = useCan();
    const form = useForm({
        name: member?.name ?? '',
        email: member?.email ?? '',
        phone: member?.phone ?? '',
        password: '',
        password_confirmation: '',
        status: member?.status ?? 'active',
        role_ids: member?.role_ids ?? ([] as number[]),
        branch_ids:
            member?.branch_ids ??
            (branches.length === 1 ? [branches[0].id] : ([] as number[])),
    });

    const toggle = (key: 'role_ids' | 'branch_ids', id: number) => {
        const current = form.data[key];
        form.setData(
            key,
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );
    };

    const submit = () => {
        if (member) {
            form.put(`/staff/${member.id}`);
        } else {
            form.post('/staff');
        }
    };

    const title = member ? `Sửa nhân viên ${member.name}` : 'Thêm nhân viên';

    return (
        <>
            <Head title={title} />
            <DocumentForm
                title={title}
                backHref="/staff"
                backLabel="Quay lại danh sách nhân viên"
                onSubmit={submit}
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
                processing={form.processing}
                aside={
                    <>
                        <FormSection title="Trạng thái" className="grid gap-2">
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
                            className="grid gap-2"
                        >
                            {branches.map((branch) => (
                                <label
                                    key={branch.id}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <Checkbox
                                        checked={form.data.branch_ids.includes(
                                            branch.id,
                                        )}
                                        onCheckedChange={() =>
                                            toggle('branch_ids', branch.id)
                                        }
                                    />
                                    {branch.name}
                                </label>
                            ))}
                            <InputError message={form.errors.branch_ids} />
                        </FormSection>
                    </>
                }
            >
                <FormSection
                    title="Thông tin nhân viên"
                    className="grid gap-4 sm:grid-cols-2"
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
                    title="Vai trò"
                    description="Quyền của nhân viên là tổng quyền của các vai trò được chọn."
                    className="grid gap-2 sm:grid-cols-2"
                >
                    {member?.is_owner && (
                        <p className="text-sm text-muted-foreground sm:col-span-2">
                            Chủ cửa hàng luôn có toàn quyền.
                        </p>
                    )}
                    {roles.map((role) => (
                        <label
                            key={role.id}
                            className="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                checked={form.data.role_ids.includes(role.id)}
                                onCheckedChange={() =>
                                    toggle('role_ids', role.id)
                                }
                            />
                            {role.name}
                        </label>
                    ))}
                    <InputError message={form.errors.role_ids} />
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
