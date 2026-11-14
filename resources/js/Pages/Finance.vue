<script setup>
import CourseBenefitTerms from "../Components/CourseBenefitTerms.vue";
import { computed } from "vue";
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Icon from "../Components/Icon.vue";
import Empty from "../Components/Empty.vue";
import ReceiptUpload from "../Components/ReceiptUpload.vue";
import Pagination from "../Components/Pagination.vue";
import { money, dateOnly, paymentStatuses, receiptStatuses } from "../finance";
import { fa, dateTime } from "../dates";
const props = defineProps({
    enrollments: Array,
    payments: Object,
    gateway_enabled: Boolean,
    transfer: Object,
});
const page = usePage();
const pay = useForm({});
const debt = computed(() =>
    props.enrollments
        .filter((e) => e.status === "active")
        .reduce((sum, e) => sum + e.summary.remaining, 0),
);
const overdue = computed(() =>
    props.enrollments.reduce((sum, e) => sum + e.summary.overdue, 0),
);
</script>
<template>
    <Head title="پرداخت‌ها و اقساط" /><Shell
        title="پرداخت‌ها و اقساط"
        :role="page.props.activeRole"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">حساب مالی شما</span>
                <h1>پرداخت‌ها و اقساط</h1>
                <p class="muted">
                    مبلغ، سررسید و وضعیت هر پرداخت را روشن و جداگانه ببینید.
                </p>
            </div>
            <Link href="/learning" class="btn secondary"
                >یادگیری من<Icon name="ArrowLeft" :size="16"
            /></Link>
        </section>
        <Link href="/refunds" class="btn secondary"
            >درخواست و پیگیری بازپرداخت</Link
        >
        <section class="stats-grid">
            <div class="stat-card glass">
                <span class="stat-icon teal"><Icon name="BookOpen" /></span>
                <div>
                    <strong class="stat-text">{{ money(debt) }}</strong
                    ><span>مانده دوره‌های فعال</span>
                </div>
            </div>
            <div class="stat-card glass">
                <span class="stat-icon gold"><Icon name="Clock3" /></span>
                <div>
                    <strong class="stat-text">{{ money(overdue) }}</strong
                    ><span>سررسید گذشته</span>
                </div>
            </div>
            <div class="stat-card glass">
                <span class="stat-icon navy"><Icon name="ReceiptText" /></span>
                <div>
                    <strong>{{ fa(enrollments.length) }}</strong
                    ><span>ثبت‌نام دارای صورتحساب</span>
                </div>
            </div>
        </section>
        <Empty
            v-if="!enrollments.length"
            icon="ReceiptText"
            title="هنوز صورتحسابی ندارید"
            text="پس از ثبت‌نام در دوره پولی، مبلغ و برنامه پرداخت اینجا قرار می‌گیرد." />
        <section
            v-for="e in enrollments"
            :key="e.id"
            :id="'enrollment-' + e.id"
            class="glass panel-card financial-enrollment"
        >
            <div class="section-heading">
                <div>
                    <h2>{{ e.title }}</h2>
                    <p class="caption muted">
                        {{ e.cohort }} ·
                        {{
                            e.payment_plan === "installments"
                                ? "پرداخت اقساطی"
                                : "پرداخت یکجا"
                        }}
                    </p>
                </div>
                <span
                    :class="['badge', e.status === 'active' ? 'teal' : 'gold']"
                    >{{
                        e.status === "active"
                            ? "ثبت‌نام فعال"
                            : "در انتظار تکمیل ثبت‌نام"
                    }}</span
                >
            </div>
            <CourseBenefitTerms
                :definition="e.consultation_benefit?.definition"
                :contract="e.consultation_benefit"
            />
            <div class="bill-summary">
                <span
                    >کل قرارداد: <b>{{ money(e.summary.total) }}</b></span
                ><span
                    >تخصیص‌یافته: <b>{{ money(e.summary.paid) }}</b></span
                ><span
                    >مانده: <b>{{ money(e.summary.remaining) }}</b></span
                >
            </div>
            <p v-if="e.status === 'pending_payment'" class="caption muted">
                ثبت‌نام پولی با تسویه مبلغ اول فعال می‌شود. مهلت رزرو فعلی:
                {{ dateTime(e.expires_at) }}.
            </p>
            <article
                v-for="i in e.installments"
                :key="i.id"
                class="installment-card"
            >
                <div class="section-heading">
                    <div>
                        <h3>
                            {{
                                e.payment_plan === "installments"
                                    ? "قسط " + fa(i.sequence)
                                    : "مبلغ ثبت‌نام"
                            }}
                            · {{ money(i.amount) }}
                        </h3>
                        <p class="caption muted">
                            سررسید: {{ dateOnly(i.due_date) }}
                        </p>
                    </div>
                    <span :class="['badge', i.paid_at ? 'teal' : 'gold']">{{
                        i.paid_at
                            ? "تسویه‌شده"
                            : i.pending_receipt
                              ? "رسید در انتظار بررسی"
                              : i.overdue
                                ? "سررسید گذشته"
                                : "پرداخت‌نشده"
                    }}</span>
                </div>
                <p v-if="i.blocked_message && !i.paid_at" class="caption muted">
                    {{ i.blocked_message }}
                </p>
                <div v-if="i.can_pay" class="learning-actions">
                    <button
                        v-if="gateway_enabled"
                        class="btn primary"
                        :disabled="pay.processing"
                        @click="
                            pay.post('/installments/' + i.id + '/pay', {
                                preserveScroll: true,
                            })
                        "
                    >
                        پرداخت آنلاین<Icon
                            name="ArrowLeft"
                            :size="16"
                        /></button
                    ><ReceiptUpload
                        v-if="i.manual_enabled"
                        :installment="i"
                        :transfer="transfer"
                    /><span
                        v-if="!gateway_enabled && !i.manual_enabled"
                        class="caption muted"
                        >روش پرداخت این نوبت هنوز فعال نشده است.</span
                    >
                </div>
                <p v-if="i.paid_at" class="caption muted">
                    تاریخ تسویه: {{ dateTime(i.paid_at) }}
                </p>
                <a
                    v-if="i.pending_receipt"
                    :href="i.pending_receipt.download_url"
                    class="text-link"
                    ><Icon name="Download" :size="16" />دریافت رسید ثبت‌شده</a
                >
            </article>
            <Link
                v-if="
                    e.status === 'pending_payment' &&
                    new Date(e.expires_at) <= new Date()
                "
                href="/courses"
                class="text-link"
                >رزرو منقضی شده؟ قیمت و ثبت‌نام فعلی دوره را ببینید<Icon
                    name="ChevronLeft"
                    :size="16"
            /></Link>
        </section>
        <section class="glass panel-card">
            <div class="section-heading">
                <h2>تاریخچه پرداخت‌ها و رسیدها</h2>
            </div>
            <Empty
                v-if="!payments.data.length"
                icon="ReceiptText"
                title="هنوز تلاشی برای پرداخت ثبت نشده"
                text="تاریخچه پرداخت و بررسی رسید، پس از شروع پرداخت نمایش داده می‌شود."
            />
            <article
                v-for="p in payments.data"
                :key="p.id"
                class="payment-history-row"
            >
                <div class="section-heading">
                    <div>
                        <h3>{{ p.title }}</h3>
                        <p class="caption muted">
                            {{
                                p.sequence
                                    ? "قسط " + fa(p.sequence) + " · "
                                    : ""
                            }}{{
                                p.method === "manual" ? "کارت‌به‌کارت" : "زیبال"
                            }}
                            · {{ dateTime(p.created_at) }}
                        </p>
                    </div>
                    <span
                        :class="[
                            'badge',
                            p.status === 'paid' ? 'teal' : 'gold',
                        ]"
                        >{{
                            p.receipt?.status === "rejected"
                                ? "رسید ردشده"
                                : paymentStatuses[p.status] || p.status
                        }}</span
                    >
                </div>
                <strong class="payment-amount">{{ money(p.amount) }}</strong>
                <Link
                    v-if="p.can_request_refund || p.refund_id"
                    :href="'/refunds?payment=' + p.id"
                    class="btn secondary"
                    >بازپرداخت این تراکنش</Link
                >
                <p v-if="p.refund_status === 'pending'" class="caption muted">
                    جلسه لغو شده؛ بازپرداخت هنوز انجام نشده و نیازمند پیگیری
                    مدیریت است.
                </p>
                <p v-if="p.review_label" class="caption muted">
                    دلیل بررسی: {{ p.review_label }}
                </p>
                <p v-if="p.reference" class="caption muted">
                    شناسه ثبت پرداخت: <bdi>{{ p.reference }}</bdi>
                </p>
                <p v-if="p.receipt?.review_note" class="lesson-text caption">
                    نتیجه بررسی: {{ p.receipt.review_note }}
                </p>
                <a
                    v-if="p.receipt"
                    :href="p.receipt.download_url"
                    class="text-link"
                    ><Icon name="Download" :size="15" />دریافت رسید</a
                >
            </article>
            <Pagination :page="payments" /></section
    ></Shell>
</template>
