<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import CertificatePolicyEditor from "../Components/CertificatePolicyEditor.vue";
import Pagination from "../Components/Pagination.vue";
defineProps({ cohorts: Object });
</script>
<template>
    <Head title="شرایط صدور گواهی" /><Shell
        title="شرایط صدور گواهی"
        role="admin"
    >
        <section class="page-intro">
            <div>
                <h1>شرایط صدور گواهی</h1>
                <p class="muted">
                    برای هر نوبت، معیارهای آموزشی مشخص و قابل بررسی تعیین کنید.
                </p>
            </div>
            <Link href="/admin/certificates" class="btn secondary"
                >درخواست‌ها و گواهی‌ها</Link
            >
        </section>
        <p v-if="!cohorts.data.length" class="muted">نوبتی ثبت نشده است.</p>
        <section
            v-for="cohort in cohorts.data"
            :key="cohort.id"
            class="glass panel-card consultation-section"
        >
            <h2>{{ cohort.course_title }} · {{ cohort.title }}</h2>
            <details>
                <summary>تنظیم معیارها</summary>
                <CertificatePolicyEditor :cohort="cohort" />
            </details>
        </section>
        <Pagination :links="cohorts.links" />
    </Shell>
</template>
