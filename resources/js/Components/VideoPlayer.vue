<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue";

const props = defineProps({
    url: String,
    kind: String,
    title: String,
    resumeAt: { type: Number, default: 0 },
    identity: [String, Number],
});
const emit = defineEmits(["position"]);
const video = ref(null);
const error = ref("");
let hls = null;
let lastSaved = -1;
let ready = false;
let alive = true;
function report(force = false) {
    if (!ready || !props.identity || !video.value) return;
    const seconds = Math.floor(video.value.currentTime);
    if (force || Math.abs(seconds - lastSaved) >= 10) {
        lastSaved = seconds;
        emit("position", { id: props.identity, seconds });
    }
}
function resume() {
    const v = video.value;
    if (
        props.resumeAt > 0 &&
        Number.isFinite(v.duration) &&
        props.resumeAt < v.duration
    ) {
        v.currentTime = props.resumeAt;
    }
    ready = true;
}
onMounted(async () => {
    const v = video.value;
    if (!v || props.kind === "embed") return;
    let Hls = null;
    if (props.kind === "hls") {
        try {
            Hls = (await import("hls.js")).default;
        } catch {
            error.value =
                "بارگذاری پخش‌کننده انجام نشد. صفحه را دوباره باز کنید.";
            return;
        }
        if (!alive) return;
    }
    if (props.kind === "hls" && Hls.isSupported()) {
        hls = new Hls();
        hls.loadSource(props.url);
        hls.attachMedia(v);
        hls.on(Hls.Events.ERROR, (_event, data) => {
            if (data.fatal)
                error.value =
                    "پخش ویدیو متوقف شد. اتصال را بررسی و صفحه را دوباره باز کنید.";
        });
    } else if (
        props.kind === "hls" &&
        !v.canPlayType("application/vnd.apple.mpegurl")
    ) {
        error.value =
            "مرورگر شما این شیوه پخش را پشتیبانی نمی‌کند. از مرورگر به‌روز استفاده کنید.";
    } else {
        v.src = props.url;
    }
});
onBeforeUnmount(() => {
    alive = false;
    hls?.destroy();
});
</script>
<template>
    <div class="learning-player">
        <iframe
            v-if="kind === 'embed'"
            :src="url"
            :title="title || 'ویدیوی آموزشی'"
            allow="fullscreen; picture-in-picture"
            allowfullscreen
            sandbox="allow-scripts allow-same-origin allow-presentation"
            referrerpolicy="strict-origin-when-cross-origin"
        ></iframe
        ><video
            v-else
            ref="video"
            controls
            playsinline
            preload="metadata"
            controlslist="nodownload"
            :aria-label="title"
            @loadedmetadata="resume"
            @timeupdate="report(false)"
            @pause="report(true)"
            @ended="report(true)"
            @error="
                error = 'اتصال به ویدیو برقرار نشد. کمی بعد دوباره تلاش کنید.'
            "
        ></video>
        <p v-if="error" class="player-error" role="alert">{{ error }}</p>
    </div>
</template>
