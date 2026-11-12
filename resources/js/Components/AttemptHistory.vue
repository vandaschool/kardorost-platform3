<script setup>
import Icon from "./Icon.vue";
import FeedbackHistory from "./FeedbackHistory.vue";
import { fa, dateTime, submissionStatuses } from "../dates";
defineProps({ attempts: { type: Array, default: () => [] } });
</script>
<template>
    <details v-if="attempts.length" class="submission-history">
        <summary>پاسخ‌ها و بازخوردهای قبلی ({{ fa(attempts.length) }})</summary>
        <article v-for="a in attempts" :key="a.id" class="history-item">
            <strong
                >نسخه {{ fa(a.version) }} ·
                {{ submissionStatuses[a.status] }}</strong
            >
            <p class="caption muted">{{ dateTime(a.created_at) }}</p>
            <p v-if="a.body" class="lesson-text">{{ a.body }}</p>
            <a v-if="a.download_url" :href="a.download_url" class="text-link"
                ><Icon name="Download" :size="15" />{{ a.file_name }}</a
            >
            <p v-if="a.score !== null" class="caption">
                نمره {{ fa(a.score) }}
            </p>
            <p v-if="a.feedback" class="lesson-text muted">
                بازخورد: {{ a.feedback }}
            </p>
            <FeedbackHistory :reviews="a.reviews || []" />
        </article>
    </details>
</template>
