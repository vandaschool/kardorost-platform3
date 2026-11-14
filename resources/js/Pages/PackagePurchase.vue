<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import ReceiptUpload from "../Components/ReceiptUpload.vue";
import { money, paymentStatuses } from "../finance";
import { dateTime, fa } from "../dates";
const props = defineProps({
    admin: Boolean,
    purchase: Object,
    payments: Array,
    gateway_enabled: Boolean,
    transfer: Object,
});
const pay = useForm({});
const labels = {
    pending_payment: "در انتظار پرداخت",
    paid: "سهمیه صادر شده",
    review_required: "وجه نیازمند رسیدگی",
    cancelled: "بسته‌شده",
};
</script>
<template>
    <Head title="سفارش پکیج مشاوره" />
    <Shell title="سفارش پکیج مشاوره" :role="admin ? 'admin' : 'student'">
        <Link
            :href="
                admin
                    ? '/admin/consultation-packages'
                    : '/consultation-packages'
            "
            class="text-link"
            >پکیج‌ها و سوابق خرید</Link
        >
        <section
            class="glass panel-card consultation-section"
            data-package-purchase
        >
            <span
                class="badge"
                :class="purchase.status === 'paid' ? 'teal' : 'gold'"
                >{{ labels[purchase.status] }}</span
            >
            <h1>{{ purchase.definition.title }}</h1>
            <p v-if="purchase.student">
                {{ purchase.student.name }} ·
                <bdi>{{ purchase.student.phone }}</bdi>
            </p>
            <p class="lesson-text">{{ purchase.definition.description }}</p>
            <p>
                {{ purchase.definition.coach_name }} ·
                {{ fa(purchase.definition.sessions) }} جلسه
                {{ fa(purchase.definition.session_minutes) }} دقیقه‌ای
            </p>
            <h2>{{ money(purchase.amount) }}</h2>
            <p>
                مهلت استفاده {{ fa(purchase.definition.valid_days) }} روز از
                صدور سهمیه است. زمان جلسه را از بین وقت‌های قابل رزرو همین مربی
                انتخاب کنید. لغو معتبر پیش از شروع، اعتبار همان جلسه را بدون
                تمدید مهلت برمی‌گرداند.
            </p>
            <p
                v-if="purchase.status === 'pending_payment'"
                class="caption muted"
            >
                مهلت پرداخت: {{ dateTime(purchase.expires_at) }}
            </p>
            <p
                v-if="purchase.blocked_message && purchase.status !== 'paid'"
                class="caption muted"
            >
                {{ purchase.blocked_message }}
            </p>
            <p v-if="purchase.status === 'review_required'" class="caption">
                وجه دریافت‌شده حفظ شده است؛ وضعیت انجام سفارش یا بازپرداخت را با
                مدیریت پیگیری کنید.
            </p>
            <Link
                v-if="purchase.consultation_grant_id"
                :href="
                    admin
                        ? '/admin/consultation-grants'
                        : '/consultation-grants'
                "
                class="btn primary"
                >مشاهده سهمیه و رزرو مشاوره</Link
            >
            <template v-if="purchase.can_pay">
                <button
                    v-if="gateway_enabled"
                    class="btn primary"
                    :disabled="pay.processing"
                    @click="
                        pay.post('/package-purchases/' + purchase.id + '/pay')
                    "
                >
                    {{
                        purchase.has_gateway_attempt
                            ? "ادامه پرداخت درگاه"
                            : "پرداخت با زیبال"
                    }}
                </button>
                <p v-if="purchase.has_gateway_attempt" class="caption muted">
                    درخواست درگاه باز است؛ تا تعیین تکلیف، واریز دیگری انجام
                    ندهید.
                </p>
                <ReceiptUpload
                    v-if="
                        purchase.definition.manual_payment_enabled &&
                        transfer.destination &&
                        !purchase.has_gateway_attempt
                    "
                    :installment="{ id: purchase.id, amount: purchase.amount }"
                    :transfer="transfer"
                    :endpoint="
                        '/package-purchases/' + purchase.id + '/receipts'
                    "
                />
                <p
                    v-if="
                        !gateway_enabled &&
                        !(
                            purchase.definition.manual_payment_enabled &&
                            transfer.destination
                        )
                    "
                    class="caption muted"
                >
                    روش پرداخت این سفارش هنوز فعال نشده است.
                </p>
            </template>
            <p
                v-for="(error, key) in pay.errors"
                :key="key"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
        </section>
        <section class="consultation-section">
            <h2>سابقه پرداخت سفارش</h2>
            <p v-if="!payments.length" class="muted">پرداختی ثبت نشده است.</p>
            <article
                v-for="payment in payments"
                :key="payment.id"
                class="glass panel-card form-stack"
            >
                <strong
                    >{{ paymentStatuses[payment.status] }} ·
                    {{ money(payment.amount) }}</strong
                >
                <p v-if="payment.review_label">{{ payment.review_label }}</p>
                <p v-if="payment.receipt?.review_note">
                    {{ payment.receipt.review_note }}
                </p>
                <a
                    v-if="payment.receipt"
                    :href="payment.receipt.download_url"
                    class="text-link"
                    >دریافت رسید خصوصی</a
                ><Link
                    v-if="payment.can_request_refund"
                    :href="'/refunds?payment=' + payment.id"
                    class="btn secondary"
                    >درخواست بررسی بازپرداخت</Link
                ><Link
                    v-if="payment.refund_id"
                    :href="
                        '/refunds?payment=' +
                        payment.id +
                        (admin ? '&view=admin' : '')
                    "
                    class="text-link"
                    >پیگیری بازپرداخت</Link
                >
            </article>
        </section>
    </Shell>
</template>
