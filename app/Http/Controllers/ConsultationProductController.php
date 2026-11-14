<?php

namespace App\Http\Controllers;

use App\Models\ConsultationProduct;
use App\Models\PackagePurchase;
use App\Models\User;
use App\Services\PackageBilling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConsultationProductController extends Controller
{
    public function index(Request $request, PackageBilling $billing): Response
    {
        $admin = $request->routeIs('admin.consultation-packages');
        $products = ConsultationProduct::with('coach:id,name')->when(! $admin, fn ($query) => $query->where('published', true)->whereHas('coach', fn ($coach) => $coach->where('is_active', true)->whereHas('roles', fn ($role) => $role->where('key', 'coach'))))->latest()->paginate(12)->through(fn ($product) => ['id' => $product->id, ...$billing->definition($product), ...($admin ? $product->only('coach_id', 'published') : [])]);
        $purchases = PackagePurchase::when(! $admin, fn ($query) => $query->where('student_id', $request->user()->id))->latest()->paginate(12, ['*'], 'purchases_page')->through(fn ($purchase) => ['id' => $purchase->id, 'title' => $purchase->definition['title'], 'amount' => $purchase->amount, 'status' => $purchase->status, 'expires_at' => $purchase->expires_at]);

        return Inertia::render('ConsultationProducts', ['admin' => $admin, 'products' => $products, 'purchases' => $purchases, 'coaches' => $admin ? User::where('is_active', true)->whereHas('roles', fn ($role) => $role->where('key', 'coach'))->get(['id', 'name']) : []]);
    }

    private function data(Request $request): array
    {
        return $request->validate(['revision' => 'required|integer|min:0', 'coach_id' => 'required|integer|exists:users,id', 'title' => 'required|string|max:160', 'description' => 'required|string|max:5000', 'price' => 'required|integer|between:1,1000000000', 'sessions' => 'required|integer|between:1,100', 'session_minutes' => 'required|integer|between:15,180', 'valid_days' => 'required|integer|between:1,365', 'published' => 'required|boolean', 'manual_payment_enabled' => 'required|boolean', 'reviewed' => 'required|accepted']);
    }

    public function create(Request $request, PackageBilling $billing): RedirectResponse
    {
        $billing->configure($request->user(), $this->data($request));

        return back()->with('success', 'پکیج ثبت شد.');
    }

    public function update(Request $request, ConsultationProduct $product, PackageBilling $billing): RedirectResponse
    {
        $billing->configure($request->user(), $this->data($request), $product);

        return back()->with('success', 'شرایط فروش به‌روز شد؛ سفارش‌های قبلی تغییر نکردند.');
    }
}
