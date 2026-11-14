<script setup>
import { ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import JalaliDateInput from "./JalaliDateInput.vue";
import Icon from "./Icon.vue";
import { money, latinDigits } from "../finance";
import { tehranInput } from "../dates";
const props = defineProps({
    installment: Object,
    transfer: Object,
    endpoint: String,
});
const open = ref(false);
const input = ref(null);
const form = useForm({
    file: null,
    transfer_reference: "",
    transfer_date: tehranInput(new Date()).slice(0, 10),
});
function submit() {
    form.transform((data) => ({
        ...data,
        transfer_reference: latinDigits(data.transfer_reference),
    })).post(
        props.endpoint || "/installments/" + props.installment.id + "/receipts",
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                form.reset();
                if (input.value) input.value.value = "";
            },
        },
    );
}
</script>
<template>
    <div class="receipt-upload">
        <button
            type="button"
            class="btn secondary"
            @click="open = !open"
            :aria-expanded="open"
        >
            <Icon name="Upload" :size="16" />ثبت رسید کارت‌به‌کارت
        </button>
        <form
            v-if="open"
            @submit.prevent="submit"
            class="form-stack receipt-upload-form"
        >
            <div class="bank-details">
                <strong>مبلغ دقیق: {{ money(installment.amount) }}</strong>
                <p>{{ transfer.holder }}</p>
                <p dir="ltr">{{ transfer.destination }}</p>
            </div>
            <p class="caption muted">
                فقط رسید همین واریز را ارسال کنید. بارگذاری رسید به معنی تأیید
                پرداخت نیست؛ مدیریت باید آن را با گردش بانکی تطبیق دهد.
            </p>
            <label
                >شماره پیگیری واریز<input
                    v-model="form.transfer_reference"
                    dir="ltr"
                    required
                    minlength="4"
                    maxlength="100" /></label
            ><label
                >تاریخ واریز (شمسی)<JalaliDateInput
                    v-model="form.transfer_date"
                    label="تاریخ واریز"
                    required /></label
            ><label
                >تصویر یا PDF رسید<input
                    ref="input"
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png"
                    required
                    @change="
                        form.file = $event.target.files[0] || null
                    " /></label
            ><small class="muted"
                >فایل خصوصی، حداکثر ۵ مگابایت؛ برای هر واریز یک رسید
                جداگانه.</small
            >
            <p
                v-for="(v, k) in form.errors"
                :key="k"
                class="field-error"
                role="alert"
            >
                {{ v }}
            </p>
            <progress
                v-if="form.progress"
                :value="form.progress.percentage"
                max="100"
                aria-label="بارگذاری رسید"
            ></progress
            ><button class="btn primary" :disabled="form.processing">
                {{ form.processing ? "در حال ثبت رسید…" : "ارسال برای بررسی" }}
            </button>
        </form>
    </div>
</template>
