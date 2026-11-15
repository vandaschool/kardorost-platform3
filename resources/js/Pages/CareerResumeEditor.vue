<script setup>
import { ref } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import ResumeEntries from "../Components/ResumeEntries.vue";
import { dateTime, fa } from "../dates";
const props = defineProps({ resume: Object, versions: Object, role: String });
const form = useForm({
    revision: props.resume.revision,
    title: props.resume.title,
    definition: JSON.parse(JSON.stringify(props.resume.draft)),
});
const snapshot = useForm({ revision: props.resume.revision });
const archive = useForm({
    revision: props.resume.revision,
    archived: props.resume.archived,
});
const skill = ref("");
function addSkill() {
    const value = skill.value.trim();
    if (
        value &&
        value.length <= 80 &&
        form.definition.skills.length < 50 &&
        !form.definition.skills.includes(value)
    ) {
        form.definition.skills.push(value);
        skill.value = "";
    }
}
function save() {
    form.revision = props.resume.revision;
    form.patch("/career/resumes/" + props.resume.id, {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}
function generate() {
    snapshot.revision = props.resume.revision;
    snapshot.post("/career/resumes/" + props.resume.id + "/snapshots");
}
function toggleArchive() {
    archive.revision = props.resume.revision;
    archive.archived = !props.resume.archived;
    archive.post("/career/resumes/" + props.resume.id + "/archive", {
        preserveScroll: true,
    });
}
</script>
<template>
    <Head title="ویرایش رزومه" /><Shell title="ویرایش رزومه" :role="role"
        ><Link class="text-link" href="/career/resumes">فهرست رزومه‌ها ←</Link>
        <section class="page-intro">
            <div>
                <h1>{{ resume.title }}</h1>
                <p>
                    {{ resume.owner_name }} ·
                    {{ resume.archived ? "بایگانی" : "پیش‌نویس خصوصی" }}
                </p>
            </div>
            <button
                class="btn secondary"
                :disabled="
                    archive.processing || form.processing || snapshot.processing
                "
                @click="toggleArchive"
            >
                {{ resume.archived ? "بازیابی رزومه" : "بایگانی رزومه" }}
            </button>
        </section>
        <p v-for="error in archive.errors" class="field-error" role="alert">
            {{ error }}
        </p>
        <section class="glass panel-card consultation-section">
            <h2>مشخصات رزومه</h2>
            <p class="muted">
                این اطلاعات را صاحب حساب و ادمین می‌بینند. تاریخ‌ها و محتوای
                سوابق را خودتان وارد کنید؛ زبان قالب، ترجمه خودکار متن را انجام
                نمی‌دهد.
            </p>
            <form class="stack-form" data-resume-draft @submit.prevent="save">
                <fieldset
                    :disabled="
                        resume.archived ||
                        form.processing ||
                        snapshot.processing
                    "
                    style="border: 0; padding: 0; min-width: 0"
                >
                    <label
                        >عنوان داخلی<input
                            v-model="form.title"
                            name="title"
                            required
                            maxlength="100" /></label
                    ><label
                        >زبان قالب<select
                            v-model="form.definition.locale"
                            name="locale"
                        >
                            <option value="fa">فارسی · راست‌چین</option>
                            <option value="en">English · Left to right</option>
                        </select></label
                    ><label
                        >نام در رزومه<input
                            v-model="form.definition.name"
                            name="resume_name"
                            required
                            maxlength="150" /></label
                    ><label
                        >عنوان حرفه‌ای<input
                            v-model="form.definition.headline"
                            name="headline"
                            maxlength="160"
                    /></label>
                    <div class="consultation-grid">
                        <label
                            >ایمیل<input
                                v-model="form.definition.email"
                                name="email"
                                type="email"
                                dir="ltr"
                                maxlength="200" /></label
                        ><label
                            >تلفن تماس<input
                                v-model="form.definition.phone"
                                name="resume_phone"
                                dir="ltr"
                                maxlength="50"
                        /></label>
                    </div>
                    <label
                        >شهر / محل فعالیت<input
                            v-model="form.definition.city"
                            name="city"
                            maxlength="150" /></label
                    ><label
                        >خلاصه حرفه‌ای<textarea
                            v-model="form.definition.summary"
                            name="summary"
                            rows="5"
                            maxlength="3000"
                        /></label
                    ><ResumeEntries
                        v-for="kind in ['experience', 'education', 'projects']"
                        :key="kind"
                        v-model="form.definition[kind]"
                        :kind="kind"
                        :disabled="resume.archived || form.processing"
                    />
                    <section class="consultation-section">
                        <h2>مهارت‌ها</h2>
                        <div class="action-row">
                            <input
                                v-model="skill"
                                name="skill"
                                maxlength="80"
                                aria-label="مهارت تازه"
                                @keydown.enter.prevent="addSkill"
                            /><button
                                type="button"
                                class="btn secondary"
                                data-add-skill
                                @click="addSkill"
                            >
                                افزودن مهارت
                            </button>
                        </div>
                        <div class="action-row">
                            <button
                                v-for="(item, index) in form.definition.skills"
                                :key="index"
                                type="button"
                                class="btn secondary"
                                @click="form.definition.skills.splice(index, 1)"
                            >
                                {{ item }} ×
                            </button>
                        </div>
                    </section>
                    <ResumeEntries
                        v-model="form.definition.links"
                        kind="links"
                        :disabled="resume.archived || form.processing"
                    />
                    <p
                        v-for="error in form.errors"
                        class="field-error"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                    <button class="btn primary" :disabled="form.processing">
                        ذخیره پیش‌نویس
                    </button>
                </fieldset>
            </form>
        </section>
        <section class="glass panel-card consultation-section">
            <h2>نسخه ثابت برای چاپ و بررسی</h2>
            <p>
                نسخه ثابت از پیش‌نویس ذخیره‌شده ساخته می‌شود. ویرایش بعدی آن را
                تغییر نمی‌دهد.
            </p>
            <p v-if="form.isDirty" class="muted">
                پیش از ساخت نسخه، تغییرات فرم را ذخیره کنید.
            </p>
            <button
                class="btn primary"
                data-resume-snapshot
                :disabled="
                    resume.archived ||
                    form.isDirty ||
                    form.processing ||
                    snapshot.processing
                "
                @click="generate"
            >
                ساخت / بازکردن نسخه ثابت
            </button>
            <p
                v-for="error in snapshot.errors"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
            <article
                v-for="version in versions.data"
                :key="version.id"
                class="consultation-section"
            >
                <h3>نسخه {{ fa(version.number) }}</h3>
                <p>{{ dateTime(version.created_at) }}</p>
                <Link
                    class="btn secondary"
                    :href="
                        '/career/resumes/' +
                        resume.id +
                        '/versions/' +
                        version.id
                    "
                    >مشاهده، چاپ و بررسی</Link
                >
            </article>
            <Pagination :page="versions" /></section
    ></Shell>
</template>
