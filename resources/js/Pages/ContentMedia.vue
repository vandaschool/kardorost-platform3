<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import ContentMediaCard from "../Components/ContentMediaCard.vue";
const props = defineProps({ files: Object, rights: String });
const input = ref(null);
const form = useForm({
    title: "",
    file: null,
    rights_confirmed: false,
    request_key: crypto.randomUUID(),
});
function upload() {
    form.post("/admin/media", {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.request_key = crypto.randomUUID();
            if (input.value) input.value.value = "";
        },
    });
}
</script>
<template>
    <Head title="تصویرهای سایت" /><Shell title="تصویرهای سایت" role="admin">
        <section class="page-intro">
            <div>
                <h1>تصویرهای سایت</h1>
                <p>
                    ثبت خصوصی، تأیید انتشار عمومی و استفاده در متن مقاله‌ها و
                    صفحه‌ها.
                </p>
            </div>
            <Link href="/admin/content" class="btn secondary">محتوای سایت</Link>
        </section>
        <details class="glass panel-card consultation-section">
            <summary>تصویر تازه</summary>
            <form class="form-stack" @submit.prevent="upload" data-media-upload>
                <label
                    >عنوان تصویر<input
                        name="title"
                        v-model="form.title"
                        maxlength="160"
                        required
                        :disabled="form.processing"
                /></label>
                <label
                    >فایل تصویر<input
                        ref="input"
                        name="file"
                        type="file"
                        accept="image/png,image/jpeg"
                        required
                        :disabled="form.processing"
                        @change="form.file = $event.target.files[0] || null"
                /></label>
                <p class="caption muted">
                    PNG یا JPEG تا ۴ مگابایت؛ هر ضلع حداکثر ۶۰۰۰ و مجموع حداکثر
                    ۱۲ میلیون پیکسل. سقف هر حساب ۵۰۰ تصویر در سابقه است. فایل
                    اصلی ثبت می‌شود؛ برش و حذف اطلاعات درون فایل انجام نمی‌شود.
                </p>
                <label class="check-label"
                    ><input
                        type="checkbox"
                        name="rights_confirmed"
                        v-model="form.rights_confirmed"
                        required
                        :disabled="form.processing"
                    />{{ rights }}</label
                >
                <p
                    v-for="error in form.errors"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <p v-if="form.progress" role="status">
                    بارگذاری: {{ form.progress.percentage }}٪
                </p>
                <button
                    class="btn primary"
                    :disabled="
                        form.processing || !form.file || !form.rights_confirmed
                    "
                >
                    ثبت تصویر خصوصی
                </button>
            </form>
        </details>
        <p v-if="!files.data.length" class="glass panel-card">
            هنوز تصویری ثبت نشده است.
        </p>
        <ContentMediaCard
            v-for="media in files.data"
            :key="media.id"
            :media="media"
        /><Pagination :page="files" />
    </Shell>
</template>

<style scoped>
.check-label {
    align-items: flex-start;
    gap: 0.65rem;
}
.check-label input {
    flex: 0 0 18px;
    padding: 0;
    margin-top: 0.3rem;
}
</style>
