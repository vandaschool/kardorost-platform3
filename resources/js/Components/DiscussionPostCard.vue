<script setup>
import { ref } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import DiscussionPostAttachments from "./DiscussionPostAttachments.vue";
import { dateTime, fa } from "../dates";
const props = defineProps({
    post: Object,
    discussion: Object,
    staff: Boolean,
    attachmentConsent: String,
});
const editing = ref(false);
const edit = useForm({
    revision: props.post.revision,
    body: props.post.body || "",
});
const moderation = useForm({
    revision: props.post.revision,
    hidden: !props.post.hidden,
    reason: "",
});
const answer = useForm({
    revision: props.discussion.revision,
    post_id: props.post.id,
});
const roles = {
    admin: "ارسال در نقش مدیریت",
    coach: "ارسال در نقش مدرس",
    student: "دانشجو",
};
function startEdit() {
    edit.revision = props.post.revision;
    edit.body = props.post.body;
    edit.clearErrors();
    editing.value = true;
}
function moderateOpen(event) {
    if (event.target.open) {
        moderation.revision = props.post.revision;
        moderation.hidden = !props.post.hidden;
        moderation.reason = "";
        moderation.clearErrors();
    }
}
function selectAnswer() {
    answer.revision = props.discussion.revision;
    answer.post_id =
        props.discussion.accepted_post_id === props.post.id
            ? null
            : props.post.id;
    answer.post("/discussions/" + props.discussion.id + "/answer", {
        preserveScroll: true,
    });
}
</script>
<template>
    <article
        :id="'post-' + post.id"
        class="glass panel-card consultation-section form-stack"
        :data-discussion-post="post.id"
    >
        <div class="section-heading">
            <p v-if="post.author_name">
                <strong>{{ post.author_name }}</strong> ·
                {{ roles[post.author_role] }}
            </p>
            <span
                v-if="discussion.accepted_post_id === post.id"
                class="badge teal"
                >پاسخ منتخب</span
            ><span v-if="post.hidden" class="badge gold">پیام پنهان‌شده</span>
        </div>
        <small class="muted"
            >{{ dateTime(post.created_at) }}
            <span v-if="post.edited"
                >· به‌روزشده، نسخه {{ fa(post.revision) }}</span
            ></small
        >
        <p v-if="post.hidden && !staff" class="muted">
            این پیام توسط مدرس یا مدیریت پنهان شده است.
        </p>
        <p v-else-if="!editing" class="lesson-text discussion-text">
            {{ post.body }}
        </p>
        <DiscussionPostAttachments
            v-if="!post.hidden || staff"
            :post="post"
            :discussion="discussion"
            :consent="attachmentConsent"
        />
        <form
            v-if="editing"
            class="form-stack"
            :data-post-edit="post.id"
            @submit.prevent="
                edit.patch(
                    '/discussions/' + discussion.id + '/posts/' + post.id,
                    {
                        preserveScroll: true,
                        onSuccess: () => (editing = false),
                    },
                )
            "
        >
            <label
                >متن تازه<textarea
                    v-model="edit.body"
                    rows="5"
                    maxlength="12000"
                    required
                />
            </label>
            <p
                v-for="(error, key) in edit.errors"
                :key="key"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
            <div class="learning-actions">
                <button class="btn primary" :disabled="edit.processing">
                    ذخیره ویرایش</button
                ><button
                    type="button"
                    class="btn secondary"
                    @click="editing = false"
                >
                    انصراف
                </button>
            </div>
        </form>
        <div class="learning-actions">
            <button
                v-if="post.can_edit && !editing"
                class="text-link"
                @click="startEdit"
            >
                ویرایش پیام من</button
            ><button
                v-if="discussion.can_accept && !post.is_first && !post.hidden"
                class="text-link"
                :disabled="answer.processing"
                @click="selectAnswer"
            >
                {{
                    discussion.accepted_post_id === post.id
                        ? "برداشتن انتخاب پاسخ"
                        : "انتخاب این پاسخ"
                }}</button
            ><Link
                v-if="staff"
                :href="
                    '/discussions/' +
                    discussion.id +
                    '/posts/' +
                    post.id +
                    '/history'
                "
                class="text-link"
                >سابقه نسخه‌های پیام</Link
            >
        </div>
        <p
            v-for="(error, key) in answer.errors"
            :key="key"
            class="field-error"
            role="alert"
        >
            {{ error }}
        </p>
        <details v-if="staff" @toggle="moderateOpen">
            <summary>مدیریت نمایش پیام</summary>
            <form
                class="form-stack"
                :data-post-moderate="post.id"
                @submit.prevent="
                    moderation.post(
                        '/discussions/' +
                            discussion.id +
                            '/posts/' +
                            post.id +
                            '/moderate',
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                moderation.revision = post.revision;
                                moderation.hidden = !post.hidden;
                                moderation.reason = '';
                            },
                        },
                    )
                "
            >
                <p>
                    {{
                        post.hidden
                            ? "پیام دوباره برای اعضای کلاس نمایش داده شود."
                            : "متن پیام از اعضای کلاس پنهان شود؛ سابقه آن حفظ می‌شود."
                    }}
                </p>
                <label
                    >دلیل، فقط برای مدرس و ادمین<textarea
                        v-model="moderation.reason"
                        minlength="3"
                        maxlength="1000"
                        required
                    />
                </label>
                <p v-if="post.moderation_reason" class="caption muted">
                    آخرین دلیل: {{ post.moderation_reason }}
                </p>
                <p
                    v-for="(error, key) in moderation.errors"
                    :key="key"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button class="btn secondary" :disabled="moderation.processing">
                    {{ post.hidden ? "نمایش دوباره پیام" : "پنهان‌سازی پیام" }}
                </button>
            </form>
        </details>
    </article>
</template>
