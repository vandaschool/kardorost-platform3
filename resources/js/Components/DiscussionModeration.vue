<script setup>
import { useForm } from "@inertiajs/vue3";
const props = defineProps({ discussion: Object });
const form = useForm({
    revision: props.discussion.revision,
    hidden: props.discussion.hidden,
    locked: props.discussion.locked,
    pinned: props.discussion.pinned,
    reason: "",
});
function open(event) {
    if (event.target.open) {
        form.revision = props.discussion.revision;
        form.hidden = props.discussion.hidden;
        form.locked = props.discussion.locked;
        form.pinned = props.discussion.pinned;
        form.reason = "";
        form.clearErrors();
    }
}
</script>
<template>
    <details class="glass panel-card" @toggle="open">
        <summary>مدیریت گفت‌وگو</summary>
        <form
            class="form-stack"
            data-discussion-moderate
            @submit.prevent="
                form.post('/discussions/' + discussion.id + '/moderate', {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.revision = discussion.revision;
                        form.reason = '';
                    },
                })
            "
        >
            <label class="check-label"
                ><input
                    v-model="form.locked"
                    name="discussion_locked"
                    type="checkbox"
                />ارسال و ویرایش پیام متوقف باشد</label
            ><label class="check-label"
                ><input
                    v-model="form.hidden"
                    name="discussion_hidden"
                    type="checkbox"
                />گفت‌وگو از اعضای کلاس پنهان شود</label
            ><label class="check-label"
                ><input
                    v-model="form.pinned"
                    name="discussion_pinned"
                    type="checkbox"
                />در ابتدای فهرست سنجاق شود</label
            ><label
                >دلیل مدیریت، فقط برای مدرس و ادمین<textarea
                    v-model="form.reason"
                    minlength="3"
                    maxlength="1000"
                    required
                />
            </label>
            <p v-if="discussion.moderation_reason" class="caption muted">
                آخرین دلیل: {{ discussion.moderation_reason }}
            </p>
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
            <button class="btn secondary" :disabled="form.processing">
                ثبت تنظیمات گفت‌وگو
            </button>
        </form>
    </details>
</template>
