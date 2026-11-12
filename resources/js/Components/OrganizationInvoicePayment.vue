<script setup>
import { useForm, Link } from "@inertiajs/vue3";
import JalaliDateInput from "./JalaliDateInput.vue";
import { latinDigits, money } from "../finance";
const props = defineProps({ invoice: Object, gatewayEnabled: Boolean });
const form = useForm({ transfer_reference: "", transfer_date: "", file: null });
function submit() {
    form.transfer_reference = latinDigits(form.transfer_reference);
    form.post(`/organization/invoices/${props.invoice.id}/receipts`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>
<template>
    <section class="panel glass consultation-card consultation-section">
        <h2>پرداخت کامل صورتحساب</h2>
        <p>مبلغ: {{ money(invoice.amount) }}</p>
        <Link
            v-if="gatewayEnabled"
            :href="`/organization/invoices/${invoice.id}/pay`"
            method="post"
            as="button"
            class="btn primary"
            >پرداخت با درگاه زیبال</Link
        >
        <details class="consultation-section">
            <summary>ثبت رسید واریز بانکی</summary>
            <p>
                مقصد: <bdi>{{ invoice.destination }}</bdi> ·
                {{ invoice.holder }}
            </p>
            <p class="muted">
                کل مبلغ صورتحساب را به حساب مقصد واریز و سپس رسید را ثبت کنید.
                تصویر رسید به‌تنهایی پرداخت را تأیید نمی‌کند.
            </p>
            <form @submit.prevent="submit" class="stack-form">
                <label
                    >شناسه واریز<input
                        v-model="form.transfer_reference"
                        name="transfer_reference"
                        required
                        maxlength="100"
                        dir="ltr" /></label
                ><JalaliDateInput
                    v-model="form.transfer_date"
                    label="تاریخ واریز سازمان"
                    required
                /><label
                    >فایل رسید (PDF، JPG یا PNG؛ تا ۵ مگابایت)<input
                        type="file"
                        accept="application/pdf,image/jpeg,image/png"
                        required
                        @change="form.file = $event.target.files[0]" /></label
                ><button class="btn primary" :disabled="form.processing">
                    ارسال رسید برای بررسی</button
                ><progress
                    v-if="form.progress"
                    :value="form.progress.percentage"
                    max="100"
                ></progress>
            </form>
        </details>
    </section>
</template>
