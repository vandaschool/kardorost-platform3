<script setup>
import { Head, Link, router } from "@inertiajs/vue3";
import { dateTime } from "../dates";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import CertificateReview from "../Components/CertificateReview.vue";
import CertificateRevoke from "../Components/CertificateRevoke.vue";
defineProps({ requests: Object, status: String });
const labels = {
    pending: "در انتظار بررسی",
    issued: "گواهی صادر شده",
    rejected: "برگشت برای اصلاح",
    withdrawn: "انصراف متقاضی",
};
</script>
<template>
    <Head title="درخواست‌های گواهی" /><Shell
        title="درخواست‌های گواهی"
        role="admin"
        ><section class="page-intro">
            <h1>درخواست‌ها و گواهی‌ها</h1>
            <Link href="/admin/certificate-policies" class="btn secondary"
                >تنظیم معیارهای صدور</Link
            >
        </section>
        <label
            >وضعیت<select
                :value="status"
                @change="
                    router.get('/admin/certificates', {
                        status: $event.target.value,
                    })
                "
            >
                <option value="pending">در انتظار بررسی</option>
                <option value="issued">صادرشده</option>
                <option value="rejected">برگشت برای اصلاح</option>
                <option value="withdrawn">انصراف متقاضی</option>
                <option value="all">همه</option>
            </select></label
        >
        <p v-if="!requests.data.length" class="muted">
            درخواستی با این وضعیت وجود ندارد.
        </p>
        <article
            v-for="request in requests.data"
            :key="request.id"
            class="glass panel-card consultation-section form-stack"
            :data-certificate-application="request.id"
        >
            <span class="badge">{{ labels[request.status] }}</span>
            <p v-if="request.withdrawn_at">
                زمان انصراف: {{ dateTime(request.withdrawn_at) }}
            </p>
            <h2>{{ request.course_title }} · {{ request.cohort_title }}</h2>
            <p>
                نام گواهی: {{ request.recipient_name }} · نام فعلی حساب:
                {{ request.student.name }} ·
                <bdi>{{ request.student.phone }}</bdi>
            </p>
            <p>
                {{ request.definition.title }} · {{ request.definition.issuer }}
            </p>
            <template v-if="request.evidence"
                ><p
                    v-for="(check, index) in request.evidence.checks"
                    :key="index"
                    :class="check.passed ? 'muted' : 'field-error'"
                >
                    {{ check.passed ? "✓" : "○" }} {{ check.label }}
                </p>
                <CertificateReview :request="request"
            /></template>
            <p v-if="request.review_note">{{ request.review_note }}</p>
            <template v-if="request.certificate"
                ><span class="badge">{{
                    request.certificate.status === "issued"
                        ? "گواهی معتبر"
                        : "گواهی باطل‌شده"
                }}</span
                ><a
                    :href="request.certificate.verification_url"
                    class="text-link"
                    >استعلام و چاپ گواهی</a
                >
                <p v-if="request.certificate.revocation_reason">
                    دلیل ابطال: {{ request.certificate.revocation_reason }}
                </p>
                <CertificateRevoke :certificate="request.certificate"
            /></template>
        </article>
        <Pagination :links="requests.links"
    /></Shell>
</template>
