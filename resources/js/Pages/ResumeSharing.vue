<script setup>
import { computed, watch } from "vue";
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import ResumeShareCard from "../Components/ResumeShareCard.vue";
import { dateTime, fa } from "../dates";
const props = defineProps({
    resume: Object,
    version: Object,
    shares: Object,
    own: Boolean,
    can_publish: Boolean,
    role: String,
    request_key: String,
    consent_text: String,
});
const page = usePage();
const base = `/career/resumes/${props.resume.id}/versions/${props.version.id}`;
const publish = useForm({
    request_key: props.request_key,
    revision: props.resume.revision,
    duration_days: 7,
    include_contacts: false,
    reviewed: false,
    consent: false,
});
watch(
    () => [publish.include_contacts, publish.duration_days],
    () => {
        publish.reviewed = false;
        publish.consent = false;
    },
);
const preview = computed(() =>
    props.version.definition
        ? {
              ...props.version.definition,
              ...(publish.include_contacts
                  ? {}
                  : { email: "", phone: "", links: [] }),
          }
        : null,
);
const labels = computed(() =>
    preview.value?.locale === "en"
        ? {
              experience: "Experience",
              education: "Education",
              projects: "Projects",
              summary: "Profile",
              skills: "Skills",
              links: "Links",
          }
        : {
              experience: "تجربه کاری",
              education: "تحصیلات",
              projects: "پروژه‌ها",
              summary: "درباره من",
              skills: "مهارت‌ها",
              links: "پیوندها",
          },
);
function create() {
    publish.post(base + "/shares", {
        preserveScroll: true,
        onSuccess: () => {
            publish.reset("reviewed", "consent");
            publish.request_key = page.props.request_key;
            publish.revision = page.props.resume.revision;
        },
    });
}
</script>
<template>
    <Head title="اشتراک اختیاری رزومه"
        ><meta name="robots" content="noindex,nofollow"
    /></Head>
    <Shell title="اشتراک اختیاری رزومه" :role="role">
        <Link :href="base" class="text-link">بازگشت به نسخه ثابت ←</Link>
        <section class="page-intro">
            <div>
                <span class="eyebrow">در اختیار خودتان</span>
                <h1>اشتراک نسخه {{ fa(version.number) }} رزومه</h1>
                <p>{{ dateTime(version.created_at) }}</p>
            </div>
        </section>
        <section class="glass panel-card consultation-section">
            <h2>چه کسی متن را می‌بیند؟</h2>
            <p>
                هرکس لینک را داشته باشد می‌تواند تا پایان مهلت یا قطع لینک، متن
                همین نسخه را بخواند، کپی یا ذخیره کند. نسخه‌های بعدی رزومه
                خودکار جایگزین آن نمی‌شوند.
            </p>
            <p class="muted">
                بایگانی رزومه لینک‌ها را قطع می‌کند؛ بازیابی، لینک قبلی را
                دوباره فعال نمی‌کند. قطع لینک یا پایان مهلت، نسخه ذخیره‌شده نزد
                گیرنده را حذف نمی‌کند.
            </p>
            <p v-if="!own" class="muted">
                انتشار با بازبینی و رضایت صاحب رزومه انجام می‌شود. ادمین دسترسی
                بررسی و قطع لینک‌های موجود را دارد.
            </p>
            <p v-if="resume.archived" class="muted">
                رزومه بایگانی است؛ ساخت لینک تازه پس از بازیابی و بازبینی دوباره
                ممکن است.
            </p>
        </section>
        <section
            v-if="preview"
            class="glass panel-card consultation-section share-preview-section"
        >
            <h2>پیش‌نمایش اطلاعات قابل نمایش</h2>
            <label class="checkbox-label"
                ><input
                    v-model="publish.include_contacts"
                    type="checkbox"
                    name="include_contacts"
                    :disabled="!can_publish"
                />
                تلفن، ایمیل و پیوندهای همین نسخه نیز نمایش داده شوند</label
            >
            <p class="muted">
                نام، شهر و متن سوابق در پیش‌نمایش زیر هستند. اطلاعات تماس یا
                اطلاعات شخصی داخل متن آزاد خودکار حذف نمی‌شوند؛ همه بخش‌ها را
                بازبینی کنید.
            </p>
            <div
                class="share-preview"
                data-resume-share-preview
                :dir="preview.locale === 'en' ? 'ltr' : 'rtl'"
            >
                <h2>{{ preview.name }}</h2>
                <p v-if="preview.headline">{{ preview.headline }}</p>
                <p v-if="preview.city">{{ preview.city }}</p>
                <p v-if="preview.email" dir="ltr" data-share-contact>
                    {{ preview.email }}
                </p>
                <p v-if="preview.phone" dir="ltr" data-share-contact>
                    {{ preview.phone }}
                </p>
                <section v-if="preview.summary">
                    <h3>{{ labels.summary }}</h3>
                    <p>{{ preview.summary }}</p>
                </section>
                <section
                    v-for="group in ['experience', 'education', 'projects']"
                    :key="group"
                >
                    <template v-if="preview[group].length"
                        ><h3>{{ labels[group] }}</h3>
                        <article
                            v-for="(item, index) in preview[group]"
                            :key="index"
                        >
                            <h4>
                                {{ item.title || item.degree
                                }}<template
                                    v-if="item.employer || item.institution"
                                >
                                    ·
                                    {{
                                        item.employer || item.institution
                                    }}</template
                                >
                            </h4>
                            <p v-if="item.period">{{ item.period }}</p>
                            <p v-if="item.description">
                                {{ item.description }}
                            </p>
                        </article></template
                    >
                </section>
                <section v-if="preview.skills.length">
                    <h3>{{ labels.skills }}</h3>
                    <p>{{ preview.skills.join(" · ") }}</p>
                </section>
                <section v-if="preview.links.length" data-share-contact>
                    <h3>{{ labels.links }}</h3>
                    <p v-for="(link, index) in preview.links" :key="index">
                        {{ link.label }} · <bdi>{{ link.url }}</bdi>
                    </p>
                </section>
            </div>
        </section>
        <p v-else class="field-error" role="alert">
            نسخه منبع قابل خواندن نیست. ساخت لینک تازه انجام نمی‌شود؛ لینک‌های
            موجود را می‌توانید قطع کنید.
        </p>
        <section
            v-if="can_publish"
            class="glass panel-card consultation-section"
        >
            <h2>ساخت لینک زمان‌دار</h2>
            <form
                class="stack-form"
                data-publish-resume-share
                @submit.prevent="create"
            >
                <label
                    >مهلت دسترسی<select
                        v-model.number="publish.duration_days"
                        name="duration_days"
                    >
                        <option :value="7">۷ روز</option>
                        <option :value="30">۳۰ روز</option>
                        <option :value="90">۹۰ روز</option>
                    </select></label
                >
                <label class="checkbox-label"
                    ><input
                        v-model="publish.reviewed"
                        type="checkbox"
                        name="share_reviewed"
                        required
                    />
                    پیش‌نمایش بالا و اطلاعات قابل انتشار را بازبینی کردم.</label
                >
                <label class="checkbox-label"
                    ><input
                        v-model="publish.consent"
                        type="checkbox"
                        name="share_consent"
                        required
                    />
                    {{ consent_text }}</label
                >
                <p
                    v-for="error in publish.errors"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button class="btn primary" :disabled="publish.processing">
                    ساخت لینک با این تنظیمات
                </button>
            </form>
            <p class="muted">
                هر نسخه یک لینک فعال دارد. تغییر مهلت یا اطلاعات تماس به قطع
                لینک قبلی و ساخت لینک تازه با رضایت دوباره نیاز دارد.
            </p>
        </section>
        <a :href="base + '/shares'" class="text-link"
            >تازه‌سازی و بازبینی دوباره</a
        >
        <section class="glass panel-card consultation-section">
            <h2>لینک‌های همین نسخه</h2>
            <p v-if="!shares.data.length" class="muted" data-no-resume-shares>
                هیچ لینکی برای این نسخه ساخته نشده است.
            </p>
            <ResumeShareCard
                v-for="share in shares.data"
                :key="share.id + ':' + share.revision"
                :share="share"
                :own="own"
            /><Pagination :page="shares" />
        </section>
    </Shell>
</template>
<style scoped>
.share-preview-section {
    min-width: 0;
    overflow-wrap: anywhere;
}
.share-preview {
    margin-top: 1rem;
    padding: 1rem;
    background: var(--surface, rgba(255, 255, 255, 0.45));
    border-radius: 1rem;
    min-width: 0;
    overflow-wrap: anywhere;
}
.share-preview p {
    white-space: pre-wrap;
}
.share-preview h4 {
    margin-bottom: 0.25rem;
}
.share-preview article {
    margin-bottom: 1rem;
}
.checkbox-label {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    line-height: 1.9;
}
.checkbox-label input {
    flex: 0 0 auto;
    margin-top: 0.4rem;
}
</style>
