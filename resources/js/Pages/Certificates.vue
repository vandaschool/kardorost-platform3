<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
defineProps({ enrollments: Object });
</script>
<template>
    <Head title="گواهی‌های من" /><Shell title="گواهی‌های من" role="student">
        <section class="page-intro">
            <div>
                <h1>گواهی‌های من</h1>
                <p class="muted">
                    معیارهای هر دوره، درخواست بررسی و وضعیت گواهی را اینجا
                    ببینید.
                </p>
            </div>
            <Link href="/learning" class="btn secondary">یادگیری من</Link>
        </section>
        <p v-if="!enrollments.data.length" class="muted">
            ثبت‌نامی برای نمایش وجود ندارد.
        </p>
        <article
            v-for="enrollment in enrollments.data"
            :key="enrollment.id"
            class="glass panel-card consultation-section"
        >
            <h2>{{ enrollment.course_title }}</h2>
            <p>{{ enrollment.cohort_title }}</p>
            <span class="badge">{{
                enrollment.certificate
                    ? enrollment.certificate.status === "issued"
                        ? "گواهی صادرشده"
                        : "گواهی باطل‌شده"
                    : enrollment.enabled
                      ? "معیارهای درخواست تعریف شده"
                      : "درخواست گواهی فعال نیست"
            }}</span>
            <p>
                <Link
                    :href="'/certificates/enrollments/' + enrollment.id"
                    class="btn secondary"
                    >معیارها و وضعیت گواهی</Link
                >
            </p>
        </article>
        <Pagination :links="enrollments.links" />
    </Shell>
</template>
