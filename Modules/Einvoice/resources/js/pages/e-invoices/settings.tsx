import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/app/confirm-action';
import { Field } from '@/components/app/field';
import { Pill } from '@/components/app/status-badge';
import { SettingsPage, SettingsSection } from '@/components/app/settings-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { useCan } from '@/hooks/use-can';
import { formatDateTime } from '@/lib/format';
import { HttpError, sendJson } from '@/lib/http';

type Provider = {
    driver: string;
    name: string;
    fields: { key: string; label: string; help: string | null }[];
    connected: boolean;
    is_active: boolean;
    is_sandbox: boolean;
    username: string | null;
    has_password: boolean;
    settings: Record<string, string | null>;
    connected_at: string | null;
};

/** Cấu hình → Hóa đơn điện tử: provider connection and auto-issue. */
export default function EinvoiceSettings({
    providers,
    settings,
    upcoming,
}: {
    providers: Provider[];
    settings: { auto_issue: boolean };
    upcoming: string[];
}) {
    const form = useForm(settings);

    return (
        <>
            <Head title="Cấu hình hóa đơn điện tử" />
            <SettingsPage
                title="Hóa đơn điện tử"
                onSave={() =>
                    form.put('/e-invoices/settings', {
                        preserveScroll: true,
                        onSuccess: () => form.setDefaults(),
                    })
                }
                onCancel={() => form.reset()}
                processing={form.processing}
                isDirty={form.isDirty}
            >
                {providers.map((provider) => (
                    <SettingsSection
                        key={provider.driver}
                        title={provider.name}
                        description="Tài khoản do nhà cung cấp cấp khi đăng ký phát hành hóa đơn điện tử. Mật khẩu được mã hóa và không hiển thị lại."
                    >
                        <ProviderForm provider={provider} />
                    </SettingsSection>
                ))}
                <SettingsSection
                    title="Tự động xuất hóa đơn"
                    description="Áp dụng cho nhà cung cấp đang dùng để phát hành."
                >
                    <label className="flex items-center justify-between gap-4 text-sm">
                        <span>
                            <span className="block font-medium">
                                Tự động xuất hóa đơn khi đơn hàng hoàn thành
                            </span>
                            <span className="text-muted-foreground">
                                Hóa đơn lấy thông tin người mua từ khách hàng
                                (MST, tên đơn vị). Lỗi phát hành hiện ở danh
                                sách hóa đơn để sửa và phát hành lại.
                            </span>
                        </span>
                        <Switch
                            checked={form.data.auto_issue}
                            onCheckedChange={(checked) =>
                                form.setData('auto_issue', checked)
                            }
                        />
                    </label>
                </SettingsSection>
                <SettingsSection
                    title="Nhà cung cấp khác"
                    description="Sẽ kết nối trong các bản cập nhật sau."
                >
                    <div className="flex flex-wrap gap-2">
                        {upcoming.map((name) => (
                            <Pill key={name}>{name}</Pill>
                        ))}
                    </div>
                </SettingsSection>
            </SettingsPage>
        </>
    );
}

