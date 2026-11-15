<script setup>
import { Head } from "@inertiajs/vue3";
import { dateTime } from "../dates";
defineProps({ certificate: Object, verification_url: String });
function print() {
    window.print();
}
</script>
<template>
    <Head title="استعلام گواهی"
        ><meta name="robots" content="noindex,noarchive"
    /></Head>
    <main class="certificate-verification">
        <nav class="verification-actions">
            <a href="/" class="text-link">کاردرست</a
            ><button class="btn secondary" @click="print">
                چاپ / ذخیره PDF
            </button>
        </nav>
        <article
            class="certificate-paper"
            :class="{ 'certificate-revoked': certificate.status === 'revoked' }"
            data-certificate-public
        >
            <p class="eyebrow">{{ certificate.issuer }}</p>
            <span
                class="badge"
                :class="certificate.status === 'issued' ? 'teal' : 'gold'"
                >{{
                    certificate.status === "issued"
                        ? "وضعیت استعلام: معتبر"
                        : "وضعیت استعلام: باطل‌شده"
                }}</span
            >
            <h1>{{ certificate.title }}</h1>
            <p>این گواهی برای</p>
            <h2>{{ certificate.recipient_name }}</h2>
            <p>برای تکمیل دوره</p>
            <h2>{{ certificate.course_title }}</h2>
            <p>{{ certificate.cohort_title }}</p>
            <p>صادرکننده: {{ certificate.issuer }}</p>
            <p>تاریخ صدور: {{ dateTime(certificate.issued_at) }}</p>
            <p v-if="certificate.revoked_at" class="field-error">
                تاریخ ابطال: {{ dateTime(certificate.revoked_at) }}
            </p>
            <p>
                شناسه گواهی: <bdi>{{ certificate.serial }}</bdi>
            </p>
            <footer>
                <p class="caption">
                    برای بررسی وضعیت فعلی این گواهی، صفحهٔ استعلام را باز کنید.
                </p>
                <a :href="verification_url" class="certificate-url" dir="ltr">{{
                    verification_url
                }}</a>
            </footer>
        </article>
    </main>
</template>
<style>
.certificate-verification {
    max-width: 1000px;
    padding: 24px 16px;
    margin: 0 auto;
}
.verification-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    gap: 16px;
}
.certificate-paper {
    background: var(--surface-raised, #fff);
    border: 3px double #24a99b;
    border-radius: 20px;
    padding: clamp(24px, 6vw, 64px);
    text-align: center;
}
.certificate-paper > .eyebrow {
    display: block;
}
.certificate-paper h1 {
    margin: 24px 0;
    font-size: clamp(24px, 4vw, 40px);
}
.certificate-paper h2 {
    margin: 20px 0;
}
.certificate-paper p {
    margin: 14px 0;
}
.certificate-paper footer {
    border-top: 1px solid #acbfc5;
    margin-top: 32px;
    padding-top: 16px;
}
.certificate-url {
    overflow-wrap: anywhere;
    font-size: 11px;
}
.certificate-revoked {
    border-color: #b34242;
}
@media print {
    @page {
        size: A4 landscape;
        margin: 12mm;
    }
    .verification-actions {
        display: none;
    }
    html,
    body {
        background: white !important;
        min-height: 0 !important;
        height: auto !important;
    }
    .certificate-verification {
        max-width: none;
        margin: 0;
        padding: 0;
    }
    .certificate-paper {
        border-radius: 0;
        break-inside: avoid;
        padding: 6mm;
        font-size: 11px;
        line-height: 1.4;
    }
    .certificate-paper h1 {
        font-size: 28px;
        margin: 12px 0;
    }
    .certificate-paper h2 {
        font-size: 20px;
        margin: 10px 0;
    }
    .certificate-paper p {
        margin: 8px 0;
    }
    .certificate-paper footer {
        margin-top: 14px;
        padding-top: 10px;
    }
    .certificate-paper,
    .certificate-paper * {
        color: #101c3e !important;
        background: white !important;
    }
    .certificate-revoked,
    .certificate-revoked * {
        color: #a02727 !important;
    }
}
</style>
