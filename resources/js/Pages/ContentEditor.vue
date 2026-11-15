<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { dateTime, fa } from "../dates";
const props = defineProps({ page: Object, publications: Object });
const draft = useForm({
    revision: props.page.revision,
    definition: JSON.parse(JSON.stringify(props.page.draft)),
});
const publish = useForm({ revision: props.page.revision });
const visibility = useForm({
    revision: props.page.revision,
    available: props.page.available,
});
function save() {
    draft.revision = props.page.revision;
    draft.patch("/admin/content/" + props.page.id + "/draft", {
        preserveScroll: true,
        onSuccess: () => draft.defaults(),
    });
}
function publishVersion() {
    publish.revision = props.page.revision;
    publish.post("/admin/content/" + props.page.id + "/publish", {
        preserveScroll: true,
    });
}
function toggle() {
    visibility.revision = props.page.revision;
    visibility.available = !props.page.available;
    visibility.post("/admin/content/" + props.page.id + "/availability", {
        preserveScroll: true,
    });
}
</script>
<template>
    <Head title="ویرایش محتوا" /><Shell title="ویرایش محتوا" role="admin"
        ><Link href="/admin/content" class="text-link">بازگشت به محتواها</Link>
        <section class="page-intro">
            <div>
                <h1>{{ page.draft.title }}</h1>
                <p>
                    <bdi>{{ page.public_path }}</bdi> · نسخه عمومی
                    {{ fa(page.current_version) }} ·
                    {{ page.available ? "نمایش فعال" : "نمایش بسته" }}
                </p>
            </div>
        </section>
        <section class="glass panel-card consultation-section">
            <h2>پیش‌نویس</h2>
            <form @submit.prevent="save" class="stack-form" data-content-draft>
                <label
                    >عنوان<input
                        v-model="draft.definition.title"
                        name="title"
                        required
                        maxlength="160"
                        :disabled="draft.processing" /></label
                ><label
                    >خلاصه<textarea
                        v-model="draft.definition.excerpt"
                        name="excerpt"
                        required
                        maxlength="320"
                        :disabled="draft.processing"
                    /></label
                ><label
                    >متن<textarea
                        v-model="draft.definition.body"
                        name="body"
                        required
                        rows="12"
                        maxlength="60000"
                        :disabled="draft.processing"
                    /></label
                ><label
                    >توضیح موتور جست‌وجو<input
                        v-model="draft.definition.meta_description"
                        name="meta_description"
                        maxlength="160"
                        :disabled="draft.processing"
                /></label>
                <p class="muted">
                    متن ساده نمایش داده می‌شود؛ کد HTML اجرا نمی‌شود. بین بندها
                    یک خط خالی بگذارید.
                </p>
                <p
                    v-for="error in draft.errors"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button
                    class="btn secondary"
                    :disabled="draft.processing || publish.processing"
                >
                    ذخیره پیش‌نویس
                </button>
            </form>
        </section>
        <section class="glass panel-card consultation-section">
            <h2>انتشار روی سایت</h2>
            <p>
                انتشار، نسخه‌ای ثابت از پیش‌نویس ذخیره‌شده می‌سازد و نمایش عمومی
                را فعال می‌کند.
            </p>
            <p v-if="draft.isDirty" class="muted">
                تغییرات فرم را پیش از انتشار ذخیره کنید.
            </p>
            <div class="action-row">
                <button
                    class="btn primary"
                    data-content-publish
                    :disabled="
                        draft.isDirty ||
                        draft.processing ||
                        publish.processing ||
                        visibility.processing
                    "
                    @click="publishVersion"
                >
                    انتشار نسخه ذخیره‌شده</button
                ><button
                    v-if="page.current_version"
                    class="btn secondary"
                    data-content-visibility
                    :disabled="
                        visibility.processing ||
                        publish.processing ||
                        draft.processing
                    "
                    @click="toggle"
                >
                    {{
                        page.available
                            ? "بستن نمایش عمومی"
                            : "نمایش دوباره نسخه منتشرشده"
                    }}</button
                ><a
                    v-if="page.available"
                    :href="page.public_path"
                    class="text-link"
                    target="_blank"
                    rel="noopener"
                    >مشاهده نسخه عمومی ←</a
                >
            </div>
            <p v-for="error in publish.errors" class="field-error" role="alert">
                {{ error }}
            </p>
            <p
                v-for="error in visibility.errors"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
        </section>
        <section class="glass panel-card consultation-section">
            <h2>نسخه‌های منتشرشده</h2>
            <p v-if="!publications.data.length" class="muted">
                نسخه‌ای منتشر نشده است.
            </p>
            <details
                v-for="publication in publications.data"
                :key="publication.id"
                class="consultation-section"
            >
                <summary>
                    نسخه {{ fa(publication.number) }} ·
                    {{ dateTime(publication.published_at) }}
                </summary>
                <h3>{{ publication.definition.title }}</h3>
                <p>{{ publication.definition.excerpt }}</p>
                <p style="white-space: pre-wrap; overflow-wrap: anywhere">
                    {{ publication.definition.body }}
                </p>
            </details>
            <Pagination :page="publications" /></section
    ></Shell>
</template>
