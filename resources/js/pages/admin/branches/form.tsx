import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { PageHeader } from '@/components/app/page-header';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
    const form = useForm({
        code: branch?.code ?? '',
        name: branch?.name ?? '',
        phone: branch?.phone ?? '',
        address: branch?.address ?? '',
        is_default: branch?.is_default ?? false,
        status: branch?.status ?? 'active',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

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
            <form onSubmit={submit} className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title={title}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href="/branches">Hủy</Link>
                            </Button>
                            <Button disabled={form.processing}>Lưu</Button>
                        </>
                    }
                />
                <div className="grid gap-4 lg:grid-cols-12">
                    <Card className="lg:col-span-8">
                        <CardHeader>
                            <CardTitle>Thông tin chi nhánh</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
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
                        </CardContent>
                    </Card>
                    <Card className="lg:col-span-4">
                        <CardHeader>
                            <CardTitle>Thiết lập</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4">
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
                                        form.setData(
                                            'is_default',
                                            checked === true,
                                        )
                                    }
                                />
                                Chi nhánh mặc định
                            </label>
                            <InputError message={form.errors.is_default} />
                        </CardContent>
                    </Card>
                </div>
            </form>
        </>
    );
}

BranchForm.layout = {
    breadcrumbs: [
        { title: 'Chi nhánh', href: '/branches' },
        { title: 'Chi tiết', href: '#' },
    ],
};
