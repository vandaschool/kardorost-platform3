<script setup>
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { dateTime, fa } from "../dates";
const page = usePage();
const props = defineProps({
    resume: Object,
    version: Object,
    checks: Object,
    role: String,
});
const form = useForm({ job_text: "" });
</script>
<template>
    <Head title="نسخه ثابت رزومه" /><Shell title="نسخه ثابت رزومه" :role="role"
        ><Link class="text-link" :href="'/career/resumes/' + resume.id"
            >بازگشت به پیش‌نویس ←</Link
        >
        <p v-if="page.props.errors?.guide" class="field-error" role="alert">
            {{ page.props.errors.guide }}
        </p>
        <section class="page-intro">
            <div>
                <h1>{{ resume.title }} · نسخه {{ fa(version.number) }}</h1>
                <p>{{ dateTime(version.created_at) }}</p>
            </div>
            <a
                :href="
                    '/career/resumes/' +
                    resume.id +
                    '/versions/' +
                    version.id +
                    '/print'
                "
                target="_blank"
                rel="noopener"
                class="btn primary"
                data-resume-print
                >چاپ / ذخیره PDF</a
            >
        </section>
        <section class="glass panel-card consultation-section">
            <h2>آماده‌سازی پروفایل لینکدین</h2>
            <p>
                متن همین نسخه را بخش‌بندی و پیش از کپی در پروفایل خودتان بازبینی
                کنید.
            </p>
            <Link
                class="btn secondary"
                :href="
                    '/career/resumes/' +
                    resume.id +
                    '/versions/' +
                    version.id +
                    '/linkedin'
                "
                data-linkedin-guide
                >راهنمای لینکدین این نسخه</Link
            >
        </section>
        <section class="glass panel-card consultation-section">
            <h2>{{ version.definition.name }}</h2>
            <p>{{ version.definition.headline }}</p>
            <p class="muted">
                این نسخه خصوصی و ثابت است؛ برای خروجی از دکمه چاپ و گزینه ذخیره
                PDF مرورگر استفاده کنید.
            </p>
            <p style="white-space: pre-wrap; overflow-wrap: anywhere">
                {{ version.definition.summary }}
            </p>
        </section>
        <section class="glass panel-card consultation-section">
            <h2>بررسی ساختار و واژه‌های آگهی</h2>
            <p>
                وجود بخش‌های رزومه و تطبیق واژه‌های متن آگهی با همین نسخه بررسی
                می‌شود. نتیجه یک راهنمای متنی با روش مشخص است.
            </p>
            <form
                class="stack-form"
                data-resume-check
                @submit.prevent="
                    form.post(
                        '/career/resumes/' +
                            resume.id +
                            '/versions/' +
                            version.id +
                            '/checks',
                    )
                "
            >
                <label
                    >متن آگهی شغلی؛ اختیاری<textarea
                        v-model="form.job_text"
                        name="job_text"
                        rows="7"
                        maxlength="12000"
                    />
                </label>
                <p class="muted">
                    با خالی‌گذاشتن آگهی، فقط ساختار بررسی می‌شود. متن آگهی در
                    حساب شما رمزگذاری‌شده نگهداری می‌شود.
                </p>
                <p
                    v-for="error in form.errors"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button class="btn primary" :disabled="form.processing">
                    بررسی این نسخه
                </button>
            </form>
        </section>
        <section class="glass panel-card consultation-section">
            <h2>بررسی‌های قبلی این نسخه</h2>
            <p v-if="!checks.data.length" class="muted">بررسی ثبت نشده است.</p>
            <article
                v-for="check in checks.data"
                :key="check.id"
                class="consultation-section"
            >
                <p>{{ dateTime(check.created_at) }}</p>
                <Link class="btn secondary" :href="'/career/checks/' + check.id"
                    >مشاهده گزارش</Link
                >
            </article>
            <Pagination :page="checks" /></section
    ></Shell>
</template>
