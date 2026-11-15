<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import { dateTime, fa } from "../dates";
defineProps({ report: Object });
const labels = { pass: "بررسی موفق", fail: "نیازمند رسیدگی", warning: "توجه" };
</script>
<template>
    <Head title="وضعیت اجرا" />
    <Shell role="admin" title="وضعیت اجرا">
        <section class="page-intro">
            <div>
                <span class="eyebrow">مدیریت و عملیات</span>
                <h1>وضعیت اجرا</h1>
                <p class="muted">
                    بررسی تنظیمات و اجرای محلی برنامه؛ نتیجه اتصال زنده سرویس‌ها
                    و آزمون نهایی استقرار جداگانه تأیید می‌شوند.
                </p>
            </div>
            <Link href="/admin/readiness" class="btn secondary" preserve-scroll
                >بررسی دوباره</Link
            >
        </section>
        <section
            class="panel glass consultation-card consultation-section"
            data-readiness-summary
        >
            <h2>
                {{
                    report.checks_passed
                        ? "بررسی‌های خودکار این نوبت موفق‌اند"
                        : `${fa(report.blocking_count)} مورد نیازمند رسیدگی است`
                }}
            </h2>
            <p class="muted">زمان بررسی: {{ dateTime(report.checked_at) }}</p>
            <p>
                برای خطاهای تنظیمات به مسئول سرور ارجاع دهید. وضعیت صف‌ها بر
                اساس اجرای کارهای آزمایشی بی‌خطر ثبت می‌شود.
            </p>
        </section>
        <section class="consultation-section" aria-label="نتیجه بررسی‌ها">
            <article
                v-for="check in report.checks"
                :key="check.id"
                :data-readiness-check="check.id"
                :data-state="check.state"
                class="panel glass consultation-card consultation-section"
            >
                <h2>{{ check.label }}</h2>
                <p>
                    <span class="readiness-status">{{
                        labels[check.state]
                    }}</span>
                </p>
                <p class="muted">{{ check.help }}</p>
                <p v-if="check.last_seen_at">
                    آخرین نشانه اجرا: {{ dateTime(check.last_seen_at) }}
                </p>
                <p v-if="check.pending_count !== undefined">
                    کارهای آماده در انتظار: {{ fa(check.pending_count) }}
                </p>
            </article>
        </section>
        <section class="panel glass consultation-card consultation-section">
            <h2>بررسی‌های نهایی پیش از بهره‌برداری</h2>
            <p class="muted">
                پاسخ موفق بررسی‌های بالا جای این پذیرش‌ها را نمی‌گیرد.
            </p>
            <ul>
                <li v-for="item in report.manual_acceptance" :key="item">
                    {{ item }}
                </li>
            </ul>
        </section>
    </Shell>
</template>

<style scoped>
.readiness-status {
    display: inline-block;
    padding: 0.35rem 0.7rem;
    border-radius: 999px;
    font-size: 0.85rem;
    background: var(--teal-soft);
    color: var(--teal-ink);
}
[data-state="fail"] .readiness-status {
    color: var(--danger);
    background: color-mix(in srgb, var(--danger) 10%, transparent);
}
[data-state="warning"] .readiness-status {
    color: var(--gold-ink);
    background: var(--gold-soft);
}
</style>
