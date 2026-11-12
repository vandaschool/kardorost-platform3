<script setup>
import { ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import Icon from "./Icon.vue";
import { money, dateOnly, latinDigits, receiptStatuses } from "../finance";
import { dateTime, fa } from "../dates";
const props = defineProps({ receipt: Object });
const reviewing = ref(false);
const form = useForm({
    action: "approve",
    verified_amount: "",
    statement_verified: false,
    review_note: "",
    expected_review_version: props.receipt.review_version,
});
function submit() {
    form.transform((data) => ({
        ...data,
        verified_amount:
            data.action === "approve"
                ? Number(latinDigits(data.verified_amount))
                : null,
    })).post("/admin/finance/receipts/" + props.receipt.id + "/review", {
        preserveScroll: true,
        onSuccess: () => (reviewing.value = false),
    });
}
function begin() {
    form.reset();
    form.expected_review_version = props.receipt.review_version;
    reviewing.value = true;
}
</script>
<template>
    <article class="receipt-review-card">
        <div class="section-heading">
            <div>
                <h3>{{ receipt.student.name }}</h3>
                <p class="caption muted">
                    {{ receipt.payment.title
                    }}<span v-if="receipt.payment.sequence">
                        · قسط {{ fa(receipt.payment.sequence) }}</span
                    >
                    ·
                    {{ money(receipt.payment.amount) }}
                </p>
            </div>
            <span
                :class="[
                    'badge',
                    receipt.status === 'approved' ? 'teal' : 'gold',
                ]"
                >{{ receiptStatuses[receipt.status] }}</span
            >
        </div>
        <dl class="receipt-facts">
            <div>
                <dt>شماره پیگیری اعلام‌شده</dt>
                <dd dir="ltr">{{ receipt.transfer_reference }}</dd>
            </div>
            <div>
                <dt>تاریخ واریز</dt>
                <dd>{{ dateOnly(receipt.transfer_date) }}</dd>
            </div>
            <div>
                <dt>حساب مقصد</dt>
                <dd dir="ltr">{{ receipt.destination }}</dd>
            </div>
            <div>
                <dt>زمان ثبت رسید</dt>
                <dd>{{ dateTime(receipt.created_at) }}</dd>
            </div>
        </dl>
        <p v-if="receipt.review_note" class="lesson-text caption">
            یادداشت بررسی: {{ receipt.review_note }}
        </p>
        <div class="learning-actions">
            <a :href="receipt.download_url" class="btn secondary"
                ><Icon name="Download" :size="16" />دریافت رسید</a
            ><button
                v-if="receipt.status === 'pending' && !reviewing"
                class="btn primary"
                @click="begin"
            >
                بررسی رسید
            </button>
        </div>
        <form
            v-if="reviewing && receipt.status === 'pending'"
            class="form-stack editor-form"
            @submit.prevent="submit"
        >
            <label
                >نتیجه بررسی<select v-model="form.action">
                    <option value="approve">تأیید و تخصیص مبلغ</option>
                    <option value="reject">رد با دلیل</option>
                </select></label
            ><template v-if="form.action === 'approve'"
                ><p class="caption muted">
                    وجود فایل رسید کافی نیست. واریز را مستقلاً در گردش بانکی
                    بررسی و مبلغ واقعی را وارد کنید.
                </p>
                <label
                    >مبلغ مشاهده‌شده در گردش بانکی (تومان)<input
                        v-model="form.verified_amount"
                        inputmode="numeric"
                        required /></label
                ><label class="check-label"
                    ><input
                        v-model="form.statement_verified"
                        type="checkbox"
                        required
                    />مبلغ، مقصد، تاریخ و شماره پیگیری را با گردش بانکی تطبیق
                    دادم.</label
                ></template
            ><label
                >یادداشت{{
                    form.action === "reject" ||
                    receipt.payment.organization_invoice_id
                        ? "، الزامی"
                        : ""
                }}<textarea
                    v-model="form.review_note"
                    maxlength="5000"
                    rows="3"
                    :required="
                        form.action === 'reject' ||
                        !!receipt.payment.organization_invoice_id
                    "
                ></textarea>
            </label>
            <p
                v-for="(v, k) in form.errors"
                :key="k"
                class="field-error"
                role="alert"
            >
                {{ v }}
            </p>
            <div class="learning-actions">
                <button class="btn primary" :disabled="form.processing">
                    ثبت نتیجه بررسی</button
                ><button
                    type="button"
                    class="btn secondary"
                    @click="reviewing = false"
                >
                    انصراف
                </button>
            </div>
        </form>
    </article>
</template>
