<script setup>
import { useForm } from "@inertiajs/vue3";
import JalaliDateInput from "./JalaliDateInput.vue";
import { latinDigits } from "../finance";
const props = defineProps({ refund: Object });
const review = useForm({ version: props.refund.version, action: "", note: "" });
const complete = useForm({
    version: props.refund.version,
    amount: "",
    destination: "",
    transfer_reference: "",
    transfer_date: "",
    statement_verified: false,
    beneficiary_verified: false,
    file: null,
});
function decide() {
    review.version = props.refund.version;
    review.post(`/refunds/${props.refund.id}/review`, { preserveScroll: true });
}
function finish() {
    complete.version = props.refund.version;
    complete.amount = latinDigits(complete.amount);
    complete.destination = latinDigits(complete.destination);
    complete.transfer_reference = latinDigits(complete.transfer_reference);
    complete.post(`/refunds/${props.refund.id}/complete`, {
        forceFormData: true,
        preserveScroll: true,
    });
}
</script>
<template>
    <div class="stack-form">
        <p v-if="refund.destination" class="muted">
            شبای درخواست تأییدشده: <bdi>{{ refund.destination }}</bdi>
        </p>
        <form
            v-if="refund.status === 'requested'"
            @submit.prevent="decide"
            class="stack-form"
        >
            <label
                >تصمیم<select v-model="review.action" required>
                    <option disabled value="">انتخاب کنید</option>
                    <option value="approve">تأیید برای واریز کامل</option>
                    <option value="reject">رد با توضیح</option>
                </select></label
            ><label
                >توضیح بررسی<textarea
                    v-model="review.note"
                    required
                    minlength="3"
                    maxlength="2000"
                ></textarea></label
            ><button class="btn primary" :disabled="review.processing">
                ثبت تصمیم
            </button>
        </form>
        <form
            v-if="refund.status === 'approved'"
            @submit.prevent="finish"
            class="stack-form"
        >
            <p class="muted">
                پس از انجام واریز خارج از سامانه، گردش حساب و هویت گیرنده را
                مستقلاً بررسی و مدرک را ثبت کنید. این فرم پولی منتقل نمی‌کند.
            </p>
            <label
                >مبلغ واریزشده (تومان)<input
                    v-model="complete.amount"
                    inputmode="numeric"
                    required /></label
            ><label
                >شبای واقعی گیرنده<input
                    v-model="complete.destination"
                    dir="ltr"
                    required
                    maxlength="100"
                    autocomplete="off" /></label
            ><label
                >شناسه واریز<input
                    v-model="complete.transfer_reference"
                    dir="ltr"
                    required
                    maxlength="150"
                    autocomplete="off" /></label
            ><JalaliDateInput
                v-model="complete.transfer_date"
                label="تاریخ واریز بازپرداخت"
                required
            /><label
                >مدرک برگشت وجه (PDF، JPG یا PNG؛ حداکثر ۵ مگابایت)<input
                    type="file"
                    accept="application/pdf,image/jpeg,image/png"
                    required
                    @change="complete.file = $event.target.files[0]" /></label
            ><label
                ><input
                    type="checkbox"
                    v-model="complete.statement_verified"
                    required
                />واریز واقعی و مبلغ را در گردش حساب بررسی کردم.</label
            ><label
                ><input
                    type="checkbox"
                    v-model="complete.beneficiary_verified"
                    required
                />هویت صاحب شبا با صاحب پرداخت تطبیق داده شد.</label
            ><button class="btn primary" :disabled="complete.processing">
                ثبت مدرک انجام بازپرداخت</button
            ><progress
                v-if="complete.progress"
                :value="complete.progress.percentage"
                max="100"
            ></progress>
        </form>
    </div>
</template>
