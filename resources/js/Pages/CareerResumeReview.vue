<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import { dateTime, fa } from "../dates";
defineProps({ resume: Object, version: Object, check: Object, role: String });
const labels = {
    name: "نام",
    headline: "عنوان حرفه‌ای",
    contact: "حداقل یک راه تماس",
    summary: "خلاصه حرفه‌ای",
    experience: "تجربه کاری",
    education: "تحصیلات",
    skills: "مهارت‌ها",
    experience_details: "توضیح حداقل یک تجربه کاری",
};
</script>
<template>
    <Head title="بررسی متنی رزومه" /><Shell
        title="بررسی متنی رزومه"
        :role="role"
        ><Link
            class="text-link"
            :href="'/career/resumes/' + resume.id + '/versions/' + version.id"
            >نسخه مبنای بررسی ←</Link
        >
        <section class="page-intro">
            <div>
                <h1>بررسی {{ resume.title }}</h1>
                <p>
                    نسخه {{ fa(version.number) }} ·
                    {{ dateTime(check.created_at) }}
                </p>
            </div>
        </section>
        <section class="glass panel-card consultation-section">
            <h2>ساختار رزومه</h2>
            <p class="muted">
                {{ fa(check.report.word_count) }} واژه در متن بررسی شد. وجود هر
                بخش را با نیاز موقعیت شغلی و سابقه خود بسنجید.
            </p>
            <ul class="resume-check-list">
                <li v-for="item in check.report.checks" :key="item.key">
                    <strong>{{ labels[item.key] }}</strong
                    ><span>{{ item.present ? "وجود دارد" : "ثبت نشده" }}</span>
                </li>
            </ul>
        </section>
        <section class="glass panel-card consultation-section">
            <h2>واژه‌های آگهی شغلی</h2>
            <p class="muted">
                روش: یکسان‌سازی ی/ک، حروف انگلیسی و فاصله‌ها، حذف واژه‌های عمومی
                و مقایسه دقیق حداکثر ۴۰ واژه پرتکرار. واژه‌های هم‌معنی و
                ترجمه‌ها جدا حساب می‌شوند.
            </p>
            <template v-if="check.report.job_provided"
                ><p>
                    {{ fa(check.report.matched_terms.length) }} واژه از
                    {{ fa(check.report.extracted_terms.length) }} واژه
                    استخراج‌شده در رزومه وجود دارد.
                </p>
                <h3>واژه‌های موجود</h3>
                <p data-matched-terms>
                    {{
                        check.report.matched_terms.join(" · ") ||
                        "واژه مشترکی پیدا نشد."
                    }}
                </p>
                <h3>واژه‌های موجود در آگهی و پیدا‌نشده در رزومه</h3>
                <p data-missing-terms>
                    {{
                        check.report.missing_terms.join(" · ") ||
                        "موردی پیدا نشد."
                    }}
                </p>
                <p class="muted">
                    فقط توانمندی و سابقه واقعی خود را به متن رزومه اضافه کنید.
                </p>
                <details>
                    <summary>متن آگهی مبنا</summary>
                    <p style="white-space: pre-wrap; overflow-wrap: anywhere">
                        {{ check.job_text }}
                    </p>
                </details></template
            >
            <p v-else class="muted">
                آگهی شغلی ثبت نشده و این گزارش ساختار را بررسی کرده است.
            </p>
        </section></Shell
    >
</template>
<style scoped>
.resume-check-list {
    list-style: none;
    padding: 0;
}
.resume-check-list li {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    padding: 14px 0;
    border-bottom: 1px solid var(--line);
}
</style>
