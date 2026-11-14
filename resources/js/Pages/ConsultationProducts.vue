<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import ConsultationProductEditor from "../Components/ConsultationProductEditor.vue";
import PackageOffer from "../Components/PackageOffer.vue";
import Pagination from "../Components/Pagination.vue";
import { money } from "../finance";
defineProps({
    admin: Boolean,
    products: Object,
    purchases: Object,
    coaches: Array,
});
const labels = {
    pending_payment: "در انتظار پرداخت",
    paid: "پرداخت‌شده",
    review_required: "نیازمند رسیدگی",
    cancelled: "بسته‌شده",
};
</script>
<template>
    <Head title="پکیج‌های مشاوره" />
    <Shell title="پکیج‌های مشاوره" :role="admin ? 'admin' : 'student'">
        <section class="page-intro">
            <div>
                <span class="eyebrow">همراه مسیر حرفه‌ای شما</span>
                <h1>
                    {{ admin ? "مدیریت پکیج‌های مشاوره" : "پکیج‌های مشاوره" }}
                </h1>
                <p class="muted">
                    مربی، مدت و تعداد جلسات و مهلت استفاده پیش از خرید مشخص‌اند.
                </p>
            </div>
            <Link
                :href="
                    admin
                        ? '/admin/consultation-grants'
                        : '/consultation-grants'
                "
                class="btn secondary"
                >سهمیه‌های مشاوره</Link
            >
        </section>
        <details v-if="admin" class="glass panel-card">
            <summary>ساخت پکیج تازه</summary>
            <ConsultationProductEditor :coaches="coaches" />
        </details>
        <p v-if="!products.data.length" class="muted">
            پکیجی برای نمایش وجود ندارد.
        </p>
        <div class="consultation-grid consultation-section">
            <template v-for="product in products.data" :key="product.id">
                <article v-if="admin" class="glass panel consultation-card">
                    <span
                        class="badge"
                        :class="product.published ? 'teal' : 'gold'"
                        >{{ product.published ? "منتشرشده" : "پیش‌نویس" }}</span
                    >
                    <h2>{{ product.title }}</h2>
                    <p>{{ product.coach_name }} · {{ money(product.price) }}</p>
                    <details>
                        <summary>ویرایش شرایط فروش</summary>
                        <ConsultationProductEditor
                            :product="product"
                            :coaches="coaches"
                        />
                    </details>
                </article>
                <PackageOffer v-else :product="product" />
            </template>
        </div>
        <Pagination :links="products.links" />
        <section class="consultation-section">
            <h2>{{ admin ? "سفارش‌های پکیج" : "سوابق خرید من" }}</h2>
            <p v-if="!purchases.data.length" class="muted">
                سفارشی ثبت نشده است.
            </p>
            <article
                v-for="purchase in purchases.data"
                :key="purchase.id"
                class="glass panel-card simple-row"
            >
                <div class="grow">
                    <strong>{{ purchase.title }}</strong>
                    <p class="caption">
                        {{ labels[purchase.status] }} ·
                        {{ money(purchase.amount) }}
                    </p>
                </div>
                <Link
                    :href="'/package-purchases/' + purchase.id"
                    class="btn secondary"
                    >مشاهده سفارش</Link
                >
            </article>
            <Pagination :links="purchases.links" />
        </section>
    </Shell>
</template>
