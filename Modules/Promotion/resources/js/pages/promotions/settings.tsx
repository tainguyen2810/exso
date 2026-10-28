import { Head, useForm } from '@inertiajs/react';
import { SettingsPage, SettingsSection } from '@/components/app/settings-page';
import { Switch } from '@/components/ui/switch';
import { Radio } from '../../components/promotion-fields';

type Values = {
    enabled: boolean;
    apply_mode: 'one' | 'many';
    coupons_enabled: boolean;
};

/** "Cấu hình khuyến mại", như Sapo: bật khuyến mại, cách áp dụng, mã giảm giá. */
export default function PromotionSettings({ values }: { values: Values }) {
    const form = useForm<Values>(values);

    return (
        <>
            <Head title="Cấu hình khuyến mại" />
            <SettingsPage
                title="Cấu hình khuyến mại"
                permission="promotions.update"
                onSave={() =>
                    form.put('/promotions/settings', {
                        preserveScroll: true,
                        onSuccess: () => form.setDefaults(),
                    })
                }
                onCancel={() => form.reset()}
                processing={form.processing}
                isDirty={form.isDirty}
            >
                <SettingsSection
                    title="Áp dụng khuyến mại"
                    description="Khi tắt, nút Áp dụng chương trình khuyến mại ở Tạo đơn và Bán tại quầy bị ẩn và đơn hàng không được tính khuyến mại."
                >
                    <label className="flex items-center justify-between gap-4 text-sm">
                        <span>
                            <span className="block font-medium">
                                Áp dụng chương trình khuyến mại cho cửa hàng
                            </span>
                            <span className="text-muted-foreground">
                                Chương trình chiết khấu và tặng quà
                            </span>
                        </span>
                        <Switch
                            checked={form.data.enabled}
                            onCheckedChange={(checked) =>
                                form.setData('enabled', checked)
                            }
                        />
                    </label>
                </SettingsSection>
                <SettingsSection
                    title="Cách áp dụng"
                    description="Khi đơn hàng thỏa điều kiện của nhiều chương trình cùng lúc."
                >
                    <div className="grid gap-3">
                        <Radio
                            checked={form.data.apply_mode === 'one'}
                            onChange={() => form.setData('apply_mode', 'one')}
                        >
                            Chỉ áp dụng 1 chương trình khuyến mại cho mỗi đơn
                            hàng (gợi ý chương trình có lợi nhất)
                        </Radio>
                        <Radio
                            checked={form.data.apply_mode === 'many'}
                            onChange={() => form.setData('apply_mode', 'many')}
                        >
                            Cho phép áp dụng nhiều chương trình cùng lúc
                        </Radio>
                        <p className="text-xs text-muted-foreground">
                            Khi áp dụng nhiều chương trình: mỗi sản phẩm chỉ
                            nhận mức giảm cao nhất, đơn hàng chỉ nhận một mức
                            chiết khấu theo tổng đơn cao nhất, quà tặng được
                            cộng dồn.
                        </p>
                    </div>
                </SettingsSection>
                <SettingsSection
                    title="Mã giảm giá"
                    description="Cho phép nhân viên nhập mã giảm giá khi tạo đơn và bán tại quầy."
                >
                    <label className="flex items-center justify-between gap-4 text-sm">
                        <span className="font-medium">
                            Phát hành và áp dụng mã giảm giá
                        </span>
                        <Switch
                            checked={form.data.coupons_enabled}
                            onCheckedChange={(checked) =>
                                form.setData('coupons_enabled', checked)
                            }
                        />
                    </label>
                </SettingsSection>
            </SettingsPage>
        </>
    );
}

PromotionSettings.layout = {
    breadcrumbs: [
        { title: 'Cấu hình', href: '/config' },
        { title: 'Cấu hình khuyến mại', href: '/promotions/settings' },
    ],
};
