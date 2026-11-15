<script setup>
import Pagination from "./Pagination.vue";
import CoachProfileDefinition from "./CoachProfileDefinition.vue";
import { dateTime } from "../dates";
defineProps({ history: Object });
const labels = {
    pending: "در انتظار بررسی",
    published: "منتشرشده",
    rejected: "نیازمند اصلاح",
    withdrawn: "بررسی متوقف‌شده",
};
</script>
<template>
    <section v-if="history" class="consultation-section">
        <h2>سوابق بررسی و رضایت</h2>
        <details
            v-for="row in history.submissions.data"
            :key="row.profile_revision"
            class="panel glass consultation-card consultation-section"
        >
            <summary>
                {{ labels[row.status] }} · {{ dateTime(row.created_at) }}
            </summary>
            <p>رضایت این نسخه: {{ dateTime(row.consented_at) }}</p>
            <p v-if="row.note" class="preserve-lines">توضیح: {{ row.note }}</p>
            <CoachProfileDefinition :definition="row.definition" />
        </details>
        <Pagination :links="history.submissions.links" />
        <h3 v-if="history.withdrawals.length">آخرین توقف‌های نمایش عمومی</h3>
        <p
            v-for="(row, index) in history.withdrawals"
            :key="index"
            class="preserve-lines"
        >
            {{ row.by_owner ? "صاحب پروفایل" : "مدیریت" }} ·
            {{ dateTime(row.created_at) }} · {{ row.reason }}
        </p>
    </section>
</template>
