import { Head, useForm } from '@inertiajs/react';
import { ImageUp, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { SettingsPage, SettingsSection } from '@/components/app/settings-page';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Info = {
    name: string | null;
    phone: string | null;
    email: string | null;
    business_field: string | null;
    address: string | null;
    province: string | null;
    district: string | null;
    ward: string | null;
    logo_url: string | null;
};

/** Thông tin cửa hàng, like Sapo: logo, contact and address. */
export default function StoreInfo({
    info,
    businessFields,
}: {
    info: Info;
    businessFields: string[];
}) {
    const initial = {
        name: info.name ?? '',
        phone: info.phone ?? '',
        email: info.email ?? '',
        business_field: info.business_field ?? '',
        address: info.address ?? '',
        province: info.province ?? '',
        district: info.district ?? '',
        ward: info.ward ?? '',
        logo: null as File | null,
        remove_logo: false,
    };
    const form = useForm(initial);
    const [preview, setPreview] = useState<string | null>(info.logo_url);

    useEffect(() => {
        if (!form.data.logo) {
            return;
        }

        const url = URL.createObjectURL(form.data.logo);
        setPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [form.data.logo]);

    const save = () =>
        form.post('/config/store', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.setDefaults({ ...form.data, logo: null }),
        });

    const field = (
        key: Exclude<keyof typeof initial, 'logo' | 'remove_logo'>,
        label: string,
        props: React.ComponentProps<typeof Input> = {},
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={key}>{label}</Label>
            <Input
                id={key}
                value={form.data[key]}
                onChange={(event) => form.setData(key, event.target.value)}
                {...props}
            />
            <InputError message={form.errors[key]} />
        </div>
    );

    return (
        <>
            <Head title="Thông tin cửa hàng" />
            <SettingsPage
                title="Thông tin cửa hàng"
                onSave={save}
                onCancel={() => {
                    form.reset();
                    setPreview(info.logo_url);
                }}
                processing={form.processing}
                isDirty={form.isDirty}
            >
                <SettingsSection
                    title="Logo cửa hàng"
                    description="Hiện trên thanh bên trái và trên mẫu in. Ảnh PNG, JPG hoặc WEBP, tối đa 2MB."
                >
                    <div className="flex items-center gap-5">
                        <div className="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted">
                            {preview ? (
                                <img
                                    src={preview}
                                    alt="Logo cửa hàng"
                                    className="size-full object-contain"
                                />
                            ) : (
                                <ImageUp className="size-8 text-muted-foreground" />
                            )}
                        </div>
                        <div className="grid gap-2">
                            <div className="flex flex-wrap gap-2">
                                <Button variant="outline" asChild>
                                    <label className="cursor-pointer">
                                        Chọn ảnh
                                        <input
                                            type="file"
                                            accept="image/png,image/jpeg,image/webp"
                                            className="sr-only"
                                            onChange={(event) => {
                                                const file =
                                                    event.target.files?.[0];

                                                if (file) {
                                                    form.setData({
                                                        ...form.data,
                                                        logo: file,
                                                        remove_logo: false,
                                                    });
                                                }

                                                event.target.value = '';
                                            }}
                                        />
                                    </label>
                                </Button>
                                {preview && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="text-destructive hover:text-destructive"
                                        onClick={() => {
                                            form.setData({
                                                ...form.data,
                                                logo: null,
                                                remove_logo: true,
                                            });
                                            setPreview(null);
                                        }}
                                    >
                                        <X className="size-4" />
                                        Xóa logo
                                    </Button>
                                )}
                            </div>
                            <InputError message={form.errors.logo} />
                        </div>
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Thông tin liên hệ"
                    description="Tên và số điện thoại hiện trên hóa đơn, phiếu giao hàng."
                    className="grid gap-4 sm:grid-cols-2"
                >
                    {field('name', 'Tên cửa hàng *', { maxLength: 150 })}
                    {field('phone', 'Số điện thoại *', {
                        inputMode: 'tel',
                        maxLength: 20,
                    })}
                    {field('email', 'Email', { type: 'email', maxLength: 150 })}
                    <div className="grid gap-2">
                        <Label>Lĩnh vực kinh doanh</Label>
                        <Select
                            value={form.data.business_field || undefined}
                            onValueChange={(value) =>
                                form.setData('business_field', value)
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Chọn lĩnh vực" />
                            </SelectTrigger>
                            <SelectContent>
                                {businessFields.map((item) => (
                                    <SelectItem key={item} value={item}>
                                        {item}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.business_field} />
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Địa chỉ"
                    description="Địa chỉ cửa hàng dùng làm địa chỉ lấy hàng mặc định."
                    className="grid gap-4 sm:grid-cols-2"
                >
                    <div className="sm:col-span-2">
                        {field('address', 'Địa chỉ *', {
                            maxLength: 255,
                            placeholder: 'Số nhà, tên đường',
                        })}
                    </div>
                    {field('province', 'Tỉnh/Thành phố', { maxLength: 100 })}
                    {field('district', 'Quận/Huyện', { maxLength: 100 })}
                    {field('ward', 'Phường/Xã', { maxLength: 100 })}
                </SettingsSection>
            </SettingsPage>
        </>
    );
}

StoreInfo.layout = {
    breadcrumbs: [
        { title: 'Cấu hình', href: '/config' },
        { title: 'Thông tin cửa hàng', href: '/config/store' },
    ],
};
