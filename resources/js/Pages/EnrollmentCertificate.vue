<script setup>
import { computed, watch } from "vue";
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import CertificateVisibilityForm from "../Components/CertificateVisibilityForm.vue";
import Shell from "../Layouts/Shell.vue";
import { dateTime } from "../dates";
const props = defineProps({
    admin: Boolean,
    can_request: Boolean,
    can_manage_visibility: Boolean,
    visibility_events: Array,
    enrollment: Object,
    course_title: String,
    cohort_title: String,
    recipient_name: String,
    policy: Object,
    definition: Object,
    evidence: Object,
    requests: Array,
    certificate: Object,
});
const page = usePage();
const withdrawal = useForm({ revision: 0 });
function withdraw(request) {
    withdrawal.revision = request.revision;
    withdrawal.post(`/certificate-requests/${request.id}/withdraw`, {
        preserveScroll: true,
    });
}
const pending = computed(() =>
    props.requests.some((r) => r.status === "pending"),
);
const form = useForm({
    revision: props.policy?.revision || 0,
    expected_name: props.recipient_name,
    public_consent: false,
});
watch(
    () => [
        props.policy?.revision,
        props.recipient_name,
        props.requests
            .map((row) => `${row.id}:${row.revision}:${row.status}`)
            .join(","),
    ],
    () => {
        form.public_consent = false;
        form.revision = props.policy?.revision || 0;
        form.expected_name = props.recipient_name;
    },
);
const labels = {
    pending: "در انتظار بررسی مدیریت",
    issued: "گواهی صادر شده",
    rejected: "نیازمند اصلاح",
    withdrawn: "انصراف ثبت‌شده",
};
</script>
<template>
    <Head title="گواهی دوره" /><Shell
        title="گواهی دوره"
        :role="admin ? 'admin' : page.props.activeRole"
    >
        <section class="page-intro">
            <div>
                <h1>{{ course_title }}</h1>
                <p>{{ cohort_title }} · گواهی پایان دوره</p>
            </div>
            <Link
                :href="
                    admin
                        ? '/admin/certificates'
                        : can_request
                          ? '/certificates'
                          : '/panel'
                "
                class="btn secondary"
                >بازگشت</Link
            >
        </section>
        <section
            v-if="certificate"
            class="glass panel-card"
            data-issued-certificate
        >
            <span
                class="badge"
                :class="certificate.status === 'issued' ? 'teal' : 'gold'"
                >{{
                    certificate.status === "issued"
                        ? "گواهی معتبر"
                        : "گواهی باطل‌شده"
                }}</span
            >
            <h2>{{ certificate.title }}</h2>
            <p>{{ certificate.recipient_name }} · {{ certificate.issuer }}</p>
            <p>
                <bdi>{{ certificate.serial }}</bdi> ·
                {{ dateTime(certificate.issued_at) }}
            </p>
            <a :href="certificate.verification_url" class="btn primary"
                >مشاهده، استعلام و چاپ گواهی</a
            >
        </section>
        <CertificateVisibilityForm
            v-if="certificate && can_manage_visibility"
            :certificate="certificate"
        />
        <section
            v-if="certificate && visibility_events.length"
            class="panel glass consultation-card consultation-section"
        >
            <h2>آخرین تغییرهای نمایش عمومی</h2>
            <p v-for="(event, index) in visibility_events" :key="index">
                {{
                    event.enabled
                        ? "نمایش عمومی با رضایت تازه فعال شد"
                        : "نمایش عمومی بسته شد"
                }}
                · {{ dateTime(event.created_at) }}
            </p>
        </section>
        <section
            v-if="!certificate && definition"
            class="glass panel-card form-stack"
            data-certificate-criteria
        >
            <h2>{{ definition.title }}</h2>
            <p>صادرکننده: {{ definition.issuer }}</p>
            <p class="caption muted">
                تکمیل درس خوداظهاری است؛ مدیریت پیش از صدور، نام و شواهد آموزشی
                را بررسی می‌کند.
            </p>
            <p
                v-for="(check, index) in evidence.checks"
                :key="index"
                :class="check.passed ? 'muted' : 'field-error'"
            >
                {{ check.passed ? "✓" : "○" }} {{ check.label }}
            </p>
            <p v-if="pending">
                درخواست شما در انتظار بررسی است. معیارهای همین درخواست ملاک
                بررسی خواهند بود.
            </p>
            <form
                v-if="can_request && !pending && policy"
                class="form-stack"
                @submit.prevent="
                    form.post(
                        '/certificates/enrollments/' +
                            enrollment.id +
                            '/request',
                    )
                "
                data-certificate-request
            >
                <p>
                    نام روی گواهی: <strong>{{ recipient_name }}</strong> · برای
                    اصلاح نام ابتدا
                    <Link href="/account" class="text-link">حساب من</Link> را
                    ویرایش کنید.
                </p>
                <label class="check-label"
                    ><input
                        v-model="form.public_consent"
                        type="checkbox"
                        required
                    />نامم را تأیید می‌کنم و با نمایش نام، عنوان دوره، صادرکننده
                    و وضعیت گواهی در صفحهٔ استعلام از طریق لینک قابل اشتراک
                    موافقم.</label
                >
                <p
                    v-for="(error, key) in form.errors"
                    :key="key"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button
                    class="btn primary"
                    :disabled="form.processing || !evidence.eligible"
                >
                    درخواست بررسی و صدور گواهی
                </button>
            </form>
        </section>
        <p v-if="!definition && !certificate" class="muted">
            درخواست گواهی برای این نوبت فعال نشده است.
        </p>
        <section class="consultation-section">
            <h2>سوابق درخواست</h2>
            <p v-if="!requests.length" class="muted">درخواستی ثبت نشده است.</p>
            <article
                v-for="request in requests"
                :key="request.id"
                class="glass panel-card"
            >
                <strong>{{ labels[request.status] }}</strong>
                <p v-if="request.withdrawn_at">
                    زمان انصراف: {{ dateTime(request.withdrawn_at) }}
                </p>
                <button
                    v-if="request.can_withdraw"
                    class="btn secondary"
                    type="button"
                    :disabled="withdrawal.processing"
                    :data-withdraw-request="request.id"
                    @click="withdraw(request)"
                >
                    انصراف از درخواست گواهی
                </button>
                <p>
                    {{ request.recipient_name }} ·
                    {{ dateTime(request.created_at) }}
                </p>
                <p v-if="request.review_note" class="lesson-text">
                    {{ request.review_note }}
                </p>
            </article>
        </section>
    </Shell>
</template>
