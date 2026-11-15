<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { dateTime, fa } from "../dates";
defineProps({
    discussion_id: Number,
    post_id: Number,
    role: String,
    revisions: Object,
});
</script>
<template>
    <Head title="سابقه پیام دوره" /><Shell title="سابقه پیام دوره" :role="role"
        ><section class="page-intro">
            <div>
                <h1>سابقه نسخه‌های پیام</h1>
                <p class="caption muted">
                    این سابقه فقط برای مدرس دوره و مدیریت قابل مشاهده است.
                </p>
            </div>
            <Link
                :href="
                    '/discussions/' +
                    discussion_id +
                    '/posts/' +
                    post_id +
                    '/locate'
                "
                class="btn secondary"
                >بازگشت به پیام</Link
            >
        </section>
        <article
            v-for="revision in revisions.data"
            :key="revision.revision"
            class="glass panel-card consultation-section form-stack"
        >
            <strong
                >نسخه {{ fa(revision.revision) }} ·
                {{ revision.hidden ? "پنهان‌شده" : "قابل نمایش" }}</strong
            >
            <p class="caption muted">
                {{ revision.actor_name }} · {{ dateTime(revision.created_at) }}
            </p>
            <p class="lesson-text" style="overflow-wrap: anywhere">
                {{ revision.body }}
            </p>
            <p v-if="revision.reason">دلیل: {{ revision.reason }}</p>
        </article>
        <Pagination :links="revisions.links"
    /></Shell>
</template>
