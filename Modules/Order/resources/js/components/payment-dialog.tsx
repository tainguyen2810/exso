import { useForm } from '@inertiajs/react';
import { Field } from '@/components/app/field';
import { MoneyInput } from '@/components/app/money-input';
import { OptionSelect, toSelectOptions } from '@/components/app/option-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatNumber } from '@/lib/format';

export type PaymentMethodOption = {
    id: number;
    name: string;
    type: string;
    bank_bin: string | null;
    bank_name: string | null;
    account_number: string | null;
    account_name: string | null;
};

export type QrInfo = {
    svg: string;
    bank: string | null;
    account_number: string;
    account_name: string | null;
    amount: number;
};

/** Thanh toán / hoàn tiền: method, amount (pre-filled) and VietQR for bank transfers. */
export function PaymentDialog({
    open,
    onOpenChange,
    url,
    remaining,
    methods,
    defaultMethod,
    qr,
    title = 'Xác nhận thanh toán',
    hint = 'Còn phải trả',
    confirmLabel = 'Thanh toán',
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    url: string;
    remaining: number;
    methods: PaymentMethodOption[];
    defaultMethod: number | string | null;
    qr: QrInfo | null;
    title?: string;
    hint?: string;
    confirmLabel?: string;
}) {
    const initial =
        methods.find(
            (method) => String(method.id) === String(defaultMethod ?? ''),
        )?.id ??
        methods[0]?.id ??
        null;
    const form = useForm({
        payment_method_id: initial as number | null,
        amount: remaining,
        note: '',
    });
    const method = methods.find(
        (item) => item.id === form.data.payment_method_id,
    );

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);

                if (next) {
                    form.setData({
                        payment_method_id: initial,
                        amount: remaining,
                        note: '',
                    });
                    form.clearErrors();
                }
            }}
        >
            <DialogContent>
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(url, {
                            preserveScroll: true,
                            onSuccess: () => onOpenChange(false),
                        });
                    }}
                >
                    <div>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription className="mt-1">
                            {hint} {formatNumber(remaining)} đ.
                        </DialogDescription>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Hình thức thanh toán"
                            error={form.errors.payment_method_id}
                        >
                            <OptionSelect
                                value={
                                    form.data.payment_method_id
                                        ? String(form.data.payment_method_id)
                                        : null
                                }
                                onChange={(value) =>
                                    form.setData(
                                        'payment_method_id',
                                        value ? Number(value) : null,
                                    )
                                }
                                options={toSelectOptions(methods)}
                            />
                        </Field>
                        <Field
                            label="Số tiền thanh toán"
                            error={form.errors.amount}
                        >
                            <MoneyInput
                                autoFocus
                                value={form.data.amount}
                                onValueChange={(value) =>
                                    form.setData('amount', value ?? 0)
                                }
                            />
                        </Field>
                    </div>
                    <Field
                        label="Tham chiếu / ghi chú"
                        error={form.errors.note}
                    >
                        <Input
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                        />
                    </Field>
                    {method?.type === 'bank' &&
                        qr &&
                        form.data.amount === qr.amount && (
                            <div className="flex items-center gap-4 rounded-md border p-3 text-sm">
                                <div
                                    className="size-36 shrink-0 [&>svg]:size-full"
                                    dangerouslySetInnerHTML={{ __html: qr.svg }}
                                />
                                <div className="grid gap-1">
                                    <span className="font-medium">
                                        Quét mã VietQR để chuyển khoản
                                    </span>
                                    <span>{qr.bank}</span>
                                    <span>
                                        {qr.account_number}
                                        {qr.account_name
                                            ? ` · ${qr.account_name}`
                                            : ''}
                                    </span>
                                    <span>
                                        Số tiền: {formatNumber(qr.amount)} đ
                                    </span>
                                </div>
                            </div>
                        )}
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Thoát
                        </Button>
                        <Button
                            disabled={
                                form.processing ||
                                !form.data.payment_method_id ||
                                form.data.amount <= 0
                            }
                        >
                            {confirmLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
