<script setup>
import { useForm } from "@inertiajs/vue3";
import { money, latinDigits } from "../finance";
const props = defineProps({
    payment: Object,
    corporate: Boolean,
    postUrl: String,
});
const form = useForm({
    version: props.payment.version,
    destination: "",
    reason: "",
    owner_confirmed: false,
    organization_confirmed: false,
    beneficiary_name: "",
});
function submit() {
    form.version = props.payment.version;
    form.destination = latinDigits(form.destination);
    form.post(props.postUrl || `/payments/${props.payment.id}/refund`, {
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
                >{{ corporate ? "شماره شبای حساب سازمان" : "شماره شبای خودتان"
                }}<input
                    v-model="form.destination"
                    dir="ltr"
                    autocomplete="off"
                    maxlength="100"
                    placeholder="IR…"
                    required /></label
            ><label v-if="corporate"
                >نام صاحب حساب سازمان<input
                    v-model="form.beneficiary_name"
                    name="beneficiary_name"
                    required
                    minlength="3"
                    maxlength="200" /></label
            ><label
                >دلیل درخواست<textarea
                    v-model="form.reason"
                    required
                    minlength="3"
                    maxlength="2000"
                ></textarea></label
            ><label v-if="corporate"
                ><input
                    type="checkbox"
                    v-model="form.organization_confirmed"
                    required
                />این شبا و نام صاحب حساب متعلق به حساب مجاز سازمان است و
                درخواست را از طرف سازمان ثبت می‌کنم.</label
            ><label v-else
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
