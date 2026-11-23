<script setup>
import { ref, onBeforeUnmount } from "vue";
import { useForm } from "@inertiajs/vue3";
const props = defineProps({ consent: String });
const form = useForm({
    title: "",
    file: null,
    consent: false,
    request_key: crypto.randomUUID(),
});
const input = ref(null),
    preview = ref(""),
    error = ref(""),
    preparing = ref(false);
let generation = 0;
function clearPreview() {
    if (preview.value) URL.revokeObjectURL(preview.value);
    preview.value = "";
}
async function choose(event) {
    const current = ++generation;
    form.file = null;
    preparing.value = false;
    error.value = "";
    clearPreview();
    const file = event.target.files[0];
    if (!file) return;
    if (
        !["image/png", "image/jpeg"].includes(file.type) ||
        file.size > 8 * 1024 * 1024
    ) {
        error.value = "عکس PNG یا JPEG تا ۸ مگابایت انتخاب کنید.";
        return;
    }
    preparing.value = true;
    let raw = "";
    try {
        const magic = new Uint8Array(await file.slice(0, 8).arrayBuffer());
        if (
            !(
                magic[0] === 137 &&
                magic[1] === 80 &&
                magic[2] === 78 &&
                magic[3] === 71
            ) &&
            !(magic[0] === 255 && magic[1] === 216 && magic[2] === 255)
        )
            throw Error();
        raw = URL.createObjectURL(file);
        const image = new Image();
        image.decoding = "async";
        await new Promise((resolve, reject) => {
            image.onload = resolve;
            image.onerror = reject;
            image.src = raw;
        });
        const w = image.naturalWidth,
            h = image.naturalHeight;
        if (!w || !h || Math.max(w, h) > 6000 || w * h > 12000000)
            throw Error();
        const scale = Math.min(1, 768 / w, 768 / h);
        const canvas = document.createElement("canvas");
        canvas.width = Math.max(1, Math.round(w * scale));
        canvas.height = Math.max(1, Math.round(h * scale));
        const context = canvas.getContext("2d");
        if (!context) throw Error();
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((resolve) =>
            canvas.toBlob(resolve, "image/png"),
        );
        if (!blob || blob.size > 4 * 1024 * 1024) throw Error();
        if (current !== generation) return;
        form.file = new File([blob], "profile-photo.png", {
            type: "image/png",
        });
        preview.value = URL.createObjectURL(blob);
    } catch {
        if (current === generation)
            error.value =
                "آماده‌سازی عکس انجام نشد؛ تصویر سالم تا ۶۰۰۰ پیکسل در هر ضلع و ۱۲ میلیون پیکسل کل انتخاب کنید.";
    } finally {
        if (raw) URL.revokeObjectURL(raw);
        if (current === generation) preparing.value = false;
    }
}
function upload() {
    form.post("/coach/profile-photos", {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            generation++;
            form.reset();
            form.request_key = crypto.randomUUID();
            clearPreview();
            if (input.value) input.value.value = "";
        },
    });
}
onBeforeUnmount(() => {
    generation++;
    clearPreview();
});
</script>
<template>
    <form class="form-stack" @submit.prevent="upload" data-coach-photo-upload>
        <label
            >نام خصوصی عکس<input
                name="photo_title"
                v-model="form.title"
                maxlength="160"
                required
                :disabled="form.processing"
        /></label>
        <label
            >انتخاب عکس<input
                ref="input"
                name="photo_file"
                type="file"
                accept="image/png,image/jpeg"
                required
                :disabled="form.processing || preparing"
                @change="choose"
        /></label>
        <p class="caption muted">
            نسخهٔ مناسب پروفایل کوچک‌سازی می‌شود و داده‌های اضافی فایل در نسخهٔ
            ثبت‌شده نگه داشته نمی‌شوند. اصل عکس را نزد خودتان نگه دارید. سقف هر
            حساب ۲۰ عکس در سابقه است.
        </p>
        <p v-if="preparing" role="status">
            در حال آماده‌سازی عکس روی دستگاه شما…
        </p>
        <p v-if="error" role="alert" class="field-error">{{ error }}</p>
        <img
            v-if="preview"
            :src="preview"
            alt="پیش‌نمایش نسخه آمادهٔ عکس"
            class="prepared-photo"
            data-prepared-coach-photo
        />
        <label class="check-label"
            ><input
                name="photo_consent"
                type="checkbox"
                v-model="form.consent"
                required
                :disabled="form.processing || preparing"
            />{{ consent }}</label
        >
        <p v-for="message in form.errors" class="field-error" role="alert">
            {{ message }}
        </p>
        <p v-if="form.progress" role="status">
            بارگذاری: {{ form.progress.percentage }}٪
        </p>
        <button
            class="btn primary"
            :disabled="
                form.processing || preparing || !form.file || !form.consent
            "
        >
            ثبت خصوصی نسخهٔ آماده
        </button>
    </form>
</template>
<style scoped>
.prepared-photo {
    display: block;
    max-width: 100%;
    max-height: 16rem;
    border-radius: 16px;
}
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
