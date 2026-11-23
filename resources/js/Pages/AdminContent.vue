<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { dateTime } from "../dates";
import ContentCategory from "../Components/ContentCategory.vue";
import ContentByline from "../Components/ContentByline.vue";
import ContentTagsEditor from "../Components/ContentTagsEditor.vue";
const props = defineProps({ pages: Object });
const form = useForm({
    kind: "article",
    slug: "",
    definition: { title: "", excerpt: "", body: "", meta_description: "" },
});
const kinds = {
    article: "مقاله مجله",
    page: "صفحه مستقل",
    home: "محتوای صفحه اصلی",
};
function create() {
    if (form.kind === "home") form.slug = "home";
    form.post("/admin/content");
}
</script>
<template>
    <Head title="محتوای سایت" /><Shell title="محتوای سایت" role="admin"
        ><section class="page-intro">
            <div>
                <h1>محتوای سایت</h1>
                <p>پیش‌نویس خصوصی، انتشار نسخه ثابت و کنترل نمایش عمومی.</p>
            </div>
            <Link href="/admin/media" class="btn secondary">تصویرهای سایت</Link>
            <a href="/" class="btn secondary" target="_blank" rel="noopener"
                >دیدن سایت عمومی</a
            >
        </section>
        <details class="glass panel-card consultation-section">
            <summary>محتوای تازه</summary>
            <form
                @submit.prevent="create"
                class="stack-form"
                data-content-create
            >
                <label
                    >نوع محتوا<select v-model="form.kind" name="kind">
                        <option v-for="(label, key) in kinds" :value="key">
                            {{ label }}
                        </option>
                    </select></label
                ><label v-if="form.kind !== 'home'"
                    >نشانی ثابت<input
                        v-model="form.slug"
                        name="slug"
                        pattern="[a-z0-9]+(-[a-z0-9]+)*"
                        dir="ltr"
                        minlength="3"
                        maxlength="80"
                        required
                        placeholder="career-path"
                /></label>
                <p class="muted">
                    نشانی پس از ساخت ثابت است. برای صفحه اصلی از home استفاده
                    می‌شود.
                </p>
                <label
                    >عنوان<input
                        v-model="form.definition.title"
                        name="title"
                        required
                        maxlength="160" /></label
                ><label
                    >خلاصه<textarea
                        v-model="form.definition.excerpt"
                        name="excerpt"
                        required
                        maxlength="320"
                    /></label
                ><label
                    >متن<textarea
                        v-model="form.definition.body"
                        name="body"
                        required
                        rows="8"
                        maxlength="60000"
                    /></label
                ><label
                    >توضیح کوتاه برای موتور جست‌وجو<input
                        v-model="form.definition.meta_description"
                        name="meta_description"
                        maxlength="160"
                /></label>
                <ContentByline
                    v-if="form.kind === 'article'"
                    v-model="form.definition.byline"
                    editable
                    :disabled="form.processing"
                />
                <ContentCategory
                    v-if="form.kind === 'article'"
                    v-model="form.definition.category"
                    editable
                    :disabled="form.processing"
                />
                <ContentTagsEditor
                    v-if="form.kind === 'article'"
                    v-model="form.definition.tags"
                    :disabled="form.processing"
                />
                <p
                    v-for="error in form.errors"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button class="btn primary" :disabled="form.processing">
                    ساخت پیش‌نویس
                </button>
            </form>
        </details>
        <section class="glass panel-card consultation-section">
            <h2>محتواها</h2>
            <p v-if="!pages.data.length" class="muted">
                محتوایی ساخته نشده است.
            </p>
            <article
                v-for="page in pages.data"
                :key="page.id"
                class="consultation-section"
            >
                <h3>
                    <Link :href="'/admin/content/' + page.id">{{
                        page.title
                    }}</Link>
                </h3>
                <p>
                    {{ kinds[page.kind] }} ·
                    {{
                        page.available ? "نمایش عمومی فعال" : "نمایش عمومی بسته"
                    }}
                </p>
                <p v-if="page.scheduled_for">
                    انتشار برنامه‌ریزی‌شده: {{ dateTime(page.scheduled_for) }}
                </p>
                <bdi>{{ page.public_path }}</bdi>
                <div class="action-row">
                    <Link
                        :href="'/admin/content/' + page.id"
                        class="btn secondary"
                        >ویرایش و انتشار</Link
                    ><a
                        v-if="page.available && page.current_version"
                        :href="page.public_path"
                        class="text-link"
                        target="_blank"
                        rel="noopener"
                        >نسخه عمومی ←</a
                    >
                </div>
            </article>
            <Pagination :page="pages" /></section
    ></Shell>
</template>
