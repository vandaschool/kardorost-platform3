<script setup>
defineProps({
    modelValue: { type: String, default: "" },
    editable: Boolean,
    disabled: Boolean,
});
const emit = defineEmits(["update:modelValue"]);
</script>
<template>
    <label v-if="editable" data-content-byline-editor
        >نام نویسنده (اختیاری)
        <input
            name="content_byline"
            :value="modelValue"
            maxlength="80"
            :disabled="disabled"
            @input="emit('update:modelValue', $event.target.value)"
        />
        <span class="caption muted"
            >نامی تا هشتاد نویسه که پس از انتشار همین نسخه، کنار مقاله نمایش
            داده می‌شود.</span
        >
    </label>
    <p
        v-if="typeof modelValue === 'string' && modelValue !== ''"
        class="caption"
        data-content-byline-preview
    >
        نویسنده: <bdi dir="auto">{{ modelValue }}</bdi>
    </p>
</template>
