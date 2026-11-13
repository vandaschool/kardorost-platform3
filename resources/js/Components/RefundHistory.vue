<script setup>
import RefundReview from "./RefundReview.vue";
import { money, dateOnly } from "../finance";
import { dateTime } from "../dates";
defineProps({ refund: Object, admin: Boolean, compact: Boolean });
const labels = {
    requested: "در انتظار بررسی",
    approved: "تأییدشده؛ منتظر واریز",
    rejected: "ردشده",
    completed: "مدرک برگشت وجه ثبت شد",
};
</script>
<template>
    <article
        class="refund-history"
        :class="
            compact ? 'consultation-section' : 'panel glass consultation-card'
        "
    >
        <span class="status-badge">{{ labels[refund.status] }}</span>
        <h3>{{ refund.title }}</h3>
        <p v-if="refund.organization_invoice_id" class="muted">
            بازپرداخت وجه تخصیص‌نیافته سازمان
        </p>
        <p v-if="refund.beneficiary_name">
            صاحب حساب: {{ refund.beneficiary_name }}
        </p>
        <p v-if="admin">{{ refund.student }}</p>
        <p>
            {{ money(refund.amount) }} ·
            <bdi>{{ refund.destination_mask }}</bdi>
        </p>
        <p class="preserve-lines">{{ refund.reason }}</p>
        <p v-if="refund.review_note" class="preserve-lines">
            نتیجه بررسی: {{ refund.review_note }}
        </p>
        <p v-if="refund.transfer_reference">
            شناسه واریز: <bdi>{{ refund.transfer_reference }}</bdi> ·
            {{ dateOnly(refund.transfer_date) }}
        </p>
        <a v-if="refund.file_url" :href="refund.file_url" class="btn secondary"
            >دریافت مدرک برگشت وجه</a
        ><RefundReview v-if="admin" :refund="refund" />
        <details class="consultation-section">
            <summary>تاریخچه درخواست</summary>
            <ol>
                <li v-for="e in refund.events" :key="e.version">
                    <strong>{{ labels[e.status] }}</strong> ·
                    {{ dateTime(e.created_at) }}
                    <p class="preserve-lines">{{ e.note }}</p>
                </li>
            </ol>
        </details>
    </article>
</template>
