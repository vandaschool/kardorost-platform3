<script setup>
import { Head, Link, router } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import CourseBenefitTerms from "../Components/CourseBenefitTerms.vue";
import { dateTime, fa } from "../dates";
defineProps({ contracts: Object });
</script>
<template>
    <Head title="مشاوره همراه دوره" />
    <Shell title="مشاوره همراه دوره" role="admin">
        <p class="muted">
            شرایط ثبت‌شده هنگام خرید و نتیجه صدور سهمیه را بررسی کنید. تغییر
            شرایط در
            <Link href="/admin/courses" class="text-link">مدیریت دوره‌ها</Link>
            فقط برای ثبت‌نام‌های بعدی است.
        </p>
        <p v-if="!contracts.data.length" class="muted">
            قراردادی ثبت نشده است.
        </p>
        <div class="consultation-grid consultation-section">
            <article
                v-for="contract in contracts.data"
                :key="contract.id"
                class="panel glass consultation-card"
            >
                <h2>{{ contract.definition.course_title }}</h2>
                <p>
                    {{ contract.definition.cohort_title }} · نسخه ثبت‌نام
                    {{ fa(contract.billing_version) }}
                </p>
                <p>
                    {{ contract.student.name }} ·
                    <bdi>{{ contract.student.phone }}</bdi>
                </p>
                <CourseBenefitTerms
                    :definition="contract.definition"
                    :contract="contract"
                    admin
                />
                <p v-if="contract.eligible_at" class="caption">
                    احراز پرداخت: {{ dateTime(contract.eligible_at) }}
                </p>
                <p v-if="contract.issued_at" class="caption">
                    صدور: {{ dateTime(contract.issued_at) }}
                </p>
                <p v-if="contract.reason_label" class="field-error">
                    {{ contract.reason_label }}
                </p>
                <button
                    v-if="contract.can_retry"
                    class="btn secondary"
                    @click="
                        router.post(
                            '/admin/course-benefits/' + contract.id + '/issue',
                            {},
                            { preserveScroll: true },
                        )
                    "
                >
                    بررسی مجدد شرایط صدور
                </button>
            </article>
        </div>
        <Pagination :links="contracts.links" />
    </Shell>
</template>
