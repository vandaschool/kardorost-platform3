<script setup>
import { useForm } from "@inertiajs/vue3";
import JalaliDateInput from "./JalaliDateInput.vue";
import { fa } from "../dates";
const props = defineProps({ license: Object });
const form = useForm({
    revision: props.license.revision,
    seat_limit: props.license.seat_limit,
    expires_on: props.license.expires_on,
    active: props.license.active,
    contract_reference: props.license.contract_reference,
    authorized: false,
});
function submit() {
    form.patch(`/admin/organization-licenses/${props.license.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            form.revision = props.license.revision;
            form.authorized = false;
        },
    });
}
</script>
<template>
    <article class="panel glass consultation-card">
        <h2>{{ license.organization_name }}</h2>
        <h3>{{ license.course_title }} · {{ license.cohort_title }}</h3>
        <p>
            {{ fa(license.used) }} صندلی مصرف‌شده ·
            {{ fa(license.remaining) }} صندلی باقی‌مانده
        </p>
        <details>
            <summary>ویرایش سهمیه</summary>
            <form
                @submit.prevent="submit"
                class="stack-form organization-license-form"
            >
                <label
                    >سقف کل صندلی<input
                        v-model="form.seat_limit"
                        type="number"
                        :min="Math.max(1, license.used)"
                        max="10000"
                        required /></label
                ><JalaliDateInput
                    v-model="form.expires_on"
                    label="پایان مهلت ثبت‌نام جدید"
                    required
                /><label
                    >شناسه قرارداد یا مجوز تخصیص<input
                        v-model="form.contract_reference"
                        required
                        maxlength="200" /></label
                ><label
                    ><input v-model="form.active" type="checkbox" />سهمیه برای
                    ثبت‌نام تازه فعال باشد.</label
                ><label
                    ><input
                        type="checkbox"
                        v-model="form.authorized"
                        required
                    />تخصیص این سهمیه طبق قرارداد یا مجوز سازمان تأیید شده
                    است.</label
                >
                <p class="muted">
                    این فرم دریافت وجه را تأیید نمی‌کند. تعلیق سهمیه یا پایان
                    مهلت، دسترسی آموزشی ثبت‌نام‌های قبلی را قطع نمی‌کند.
                </p>
                <button class="btn secondary" :disabled="form.processing">
                    ذخیره تغییرات
                </button>
            </form>
        </details>
    </article>
</template>
