<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import { fa, dateTime } from "../dates";
defineProps({
    attempt: Object,
    title: String,
    source: String,
    number: Number,
    result: Object,
    student_name: String,
    role: String,
});
</script>
<template>
    <Head :title="'نتیجه ' + title" /><Shell title="گزارش خودشناسی" :role="role"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">گزارش پاسخ‌های ثبت‌شده</span>
                <h1>{{ title }}</h1>
                <p>
                    {{ student_name }} · نسخه {{ fa(number) }} ·
                    {{ dateTime(attempt.completed_at) }}
                </p>
            </div>
            <Link
                v-if="role === 'student'"
                href="/assessments"
                class="btn secondary"
                >بازگشت به خودشناسی</Link
            ><Link
                v-else
                :href="
                    role === 'admin' ? '/admin/assessments' : '/organization'
                "
                class="btn secondary"
                >بازگشت به گزارش‌ها</Link
            >
        </section>
        <p class="muted preserve-lines">منبع و روش: {{ source }}</p>
        <p class="muted">
            درصد زیر، جایگاه نمره در بازه ممکن همین مقیاس است؛ رتبه در جامعه،
            تشخیص یا مقایسه با دیگران نیست. امتیاز ابعاد مستقل گزارش می‌شود و
            نمره کلی ترکیبی ساخته نمی‌شود.
        </p>
        <div class="consultation-grid consultation-section">
            <article
                v-for="d in result.dimensions"
                :key="d.key"
                class="panel glass consultation-card"
            >
                <h2>{{ d.title }}</h2>
                <p class="preserve-lines">{{ d.description }}</p>
                <p>
                    امتیاز خام: {{ fa(d.sum) }} · بازه {{ fa(d.min) }} تا
                    {{ fa(d.max) }} · {{ fa(d.count) }} پرسش
                </p>
                <p>جایگاه در بازه امتیاز: {{ fa(d.range_percent) }}٪</p>
                <meter
                    :min="0"
                    :max="100"
                    :value="d.range_percent"
                    :aria-label="
                        d.title + ': ' + d.range_percent + ' درصد بازه امتیاز'
                    "
                    class="assessment-progress"
                ></meter>
            </article>
        </div>
        <details class="panel glass consultation-card consultation-section">
            <summary>روش محاسبه</summary>
            <p>
                هر پاسخ از ۱ تا ۵ امتیاز دارد. برای پرسش معکوس، ۶ منهای پاسخ
                محاسبه می‌شود. امتیاز خام هر بعد، جمع پرسش‌های همان بعد است.
                درصد بازه برابر است با (امتیاز خام منهای کمینه) تقسیم بر (بیشینه
                منهای کمینه) ضربدر ۱۰۰.
            </p>
        </details></Shell
    >
</template>
