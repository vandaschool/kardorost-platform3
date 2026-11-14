<script setup>
import { Head, Link, useForm, router } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Icon from "../Components/Icon.vue";
import Empty from "../Components/Empty.vue";
import ReceiptReview from "../Components/ReceiptReview.vue";
import Pagination from "../Components/Pagination.vue";
import { money, dateOnly, paymentStatuses } from "../finance";
import { fa, dateTime } from "../dates";
const props = defineProps({
    tab: String,
    status: String,
    rows: Object,
    stats: Object,
});
const form = useForm({});
const filterStates = () =>
    props.tab === "receipts"
        ? [
              ["all", "همه"],
              ["pending", "در انتظار بررسی"],
              ["approved", "تأییدشده"],
              ["rejected", "ردشده"],
          ]
        : [
              ["all", "همه"],
              ["paid", "تخصیص‌یافته"],
              ["refunded", "بازپرداخت ثبت‌شده"],
              ["review_required", "نیازمند بررسی"],
              ["pending_review", "رسید در انتظار بررسی"],
              ["pending", "در انتظار درگاه"],
              ["failed", "ناموفق"],
              ["expired", "منقضی"],
          ];
</script>
<template>
    <Head title="مدیریت مالی" /><Shell title="مدیریت مالی" role="admin"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">گردش و پیگیری پرداخت‌ها</span>
                <h1>مدیریت مالی</h1>
                <p class="muted">
                    تأیید دریافت وجه، تخصیص به صورتحساب و رسیدگی به بدهی‌ها.
                </p>
            </div>
        </section>
        <section class="stats-grid">
            <div class="stat-card glass">
                <span class="stat-icon teal"><Icon name="CircleCheck" /></span>
                <div>
                    <strong class="stat-text">{{
                        money(stats.allocated)
                    }}</strong
                    ><span>تأیید و تخصیص‌یافته</span>
                </div>
            </div>
            <div class="stat-card glass">
                <span class="stat-icon gold"><Icon name="CircleAlert" /></span>
                <div>
                    <strong class="stat-text">{{
                        money(stats.unallocated)
                    }}</strong
                    ><span>دریافتی نیازمند تخصیص</span>
                </div>
            </div>
            <div class="stat-card glass">
                <span class="stat-icon navy"><Icon name="ReceiptText" /></span>
                <div>
                    <strong>{{ fa(stats.pending_receipts) }}</strong
                    ><span>رسید منتظر بررسی</span>
                </div>
            </div>
        </section>
        <Link href="/refunds?view=admin" class="btn secondary"
            >کارتابل بازپرداخت‌ها</Link
        >
        <nav class="learning-tabs" aria-label="بخش‌های مالی">
            <Link
                v-for="[key, label] in [
                    ['receipts', 'بررسی رسیدها'],
                    ['payments', 'پرداخت‌ها'],
                    ['debts', 'بدهی‌های سررسیدگذشته'],
                ]"
                :key="key"
                :href="'/admin/finance?tab=' + key"
                :class="{ active: tab === key }"
                >{{ label }}</Link
            >
        </nav>
        <section class="glass panel-card">
            <div class="section-heading">
                <h2>
                    {{
                        tab === "receipts"
                            ? "کارتابل رسیدها"
                            : tab === "payments"
                              ? "تاریخچه پرداخت‌ها"
                              : "بدهی دوره‌های فعال"
                    }}
                </h2>
                <label v-if="tab !== 'debts'"
                    >وضعیت<select
                        :value="status"
                        @change="
                            router.get('/admin/finance', {
                                tab,
                                status: $event.target.value,
                            })
                        "
                    >
                        <option
                            v-for="[key, label] in filterStates()"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </select></label
                >
            </div>
            <Empty
                v-if="!rows.data.length"
                icon="ReceiptText"
                title="موردی در این فهرست نیست"
                text="نتایج مطابق بخش و وضعیت انتخاب‌شده نمایش داده می‌شوند."
            /><template v-if="tab === 'receipts'"
                ><ReceiptReview
                    v-for="r in rows.data"
                    :key="r.id"
                    :receipt="r" /></template
            ><template v-else-if="tab === 'payments'"
                ><article
                    v-for="p in rows.data"
                    :key="p.id"
                    class="payment-history-row"
                >
                    <div class="section-heading">
                        <div>
                            <h3>{{ p.student.name }}</h3>
                            <p class="caption muted">
                                {{ p.title }} ·
                                {{
                                    p.sequence
                                        ? "قسط " + fa(p.sequence)
                                        : "صورتحساب"
                                }}
                                ·
                                {{
                                    p.method === "manual"
                                        ? "کارت‌به‌کارت"
                                        : "زیبال"
                                }}
                            </p>
                        </div>
                        <span
                            :class="[
                                'badge',
                                p.status === 'paid' ? 'teal' : 'gold',
                            ]"
                            >{{ paymentStatuses[p.status] || p.status }}</span
                        >
                    </div>
                    <strong class="payment-amount">{{
                        money(p.amount)
                    }}</strong>
                    <p class="caption muted">
                        {{ dateTime(p.created_at)
                        }}<span v-if="p.reference">
                            · شناسه: <bdi>{{ p.reference }}</bdi></span
                        >
                    </p>
                    <p
                        v-if="p.refund_status === 'pending'"
                        class="caption muted"
                    >
                        جلسه لغو شده؛ بازپرداخت هنوز انجام نشده و نیازمند پیگیری
                        مدیریت است.
                    </p>
                    <p v-if="p.review_label" class="caption muted">
                        {{ p.review_label }}
                    </p>
                    <p
                        v-if="p.status === 'review_required'"
                        class="caption muted"
                    >
                        تخصیص فقط با تأیید دریافت وجه، صورتحساب معتبر و ظرفیت
                        کافی ممکن است. این بخش بازپرداخت خودکار ندارد.
                    </p>
                    <div class="learning-actions">
                        <button
                            v-if="p.can_allocate"
                            class="btn secondary"
                            :disabled="form.processing"
                            @click="
                                form.post(
                                    '/admin/finance/payments/' +
                                        p.id +
                                        '/allocate',
                                    { preserveScroll: true },
                                )
                            "
                        >
                            {{
                                p.package_purchase_id
                                    ? "بررسی انجام سفارش پکیج"
                                    : "تلاش برای تخصیص به صورتحساب"
                            }}</button
                        ><a
                            v-if="p.receipt"
                            :href="p.receipt.download_url"
                            class="text-link"
                            ><Icon name="Download" :size="15" />رسید</a
                        >
                    </div>
                </article></template
            ><template v-else
                ><article v-for="i in rows.data" :key="i.id" class="simple-row">
                    <span class="row-icon gold"><Icon name="Clock3" /></span>
                    <div class="grow">
                        <strong>{{ i.student.name }} · {{ i.title }}</strong
                        ><small
                            >قسط {{ fa(i.sequence) }} ·
                            {{ dateOnly(i.due_date) }} ·
                            <bdi>{{ i.student.phone }}</bdi></small
                        >
                    </div>
                    <strong class="payment-amount">{{
                        money(i.amount)
                    }}</strong>
                </article>
                <p class="caption muted">
                    این فهرست فقط بدهی ثبت‌نام‌های فعال را نشان می‌دهد؛ رزروهای
                    پرداخت‌نشده بدهی آموزشی محسوب نمی‌شوند.
                </p></template
            ><Pagination :page="rows" /></section
    ></Shell>
</template>