function ProviderForm({ provider }: { provider: Provider }) {
    const can = useCan();
    const form = useForm({
        username: provider.username ?? '',
        password: '',
        is_sandbox: provider.is_sandbox,
        is_active: provider.connected ? provider.is_active : true,
        settings: Object.fromEntries(
            provider.fields.map((field) => [
                field.key,
                provider.settings[field.key] ?? '',
            ]),
        ) as Record<string, string>,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const [check, setCheck] = useState<{ ok: boolean; message: string } | null>(
        null,
    );
    const [checking, setChecking] = useState(false);

    const verify = async () => {
        setChecking(true);
        setCheck(null);

        try {
            const result = await sendJson<{ message: string }>(
                'POST',
                `/e-invoices/providers/${provider.driver}/verify`,
                form.data,
            );
            setCheck({ ok: true, message: result.message });
        } catch (error) {
            setCheck({
                ok: false,
                message:
                    error instanceof HttpError
                        ? error.message
                        : 'Có lỗi xảy ra, hãy thử lại.',
            });
        } finally {
            setChecking(false);
        }
    };

    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap items-center gap-2 text-sm">
                {provider.connected ? (
                    <>
                        <Pill tone={provider.is_active ? 'success' : 'neutral'}>
                            {provider.is_active
                                ? 'Đang dùng để phát hành'
                                : 'Đã kết nối, đang tắt'}
                        </Pill>
                        {provider.is_sandbox && (
                            <Pill tone="warning">Môi trường thử nghiệm</Pill>
                        )}
                        <span className="text-muted-foreground">
                            Kết nối lúc {formatDateTime(provider.connected_at)}
                        </span>
                    </>
                ) : (
                    <Pill>Chưa kết nối</Pill>
                )}
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Tài khoản" error={errors.username}>
                    <Input
                        value={form.data.username}
                        onChange={(event) =>
                            form.setData('username', event.target.value)
                        }
                        autoComplete="off"
                    />
                </Field>
                <Field label="Mật khẩu" error={errors.password}>
                    <Input
                        type="password"
                        value={form.data.password}
                        onChange={(event) =>
                            form.setData('password', event.target.value)
                        }
                        placeholder={
                            provider.has_password
                                ? '•••••••• (để trống nếu không đổi)'
                                : ''
                        }
                        autoComplete="new-password"
                    />
                </Field>
                {provider.fields.map((field) => (
                    <Field
                        key={field.key}
                        label={field.label}
                        error={errors[`settings.${field.key}`]}
                    >
                        <Input
                            value={form.data.settings[field.key] ?? ''}
                            onChange={(event) =>
                                form.setData('settings', {
                                    ...form.data.settings,
                                    [field.key]: event.target.value,
                                })
                            }
                        />
                        {field.help && (
                            <span className="text-xs text-muted-foreground">
                                {field.help}
                            </span>
                        )}
                    </Field>
                ))}
            </div>
            <label className="flex items-center justify-between gap-4 text-sm">
                <span>
                    <span className="block font-medium">
                        Môi trường thử nghiệm
                    </span>
                    <span className="text-muted-foreground">
                        Dùng tài khoản demo của nhà cung cấp, hóa đơn không có
                        giá trị.
                    </span>
                </span>
                <Switch
                    checked={form.data.is_sandbox}
                    onCheckedChange={(checked) =>
                        form.setData('is_sandbox', checked)
                    }
                />
            </label>
            <label className="flex items-center justify-between gap-4 text-sm">
                <span>
                    <span className="block font-medium">
                        Dùng để phát hành hóa đơn
                    </span>
                    <span className="text-muted-foreground">
                        Chỉ một nhà cung cấp được dùng để phát hành.
                    </span>
                </span>
                <Switch
                    checked={form.data.is_active}
                    onCheckedChange={(checked) =>
                        form.setData('is_active', checked)
                    }
                />
            </label>
            {check && (
                <p
                    className={
                        check.ok
                            ? 'text-sm text-success'
                            : 'text-sm text-destructive'
                    }
                >
                    {check.message}
                </p>
            )}
            {can('settings.update') && (
                <div className="flex flex-wrap justify-between gap-2 border-t pt-4">
                    <div>
                        {provider.connected && (
                            <ConfirmAction
                                onConfirm={() =>
                                    router.delete(
                                        `/e-invoices/providers/${provider.driver}`,
                                        { preserveScroll: true },
                                    )
                                }
                                title={`Ngắt kết nối ${provider.name}?`}
                                description="Tài khoản bị xóa khỏi EXSO. Hóa đơn đã phát hành vẫn giữ nguyên; muốn hủy, điều chỉnh hay tải hóa đơn thì cần kết nối lại."
                                confirmLabel="Ngắt kết nối"
                                destructive
                                trigger={
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="border-destructive text-destructive hover:bg-destructive/5 hover:text-destructive"
                                    >
                                        Ngắt kết nối
                                    </Button>
                                }
                            />
                        )}
                    </div>
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={checking}
                            onClick={verify}
                        >
                            Kiểm tra kết nối
                        </Button>
                        <Button
                            type="button"
                            disabled={form.processing}
                            onClick={() =>
                                form.put(
                                    `/e-invoices/providers/${provider.driver}`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            form.setData('password', ''),
                                    },
                                )
                            }
                        >
                            {provider.connected ? 'Lưu kết nối' : 'Kết nối'}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

EinvoiceSettings.layout = {
    breadcrumbs: [
        { title: 'Cấu hình', href: '/config' },
        { title: 'Hóa đơn điện tử', href: '/e-invoices/settings' },
    ],
};
