<script setup>
import { useForm } from "@inertiajs/vue3";
const props = defineProps({ certificate: Object });
const form = useForm({ revision: props.certificate.revision, reason: "" });
</script>
<template>
    <details v-if="certificate.status === 'issued'">
        <summary>ابطال گواهی</summary>
        <form
            class="form-stack"
            @submit.prevent="
                form.post('/admin/certificates/' + certificate.id + '/revoke', {
                    preserveScroll: true,
                })
            "
            :data-certificate-revoke="certificate.id"
        >
            <label
                >دلیل ابطال<textarea
                    v-model="form.reason"
                    minlength="3"
                    maxlength="2000"
                    required
                />
            </label>
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
            <button class="btn secondary" :disabled="form.processing">
                ثبت ابطال گواهی
            </button>
        </form>
    </details>
</template>
