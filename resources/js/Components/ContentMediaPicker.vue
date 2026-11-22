<script setup>
import ContentMediaThumbnail from "./ContentMediaThumbnail.vue";
import { onBeforeUnmount, ref } from "vue";
import { Link } from "@inertiajs/vue3";
const props = defineProps({ disabled: Boolean });
const emit = defineEmits(["select"]);
const open = ref(false),
    q = ref(""),
    items = ref([]),
    selected = ref(null),
    alt = ref(""),
    caption = ref(""),
    error = ref(""),
    loading = ref(false);
let controller = null;
async function search() {
    controller?.abort();
    const current = new AbortController();
    controller = current;
    loading.value = true;
    error.value = "";
    selected.value = null;
    try {
        const response = await fetch(
            "/admin/media/options?q=" + encodeURIComponent(q.value),
            {
                headers: { Accept: "application/json" },
                signal: current.signal,
            },
        );
        if (!response.ok) throw Error();
        items.value = (await response.json()).items;
    } catch (e) {
        if (e.name !== "AbortError")
            error.value = "تصویرها دریافت نشدند؛ دوباره جست‌وجو کنید.";
    } finally {
        if (controller === current) loading.value = false;
    }
}
function toggle() {
    open.value = !open.value;
    if (open.value) search();
}
function choose(item) {
    selected.value = item;
    alt.value = item.title;
}
function insert() {
    if (!selected.value || !alt.value.trim()) return;
    emit("select", {
        type: "image",
        media: selected.value.key,
        sha256: selected.value.sha256,
        alt: alt.value.trim(),
        caption: caption.value.trim(),
    });
    open.value = false;
    selected.value = null;
    caption.value = "";
}
onBeforeUnmount(() => controller?.abort());
</script>
<template>
    <div class="form-stack">
        <button
            type="button"
            class="btn secondary"
            :disabled="disabled"
            @click="toggle"
            data-media-picker-toggle
        >
            افزودن تصویر از کتابخانه
        </button>
        <section v-if="open" class="form-stack" aria-label="انتخاب تصویر محتوا">
            <p>
                فقط تصویرهای منتشرشده قابل انتخاب‌اند.
                <Link href="/admin/media" target="_blank">مدیریت تصویرها</Link>
            </p>
            <label
                >جست‌وجوی عنوان<input
                    v-model="q"
                    maxlength="80"
                    :disabled="loading || disabled"
                    @keydown.enter.prevent="search"
                    aria-label="جست‌وجوی تصویر"
            /></label>
            <button
                type="button"
                class="btn secondary"
                @click="search"
                :disabled="loading || disabled"
                data-media-search
            >
                جست‌وجو
            </button>
            <p v-if="loading" role="status">در حال دریافت تصویرها…</p>
            <p v-if="error" role="alert" class="field-error">{{ error }}</p>
            <p v-if="!loading && !items.length">تصویر منتشرشده‌ای پیدا نشد.</p>
            <div class="media-options">
                <button
                    v-for="item in items"
                    :key="item.key"
                    type="button"
                    class="media-option"
                    :aria-pressed="selected?.key === item.key"
                    @click="choose(item)"
                    :disabled="disabled"
                    :data-media-option="item.key"
                >
                    <ContentMediaThumbnail
                        :media-key="item.key"
                        alt=""
                        loading="lazy"
                    /><span>{{ item.title }}</span>
                </button>
            </div>
            <template v-if="selected"
                ><label
                    >شرح تصویر برای دسترس‌پذیری<input
                        v-model="alt"
                        maxlength="240"
                        :disabled="disabled"
                        aria-label="شرح تصویر" /></label
                ><label
                    >زیرنویس اختیاری<input
                        v-model="caption"
                        maxlength="320"
                        :disabled="disabled"
                        aria-label="زیرنویس تصویر" /></label
                ><button
                    type="button"
                    class="btn secondary"
                    :disabled="disabled || !alt.trim()"
                    @click="insert"
                    data-media-insert
                >
                    افزودن به متن
                </button></template
            >
            <p class="caption muted">
                تصویر به پایان متن افزوده می‌شود؛ تغییر محتوا را پیش از انتشار
                ذخیره کنید.
            </p>
        </section>
    </div>
</template>
<style scoped>
.media-options {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 140px), 1fr));
    gap: 0.75rem;
}
.media-option {
    padding: 0.5rem;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: transparent;
    color: inherit;
    overflow-wrap: anywhere;
}
.media-option[aria-pressed="true"] {
    border: 2px solid var(--teal);
}
.media-option img {
    display: block;
    width: 100%;
    height: 5rem;
    object-fit: contain;
}
</style>
