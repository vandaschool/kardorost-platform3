<script setup>
import { useForm } from "@inertiajs/vue3";
const props = defineProps({
    url: String,
    membershipKey: String,
    action: String,
    label: String,
});
const form = useForm({
    membership_key: props.membershipKey,
    action: props.action,
    reason: "",
});
function submit() {
    form.membership_key = props.membershipKey;
    form.action = props.action;
    form.post(props.url, { preserveScroll: true });
}
</script>
<template>
    <details class="consultation-section">
        <summary>{{ label }}</summary>
        <form class="stack-form" @submit.prevent="submit">
            <label
                >دلیل<textarea
                    v-model="form.reason"
                    name="reason"
                    required
                    maxlength="2000"
                />
            </label>
            <p class="muted">
                دسترسی گزارش سازمان قطع می‌شود. ثبت‌نام‌های قبلی و سابقه مصرف
                سهمیه حفظ می‌شوند.
            </p>
            <p v-for="error in form.errors" class="field-error" role="alert">
                {{ error }}
            </p>
            <button class="btn secondary" :disabled="form.processing">
                {{ label }}
            </button>
        </form>
    </details>
</template>
