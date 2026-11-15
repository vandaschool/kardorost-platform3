<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { fa } from "../dates";
defineProps({
    publications: Object,
    course_title: String,
    cohort_title: String,
    role: String,
});
</script>
<template>
    <Head title="گزارش نظرسنجی دوره" /><Shell
        title="گزارش نظرسنجی دوره"
        :role="role"
        ><section class="page-intro">
            <div>
                <h1>{{ course_title }}</h1>
                <p>{{ cohort_title }} · نظرسنجی‌های منتشرشده</p>
            </div>
        </section>
        <p v-if="!publications.data.length" class="muted">
            نظرسنجی منتشر نشده است.
        </p>
        <article
            v-for="publication in publications.data"
            :key="publication.id"
            class="glass panel-card consultation-section"
        >
            <h2>{{ publication.title }}</h2>
            <p>
                نسخه {{ fa(publication.number) }} ·
                {{
                    publication.mode === "identified"
                        ? "با نام"
                        : "بدون نمایش نام"
                }}
            </p>
            <Link
                :href="'/survey-publications/' + publication.id + '/report'"
                class="btn secondary"
                >مشاهده گزارش</Link
            >
        </article>
        <Pagination :links="publications.links"
    /></Shell>
</template>
