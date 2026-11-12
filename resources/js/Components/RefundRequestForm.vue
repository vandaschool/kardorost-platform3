<script setup>
import { useForm } from "@inertiajs/vue3";
import { money, latinDigits } from "../finance";
const props = defineProps({ payment: Object });
const form = useForm({
    version: props.payment.version,
    destination: "",
    reason: "",
    owner_confirmed: false,
});
function submit() {
    form.version = props.payment.version;
    form.destination = latinDigits(form.destination);
    form.post(`/payments/${props.payment.id}/refund`, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>
<template>
    <article class="panel glass consultation-card">
        <h3>{{ payment.title }}</h3>
        <p>بازپرداخت کامل: {{ money(payment.amount) }}</p>
        <p v-if="!payment.eligible" class="muted">
            این تراکنش تخصیص‌یافته در این مرحله مشمول درخواست بازپرداخت نیست.
        </p>
        <form v-else @submit.prevent="submit" class="stack-form">
            <label
                >شماره شبای خودتان<input
                    v-model="form.destination"
                    dir="ltr"
                    autocomplete="off"
                    maxlength="100"
                    placeholder="IR…"
                    required /></label
            ><label
                >دلیل درخواست<textarea
                    v-model="form.reason"
                    required
                    minlength="3"
                    maxlength="2000"
                ></textarea></label
            ><label
                ><input
                    type="checkbox"
                    v-model="form.owner_confirmed"
                    required
                />شماره شبا متعلق به صاحب حساب کاربری و دریافت‌کننده وجه
                است.</label
            >
            <p class="muted">
                درخواست و تأیید آن به معنی برگشت وجه نیست؛ نتیجه و مدرک واریز در
                همین بخش ثبت می‌شود.
            </p>
            <button class="btn primary" :disabled="form.processing">
                ثبت درخواست بازپرداخت کامل
            </button>
        </form>
    </article>
</template>
