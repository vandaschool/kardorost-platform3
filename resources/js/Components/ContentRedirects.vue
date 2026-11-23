<script setup>
import { useForm } from "@inertiajs/vue3";
import { fa } from "../dates";
const props = defineProps({
    page: Object,
    redirects: { type: Array, default: () => [] },
});
const adding = useForm({ from_slug: "" });
const changing = useForm({ revision: 0, enabled: false });
function add() {
    adding.post(`/admin/content/${props.page.id}/redirects`, {
        preserveScroll: true,
        onSuccess: () => adding.reset(),
    });
}
function toggle(item) {
    changing.revision = item.revision;
    changing.enabled = !item.enabled;
    changing.post(`/admin/content/${props.page.id}/redirects/${item.id}`, {
        preserveScroll: true,
    });
}
</script>
<template>
    <section
        v-if="['article', 'page'].includes(page.kind)"
        class="glass panel-card consultation-section"
    >
        <details data-content-redirects>
            <summary>نشانی‌های قبلی این محتوا</summary>
            <p class="caption muted">
                نشانی قبلی به نسخهٔ عمومی همین محتوا منتقل می‌شود. با بستن نمایش
                محتوا، انتقال هم متوقف می‌شود. نشانی اصلیِ هر محتوا همیشه اولویت
                دارد. مقصد بیرونی و انتقال زنجیره‌ای ثبت نمی‌شود.
            </p>
            <form
                class="stack-form"
                data-content-redirect-create
                @submit.prevent="add"
            >
                <label
                    >بخش پایانی نشانی قبلی
                    <input
                        name="from_slug"
                        v-model="adding.from_slug"
                        dir="ltr"
                        required
                        minlength="3"
                        maxlength="80"
                        pattern="[a-z0-9]+(-[a-z0-9]+)*"
                        placeholder="previous-article"
                        :disabled="adding.processing || changing.processing"
                    />
                </label>
                <p class="caption muted">
                    فقط حروف کوچک انگلیسی، عدد و خط تیره؛ بدون دامنه یا مسیر.
                    مقصد: <bdi>{{ page.public_path }}</bdi>
                </p>
                <p
                    v-for="(message, key) in adding.errors"
                    :key="key"
                    class="error-text"
                    role="alert"
                >
                    {{ message }}
                </p>
                <button
                    class="btn primary"
                    :disabled="
                        adding.processing ||
                        changing.processing ||
                        !page.available ||
                        !page.current_version ||
                        redirects.length >= 25
                    "
                >
                    ثبت انتقال موقت
                </button>
                <p
                    v-if="!page.available || !page.current_version"
                    class="caption muted"
                >
                    ابتدا محتوا را منتشر کنید و نمایش عمومی را فعال نگه دارید.
                </p>
                <p class="caption muted">
                    {{ fa(redirects.length) }} از {{ fa(25) }} نشانی در سابقه؛
                    خاموش‌کردن، سابقه را حذف نمی‌کند.
                </p>
            </form>
            <p
                v-for="(message, key) in changing.errors"
                :key="key"
                class="error-text"
                role="alert"
            >
                {{ message }}
            </p>
            <div
                v-for="item in redirects"
                :key="item.id"
                class="redirect-row"
                :data-content-redirect="item.id"
            >
                <div>
                    <bdi class="redirect-path">{{ item.source_path }}</bdi>
                    <p class="caption muted">
                        {{ item.enabled ? "انتقال روشن" : "انتقال خاموش" }}
                    </p>
                </div>
                <button
                    type="button"
                    class="btn secondary"
                    :data-toggle-content-redirect="item.id"
                    :disabled="adding.processing || changing.processing"
                    @click="toggle(item)"
                >
                    {{
                        item.enabled ? "خاموش‌کردن انتقال" : "روشن‌کردن انتقال"
                    }}
                </button>
            </div>
        </details>
    </section>
</template>
<style scoped>
.redirect-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-block: 1rem;
}
.redirect-path {
    overflow-wrap: anywhere;
}
.redirect-row > div {
    min-width: 0;
    flex: 1 1 180px;
}
</style>
