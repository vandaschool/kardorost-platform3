<script setup>
import { computed, ref, watch } from "vue";
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import CoachProfileDefinition from "../Components/CoachProfileDefinition.vue";
import CoachProfileLinksEditor from "../Components/CoachProfileLinksEditor.vue";
import CoachProfileHistory from "../Components/CoachProfileHistory.vue";
const props = defineProps({
    profile: Object,
    can_edit: Boolean,
    history: Object,
    photo_options: Array,
});
const page = usePage();
const draft = props.profile?.draft;
const form = useForm({
    revision: props.profile?.revision || 0,
    definition: {
        display_name: draft?.display_name || page.props.auth.user.name,
        headline: draft?.headline || "",
        bio: draft?.bio || "",
        city: draft?.city || "",
        focus_areas: draft?.focus_areas || [""],
        languages: draft?.languages || [""],
        links: (draft?.links || []).map((link) => ({ ...link })),
        ...(draft?.photo ? { photo: { ...draft.photo } } : {}),
    },
});
const refreshingPhotos = ref(false);
function refreshPhotos() {
    router.reload({
        only: ["photo_options", "can_edit"],
        preserveState: true,
        preserveScroll: true,
        onStart: () => (refreshingPhotos.value = true),
        onFinish: () => (refreshingPhotos.value = false),
    });
}
const photoKey = ref(draft?.photo?.key || "");
const photos = computed(() =>
    (props.photo_options || []).filter((p) => p.metadata && !p.withdrawn_at),
);
function choosePhoto() {
    const photo = photos.value.find((p) => p.public_key === photoKey.value);
    if (photo)
        form.definition.photo = {
            key: photo.public_key,
            sha256: photo.metadata.sha256,
        };
    else if (!photoKey.value) delete form.definition.photo;
}
const saved = ref(JSON.stringify(form.definition));
const dirty = computed(() => saved.value !== JSON.stringify(form.definition));
const submit = useForm({
    revision: props.profile?.revision,
    public_consent: false,
});
const withdraw = useForm({ revision: props.profile?.revision, reason: "" });
const labels = {
    draft: "پیش‌نویس",
    submitted: "در انتظار بررسی مدیریت",
    approved: "نسخه بررسی‌شده منتشر شده",
    rejected: "نیازمند اصلاح",
};
watch(
    () => props.profile?.revision,
    (revision) => {
        form.revision = revision;
        submit.revision = revision;
        submit.public_consent = false;
        withdraw.revision = revision;
    },
);
function save() {
    const submitted = JSON.stringify(form.definition);
    form.post("/coach/profile", {
        preserveScroll: true,
        onSuccess: () => (saved.value = submitted),
    });
}
</script>
<template>
    <Head title="پروفایل حرفه‌ای مربی" /><Shell
        title="پروفایل حرفه‌ای مربی"
        :role="page.props.activeRole"
        ><section class="page-intro">
            <div>
                <h1>پروفایل حرفه‌ای مربی</h1>
                <p class="muted">
                    اطلاعات را خودتان وارد کنید؛ همان نسخه پس از رضایت شما و
                    بررسی مدیریت عمومی می‌شود.
                </p>
            </div>
            <a
                v-if="profile?.public_enabled && can_edit"
                :href="profile.public_url"
                class="btn secondary"
                >مشاهده نسخه عمومی</a
            >
        </section>
        <p v-if="profile" class="muted">
            وضعیت پیش‌نویس: {{ labels[profile.state] }}
        </p>
        <p v-if="!can_edit" class="muted">
            برای ویرایش و ارسال تازه، نقش مربی فعال با موبایل تأییدشده لازم است.
            سوابق و کنترل بستن نمایش متعلق به حساب شماست.
        </p>
        <form
            v-if="can_edit"
            class="panel glass consultation-card stack-form"
            data-coach-profile-editor
            @submit.prevent="save"
        >
            <label
                >نام نمایشی عمومی<input
                    v-model="form.definition.display_name"
                    name="coach_display_name"
                    required
                    minlength="3"
                    maxlength="160" /></label
            ><label
                >عنوان حرفه‌ای<input
                    v-model="form.definition.headline"
                    name="coach_headline"
                    required
                    minlength="3"
                    maxlength="160" /></label
            ><label
                >معرفی<textarea
                    v-model="form.definition.bio"
                    name="coach_bio"
                    required
                    minlength="20"
                    maxlength="6000"
                ></textarea></label
            ><label
                >شهر (اختیاری)<input
                    v-model="form.definition.city"
                    name="coach_city"
                    maxlength="100"
            /></label>
            <label
                >عکس این نسخه (اختیاری)<select
                    v-model="photoKey"
                    @change="choosePhoto"
                    name="coach_photo"
                >
                    <option value="">بدون عکس</option>
                    <option
                        v-if="
                            photoKey &&
                            !photos.some((p) => p.public_key === photoKey)
                        "
                        :value="photoKey"
                        disabled
                    >
                        عکس قبلی در دسترس نیست؛ دوباره انتخاب کنید.
                    </option>
                    <option
                        v-for="photo in photos"
                        :key="photo.public_key"
                        :value="photo.public_key"
                    >
                        {{ photo.metadata.title }}
                    </option>
                </select></label
            >
            <p class="caption muted">
                فقط عکس‌های حساب خودتان قابل انتخاب‌اند؛ همان انتخاب همراه متن
                این نسخه برای رضایت و بررسی ارسال می‌شود.
                <Link href="/coach/profile-photos" target="_blank"
                    >ثبت و مدیریت عکس‌های من</Link
                >
            </p>
            <button
                type="button"
                class="btn secondary"
                :disabled="refreshingPhotos || form.processing"
                @click="refreshPhotos"
                data-refresh-photo-options
            >
                به‌روزرسانی فهرست عکس‌ها
            </button>
            <fieldset>
                <legend>زمینه‌های فعالیت</legend>
                <label
                    v-for="(area, index) in form.definition.focus_areas"
                    :key="index"
                    >زمینه {{ index + 1
                    }}<input
                        v-model="form.definition.focus_areas[index]"
                        :name="`coach_focus_${index}`"
                        required
                        minlength="3"
                        maxlength="60"
                    /><button
                        v-if="form.definition.focus_areas.length > 1"
                        type="button"
                        class="text-link"
                        @click="form.definition.focus_areas.splice(index, 1)"
                    >
                        حذف
                    </button></label
                ><button
                    type="button"
                    class="btn secondary"
                    :disabled="form.definition.focus_areas.length >= 12"
                    @click="form.definition.focus_areas.push('')"
                >
                    افزودن زمینه
                </button>
            </fieldset>
            <fieldset>
                <legend>زبان‌ها</legend>
                <label
                    v-for="(language, index) in form.definition.languages"
                    :key="index"
                    >زبان {{ index + 1
                    }}<input
                        v-model="form.definition.languages[index]"
                        :name="`coach_language_${index}`"
                        required
                        minlength="2"
                        maxlength="40"
                    /><button
                        v-if="form.definition.languages.length > 1"
                        type="button"
                        class="text-link"
                        @click="form.definition.languages.splice(index, 1)"
                    >
                        حذف
                    </button></label
                ><button
                    type="button"
                    class="btn secondary"
                    :disabled="form.definition.languages.length >= 5"
                    @click="form.definition.languages.push('')"
                >
                    افزودن زبان
                </button>
            </fieldset>
            <CoachProfileLinksEditor
                v-model="form.definition.links"
                :disabled="form.processing"
            />
            <p class="muted">
                نام، معرفی، شهر، زمینه‌ها، زبان‌ها و پیوندهای انتخاب‌شده عمومی
                می‌شوند. محتوای نسخه عمومی قبلی با ذخیره پیش‌نویس عوض نمی‌شود.
            </p>
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
            <button class="btn primary" :disabled="form.processing">
                ذخیره پیش‌نویس
            </button>
        </form>
        <form
            v-if="
                can_edit &&
                profile &&
                ['draft', 'rejected'].includes(profile.state)
            "
            class="panel glass consultation-card stack-form consultation-section"
            data-coach-profile-submit
            @submit.prevent="
                submit.post('/coach/profile/submit', { preserveScroll: true })
            "
        >
            <p v-if="dirty">برای ارسال، ابتدا پیش‌نویس را ذخیره کنید.</p>
            <label
                ><input
                    type="checkbox"
                    name="coach_public_consent"
                    v-model="submit.public_consent"
                    required
                />با نمایش عمومی نام، اطلاعات، عکس و پیوندهای انتخاب‌شدهٔ همین
                نسخه ذخیره‌شده پس از بررسی مدیریت موافقم.</label
            >
            <p
                v-for="(error, key) in submit.errors"
                :key="key"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
            <button class="btn primary" :disabled="submit.processing || dirty">
                ارسال همین نسخه برای بررسی
            </button>
        </form>
        <section
            v-if="profile?.submission"
            class="panel glass consultation-card consultation-section"
            data-coach-submission
        >
            <h2>آخرین نسخه ارسال‌شده</h2>
            <CoachProfileDefinition
                :definition="profile.submission.definition"
            />
            <p v-if="profile.submission.note" class="preserve-lines">
                توضیح مدیریت: {{ profile.submission.note }}
            </p>
        </section>
        <form
            v-if="
                profile &&
                (profile.public_enabled || profile.state === 'submitted')
            "
            class="panel glass consultation-card stack-form consultation-section"
            data-coach-profile-withdraw
            @submit.prevent="
                withdraw.post('/coach/profile/withdraw', {
                    preserveScroll: true,
                })
            "
        >
            <h2>بستن نمایش عمومی و بررسی جاری</h2>
            <label
                >توضیح اختیاری<textarea
                    name="coach_withdraw_reason"
                    v-model="withdraw.reason"
                    maxlength="2000"
                ></textarea>
            </label>
            <p class="muted">
                نسخه‌ها و رضایت تاریخی در حساب شما می‌مانند. انتشار دوباره به
                رضایت تازه و بررسی مدیریت نیاز دارد.
            </p>
            <button class="btn secondary" :disabled="withdraw.processing">
                بستن نمایش عمومی و بررسی
            </button>
        </form>
        <section
            v-if="profile?.published"
            class="panel glass consultation-card consultation-section"
        >
            <h2>آخرین نسخه منتشرشده</h2>
            <CoachProfileDefinition
                :definition="profile.published.definition"
            />
        </section>
        <CoachProfileHistory :history="history"
    /></Shell>
</template>
