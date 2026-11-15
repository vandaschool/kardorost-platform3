<script setup>
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { dateTime, fa } from "../dates";
const props = defineProps({
    deliveries: Object,
    filters: Object,
    selected: Object,
    attempts: Object,
    invitation_issues: Array,
    reminder_issues: Array,
    legacy_untracked_count: Number,
    settings: Object,
});
const query = useForm({ revision: 0 });
const labels = {
    pending: "منتظر گزارش",
    polling: "در حال بررسی",
    delivered: "رسیده به گوشی",
    undelivered: "تحویل انجام نشده",
    unknown: "نتیجه نامشخص",
    unavailable: "استعلام در دسترس نیست",
    processing: "در حال بررسی",
    interrupted: "بررسی قطع‌شده",
    superseded: "پاسخ قدیمی کنارگذاشته‌شده",
};
const errors = {
    unconfigured: "تنظیمات سرویس کامل نیست",
    sender_account_changed: "حساب ارسال‌کننده با تنظیمات فعلی یکسان نیست",
    unsupported_delivery_mode: "استعلام خودکار برای این حالت اتصال آماده نیست",
    unsupported_reference: "شناسه خارج از محدوده استعلام این سرویس است",
    delivery_access_unavailable: "سرویس دسترسی به گزارش تحویل را تأیید نکرد",
    query_transport_error:
        "پاسخ قابل تأیید دریافت نشد؛ استعلام بعداً تکرار می‌شود",
    invalid_query_response: "ساختار پاسخ سرویس قابل تأیید نبود",
    provider_query_error: "سرویس، خطای استعلام گزارش کرد",
    tracking_window_expired: "بازه پیگیری تمام شده و نتیجه قطعی ثبت نشده",
    query_interrupted: "اجرای قبلی استعلام قطع شد",
    worker_interrupted: "اجرای ارسال قطع شد؛ نتیجه نامشخص است",
    transport_unknown: "نتیجه ارسال شبکه نامشخص است",
    response_unknown: "پاسخ ارسال قابل تأیید نبود",
    provider_rejected: "سرویس درخواست ارسال را نپذیرفت",
    provider_reference_conflict: "شناسه سرویس برای ارسال دیگری ثبت شده است",
    unexpected_error: "نتیجه عملیات قابل تأیید نبود",
    invalid_arguments: "مقادیر الگوی پیامک معتبر نیستند",
};
function retry(delivery) {
    query.revision = delivery.revision;
    query.post("/admin/communications/" + delivery.id + "/query", {
        preserveScroll: true,
    });
}
</script>
<template>
    <Head title="پیامک و یادآوری‌ها" /><Shell
        title="پیامک و یادآوری‌ها"
        role="admin"
        ><section class="page-intro">
            <div>
                <h1>پیامک و یادآوری‌ها</h1>
                <p>
                    پذیرش سرویس، تحویل گوشی و نتیجه نامشخص جدا ثبت می‌شوند.
                    استعلام، پیامک تازه ارسال نمی‌کند.
                </p>
            </div>
        </section>
        <section class="glass panel-card consultation-section">
            <h2>وضعیت این محیط</h2>
            <p>
                اتصال:
                {{
                    settings.mode === "rest"
                        ? "REST"
                        : settings.mode === "console"
                          ? "کنسول"
                          : "تنظیم‌نشده"
                }}
                · استعلام تحویل
                {{ settings.delivery_enabled ? "فعال" : "غیرفعال" }}
            </p>
            <p>
                الگوی دعوت
                {{
                    settings.invitation_configured
                        ? "تنظیم شده"
                        : "نیازمند تنظیم"
                }}
                · الگوی یادآوری
                {{
                    settings.reminder_configured ? "تنظیم شده" : "نیازمند تنظیم"
                }}
            </p>
            <p>
                یادآوری داخلی
                {{ settings.in_app_reminders_enabled ? "فعال" : "غیرفعال" }} ·
                بازه پیش از رویداد {{ fa(settings.lead_minutes) }} دقیقه · پیامک
                یادآوری
                {{ settings.sms_reminders_enabled ? "فعال" : "غیرفعال" }}
            </p>
            <p v-if="!settings.delivery_supported" class="muted">
                استعلام خودکار تحویل در این نسخه برای اتصال REST پیاده شده است؛
                تحویل ارسال‌های کنسول قطعی اعلام نمی‌شود.
            </p>
            <p v-if="legacy_untracked_count" class="muted">
                {{ fa(legacy_untracked_count) }} ارسال قدیمی، شناسه حساب ثبت‌شده
                برای پیگیری ندارد.
            </p>
        </section>
        <section class="glass panel-card consultation-section">
            <h2>گزارش تحویل</h2>
            <label
                >وضعیت<select
                    name="delivery_status"
                    :value="filters.status || ''"
                    @change="
                        router.get('/admin/communications', {
                            status: $event.target.value || undefined,
                        })
                    "
                >
                    <option value="">همه</option>
                    <option
                        v-for="state in [
                            'pending',
                            'polling',
                            'delivered',
                            'undelivered',
                            'unknown',
                            'unavailable',
                        ]"
                        :value="state"
                    >
                        {{ labels[state] }}
                    </option>
                </select></label
            >
            <p v-if="!deliveries.data.length" class="muted">
                گزارشی ثبت نشده است.
            </p>
            <p v-for="error in query.errors" class="field-error" role="alert">
                {{ error }}
            </p>
            <article
                v-for="delivery in deliveries.data"
                :key="delivery.id"
                class="consultation-section"
                :data-delivery="delivery.id"
            >
                <h3>
                    {{
                        delivery.source_type === "invitation"
                            ? "دعوت سازمانی"
                            : "یادآوری رویداد"
                    }}
                    · {{ labels[delivery.status] }}
                </h3>
                <p>
                    شناسه سرویس: <bdi>{{ delivery.provider_reference }}</bdi>
                </p>
                <p v-if="delivery.last_checked_at" class="muted">
                    آخرین بررسی: {{ dateTime(delivery.last_checked_at) }}
                </p>
                <p v-if="delivery.error_code">
                    {{
                        errors[delivery.error_code] ||
                        "گزارش نیازمند بررسی است."
                    }}
                </p>
                <p v-if="delivery.next_poll_at" class="muted">
                    بررسی بعدی: {{ dateTime(delivery.next_poll_at) }}
                </p>
                <div class="action-row">
                    <Link
                        class="btn secondary"
                        :href="'/admin/communications?delivery=' + delivery.id"
                        >سابقه بررسی‌ها</Link
                    ><button
                        v-if="
                            !['delivered', 'polling'].includes(delivery.status)
                        "
                        class="btn secondary"
                        :disabled="
                            query.processing || !settings.delivery_enabled
                        "
                        @click="retry(delivery)"
                    >
                        درخواست استعلام تازه
                    </button>
                </div>
            </article>
            <Pagination :page="deliveries" />
        </section>
        <section v-if="selected" class="glass panel-card consultation-section">
            <h2>
                سابقه استعلام <bdi>{{ selected.provider_reference }}</bdi>
            </h2>
            <p v-if="!attempts.data.length" class="muted">
                بررسی ثبت نشده است.
            </p>
            <article
                v-for="attempt in attempts.data"
                :key="attempt.number"
                class="consultation-section"
            >
                <h3>
                    بررسی {{ fa(attempt.number) }} ·
                    {{ labels[attempt.status] }}
                </h3>
                <p v-if="attempt.code !== null">
                    کد پاسخ سرویس: {{ fa(attempt.code) }}
                </p>
                <p v-if="attempt.error_code">
                    {{ errors[attempt.error_code] || "نتیجه قابل تأیید نبود." }}
                </p>
                <small>{{ dateTime(attempt.created_at) }}</small>
            </article>
            <Pagination :page="attempts" />
        </section>
        <section class="glass panel-card consultation-section">
            <h2>ارسال‌های نیازمند رسیدگی</h2>
            <p class="muted">
                برای نتیجه نامشخص، ارسال خودکار دوباره انجام نمی‌شود.
            </p>
            <p
                v-if="!invitation_issues.length && !reminder_issues.length"
                class="muted"
            >
                موردی ثبت نشده است.
            </p>
            <article
                v-for="issue in invitation_issues"
                :key="'invitation-' + issue.id"
                class="consultation-section"
            >
                <h3>دعوت {{ issue.organization }}</h3>
                <p>
                    {{
                        errors[issue.failure_code] || "ارسال نیازمند بررسی است."
                    }}
                </p>
                <small>{{ dateTime(issue.finished_at) }}</small>
            </article>
            <article
                v-for="issue in reminder_issues"
                :key="'reminder-' + issue.id"
                class="consultation-section"
            >
                <h3>یادآوری {{ issue.recipient_name }}</h3>
                <p>
                    {{
                        errors[issue.failure_code] || "ارسال نیازمند بررسی است."
                    }}
                </p>
                <small>{{ dateTime(issue.finished_at) }}</small>
            </article>
        </section></Shell
    >
</template>
