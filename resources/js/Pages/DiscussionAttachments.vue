<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { dateTime, fa } from "../dates";
defineProps({ files: Object, role: String });
const form = useForm({ revision: 1 });
function withdraw(file) {
    form.revision = file.revision;
    form.post(`/discussion-attachments/${file.id}/withdraw`, {
        preserveScroll: true,
    });
}
</script>
<template>
    <Head title="ضمیمه‌های من" /><Shell title="ضمیمه‌های من" :role="role">
        <section class="page-intro">
            <div>
                <h1>ضمیمه‌های من</h1>
                <p class="muted">
                    فقط فایل‌هایی که خودتان در گفت‌وگوهای دوره بارگذاری
                    کرده‌اید. پس از پایان دسترسی کلاس هم می‌توانید دریافت فایل
                    خود را متوقف کنید.
                </p>
            </div>
            <Link href="/discussions" class="btn secondary"
                >گفت‌وگوهای دوره</Link
            >
        </section>
        <p class="muted caption">
            برداشتن ضمیمه، دانلود آن را متوقف می‌کند؛ پیام و سابقه نسخه‌ها باقی
            می‌مانند. دانلود فعال به دسترسی جاری گفت‌وگو نیاز دارد.
        </p>
        <p v-if="!files.data.length" class="muted">
            هنوز ضمیمه‌ای بارگذاری نکرده‌اید.
        </p>
        <article
            v-for="file in files.data"
            :key="file.id"
            class="glass panel-card consultation-section form-stack"
            :data-own-attachment="file.id"
        >
            <h2 style="overflow-wrap: anywhere">{{ file.file_name }}</h2>
            <p class="muted">
                {{ dateTime(file.created_at) }} ·
                {{
                    file.size == null
                        ? "فایل در دسترس نیست"
                        : fa(Math.ceil(file.size / 1024)) + " کیلوبایت"
                }}
            </p>
            <span class="badge" :class="file.withdrawn ? 'gold' : 'teal'">{{
                file.withdrawn ? "برداشته‌شده" : "فعال"
            }}</span>
            <div class="learning-actions">
                <a
                    v-if="file.file_url"
                    :href="file.file_url"
                    class="btn secondary"
                    >دریافت فایل</a
                ><Link
                    v-if="file.post_url"
                    :href="file.post_url"
                    class="text-link"
                    >رفتن به پیام</Link
                >
            </div>
            <form v-if="!file.withdrawn" @submit.prevent="withdraw(file)">
                <button class="btn secondary" :disabled="form.processing">
                    برداشتن ضمیمه
                </button>
            </form>
        </article>
        <p v-for="error in form.errors" class="field-error" role="alert">
            {{ error }}
        </p>
        <Pagination :links="files.links" />
    </Shell>
</template>
