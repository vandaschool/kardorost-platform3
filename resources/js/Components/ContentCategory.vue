<script setup>
defineProps({
    modelValue: { type: String, default: "" },
    editable: Boolean,
    disabled: Boolean,
});
const emit = defineEmits(["update:modelValue"]);
</script>
<template>
    <label v-if="editable" data-content-category-editor
        >دستهٔ مقاله (اختیاری)
        <input
            name="content_category"
            :value="modelValue"
            maxlength="40"
            :disabled="disabled"
            @input="emit('update:modelValue', $event.target.value)"
        />
        <span class="caption muted"
            >یک دسته تا چهل نویسه؛ فقط پس از انتشار همین نسخه در مجله دیده
            می‌شود.</span
        >
    </label>
    <p
        v-if="typeof modelValue === 'string' && modelValue !== ''"
        class="caption"
        data-content-category-preview
    >
        دسته: {{ modelValue }}
    </p>
</template>
