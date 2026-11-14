<script setup>
import { Link } from "@inertiajs/vue3";
import { fa } from "../dates";
defineProps({ definition: Object, contract: Object, admin: Boolean });
</script>
<template>
    <aside v-if="definition" class="checkout-schedule">
        <strong>{{ definition.title }}</strong>
        <p>
            {{ fa(definition.sessions) }} جلسه
            {{ fa(definition.session_minutes) }} دقیقه‌ای با
            {{ definition.coach_name }}
        </p>
        <p class="caption">
            فعال‌سازی
            {{
                definition.activation === "paid_in_full"
                    ? "پس از تسویه کامل"
                    : "پس از پرداخت اول"
            }}
            · مهلت استفاده {{ fa(definition.valid_days) }} روز از صدور سهمیه.
            انتخاب زمان از بین وقت‌های قابل رزرو مربی انجام می‌شود.
        </p>
        <p v-if="contract" class="caption">
            <span class="badge">{{ contract.status_label }}</span
            ><span v-if="contract.status === 'blocked'">
                برای پیگیری با مدیریت تماس بگیرید؛ پرداخت ثبت‌شده محفوظ
                است.</span
            >
        </p>
        <Link
            v-if="contract?.status === 'issued'"
            :href="
                admin ? '/admin/consultation-grants' : '/consultation-grants'
            "
            class="text-link"
            >مشاهده سهمیه و رزرو مشاوره</Link
        >
    </aside>
</template>
