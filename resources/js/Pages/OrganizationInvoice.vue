<script setup>
import { watch, ref } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import OrganizationInvoiceEditor from "../Components/OrganizationInvoiceEditor.vue";
import OrganizationInvoicePayment from "../Components/OrganizationInvoicePayment.vue";
import ReceiptReview from "../Components/ReceiptReview.vue";
import Pagination from "../Components/Pagination.vue";
import { money, dateOnly, paymentStatuses, receiptStatuses } from "../finance";
import { dateTime, fa } from "../dates";
const props = defineProps({
    admin: Boolean,
    invoice: Object,
    payments: Object,
    can_pay: Boolean,
    blocked: Boolean,
    gateway_enabled: Boolean,
    pending_payment_amount: Number,
    licenses: Array,
});
const draftDirty = ref(false);
const issue = useForm({ revision: props.invoice.revision, reviewed: false });
const cancel = useForm({ revision: props.invoice.revision, reason: "" });
watch(
    () => props.invoice.revision,
    () => {
        issue.revision = props.invoice.revision;
        cancel.revision = props.invoice.revision;
        issue.reviewed = false;
    },
);
const labels = {
    draft: "پیش‌نویس",
    issued: "صادرشده",
    paid: "تسویه‌شده",
    void: "باطل‌شده",
};
function receipt(p) {
    return {
        ...p.receipt,
        student: { name: p.payer_name_snapshot || "مدیر سازمان" },
        payment: {
            title: props.invoice.title,
            amount: p.amount,
            organization_invoice_id: props.invoice.id,
            sequence: null,
        },
    };
}
</script>
<template>
    <Head :title="invoice.title" /><Shell
        title="صورتحساب قرارداد"
        :role="admin ? 'admin' : 'org_admin'"
        ><Link
            :href="
                admin
                    ? '/admin/organization-invoices'
                    : '/organization/invoices'
            "
            class="text-link"
            >بازگشت به صورتحساب‌ها</Link
        >
        <section class="page-intro">
            <div>
                <span class="eyebrow"
                    >{{ labels[invoice.status] }} ·
                    {{ invoice.number || "پیش‌نویس داخلی" }}</span
                >
                <h1>{{ invoice.title }}</h1>
                <p>
                    {{ invoice.organization_name }} · قرارداد
                    {{ invoice.contract_reference }}
                </p>
            </div>
        </section>
        <OrganizationInvoiceEditor
            v-if="admin && invoice.status === 'draft'"
            :invoice="invoice"
            :pending-amount="pending_payment_amount"
            :licenses="licenses"
            @dirty="draftDirty = $event" />
        <p v-if="draftDirty" class="muted">
            برای صدور، ابتدا تغییرات پیش‌نویس را ذخیره کنید.
        </p>
        <section class="panel glass consultation-card">
            <h2>مبلغ و ردیف‌های صورتحساب</h2>
            <article
                v-for="(l, i) in invoice.lines"
                :key="i"
                class="assessment-editor-item"
            >
                <h3>{{ l.title }}</h3>
                <p>
                    {{ fa(l.quantity) }} × {{ money(l.unit_price) }} =
                    {{ money(l.quantity * l.unit_price) }}
                </p>
            </article>
            <strong>جمع: {{ money(invoice.amount) }}</strong>
            <p data-testid="invoice-paid">
                پرداخت تأییدشده: {{ money(invoice.paid_amount) }}
            </p>
            <p data-testid="invoice-remaining">
                <strong>مانده: {{ money(invoice.remaining_amount) }}</strong>
            </p>
            <progress
                v-if="invoice.amount"
                :value="invoice.paid_amount"
                :max="invoice.amount"
                aria-label="پیشرفت پرداخت صورتحساب"
            ></progress>
            <p>
                سررسید: {{ dateOnly(invoice.due_date) }}
                <span v-if="invoice.overdue">· سررسید گذشته</span>
            </p>
            <p v-if="invoice.issued_at">
                صدور: {{ dateTime(invoice.issued_at) }}
            </p>
            <p v-if="invoice.paid_at">تسویه: {{ dateTime(invoice.paid_at) }}</p>
            <p v-if="invoice.void_reason" class="preserve-lines">
                دلیل ابطال: {{ invoice.void_reason }}
            </p>
            <p class="muted">
                این سند صورتحساب داخلی قرارداد است. صدور و پرداخت آن سهمیه یا
                دسترسی آموزشی را خودکار تغییر نمی‌دهد.
            </p>
        </section>
        <form
            v-if="admin && invoice.status === 'draft'"
            @submit.prevent="
                issue.post(`/admin/organization-invoices/${invoice.id}/issue`, {
                    preserveScroll: true,
                })
            "
            class="panel glass consultation-card stack-form organization-license-form consultation-section"
        >
            <label
                ><input
                    type="checkbox"
                    v-model="issue.reviewed"
                    required
                />مبلغ، ردیف‌ها، قرارداد و سررسید پیش‌نویس ذخیره‌شده را بررسی
                کرده‌ام.</label
            ><button
                class="btn primary"
                :disabled="issue.processing || draftDirty"
            >
                صدور و تثبیت صورتحساب
            </button>
        </form>
        <details
            v-if="admin && ['draft', 'issued'].includes(invoice.status)"
            class="panel glass consultation-card consultation-section"
        >
            <summary>ابطال صورتحساب</summary>
            <form
                class="stack-form"
                @submit.prevent="
                    cancel.post(
                        `/admin/organization-invoices/${invoice.id}/void`,
                        { preserveScroll: true },
                    )
                "
            >
                <label
                    >دلیل ابطال<textarea
                        v-model="cancel.reason"
                        required
                        minlength="3"
                        maxlength="2000"
                    ></textarea>
                </label>
                <p class="muted">
                    صورتحساب دارای وجه دریافت‌شده یا پرداخت در جریان باطل
                    نمی‌شود.
                </p>
                <button class="btn secondary" :disabled="cancel.processing">
                    ثبت ابطال
                </button>
            </form>
        </details>
        <OrganizationInvoicePayment
            v-if="can_pay"
            :invoice="invoice"
            :gateway-enabled="gateway_enabled" />
        <p v-if="blocked" class="flash" role="status">
            رسید یا پرداخت قبلی نیازمند بررسی است؛ دوباره پرداخت نکنید.
        </p>
        <section class="consultation-section">
            <h2>سوابق پرداخت و بررسی</h2>
            <p v-if="!payments.data.length" class="muted">
                هنوز پرداختی ثبت نشده است.
            </p>
            <article
                v-for="p in payments.data"
                :key="p.id"
                class="panel glass consultation-card"
            >
                <h3>
                    {{ paymentStatuses[p.status] || p.status }} ·
                    {{ money(p.amount) }}
                </h3>
                <p>
                    {{ p.payer_name_snapshot }} ·
                    {{ p.method === "manual" ? "واریز بانکی" : "زیبال" }} ·
                    {{ dateTime(p.created_at) }}
                </p>
                <p v-if="p.reference">
                    شناسه تأییدشده: <bdi>{{ p.reference }}</bdi>
                </p>
                <p v-if="p.allocated_amount">
                    کسرشده از بدهی: {{ money(p.allocated_amount) }}
                </p>
                <p v-if="p.review_label" class="muted">{{ p.review_label }}</p>
                <ReceiptReview
                    v-if="admin && p.receipt"
                    :receipt="receipt(p)"
                />
                <div v-else-if="p.receipt">
                    <p>وضعیت رسید: {{ receiptStatuses[p.receipt.status] }}</p>
                    <p v-if="p.receipt.review_note" class="preserve-lines">
                        {{ p.receipt.review_note }}
                    </p>
                    <a :href="p.receipt.download_url" class="btn secondary"
                        >دریافت رسید خصوصی</a
                    >
                </div>
            </article>
            <Pagination :links="payments.links" /></section
    ></Shell>
</template>
