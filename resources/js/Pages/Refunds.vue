<script setup>
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import RefundRequestForm from "../Components/RefundRequestForm.vue";
import RefundReview from "../Components/RefundReview.vue";
import { money, dateOnly } from "../finance";
import { dateTime } from "../dates";
const props = defineProps({
    admin: Boolean,
    status: String,
    rows: Object,
    candidates: Object,
    selected_payment: Number,
});
const page = usePage();
const labels = {
    requested: "در انتظار بررسی",
    approved: "تأییدشده؛ منتظر واریز",
    rejected: "ردشده",
    completed: "مدرک برگشت وجه ثبت شد",
};
function filter(status) {
    router.get("/refunds", {
        view: props.admin ? "admin" : undefined,
        status,
        payment: props.selected_payment || undefined,
    });
}
</script>
<template>
    <Head title="بازپرداخت‌ها" /><Shell
        title="بازپرداخت‌ها"
        :role="admin ? 'admin' : page.props.activeRole"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">پیگیری روشن برگشت وجه</span>
                <h1>{{ admin ? "کارتابل بازپرداخت" : "بازپرداخت‌های من" }}</h1>
                <p class="muted">
                    وضعیت درخواست، بررسی و مدرک واریز را جداگانه ببینید.
                </p>
            </div>
            <Link
                :href="admin ? '/admin/finance?tab=payments' : '/finance'"
                class="btn secondary"
                >بازگشت به مالی</Link
            >
        </section>
        <p class="muted">
            بازپرداخت این مرحله کامل و دستی است. ثبت درخواست یا تأیید ادمین به
            معنی برگشت وجه نیست. شبا و مدارک فقط برای صاحب پرداخت و ادمین قابل
            دسترسی‌اند.
        </p>
        <section v-if="candidates" class="consultation-section">
            <h2>درخواست جدید یا اصلاح درخواست ردشده</h2>
            <p v-if="!candidates.data.length" class="muted">
                تراکنشی برای درخواست تازه وجود ندارد. لغو دوره فعال و بازپرداخت
                جزئی هنوز در این مسیر فعال نشده‌اند.
            </p>
            <div class="consultation-grid">
                <RefundRequestForm
                    v-for="p in candidates.data"
                    :key="p.id"
                    :payment="p"
                />
            </div>
            <Pagination :links="candidates.links" />
        </section>
        <section class="consultation-section">
            <div class="section-heading">
                <h2>{{ admin ? "درخواست‌ها" : "تاریخچه و پیگیری" }}</h2>
                <label
                    >وضعیت<select
                        :value="status"
                        @change="filter($event.target.value)"
                    >
                        <option value="all">همه</option>
                        <option
                            v-for="(label, key) in labels"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </select></label
                >
            </div>
            <p v-if="!rows.data.length" class="muted">
                درخواستی مطابق این وضعیت وجود ندارد.
            </p>
            <div class="consultation-grid">
                <article
                    v-for="r in rows.data"
                    :key="r.id"
                    class="panel glass consultation-card"
                >
                    <span class="status-badge">{{ labels[r.status] }}</span>
                    <h3>{{ r.title }}</h3>
                    <p v-if="admin">{{ r.student }}</p>
                    <p>
                        {{ money(r.amount) }} ·
                        <bdi>{{ r.destination_mask }}</bdi>
                    </p>
                    <p class="preserve-lines">{{ r.reason }}</p>
                    <p v-if="r.review_note" class="preserve-lines">
                        نتیجه بررسی: {{ r.review_note }}
                    </p>
                    <p v-if="r.transfer_reference">
                        شناسه واریز: <bdi>{{ r.transfer_reference }}</bdi> ·
                        {{ dateOnly(r.transfer_date) }}
                    </p>
                    <a
                        v-if="r.file_url"
                        :href="r.file_url"
                        class="btn secondary"
                        >دریافت مدرک برگشت وجه</a
                    ><RefundReview v-if="admin" :refund="r" />
                    <details class="consultation-section">
                        <summary>تاریخچه درخواست</summary>
                        <ol>
                            <li v-for="e in r.events" :key="e.version">
                                <strong>{{ labels[e.status] }}</strong> ·
                                {{ dateTime(e.created_at) }}
                                <p class="preserve-lines">{{ e.note }}</p>
                            </li>
                        </ol>
                    </details>
                </article>
            </div>
            <Pagination :links="rows.links" /></section
    ></Shell>
</template>
