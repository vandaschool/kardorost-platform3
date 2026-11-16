<script setup>
import { ref } from "vue";
import { Head, Link, usePage } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import { dateTime, fa } from "../dates";
const page = usePage();
const props = defineProps({ guide: Object, role: String });
const copied = ref("");
const copyError = ref("");
const base = `/career/resumes/${props.guide.resume_id}/versions/${props.guide.version.id}`;
async function copy(section) {
    copied.value = "";
    copyError.value = "";
    try {
        if (!navigator.clipboard?.writeText)
            throw new Error("clipboard unavailable");
        await navigator.clipboard.writeText(section.text);
        copied.value = section.key;
    } catch {
        document.getElementById(`linkedin-${section.key}`)?.select();
        copyError.value =
            "کپی خودکار در این مرورگر در دسترس نیست؛ متن انتخاب‌شده را دستی کپی کنید یا فایل متنی را بگیرید.";
    }
}
</script>
<template>
    <Head title="راهنمای پروفایل لینکدین" />
    <Shell title="راهنمای پروفایل لینکدین" :role="role">
        <Link :href="base" class="text-link">بازگشت به نسخه ثابت رزومه ←</Link>
        <p v-if="page.props.errors?.guide" class="field-error" role="alert">
            {{ page.props.errors.guide }}
        </p>
        <section class="page-intro">
            <div class="guide-intro">
                <span class="eyebrow">ابزارهای مسیر شغلی</span>
                <h1>متن پروفایل لینکدین را آماده کنید</h1>
                <p>
                    {{ guide.name }} · نسخه {{ fa(guide.version.number) }} ·
                    {{ dateTime(guide.version.created_at) }}
                </p>
            </div>
            <a
                :href="`${base}/linkedin/download`"
                class="btn primary"
                data-linkedin-download
                >دانلود فایل متنی</a
            >
        </section>
        <section class="glass panel-card consultation-section">
            <h2>از همین نسخه ثابت، با بازبینی شما</h2>
            <p>
                متن‌های زیر از نسخه انتخاب‌شده رزومه مرتب شده‌اند. هر بخش را
                بخوانید و در صورت مناسب‌بودن در پروفایل خودتان کپی کنید. زبان و
                سوابق همان متن ورودی شماست.
            </p>
            <p class="muted">
                تلفن، ایمیل و لینک‌های بخش تماس منتقل نمی‌شوند؛ متن آزاد را از
                نظر اطلاعات شخصی و ادعاهای قابل اثبات بازبینی کنید. برای تغییر
                متن منبع، پیش‌نویس رزومه را ویرایش و نسخه تازه بسازید.
            </p>
            <p class="muted">
                این ابزار ترجمه یا تأیید سوابق انجام نمی‌دهد و چیزی در لینکدین
                منتشر نمی‌کند. محدودیت و چیدمان هر بخش را هنگام انتقال در خود
                لینکدین بررسی کنید.
            </p>
        </section>
        <p v-if="copyError" class="field-error" role="alert">{{ copyError }}</p>
        <p
            v-if="!guide.sections.length"
            class="glass panel-card consultation-section"
            data-linkedin-empty
        >
            هنوز متن قابل کپی در بخش‌های این نسخه نیست. از پیش‌نویس رزومه،
            اطلاعات واقعی خود را اضافه و نسخه تازه بسازید.
        </p>
        <section
            v-for="section in guide.sections"
            :key="section.key"
            class="glass panel-card consultation-section guide-section"
            :data-linkedin-section="section.key"
        >
            <div class="action-row">
                <h2>{{ section.label }}</h2>
                <span class="muted">{{ fa(section.characters) }} نویسه</span>
                <button
                    type="button"
                    class="btn secondary"
                    :data-linkedin-copy="section.key"
                    @click="copy(section)"
                >
                    کپی این بخش
                </button>
                <span v-if="copied === section.key" class="muted" role="status"
                    >کپی شد</span
                >
            </div>
            <label :for="`linkedin-${section.key}`" class="muted"
                >متن قابل کپی · {{ section.label }}</label
            >
            <textarea
                :id="`linkedin-${section.key}`"
                :value="section.text"
                :dir="guide.locale === 'en' ? 'ltr' : 'rtl'"
                rows="6"
                readonly
                spellcheck="false"
            />
        </section>
        <section class="glass panel-card consultation-section guide-section">
            <h2>مرور بخش‌ها پیش از انتشار</h2>
            <p class="muted">
                وجود متن فقط به معنی ثبت آن در رزومه است؛ صحت و تناسب آن را
                خودتان بررسی کنید. خالی‌بودن یک بخش به‌تنهایی اشکال نیست.
            </p>
            <ul class="guide-checklist">
                <li
                    v-for="check in guide.checks"
                    :key="check.key"
                    :data-linkedin-check="check.key"
                >
                    <span>{{
                        check.present
                            ? "در این نسخه هست"
                            : "در این نسخه خالی است"
                    }}</span>
                    · {{ check.label }}
                </li>
            </ul>
            <p>
                پیش از عمومی‌کردن، سطح نمایش پروفایل و راه‌های تماس را در
                تنظیمات لینکدین بررسی کنید. عدد، نتیجه یا مسئولیتی را فقط وقتی
                اضافه کنید که واقعاً درباره تجربه خودتان قابل توضیح باشد.
            </p>
            <Link
                :href="`/career/resumes/${guide.resume_id}`"
                class="btn secondary"
                >بازکردن پیش‌نویس رزومه</Link
            >
        </section>
    </Shell>
</template>
<style scoped>
.guide-intro,
.guide-section {
    min-width: 0;
    overflow-wrap: anywhere;
}
.guide-section textarea {
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
    margin-top: 0.6rem;
    resize: vertical;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
.guide-section .action-row {
    flex-wrap: wrap;
}
.guide-checklist {
    padding-inline-start: 1.25rem;
    line-height: 2;
}
</style>
