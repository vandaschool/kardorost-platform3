<script setup>
import ContentMediaThumbnail from "./ContentMediaThumbnail.vue";
import { useForm } from "@inertiajs/vue3";
import { fa, dateTime } from "../dates";
const props = defineProps({ media: Object });
const publish = useForm({ revision: props.media.revision, reviewed: false });
const withdraw = useForm({ revision: props.media.revision });
function publishImage() {
    publish.revision = props.media.revision;
    publish.post(`/admin/media/${props.media.id}/publish`, {
        preserveScroll: true,
        onSuccess: () => publish.reset("reviewed"),
    });
}
function withdrawImage() {
    withdraw.revision = props.media.revision;
    withdraw.post(`/admin/media/${props.media.id}/withdraw`, {
        preserveScroll: true,
    });
}
</script>
<template>
    <article
        class="glass panel-card consultation-section"
        :data-media-card="media.id"
    >
        <h2>{{ media.metadata?.title || "تصویر با اطلاعات نامعتبر" }}</h2>
        <p>
            {{
                media.withdrawn_at
                    ? "برداشته‌شده"
                    : media.published_at
                      ? "منتشرشده"
                      : "خصوصی؛ منتشر نشده"
            }}
            · {{ dateTime(media.created_at) }}
        </p>
        <template v-if="media.metadata">
            <ContentMediaThumbnail
                v-if="!media.withdrawn_at"
                :media-key="media.public_key"
                :alt="media.metadata.title"
                class="media-preview"
                loading="lazy"
            />
            <p class="caption">
                {{ media.metadata.file_name }} ·
                {{ fa(media.metadata.width) }}×{{
                    fa(media.metadata.height)
                }}
                پیکسل · {{ fa(Math.ceil(media.metadata.size / 1024)) }} کیلوبایت
            </p>
            <a
                v-if="media.published_at && !media.withdrawn_at"
                class="text-link"
                :href="`/media/${media.public_key}/${media.metadata.sha256}`"
                target="_blank"
                rel="noopener"
                data-public-media
                >دیدن تصویر عمومی</a
            >
        </template>
        <form
            v-if="!media.published_at && !media.withdrawn_at && media.metadata"
            @submit.prevent="publishImage"
            class="form-stack"
            data-media-publish
        >
            <label class="check-label"
                ><input
                    type="checkbox"
                    v-model="publish.reviewed"
                    :disabled="publish.processing"
                    required
                />همین تصویر را برای نمایش عمومی در سایت بررسی و تأیید
                کرده‌ام.</label
            >
            <p v-for="error in publish.errors" class="field-error" role="alert">
                {{ error }}
            </p>
            <button
                class="btn primary"
                :disabled="publish.processing || !publish.reviewed"
            >
                انتشار عمومی تصویر
            </button>
        </form>
        <form
            v-if="!media.withdrawn_at"
            @submit.prevent="withdrawImage"
            class="form-stack"
            data-media-withdraw
        >
            <p class="caption muted">
                برداشتن، دریافت تازه و نمایش تصویر در محتوا را متوقف می‌کند.
                فایل و سابقه حفظ می‌شوند؛ کپی‌های قبلاً دریافت‌شده از دستگاه
                دیگران پاک نمی‌شوند.
            </p>
            <p
                v-for="error in withdraw.errors"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
            <button
                class="btn secondary"
                :disabled="withdraw.processing || publish.processing"
            >
                برداشتن تصویر
            </button>
        </form>
    </article>
</template>
<style scoped>
.media-preview {
    display: block;
    max-width: 100%;
    max-height: 16rem;
    object-fit: contain;
    border-radius: 12px;
    margin-block: 1rem;
}
p {
    overflow-wrap: anywhere;
}
</style>

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
