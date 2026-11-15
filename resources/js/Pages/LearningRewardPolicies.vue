<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import LearningRewardPolicyForm from "../Components/LearningRewardPolicyForm.vue";
import { fa } from "../dates";
defineProps({ cohorts: Object });
</script>
<template>
    <Head title="قواعد امتیاز یادگیری" /><Shell
        title="قواعد امتیاز یادگیری"
        role="admin"
        ><section class="page-intro">
            <div>
                <h1>قواعد امتیاز یادگیری</h1>
                <p class="muted">
                    پیش‌فرض غیرفعال است. امتیاز از تکمیل خوداظهاری درس می‌آید؛
                    ارزش مالی، مدرک یا تأیید مهارت نیست.
                </p>
            </div>
            <Link href="/admin/learning-rewards" class="btn secondary"
                >سابقه کاربران</Link
            >
        </section>
        <p v-if="!cohorts.data.length" class="muted">نوبتی ثبت نشده است.</p>
        <section
            v-for="cohort in cohorts.data"
            :key="cohort.id"
            class="panel glass consultation-card consultation-section"
        >
            <h2>{{ cohort.course_title }} · {{ cohort.title }}</h2>
            <p>
                {{ cohort.policy?.enabled ? "فعال" : "غیرفعال" }} · نسخه
                {{ fa(cohort.policy?.revision || 0) }}
            </p>
            <details>
                <summary>تنظیم قواعد تکمیل‌های بعدی</summary>
                <LearningRewardPolicyForm :cohort="cohort" />
            </details>
        </section>
        <Pagination :links="cohorts.links"
    /></Shell>
</template>
