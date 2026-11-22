<script setup>
import { onMounted, onBeforeUnmount, ref, watch } from "vue";
import {
    documentFromText,
    documentText,
    safeContentLink,
} from "../content-document";
const props = defineProps({ modelValue: Object, disabled: Boolean });
const emit = defineEmits(["update:modelValue"]);
const editor = ref(null);
const link = ref("");
const error = ref("");
let savedRange = null;
let last = "";
function remember() {
    const selection = window.getSelection();
    if (selection?.rangeCount && editor.value?.contains(selection.anchorNode))
        savedRange = selection.getRangeAt(0).cloneRange();
}
function collect(
    node,
    style = { bold: false, italic: false, href: null },
    result = [],
) {
    if (node.nodeType === Node.TEXT_NODE) {
        result.push({ text: node.textContent || "", ...style });
        return result;
    }
    if (
        node.nodeType !== Node.ELEMENT_NODE ||
        ["SCRIPT", "STYLE", "IFRAME", "OBJECT", "SVG", "MATH"].includes(
            node.tagName,
        )
    )
        return result;
    if (node.tagName === "BR") {
        result.push({ text: "\n", ...style });
        return result;
    }
    const current = {
        ...style,
        bold: style.bold || ["B", "STRONG"].includes(node.tagName),
        italic: style.italic || ["I", "EM"].includes(node.tagName),
        href:
            node.tagName === "A"
                ? safeContentLink(node.getAttribute("href"))
                : style.href,
    };
    if (
        ["P", "DIV", "LI"].includes(node.tagName) &&
        result.length &&
        !result.at(-1).text.endsWith("\n")
    )
        result.push({ text: "\n", ...style });
    for (const child of node.childNodes) collect(child, current, result);
    return result;
}
function changed() {
    const blocks = [];
    function block(node) {
        if (node.nodeType === Node.TEXT_NODE) {
            if (node.textContent?.trim())
                blocks.push({ type: "paragraph", children: collect(node) });
            return;
        }
        if (
            node.nodeType !== Node.ELEMENT_NODE ||
            ["SCRIPT", "STYLE", "IFRAME", "OBJECT", "SVG", "MATH"].includes(
                node.tagName,
            )
        )
            return;
        const tag = node.tagName;
        if (tag === "HR") blocks.push({ type: "divider" });
        else if (["UL", "OL"].includes(tag)) {
            const items = Array.from(node.children)
                .filter((n) => n.tagName === "LI")
                .map((n) => collect(n));
            if (items.length)
                blocks.push({
                    type: tag === "UL" ? "bullet_list" : "ordered_list",
                    items,
                });
        } else if (
            ["DIV", "P"].includes(tag) &&
            Array.from(node.children).some((n) =>
                [
                    "P",
                    "DIV",
                    "H2",
                    "H3",
                    "UL",
                    "OL",
                    "BLOCKQUOTE",
                    "HR",
                ].includes(n.tagName),
            )
        ) {
            for (const child of node.childNodes) block(child);
        } else if (["H2", "H3"].includes(tag))
            blocks.push({
                type: "heading",
                level: tag === "H2" ? 2 : 3,
                children: collect(node),
            });
        else
            blocks.push({
                type: tag === "BLOCKQUOTE" ? "quote" : "paragraph",
                children: collect(node),
            });
    }
    for (const child of editor.value.childNodes) block(child);
    const value = {
        version: 1,
        blocks: blocks.length ? blocks : [{ type: "paragraph", children: [] }],
    };
    if (documentText(value).length > 60000 || value.blocks.length > 100)
        error.value =
            "متن بیش از حد طولانی است؛ حداکثر ۶۰هزار نویسه و ۱۰۰ بخش.";
    else error.value = "";
    last = JSON.stringify(value);
    emit("update:modelValue", value);
    remember();
}
function appendRuns(target, runs) {
    for (const run of runs || []) {
        let item = document.createTextNode(
            typeof run.text === "string" ? run.text : "",
        );
        const href = safeContentLink(run.href);
        if (href) {
            const a = document.createElement("a");
            a.setAttribute("href", href);
            a.append(item);
            item = a;
        }
        if (run.italic) {
            const em = document.createElement("em");
            em.append(item);
            item = em;
        }
        if (run.bold) {
            const strong = document.createElement("strong");
            strong.append(item);
            item = strong;
        }
        target.append(item);
    }
}
function restore() {
    if (!editor.value || JSON.stringify(props.modelValue) === last) return;
    const value = props.modelValue || documentFromText("");
    const elements = [];
    for (const block of value.blocks || []) {
        const tag =
            block.type === "heading"
                ? block.level === 2
                    ? "h2"
                    : "h3"
                : {
                      paragraph: "p",
                      quote: "blockquote",
                      bullet_list: "ul",
                      ordered_list: "ol",
                      divider: "hr",
                  }[block.type] || "p";
        const node = document.createElement(tag);
        if (["ul", "ol"].includes(tag))
            for (const runs of block.items || []) {
                const li = document.createElement("li");
                appendRuns(li, runs);
                node.append(li);
            }
        else if (tag !== "hr") {
            appendRuns(node, block.children);
            if (!node.childNodes.length)
                node.append(document.createElement("br"));
        }
        elements.push(node);
    }
    editor.value.replaceChildren(...elements);
    last = JSON.stringify(value);
    savedRange = null;
}
function command(name, value = null) {
    if (props.disabled) return;
    editor.value.focus();
    const selection = window.getSelection();
    if (
        savedRange &&
        editor.value.contains(savedRange.commonAncestorContainer)
    ) {
        selection.removeAllRanges();
        selection.addRange(savedRange);
    }
    document.execCommand(name, false, value);
    changed();
}
function applyLink() {
    if (!savedRange || savedRange.collapsed) {
        error.value = "ابتدا متن لینک را انتخاب کنید.";
        return;
    }
    const href = safeContentLink(link.value);
    if (link.value.trim() && !href) {
        error.value = "نشانی وب معتبر یا مسیر داخلی وارد کنید.";
        return;
    }
    command(href ? "createLink" : "unlink", href);
    link.value = "";
}
function paste(event) {
    event.preventDefault();
    if (!props.disabled)
        command("insertText", event.clipboardData.getData("text/plain"));
}
function preventNavigation(event) {
    if (event.target.closest("a")) event.preventDefault();
}
onMounted(restore);
watch(() => props.modelValue, restore, { deep: true });
onBeforeUnmount(() => {
    savedRange = null;
});
</script>
<template>
    <section class="content-document-editor form-stack">
        <div class="learning-actions" role="toolbar" aria-label="قالب‌بندی متن">
            <button
                type="button"
                class="btn secondary"
                :disabled="disabled"
                @mousedown.prevent
                @click="command('bold')"
            >
                <strong>پررنگ</strong>
            </button>
            <button
                type="button"
                class="btn secondary"
                :disabled="disabled"
                @mousedown.prevent
                @click="command('italic')"
            >
                <em>تأکید</em>
            </button>
            <button
                type="button"
                class="btn secondary"
                :disabled="disabled"
                @mousedown.prevent
                @click="command('formatBlock', 'p')"
            >
                پاراگراف
            </button>
            <button
                type="button"
                class="btn secondary"
                :disabled="disabled"
                @mousedown.prevent
                @click="command('formatBlock', 'h2')"
            >
                تیتر
            </button>
            <button
                type="button"
                class="btn secondary"
                :disabled="disabled"
                @mousedown.prevent
                @click="command('formatBlock', 'h3')"
            >
                زیرتیتر
            </button>
            <button
                type="button"
                class="btn secondary"
                :disabled="disabled"
                @mousedown.prevent
                @click="command('formatBlock', 'blockquote')"
            >
                نقل‌قول
            </button>
            <button
                type="button"
                class="btn secondary"
                :disabled="disabled"
                @mousedown.prevent
                @click="command('insertUnorderedList')"
            >
                فهرست نشانه‌دار
            </button>
            <button
                type="button"
                class="btn secondary"
                :disabled="disabled"
                @mousedown.prevent
                @click="command('insertOrderedList')"
            >
                فهرست شماره‌دار
            </button>
            <button
                type="button"
                class="btn secondary"
                :disabled="disabled"
                @mousedown.prevent
                @click="command('insertHorizontalRule')"
            >
                جداکننده
            </button>
        </div>
        <div class="learning-actions">
            <label class="grow"
                >لینک برای متن انتخاب‌شده<input
                    v-model="link"
                    dir="ltr"
                    placeholder="https://example.com یا /catalog"
                    :disabled="disabled"
                    aria-label="نشانی لینک متن"
                    @keydown.enter.prevent="applyLink" /></label
            ><button
                type="button"
                class="btn secondary"
                :disabled="disabled"
                @mousedown.prevent
                @click="applyLink"
            >
                ثبت یا برداشتن لینک
            </button>
        </div>
        <div
            ref="editor"
            class="rich-content-input"
            :contenteditable="!disabled"
            role="textbox"
            aria-multiline="true"
            aria-label="متن قالب‌دار"
            data-content-document
            @input="changed"
            @mouseup="remember"
            @keyup="remember"
            @paste="paste"
            @click="preventNavigation"
        ></div>
        <p v-if="error" class="field-error" role="alert">{{ error }}</p>
        <p class="caption muted">
            فقط قالب متن و لینک ثبت می‌شود؛ متن چسبانده‌شده به صورت متن ساده
            وارد می‌شود. تغییرات تا ذخیره و انتشار روی سایت عمومی نمایش داده
            نمی‌شوند.
        </p>
    </section>
</template>
<style scoped>
.rich-content-input {
    min-height: 18rem;
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 1rem;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
    line-height: 2;
}
.rich-content-input:focus {
    outline: 2px solid var(--teal);
    outline-offset: 2px;
}
.rich-content-input :deep(ul),
.rich-content-input :deep(ol) {
    padding-inline-start: 1.6rem;
}
.rich-content-input :deep(blockquote) {
    border-inline-start: 3px solid var(--teal);
    margin-inline: 0;
    padding-inline-start: 1rem;
}
.rich-content-input :deep(a) {
    text-decoration: underline;
}
</style>
