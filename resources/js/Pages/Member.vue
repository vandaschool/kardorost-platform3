<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Empty from "../Components/Empty.vue";
import Icon from "../Components/Icon.vue";
defineProps({ member: Object, organization: Object, enrollments: Array });
</script>
<template>
    <Head :title="'گزارش ' + member.name" /><Shell
        title="گزارش فردی"
        role="org_admin"
        ><Link href="/organization" class="text-link"
            ><Icon name="ArrowRight" :size="17" />بازگشت به اعضا</Link
        >
        <section class="page-intro">
            <div>
                <span class="eyebrow">{{ organization.name }}</span>
                <h1>{{ member.name }}</h1>
                <p class="muted" dir="ltr">{{ member.phone }}</p>
            </div>
            <span class="small-chip"
                ><Icon name="ShieldCheck" :size="16" />عضو مجموعه شما</span
            >
        </section>
        <section class="glass panel-card">
            <h2>ثبت‌نام‌های آموزشی</h2>
            <Empty
                v-if="!enrollments.length"
                icon="BookOpen"
                title="هنوز ثبت‌نامی وجود ندارد"
                text="ثبت‌نام‌های این عضو پس از انتخاب دوره نمایش داده می‌شوند."
            />
            <div v-for="e in enrollments" :key="e.id" class="simple-row">
                <Icon name="BookOpen" />
                <div class="grow">
                    <strong>{{ e.course.title }}</strong
                    ><small>{{ e.cohort.title }}</small>
                </div>
                <span class="badge teal">{{
                    e.status === "active" ? "فعال" : "در انتظار پرداخت"
                }}</span>
            </div>
        </section>
        <p class="muted caption">
            گزارش آزمون و پیشرفت آموزشی با تکمیل ماژول‌های مربوط به این پرونده
            متصل می‌شوند.
        </p></Shell
    >
</template>
