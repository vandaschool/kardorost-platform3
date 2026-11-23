<script setup>
import { fa } from "../dates";
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    disabled: Boolean,
});
const emit = defineEmits(["update:modelValue"]);
function patch(index, key, value) {
    emit(
        "update:modelValue",
        props.modelValue.map((link, i) =>
            i === index ? { ...link, [key]: value } : { ...link },
        ),
    );
}
function add() {
    if (props.modelValue.length < 5)
        emit("update:modelValue", [
            ...props.modelValue,
            { label: "", url: "" },
        ]);
}
function remove(index) {
    emit(
        "update:modelValue",
        props.modelValue.filter((_, i) => i !== index),
    );
}
</script>
<template>
    <fieldset :disabled="disabled" data-coach-links-editor>
        <legend>پیوندهای عمومی (اختیاری)</legend>
        <p class="caption muted">
            تا پنج پیوند از وب‌سایت یا صفحه‌های حرفه‌ای خودتان وارد کنید.
            پیوندهای همین نسخه همراه اطلاعات پروفایل برای رضایت و بررسی ارسال
            می‌شوند.
        </p>
        <div
            v-for="(link, index) in modelValue"
            :key="index"
            class="coach-link-row"
            :data-coach-link-row="index"
        >
            <h3>پیوند {{ fa(index + 1) }}</h3>
            <label
                >عنوان پیوند<input
                    :name="`coach_link_label_${index}`"
                    :value="link.label"
                    minlength="3"
                    maxlength="80"
                    required
                    @input="patch(index, 'label', $event.target.value)"
            /></label>
            <label
                >نشانی کامل وب<input
                    :name="`coach_link_url_${index}`"
                    :value="link.url"
                    type="url"
                    dir="ltr"
                    maxlength="2048"
                    placeholder="https://example.com"
                    required
                    @input="patch(index, 'url', $event.target.value)"
            /></label>
            <button
                type="button"
                class="btn secondary"
                :data-remove-coach-link="index"
                @click="remove(index)"
            >
                حذف این پیوند
            </button>
        </div>
        <button
            type="button"
            class="btn secondary"
            :disabled="modelValue.length >= 5"
            data-add-coach-link
            @click="add"
        >
            افزودن پیوند
        </button>
    </fieldset>
</template>
<style scoped>
.coach-link-row {
    padding-block: 1rem;
    display: grid;
    gap: 0.75rem;
    border-bottom: 1px solid rgba(12, 31, 62, 0.12);
    margin-bottom: 1rem;
    min-width: 0;
}
.coach-link-row h3 {
    font-size: 1rem;
    margin: 0;
}
.coach-link-row button {
    justify-self: start;
}
input {
    min-width: 0;
}
</style>
