export function documentFromText(text = "") {
    return {
        version: 1,
        blocks: String(text)
            .replace(/\r\n?/g, "\n")
            .split(/\n\s*\n/)
            .map((text) => ({
                type: "paragraph",
                children: [{ text, bold: false, italic: false, href: null }],
            })),
    };
}
export function documentText(document) {
    return (document?.blocks || [])
        .flatMap((block) =>
            block.type === "image"
                ? [block.alt + (block.caption ? "\n" + block.caption : "")]
                : block.items
                  ? [
                        block.items
                            .map((runs) => runs.map((run) => run.text).join(""))
                            .join("\n"),
                    ]
                  : block.children
                    ? [block.children.map((run) => run.text).join("")]
                    : [],
        )
        .join("\n\n")
        .trim();
}
export function safeContentLink(value) {
    if (typeof value !== "string") return null;
    const href = value.trim();
    if (!href || href.length > 2048 || /[\p{C}\s<>"`\\]/u.test(href))
        return null;
    if (href.startsWith("#"))
        return /^#[a-zA-Z0-9_-]{1,100}$/.test(href) ? href : null;
    if (href.startsWith("/") && !href.startsWith("//")) return href;
    try {
        const url = new URL(href);
        return ["http:", "https:"].includes(url.protocol) &&
            url.hostname &&
            !url.username &&
            !url.password
            ? href
            : null;
    } catch {
        return null;
    }
}
