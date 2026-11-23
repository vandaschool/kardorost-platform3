<script setup>
import { fa } from "../dates";
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    disabled: Boolean,
});
const emit = defineEmits(["update:modelValue"]);
function update(index, value) {
    emit(
        "update:modelValue",
        props.modelValue.map((tag, i) => (i === index ? value : tag)),
    );
}
function add() {
    if (props.modelValue.length < 8)
        emit("update:modelValue", [...props.modelValue, ""]);
}
function remove(index) {
    emit(
        "update:modelValue",
        props.modelValue.filter((_, i) => i !== index),
    );
}
</script>
<template>
    <fieldset :disabled="disabled" data-content-tags-editor>
        <legend>برچسب‌های مقاله (اختیاری)</legend>
        <p class="caption muted">
            تا هشت برچسب متفاوت، هرکدام حداکثر چهل نویسه. برچسب‌های پیش‌نویس پس
            از انتشار همان نسخه در مجله دیده می‌شوند.
        </p>
        <div
            v-for="(tag, index) in modelValue"
            :key="index"
            class="content-tag-row"
            :data-content-tag-row="index"
        >
            <label
                >برچسب {{ fa(index + 1)
                }}<input
                    :name="`content_tag_${index}`"
                    :value="tag"
                    maxlength="40"
                    required
                    @input="update(index, $event.target.value)"
            /></label>
            <button
                type="button"
                class="btn secondary"
                :data-remove-content-tag="index"
                @click="remove(index)"
            >
                حذف برچسب
            </button>
        </div>
        <button
            type="button"
            class="btn secondary"
            data-add-content-tag
            :disabled="modelValue.length >= 8"
            @click="add"
        >
            افزودن برچسب
        </button>
    </fieldset>
</template>
<style scoped>
.content-tag-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
    align-items: end;
    margin-block: 0.8rem;
}
label {
    flex: 1 1 180px;
    min-width: 0;
}
</style>
