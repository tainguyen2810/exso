<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Branches\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\Product\Services\ProductExcel;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Reader\Exception\ReaderException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportController extends Controller
{
    public function template(): StreamedResponse
    {
        return ProductExcel::template();
    }

    /**
     * Import products from our template or a Sapo product export.
     * Nothing is saved when any row is invalid.
     */
    public function store(Request $request, ProductExcel $excel): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:xlsx,csv,txt'],
        ], [], ['file' => 'tệp Excel']);

        /** @var User $user */
        $user = $request->user();

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $path = $file->getRealPath().'.'.strtolower($file->getClientOriginalExtension());
        copy($file->getRealPath(), $path);

        try {
            $result = $excel->import($path, app(CurrentBranch::class)->id($user), $user->branchIds(), $user->can('products.view_cost'));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        } catch (IOException|ReaderException) {
            return back()->withErrors(['file' => 'Không đọc được tệp. Hãy dùng tệp .xlsx.']);
        } finally {
            @unlink($path);
        }

        $skipped = $result['skipped_branches'] === [] ? '' : ' Bỏ qua tồn kho của chi nhánh bạn không được phân công: '.implode(', ', $result['skipped_branches']).'.';
        $this->toast("Đã nhập sản phẩm: thêm {$result['created']}, cập nhật {$result['updated']}.{$skipped}");

        return to_route('products.index');
    }
}
