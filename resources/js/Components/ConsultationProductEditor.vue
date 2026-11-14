<script setup>
import { useForm } from "@inertiajs/vue3";
import { latinDigits } from "../finance";
const props = defineProps({ product: Object, coaches: Array });
const form = useForm({
    revision: props.product?.revision || 0,
    coach_id: props.product?.coach_id || "",
    title: props.product?.title || "",
    description: props.product?.description || "",
    price: props.product ? props.product.price / 10 : "",
    sessions: props.product?.sessions || 1,
    session_minutes: props.product?.session_minutes || 60,
    valid_days: props.product?.valid_days || 90,
    published: !!props.product?.published,
    manual_payment_enabled: !!props.product?.manual_payment_enabled,
    reviewed: false,
});
function save() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            if (props.product) {
                form.revision = props.product.revision;
                form.reviewed = false;
            } else form.reset();
        },
    };
    form.transform((data) => ({
        ...data,
        price: Number(latinDigits(data.price)),
    }));
    if (props.product)
        form.patch("/admin/consultation-packages/" + props.product.id, options);
    else form.post("/admin/consultation-packages", options);
}
</script>
<template>
    <form
        class="form-stack"
        @submit.prevent="save"
        :data-product-editor="product?.id || 'new'"
    >
        <label
            >عنوان<input
                v-model="form.title"
                name="product_title"
                maxlength="160"
                required
        /></label>
        <label
            >شرح پکیج<textarea
                v-model="form.description"
                name="product_description"
                maxlength="5000"
                required
            />
        </label>
        <label
            >مربی<select v-model="form.coach_id" name="product_coach" required>
                <option value="" disabled>انتخاب مربی</option>
                <option
                    v-if="
                        product &&
                        !coaches.some((c) => c.id === product.coach_id)
                    "
                    :value="product.coach_id"
                >
                    مربی ثبت‌شده، غیرفعال
                </option>
                <option
                    v-for="coach in coaches"
                    :key="coach.id"
                    :value="coach.id"
                >
                    {{ coach.name }}
                </option>
            </select></label
        >
        <label
            >قیمت کل پکیج (تومان)<input
                v-model="form.price"
                name="product_price"
                inputmode="numeric"
                required
        /></label>
        <label
            >تعداد جلسه<input
                v-model="form.sessions"
                name="product_sessions"
                type="number"
                min="1"
                max="100"
                required
        /></label>
        <label
            >مدت هر جلسه (دقیقه)<input
                v-model="form.session_minutes"
                name="product_minutes"
                type="number"
                min="15"
                max="180"
                required
        /></label>
        <label
            >مهلت استفاده از روز صدور (روز)<input
                v-model="form.valid_days"
                name="product_days"
                type="number"
                min="1"
                max="365"
                required
        /></label>
        <label class="check-label"
            ><input
                v-model="form.published"
                name="product_published"
                type="checkbox"
            />برای خرید منتشر شود</label
        >
        <label class="check-label"
            ><input
                v-model="form.manual_payment_enabled"
                name="product_manual"
                type="checkbox"
            />پرداخت کارت‌به‌کارت پذیرفته شود</label
        >
        <p class="caption muted">
            برای ارائه جلسات، وقت‌های قابل رزرو همین مربی با مدت مشخص‌شده لازم
            است. تغییر تنظیمات فقط بر سفارش‌های بعدی اثر دارد.
        </p>
        <label class="check-label"
            ><input
                v-model="form.reviewed"
                name="product_reviewed"
                type="checkbox"
                required
            />قیمت، مربی و شرایط جلسات را بررسی کردم. لغو پیش از شروع، اعتبار
            همان جلسه را برمی‌گرداند.</label
        >
        <p
            v-for="(error, key) in form.errors"
            :key="key"
            class="field-error"
            role="alert"
        >
            {{ error }}
        </p>
        <button class="btn primary" :disabled="form.processing">
            {{ product ? "ذخیره شرایط فروش" : "ثبت پکیج" }}
        </button>
    </form>
</template>
