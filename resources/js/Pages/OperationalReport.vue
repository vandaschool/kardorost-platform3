<script setup>
import { computed } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import JalaliDateInput from "../Components/JalaliDateInput.vue";
import Pagination from "../Components/Pagination.vue";
import { money, dateOnly, paymentStatuses } from "../finance";
import { dateTime, fa } from "../dates";
const props = defineProps({
    filters: Object,
    summary: Object,
    records: Object,
    export_url: String,
    generated_at: String,
});
const form = useForm({ ...props.filters });
const datasets = {
    captures: "دریافت‌های تأییدشده",
    refunds: "بازپرداخت‌های انجام‌شده",
    enrollments: "ثبت‌نام‌های ساخته‌شده",
};
const statuses = {
    ...paymentStatuses,
    completed: "انجام‌شده",
    active: "فعال",
    reserved: "رزروشده",
    cancelled: "لغوشده",
    pending: "در انتظار",
};
const metrics = computed(() => [
    {
        key: "captured_amount",
        label: "وجه دریافت‌شده",
        value: money(props.summary.totals.captured_amount),
        text: `${fa(props.summary.totals.captured_count)} دریافت تأییدشده`,
    },
    {
        key: "refunded_amount",
        label: "بازپرداخت انجام‌شده",
        value: money(props.summary.totals.refunded_amount),
        text: `${fa(props.summary.totals.refunded_count)} واریز ثبت‌شده`,
    },
    {
        key: "net_cash_flow",
        label: "خالص جریان وجه",
        value: money(props.summary.totals.net_cash_flow),
        text: "دریافت منهای بازپرداخت همین بازه",
    },
    {
        key: "new_accounts",
        label: "حساب‌های تازه",
        value: fa(props.summary.totals.new_accounts),
        text: `${fa(props.summary.totals.new_enrollments)} ثبت‌نام ساخته‌شده در بازه`,
    },
]);
function apply() {
    form.get("/admin/reports", { preserveScroll: true, preserveState: false });
}
</script>
<template>
    <Head title="گزارش و خروجی‌ها" />
    <Shell title="گزارش و خروجی‌ها" role="admin">
        <section class="page-intro">
            <div>
                <span class="eyebrow">دید روشن از عملکرد</span>
                <h1>گزارش و خروجی‌ها</h1>
                <p class="muted">
                    بازه از ابتدای روز اول تا پایان روز آخر به وقت تهران است.
                    آخرین دریافت گزارش: {{ dateTime(generated_at) }}
                </p>
            </div>
        </section>
        <form
            class="panel glass consultation-card stack-form report-filter"
            @submit.prevent="apply"
        >
            <JalaliDateInput v-model="form.from" label="از تاریخ" required />
            <JalaliDateInput v-model="form.to" label="تا تاریخ" required />
            <label
                >ریز گزارش<select v-model="form.dataset" name="dataset">
                    <option
                        v-for="(label, key) in datasets"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </select></label
            >
            <button class="btn primary" :disabled="form.processing">
                نمایش گزارش
            </button>
            <p class="muted">
                بازه حداکثر ۳۶۶ روز؛ خروجی شامل تمام رکوردهای همین بازه و همین
                نوع گزارش است.
            </p>
        </form>
        <section
            class="report-metrics consultation-section"
            aria-label="شاخص‌های بازه"
        >
            <article
                v-for="metric in metrics"
                :key="metric.key"
                :data-metric="metric.key"
                class="panel glass consultation-card"
            >
                <h2>{{ metric.label }}</h2>
                <strong>{{ metric.value }}</strong>
                <p class="muted">{{ metric.text }}</p>
            </article>
        </section>
        <section class="panel glass consultation-card consultation-section">
            <h2>معنای عددها</h2>
            <p>
                دریافت، وجهی است که دریافت آن تأیید شده؛ حتی اگر هنوز نیازمند
                تعیین تکلیف باشد یا بعداً برگشت خورده باشد. زمان ایجاد درخواست
                درگاه، دریافت وجه محسوب نمی‌شود.
            </p>
            <p>
                بازپرداخت فقط هنگام ثبت واریز نهایی در این بازه کسر می‌شود؛
                درخواست یا تأیید اولیه بازپرداخت کافی نیست. خالص جریان وجه، سود
                یا درآمد حسابداری نیست.
            </p>
            <p>
                ثبت‌نام ساخته‌شده می‌تواند فعال، رزرو، منقضی یا لغوشده باشد.
                وضعیت ردیف‌ها وضعیت فعلی است و گزارش تاریخیِ تغییر وضعیت را نشان
                نمی‌دهد.
            </p>
        </section>
        <section class="panel glass consultation-card consultation-section">
            <h2>تفکیک موضوع دریافت</h2>
            <div class="report-purpose-grid">
                <article
                    v-for="row in summary.purposes"
                    :key="row.key"
                    class="assessment-editor-item"
                >
                    <h3>{{ row.label }}</h3>
                    <p>
                        دریافت: {{ money(row.captured_amount) }} ·
                        {{ fa(row.captured_count) }} رکورد
                    </p>
                    <p>
                        بازپرداخت: {{ money(row.refunded_amount) }} ·
                        {{ fa(row.refunded_count) }} رکورد
                    </p>
                </article>
            </div>
        </section>
        <details
            class="panel glass consultation-card consultation-section"
            data-testid="report-daily"
        >
            <summary>روزهای بازه و جریان وجه</summary>
            <div
                v-for="day in summary.daily"
                :key="day.date"
                class="report-day"
            >
                <strong>{{ dateOnly(day.date) }}</strong
                ><span>دریافت: {{ money(day.captured_amount) }}</span
                ><span>بازپرداخت: {{ money(day.refunded_amount) }}</span>
            </div>
        </details>
        <section class="panel glass consultation-card consultation-section">
            <h2>وضعیت فعلی ثبت‌نام‌های ساخته‌شده در بازه</h2>
            <p v-if="!summary.enrollment_statuses.length" class="muted">
                ثبت‌نامی در این بازه ساخته نشده است.
            </p>
            <p v-for="row in summary.enrollment_statuses" :key="row.status">
                {{ statuses[row.status] || row.status }}: {{ fa(row.count) }}
            </p>
        </section>
        <section class="consultation-section">
            <div class="page-intro">
                <div>
                    <h2>{{ datasets[filters.dataset] }}</h2>
                    <p class="muted">
                        {{ fa(records.total) }} رکورد · مبلغ هر ردیف مربوط به
                        همان سند است.
                    </p>
                </div>
                <a
                    :href="export_url"
                    class="btn secondary"
                    data-testid="report-export"
                    >دریافت CSV این گزارش</a
                >
            </div>
            <p v-if="!records.data.length" class="muted">
                رکوردی در این بازه وجود ندارد.
            </p>
            <article
                v-for="row in records.data"
                :key="row.id"
                :data-report-record="row.id"
                class="panel glass consultation-card report-record"
            >
                <h3>{{ row.purpose }} · سند {{ fa(row.id) }}</h3>
                <p>
                    {{ money(row.amount) }} ·
                    {{ statuses[row.status] || row.status }}
                </p>
                <p>
                    حساب {{ fa(row.user_id) }} · شناسه مبنا
                    {{ fa(row.source_id) }}
                </p>
                <p>{{ dateTime(row.occurred_at) }}</p>
                <p v-if="row.reference">
                    شناسه تأییدشده: <bdi>{{ row.reference }}</bdi>
                </p>
            </article>
            <Pagination :links="records.links" />
        </section>
        <div class="learning-actions consultation-section">
            <Link href="/admin/finance?tab=payments" class="btn secondary"
                >کارتابل پرداخت‌ها</Link
            ><Link href="/refunds?view=admin" class="btn secondary"
                >کارتابل بازپرداخت‌ها</Link
            ><Link href="/admin/organization-invoices" class="btn secondary"
                >قراردادهای سازمان</Link
            >
        </div>
    </Shell>
</template>
<style scoped>
.report-metrics,
.report-purpose-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}
.report-metrics h2 {
    font-size: 1rem;
}
.report-metrics strong {
    font-size: 1.2rem;
}
.report-day {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
    padding: 1rem 0;
    border-bottom: 1px solid var(--border);
}
.report-record {
    margin-block: 0.75rem;
    overflow-wrap: anywhere;
}
@media (max-width: 600px) {
    .report-metrics,
    .report-purpose-grid,
    .report-day {
        grid-template-columns: 1fr;
    }
}
</style>
