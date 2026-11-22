<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import DiscussionPostCard from "../Components/DiscussionPostCard.vue";
import DiscussionModeration from "../Components/DiscussionModeration.vue";
const props = defineProps({
    discussion: Object,
    cohort: Object,
    course: Object,
    posts: Object,
    staff: Boolean,
    attachment_consent: String,
    role: String,
});
const reply = useForm({
    body: "",
    request_key: crypto.randomUUID(),
});
function send() {
    reply.post("/discussions/" + props.discussion.id + "/posts", {
        preserveScroll: true,
        onSuccess: () => {
            reply.reset("body");
            reply.request_key = crypto.randomUUID();
        },
    });
}
</script>
<template>
    <Head :title="discussion.title" /><Shell title="گفت‌وگوی دوره" :role="role"
        ><Link
            :href="'/cohorts/' + cohort.id + '/discussions'"
            class="text-link"
            >همه گفت‌وگوهای {{ cohort.title }}</Link
        >
        <section class="page-intro">
            <div>
                <p class="eyebrow">{{ course.title }}</p>
                <h1>{{ discussion.title }}</h1>
                <p>آغازکننده: {{ discussion.author_name }}</p>
                <div class="learning-actions">
                    <span v-if="discussion.hidden" class="badge gold"
                        >پنهان‌شده</span
                    ><span v-if="discussion.locked" class="badge gold"
                        >بسته</span
                    ><span v-if="discussion.pinned" class="badge"
                        >سنجاق‌شده</span
                    ><Link
                        v-if="discussion.accepted_post_id"
                        :href="
                            '/discussions/' +
                            discussion.id +
                            '/posts/' +
                            discussion.accepted_post_id +
                            '/locate'
                        "
                        class="text-link"
                        >رفتن به پاسخ منتخب</Link
                    >
                </div>
            </div>
        </section>
        <DiscussionModeration v-if="staff" :discussion="discussion" />
        <p v-if="discussion.hidden && !staff" class="muted">
            گفت‌وگوی شما برای رسیدگی از نمایش عمومی کلاس خارج شده است.
        </p>
        <DiscussionPostCard
            v-for="post in posts.data"
            :key="post.id"
            :post="post"
            :discussion="discussion"
            :staff="staff"
            :attachment-consent="attachment_consent"
        /><Pagination :links="posts.links" />
        <section
            v-if="discussion.can_reply"
            class="glass panel-card consultation-section"
        >
            <h2>پاسخ تازه</h2>
            <form
                class="form-stack"
                data-discussion-reply
                @submit.prevent="send"
            >
                <label
                    >متن پاسخ<textarea
                        v-model="reply.body"
                        name="reply_body"
                        rows="5"
                        maxlength="12000"
                        required
                    />
                </label>
                <p class="caption muted">
                    نام و پاسخ شما برای اعضای فعال این نوبت، مدرس و مدیریت دیده
                    می‌شود.
                </p>
                <p
                    v-for="(error, key) in reply.errors"
                    :key="key"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button class="btn primary" :disabled="reply.processing">
                    ارسال پاسخ
                </button>
            </form>
        </section>
        <p v-else class="muted consultation-section">
            ارسال پیام تازه در این گفت‌وگو متوقف است.
        </p></Shell
    >
</template>
<style>
.discussion-text {
    overflow-wrap: anywhere;
}
[data-discussion-post] {
    scroll-margin-top: 96px;
}
[data-discussion-post]:target {
    outline: 2px solid var(--teal);
}
</style>
