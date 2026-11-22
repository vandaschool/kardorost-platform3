<script setup>
import { reactive } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import { fa } from "../dates";
const props = defineProps({
    post: Object,
    discussion: Object,
    consent: String,
});
const upload = useForm({
    revision: props.post.revision,
    request_key: crypto.randomUUID(),
    sharing_policy: "active_cohort_members_v1",
    confirmed: false,
    file: null,
});
const withdrawal = useForm({ revision: 1, reason: null });
const reasons = reactive({});
function send() {
    upload.revision = props.post.revision;
    upload.post(
        `/discussions/${props.discussion.id}/posts/${props.post.id}/attachments`,
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                upload.reset("file", "confirmed");
                upload.request_key = crypto.randomUUID();
            },
        },
    );
}
function withdraw(file) {
    withdrawal.revision = file.revision;
    withdrawal.reason = reasons[file.id] || null;
    withdrawal.post(`/discussion-attachments/${file.id}/withdraw`, {
        preserveScroll: true,
    });
}
</script>
<template>
    <section
        v-if="post.attachments?.length || post.can_edit"
        class="form-stack consultation-section"
        :data-post-attachments="post.id"
    >
        <h3>ضمیمه‌های پیام</h3>
        <p class="muted caption">
            دانلود فقط برای اعضای مجاز همین نوبت، مدرس فعلی و ادمین است.
        </p>
        <div
            v-for="file in post.attachments || []"
            :key="file.id"
            class="form-stack"
            :data-discussion-attachment="file.id"
        >
            <a
                v-if="file.file_url"
                :href="file.file_url"
                class="text-link"
                style="overflow-wrap: anywhere"
                >دریافت {{ file.file_name }} ·
                {{ fa(Math.ceil(file.size / 1024)) }} کیلوبایت</a
            >
            <span v-else class="muted">فایل در دسترس نیست.</span>
            <details v-if="file.can_withdraw">
                <summary>برداشتن این ضمیمه</summary>
                <form @submit.prevent="withdraw(file)" class="form-stack">
                    <p class="caption muted">
                        دریافت فایل متوقف می‌شود؛ سابقه پیام حفظ می‌شود.
                    </p>
                    <label v-if="!file.is_owner"
                        >دلیل رسیدگی<input
                            v-model="reasons[file.id]"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="error in withdrawal.errors"
                        class="field-error"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                    <button
                        class="btn secondary"
                        :disabled="withdrawal.processing"
                    >
                        برداشتن ضمیمه
                    </button>
                </form>
            </details>
        </div>
        <details v-if="post.can_edit">
            <summary>افزودن ضمیمه</summary>
            <form
                class="form-stack"
                @submit.prevent="send"
                :data-attachment-upload="post.id"
            >
                <label
                    >فایل PDF، تصویر PNG/JPEG یا متن ساده<input
                        :key="upload.request_key"
                        type="file"
                        accept=".pdf,.png,.jpg,.jpeg,.txt"
                        required
                        @change="upload.file = $event.target.files[0] || null"
                /></label>
                <p class="caption muted">
                    حداکثر ۵ مگابایت؛ سه ضمیمه فعال و ده بارگذاری در سابقه هر
                    پیام.
                </p>
                <label class="check-label"
                    ><input
                        v-model="upload.confirmed"
                        type="checkbox"
                        required
                    />{{ consent }}</label
                >
                <p
                    v-for="error in upload.errors"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button
                    class="btn secondary"
                    :disabled="
                        upload.processing || !upload.file || !upload.confirmed
                    "
                >
                    ثبت ضمیمه
                </button>
            </form>
        </details>
        <Link href="/discussion-attachments" class="text-link"
            >مدیریت ضمیمه‌های من</Link
        >
    </section>
</template>
