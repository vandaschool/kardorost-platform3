<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import OrganizationInvoiceEditor from "../Components/OrganizationInvoiceEditor.vue";
import { money, dateOnly } from "../finance";
defineProps({
    admin: Boolean,
    organization: Object,
    invoices: Object,
    organizations: Array,
});
const labels = {
    draft: "پیش‌نویس",
    issued: "صادرشده",
    paid: "تسویه‌شده",
    void: "باطل‌شده",
};
</script>
<template>
    <Head title="صورتحساب‌های سازمانی" /><Shell
        title="صورتحساب‌های سازمانی"
        :role="admin ? 'admin' : 'org_admin'"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">{{
                    admin ? "قراردادها و دریافت وجه" : organization.name
                }}</span>
                <h1>صورتحساب‌های سازمانی</h1>
                <p class="muted">
                    صورتحساب قرارداد از هزینه شخصی دانشجو و سهمیه صندلی جدا ثبت
                    می‌شود.
                </p>
            </div>
            <Link v-if="!admin" href="/organization" class="btn secondary"
                >انتخاب سازمان و اعضا</Link
            >
        </section>
        <details v-if="admin" class="consultation-section">
            <summary>ساخت صورتحساب تازه</summary>
            <OrganizationInvoiceEditor
                :organizations="organizations"
                :licenses="[]"
            />
        </details>
        <p v-if="!invoices.data.length" class="muted">
            صورتحسابی برای نمایش وجود ندارد.
        </p>
        <div class="consultation-grid consultation-section">
            <article
                v-for="i in invoices.data"
                :key="i.id"
                class="panel glass consultation-card"
            >
                <span class="status-badge">{{ labels[i.status] }}</span>
                <h2>{{ i.title }}</h2>
                <p>
                    {{ i.organization_name }} ·
                    {{ i.number || "هنوز صادر نشده" }}
                </p>
                <strong>{{ money(i.amount) }}</strong>
                <p v-if="i.status !== 'draft'">
                    پرداخت تأییدشده: {{ money(i.paid_amount) }} · مانده:
                    {{ money(i.remaining_amount) }}
                </p>
                <p>
                    سررسید: {{ dateOnly(i.due_date) }}
                    <span v-if="i.overdue">· سررسید گذشته</span>
                </p>
                <Link
                    :href="`${admin ? '/admin' : ''}/organization${admin ? '-invoices' : '/invoices'}/${i.id}`"
                    class="btn primary"
                    >جزئیات و پیگیری</Link
                >
            </article>
        </div>
        <Pagination :links="invoices.links"
    /></Shell>
</template>
