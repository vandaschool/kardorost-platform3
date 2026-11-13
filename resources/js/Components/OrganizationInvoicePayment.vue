<script setup>
import { watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import JalaliDateInput from "./JalaliDateInput.vue";
import { latinDigits, money } from "../finance";
const props = defineProps({
    invoice: Object,
    gatewayEnabled: Boolean,
    pendingAmount: Number,
});
const gateway = useForm({
    amount: String(
        (props.pendingAmount ?? props.invoice.remaining_amount) / 10,
    ),
});
const form = useForm({
    amount: String(props.invoice.remaining_amount / 10),
    transfer_reference: "",
    transfer_date: "",
    file: null,
});
watch(
    () => [props.invoice.remaining_amount, props.pendingAmount],
    ([remaining, pending]) => {
        gateway.amount = String((pending ?? remaining) / 10);
        form.amount = String(remaining / 10);
    },
);
function pay() {
    gateway.amount = latinDigits(gateway.amount);
    gateway.post(`/organization/invoices/${props.invoice.id}/pay`);
}
function submit() {
    form.amount = latinDigits(form.amount);
    form.transfer_reference = latinDigits(form.transfer_reference);
    form.post(`/organization/invoices/${props.invoice.id}/receipts`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () =>
            form.reset("transfer_reference", "transfer_date", "file"),
    });
}
</script>
<template>
    <section class="panel glass consultation-card consultation-section">
        <h2>پرداخت صورتحساب</h2>
        <p>مانده قابل پرداخت: {{ money(invoice.remaining_amount) }}</p>
        <p class="muted">
            می‌توانید تمام مانده یا بخشی از آن را پرداخت کنید. فقط واریز
            تأییدشده از بدهی کسر می‌شود.
        </p>
        <p v-if="pendingAmount" role="status">
            درخواست درگاه به مبلغ {{ money(pendingAmount) }} در جریان است. همان
            درخواست را ادامه دهید؛ تا تعیین تکلیف یا انقضای آن رسید تازه
            نفرستید.
        </p>
        <form v-if="gatewayEnabled" @submit.prevent="pay" class="stack-form">
            <label
                >مبلغ این پرداخت درگاه (تومان)<input
                    v-model="gateway.amount"
                    :readonly="!!pendingAmount"
                    name="gateway_amount"
                    inputmode="numeric"
                    dir="ltr"
                    required
                    maxlength="12"
            /></label>
            <button
                class="btn primary"
                :disabled="gateway.processing || form.processing"
            >
                پرداخت با درگاه زیبال
            </button>
        </form>
        <details v-if="!pendingAmount" class="consultation-section">
            <summary>ثبت رسید واریز بانکی</summary>
            <p>
                مقصد: <bdi>{{ invoice.destination }}</bdi> ·
                {{ invoice.holder }}
            </p>
            <p class="muted">
                مبلغی را که به حساب مقصد واریز کرده‌اید وارد کنید و رسید همان
                واریز را بفرستید. تصویر رسید به‌تنهایی پرداخت را تأیید نمی‌کند.
            </p>
            <form @submit.prevent="submit" class="stack-form">
                <label
                    >مبلغ این واریز (تومان)<input
                        v-model="form.amount"
                        name="payment_amount"
                        inputmode="numeric"
                        dir="ltr"
                        required
                        maxlength="12"
                /></label>
                <label
                    >شناسه واریز<input
                        v-model="form.transfer_reference"
                        name="transfer_reference"
                        required
                        maxlength="100"
                        dir="ltr"
                /></label>
                <JalaliDateInput
                    v-model="form.transfer_date"
                    label="تاریخ واریز سازمان"
                    required
                />
                <label
                    >فایل رسید (PDF، JPG یا PNG؛ تا ۵ مگابایت)<input
                        type="file"
                        accept="application/pdf,image/jpeg,image/png"
                        required
                        @change="form.file = $event.target.files[0]"
                /></label>
                <button
                    class="btn primary"
                    :disabled="form.processing || gateway.processing"
                >
                    ارسال رسید برای بررسی
                </button>
                <progress
                    v-if="form.progress"
                    :value="form.progress.percentage"
                    max="100"
                ></progress>
            </form>
        </details>
    </section>
</template>
