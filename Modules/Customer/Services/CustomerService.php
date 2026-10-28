<?php

namespace Modules\Customer\Services;

use App\Support\Sequences\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerGroup;

/**
 * Creating and editing customers: generated code, one phone number per
 * customer, default group. Used by the customer pages, quick-add from an
 * order, POS and Excel import.
 *
 * @phpstan-type CustomerData array<string, mixed>
 */
class CustomerService
{
    /**
     * @param  CustomerData  $data
     */
    public function save(?Customer $customer, array $data): Customer
    {
        if (array_key_exists('phone', $data)) {
            $data['phone'] = Customer::normalizePhone($data['phone']);
            $this->checkPhone($customer, $data['phone']);
        }

        if (isset($data['code'])) {
            $data['code'] = trim((string) $data['code']) === '' ? null : trim((string) $data['code']);
            $this->checkCode($customer, $data['code']);
        }

        return DB::transaction(function () use ($customer, $data): Customer {
            if ($customer === null) {
                $data['code'] ??= Sequence::next('CUZN', Customer::class);
                $data['group_id'] ??= $this->defaultGroupId();
                $data['created_by'] ??= auth()->id();
                $data['status'] ??= 'active';
                $customer = new Customer;
            } elseif (array_key_exists('code', $data) && $data['code'] === null) {
                unset($data['code']);
            }

            $customer->fill($data)->save();

            return $customer;
        });
    }

    /**
     * Group given to new customers ("Bán lẻ"), created on first use.
     */
    public function defaultGroupId(): ?int
    {
        $id = CustomerGroup::query()->where('is_default', true)->value('id');

        if ($id === null && ! CustomerGroup::query()->withTrashed()->exists()) {
            CustomerGroup::installDefaults();
            $id = CustomerGroup::query()->where('is_default', true)->value('id');
        }

        return $id === null ? null : (int) $id;
    }

    /**
     * A customer with orders or debt is kept for the history.
     */
    public function delete(Customer $customer): void
    {
        if ($customer->debt !== 0) {
            throw ValidationException::withMessages(['customer' => "Khách hàng {$customer->name} còn công nợ, không xóa được."]);
        }

        $customer->delete();
    }

    protected function checkPhone(?Customer $customer, ?string $phone): void
    {
        if ($phone === null) {
            return;
        }

        if (strlen($phone) < 8 || strlen($phone) > 15) {
            throw ValidationException::withMessages(['phone' => 'Số điện thoại không hợp lệ.']);
        }

        $taken = Customer::query()->withTrashed()
            ->where('phone', $phone)
            ->when($customer !== null, fn ($query) => $query->whereKeyNot($customer->id))
            ->first();

        if ($taken !== null) {
            throw ValidationException::withMessages(['phone' => "Số điện thoại {$phone} đã dùng cho khách hàng {$taken->name} ({$taken->code})."]);
        }
    }

    protected function checkCode(?Customer $customer, ?string $code): void
    {
        if ($code === null) {
            return;
        }

        $taken = Customer::query()->withTrashed()
            ->where('code', $code)
            ->when($customer !== null, fn ($query) => $query->whereKeyNot($customer->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => "Mã khách hàng {$code} đã được dùng."]);
        }
    }
}
