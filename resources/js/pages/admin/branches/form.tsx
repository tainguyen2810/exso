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

type Branch = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    address: string | null;
    is_default: boolean;
    status: string;
};

export default function BranchForm({ branch }: { branch: Branch | null }) {
    const can = useCan();
    const form = useForm({
        code: branch?.code ?? '',
        name: branch?.name ?? '',
        phone: branch?.phone ?? '',
        address: branch?.address ?? '',
        is_default: branch?.is_default ?? false,
        status: branch?.status ?? 'active',
    });

    const submit = () => {
        if (branch) {
            form.put(`/branches/${branch.id}`);
        } else {
            form.post('/branches');
        }
    };

    const title = branch ? `Sửa chi nhánh ${branch.name}` : 'Thêm chi nhánh';

    return (
        <>
            <Head title={title} />
            <DocumentForm
                title={title}
                backHref="/branches"
                backLabel="Quay lại danh sách chi nhánh"
                onSubmit={submit}
                actions={
                    branch &&
                    !branch.is_default &&
                    can('branches.delete') && (
                        <ConfirmDelete
                            url={`/branches/${branch.id}`}
                            title={`Xóa chi nhánh ${branch.name}?`}
                            description="Nhân viên đang gán chi nhánh này sẽ không còn làm việc ở đây."
                            trigger={
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                >
                                    Xóa chi nhánh
                                </Button>
                            }
                        />
                    )
                }
                processing={form.processing}
                aside={
                    <FormSection title="Thiết lập" className="grid gap-4">
                        <div className="grid gap-2">
                            <Label>Trạng thái</Label>
                            <Select
                                value={form.data.status}
                                onValueChange={(value) =>
                                    form.setData('status', value)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="active">
                                        Đang hoạt động
                                    </SelectItem>
                                    <SelectItem value="inactive">
                                        Ngừng hoạt động
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.status} />
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.is_default}
                                onCheckedChange={(checked) =>
                                    form.setData('is_default', checked === true)
                                }
                            />
                            Chi nhánh mặc định
                        </label>
                        <InputError message={form.errors.is_default} />
                    </FormSection>
                }
            >
                <FormSection
                    title="Thông tin chi nhánh"
                    className="grid gap-4 sm:grid-cols-2"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="name">Tên chi nhánh *</Label>
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
                        <Label htmlFor="code">Mã chi nhánh *</Label>
                        <Input
                            id="code"
                            value={form.data.code}
                            onChange={(e) =>
                                form.setData(
                                    'code',
                                    e.target.value.toUpperCase(),
                                )
                            }
                            placeholder="VD: CN02"
                        />
                        <InputError message={form.errors.code} />
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
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="address">Địa chỉ</Label>
                        <Input
                            id="address"
                            value={form.data.address}
                            onChange={(e) =>
                                form.setData('address', e.target.value)
                            }
                        />
                        <InputError message={form.errors.address} />
                    </div>
                </FormSection>
            </DocumentForm>
        </>
    );
}

BranchForm.layout = {
    breadcrumbs: [
        { title: 'Chi nhánh', href: '/branches' },
        { title: 'Chi tiết', href: '#' },
    ],
};
