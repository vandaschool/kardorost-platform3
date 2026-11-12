<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
const props = defineProps({ assessments: Object });
const form = useForm({ title: "" });
</script>
<template>
    <Head title="مدیریت خودشناسی" /><Shell title="مدیریت خودشناسی" role="admin"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">محتوا و انتشار پرسشنامه</span>
                <h1>خودشناسی</h1>
                <p class="muted">
                    این مرحله برای پرسشنامه‌های رایگان پنج‌گزینه‌ای است. ابزارها
                    و کلید نهایی بعداً انتخاب می‌شوند.
                </p>
            </div>
        </section>
        <form
            class="panel glass consultation-card stack-form"
            @submit.prevent="form.post('/admin/assessments')"
        >
            <label
                >عنوان پیش‌نویس تازه<input
                    v-model="form.title"
                    required
                    maxlength="160" /></label
            ><button class="btn primary" :disabled="form.processing">
                ساخت پیش‌نویس
            </button>
        </form>
        <section class="consultation-section">
            <p v-if="!assessments.data.length" class="muted">
                هنوز پرسشنامه‌ای تعریف نشده است.
            </p>
            <div class="consultation-grid">
                <article
                    class="panel glass consultation-card"
                    v-for="a in assessments.data"
                    :key="a.id"
                >
                    <h2>{{ a.title }}</h2>
                    <p>
                        {{
                            a.current_version
                                ? "دارای نسخه منتشرشده"
                                : "پیش‌نویس"
                        }}
                        ·
                        {{ a.available ? "شروع تازه فعال" : "شروع تازه بسته" }}
                    </p>
                    <Link
                        class="btn secondary"
                        :href="`/admin/assessments/${a.id}`"
                        >ویرایش، انتشار و گزارش‌ها</Link
                    >
                </article>
            </div>
            <Pagination :links="assessments.links" /></section
    ></Shell>
</template>
