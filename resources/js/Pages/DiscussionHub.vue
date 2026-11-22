<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
defineProps({ cohorts: Object, role: String });
</script>
<template>
    <Head title="گفت‌وگوهای دوره" /><Shell title="گفت‌وگوهای دوره" :role="role"
        ><section class="page-intro">
            <div>
                <h1>گفت‌وگوهای دوره</h1>
                <p class="muted">
                    پرسش‌ها و بحث‌های هر نوبت را در فضای همان کلاس دنبال کنید.
                </p>
            </div>
            <Link href="/discussion-attachments" class="btn secondary"
                >مدیریت ضمیمه‌های من</Link
            >
        </section>
        <p v-if="!cohorts.data.length" class="muted">
            نوبت آموزشی قابل دسترسی برای شما وجود ندارد.
        </p>
        <article
            v-for="cohort in cohorts.data"
            :key="cohort.id"
            class="glass panel-card consultation-section"
        >
            <h2>{{ cohort.course_title }}</h2>
            <p>{{ cohort.title }}</p>
            <Link
                :href="'/cohorts/' + cohort.id + '/discussions'"
                class="btn secondary"
                >ورود به گفت‌وگوهای این نوبت</Link
            >
        </article>
        <Pagination :links="cohorts.links"
    /></Shell>
</template>
