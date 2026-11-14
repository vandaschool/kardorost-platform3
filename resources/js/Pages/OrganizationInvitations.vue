<script setup>
import { watch } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import OrganizationInvitationCard from "../Components/OrganizationInvitationCard.vue";
import Pagination from "../Components/Pagination.vue";
import { dateTime, fa } from "../dates";
const props = defineProps({
    organization: Object,
    invitations: Object,
    preview: Object,
    sms_enabled: Boolean,
});
const single = useForm({ phone: "" });
const upload = useForm({ file: null });
const commit = useForm({ reviewed: false });
watch(
    () => props.preview?.id,
    () => commit.reset(),
);
const rowLabels = {
    valid: "معتبر",
    invalid: "شماره یا قالب نامعتبر",
    duplicate: "تکراری در فایل؛ کنار گذاشته می‌شود",
};
function previewFile() {
    upload.post("/organization/invitation-imports", {
        forceFormData: true,
        preserveScroll: true,
    });
}
</script>
<template>
    <Head title="دعوت اعضای سازمان" /><Shell title="دعوت اعضا" role="org_admin">
        <Link href="/organization" class="text-link"
            >بازگشت به اعضا و انتخاب سازمان</Link
        >
        <section class="page-intro">
            <div>
                <span class="eyebrow">{{ organization.name }}</span>
                <h1>دعوت اعضای سازمان</h1>
                <p class="muted">
                    دعوت با شماره موبایل ثبت می‌شود؛ عضویت و دسترسی گزارش پس از
                    پذیرش فرد فعال می‌شود.
                </p>
            </div>
        </section>
        <p class="muted">
            فرد می‌تواند پس از ثبت‌نام و تأیید همین شماره، از «دعوت سازمان‌ها»
            در پنل دانشجو پاسخ دهد. اعلان برای حساب‌های واجد شرایط درون سامانه
            ثبت می‌شود. حساب یا رمز عبور خودکار ساخته نمی‌شود.
        </p>
        <p v-if="sms_enabled" class="muted">
            با ثبت دعوت تازه یا تمدیدشده، درخواست پیامک وارد صف می‌شود. وضعیت
            ارسال را در کارت دعوت ببینید. ثبت در صف یا پذیرش سرویس پیامک، تحویل
            قطعی به گوشی نیست.
        </p>
        <p v-else class="muted">
            پیامک دعوت فعلاً غیرفعال است؛ دعوت در حساب دانشجو و اعلان داخلی قابل
            پیگیری است.
        </p>
        <div class="consultation-grid">
            <section class="panel glass consultation-card">
                <h2>دعوت یک نفر</h2>
                <form
                    class="stack-form"
                    @submit.prevent="
                        single.post('/organization/invitations', {
                            preserveScroll: true,
                            onSuccess: () => single.reset(),
                        })
                    "
                >
                    <label
                        >موبایل فرد<input
                            v-model="single.phone"
                            name="invite_phone"
                            type="tel"
                            dir="ltr"
                            maxlength="30"
                            required /></label
                    ><button class="btn primary" :disabled="single.processing">
                        ثبت دعوت هفت‌روزه
                    </button>
                </form>
            </section>
            <section class="panel glass consultation-card">
                <h2>دعوت گروهی با CSV</h2>
                <p class="muted">
                    فایل UTF-8، فقط یک ستون با عنوان phone و حداکثر صد ردیف
                    شماره موبایل. ابتدا پیش‌نمایش را بررسی کنید؛ فایل Excel را
                    پیش از بارگذاری به CSV تبدیل کنید.
                </p>
                <a href="/organization/invitations/template" class="text-link"
                    >دریافت فایل الگو</a
                >
                <form
                    class="stack-form consultation-section"
                    @submit.prevent="previewFile"
                >
                    <label
                        >فایل CSV (تا یک مگابایت)<input
                            type="file"
                            name="invitation_csv"
                            accept=".csv,text/csv"
                            required
                            @change="
                                upload.file = $event.target.files[0]
                            " /></label
                    ><button
                        class="btn secondary"
                        :disabled="upload.processing"
                    >
                        بررسی و پیش‌نمایش فایل
                    </button>
                </form>
            </section>
        </div>
        <section
            v-if="preview"
            class="panel glass consultation-card consultation-section invitation-import-preview"
        >
            <h2>پیش‌نمایش دعوت گروهی</h2>
            <p>
                شماره یکتا و معتبر: {{ fa(preview.valid_count) }} · نامعتبر:
                {{ fa(preview.invalid_count) }} · تکراری:
                {{ fa(preview.duplicate_count) }}
            </p>
            <p class="muted">
                اعتبار این پیش‌نمایش تا {{ dateTime(preview.expires_at) }} است.
                تأیید فقط برای همین مدیر و همین سازمان معتبر است. شماره عضو یا
                دعوت قبلی دوباره اضافه نمی‌شود؛ دعوت ردشده یا منقضی را از فهرست
                جداگانه تمدید کنید.
            </p>
            <details>
                <summary>
                    نمایش {{ fa(preview.rows.length) }} ردیف بررسی‌شده
                </summary>
                <ol>
                    <li
                        v-for="row in preview.rows"
                        :key="row.row"
                        class="assessment-editor-item"
                    >
                        <span
                            >ردیف {{ fa(row.row) }} ·
                            {{ rowLabels[row.status] }}</span
                        >
                        <p>
                            <bdi>{{ row.phone || row.value }}</bdi>
                        </p>
                    </li>
                </ol>
            </details>
            <p v-if="preview.committed_at" class="flash" role="status">
                تأیید شده؛ دعوت تازه: {{ fa(preview.result.created) }} · عضو یا
                دعوت قبلی: {{ fa(preview.result.skipped) }}
            </p>
            <p
                v-else-if="preview.invalid_count || preview.expired"
                class="field-error"
                role="alert"
            >
                فایل ردیف نامعتبر دارد یا پیش‌نمایش منقضی شده است. فایل را اصلاح
                و دوباره بارگذاری کنید؛ دعوتی ساخته نشده است.
            </p>
            <form
                v-else
                class="stack-form consultation-section"
                @submit.prevent="
                    commit.post(
                        `/organization/invitation-imports/${preview.id}/commit`,
                        { preserveScroll: true },
                    )
                "
            >
                <label
                    ><input
                        v-model="commit.reviewed"
                        type="checkbox"
                        required
                    />شماره‌ها و سازمان مقصد را بررسی کردم؛ فقط برای ردیف‌های
                    یکتا دعوت ساخته شود.</label
                ><button class="btn primary" :disabled="commit.processing">
                    تأیید و ساخت دعوت‌ها
                </button>
            </form>
        </section>
        <section class="consultation-section">
            <h2>سوابق دعوت</h2>
            <p v-if="!invitations.data.length" class="muted">
                دعوتی ثبت نشده است.
            </p>
            <div class="consultation-grid">
                <OrganizationInvitationCard
                    v-for="invitation in invitations.data"
                    :key="invitation.id"
                    :invitation="invitation"
                    manager
                />
            </div>
            <Pagination :links="invitations.links" />
        </section>
    </Shell>
</template>
