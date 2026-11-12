<script setup>
import { fa, dateTime, submissionStatuses } from "../dates";
defineProps({ reviews: { type: Array, default: () => [] } });
</script>
<template>
    <details v-if="reviews.length > 1" class="submission-history">
        <summary>
            بازخوردهای قبلی این پاسخ ({{ fa(reviews.length - 1) }})
        </summary>
        <div v-for="r in reviews.slice(1)" :key="r.id" class="history-item">
            <strong
                >{{ submissionStatuses[r.status] }} · بازخورد
                {{ fa(r.revision) }}</strong
            ><small class="muted"> · {{ dateTime(r.created_at) }}</small>
            <p v-if="r.score !== null" class="caption">
                نمره {{ fa(r.score) }}
            </p>
            <p v-if="r.feedback" class="lesson-text">{{ r.feedback }}</p>
        </div>
    </details>
</template>
