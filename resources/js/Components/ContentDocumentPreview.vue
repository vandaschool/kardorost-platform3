<script>
import { h } from "vue";
import { safeContentLink } from "../content-document";
function runs(value) {
    return (Array.isArray(value) ? value : []).map((run) => {
        let node = typeof run.text === "string" ? run.text : "";
        const href = safeContentLink(run.href);
        if (href)
            node = h(
                "a",
                {
                    href,
                    target:
                        href.startsWith("/") || href.startsWith("#")
                            ? undefined
                            : "_blank",
                    rel: "noopener noreferrer",
                },
                [node],
            );
        if (run.italic) node = h("em", null, [node]);
        if (run.bold) node = h("strong", null, [node]);
        return node;
    });
}
export default {
    props: { document: Object },
    render() {
        return h(
            "div",
            {
                class: "content-document-preview",
                "data-content-document-preview": "",
            },
            (this.document?.blocks || []).map((block) => {
                if (["bullet_list", "ordered_list"].includes(block.type))
                    return h(
                        block.type === "bullet_list" ? "ul" : "ol",
                        null,
                        (block.items || []).map((item) =>
                            h("li", null, runs(item)),
                        ),
                    );
                if (block.type === "divider") return h("hr");
                const tag =
                    block.type === "heading"
                        ? block.level === 2
                            ? "h2"
                            : "h3"
                        : block.type === "quote"
                          ? "blockquote"
                          : "p";
                return h(tag, null, runs(block.children));
            }),
        );
    },
};
</script>
<style scoped>
.content-document-preview {
    overflow-wrap: anywhere;
    white-space: pre-wrap;
    line-height: 2;
}
.content-document-preview :deep(ul),
.content-document-preview :deep(ol) {
    padding-inline-start: 1.6rem;
}
.content-document-preview :deep(a) {
    text-decoration: underline;
}
.content-document-preview :deep(blockquote) {
    border-inline-start: 3px solid var(--teal);
    padding-inline-start: 1rem;
    margin-inline: 0;
}
</style>
