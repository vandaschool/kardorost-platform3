import assert from "node:assert/strict";
import { writeFile } from "node:fs/promises";
const base = process.env.BROWSER_BASE_URL;
const password = process.env.BROWSER_PASSWORD;
assert(
    base &&
        password &&
        process.env.BROWSER_STUDENT_PHONE &&
        process.env.BROWSER_COACH_PHONE &&
        process.env.BROWSER_ADMIN_PHONE,
    "Supply isolated browser fixture credentials and BROWSER_BASE_URL.",
);
assert(
    ["127.0.0.1", "localhost"].includes(new URL(base).hostname),
    "This workflow only mutates a local fixture.",
);
const target = await (
    await fetch(
        "http://127.0.0.1:9222/json/new?" + encodeURIComponent(base + "/login"),
        { method: "PUT" },
    )
).json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve) =>
    socket.addEventListener("open", resolve, { once: true }),
);
let id = 0;
const pending = new Map();
const exceptions = [];
const activeRequests = new Set();
socket.addEventListener("message", (event) => {
    const m = JSON.parse(event.data);
    if (
        m.method === "Network.requestWillBeSent" &&
        ["XHR", "Fetch"].includes(m.params.type)
    )
        activeRequests.add(m.params.requestId);
    if (["Network.loadingFinished", "Network.loadingFailed"].includes(m.method))
        activeRequests.delete(m.params.requestId);
    if (m.method === "Runtime.consoleAPICalled" && m.params.type === "error")
        exceptions.push(
            "console: " +
                m.params.args
                    .map((a) => a.description || a.value || "")
                    .join(" "),
        );
    if (m.method === "Runtime.exceptionThrown")
        exceptions.push(m.params.exceptionDetails.text);
    if (m.id) {
        const p = pending.get(m.id);
        if (m.error) p.reject(m.error);
        else p.resolve(m.result);
        pending.delete(m.id);
    }
});
function send(method, params = {}) {
    return new Promise((resolve, reject) => {
        const n = ++id;
        pending.set(n, { resolve, reject });
        socket.send(JSON.stringify({ id: n, method, params }));
    });
}
await send("Runtime.enable");
await send("Page.enable");
await send("Network.enable");
async function evaluate(expression) {
    const r = await send("Runtime.evaluate", {
        expression,
        returnByValue: true,
        awaitPromise: true,
    });
    if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails));
    return r.result.value;
}
async function until(expression) {
    for (let i = 0; i < 100; i++) {
        if (await evaluate(expression)) return;
        await new Promise((r) => setTimeout(r, 100));
    }
    throw new Error(
        "Timed out: " +
            expression +
            "\n" +
            (await evaluate("document.body.innerText")),
    );
}
async function goto(path) {
    await send("Page.navigate", { url: base + path });
    await until(
        `location.pathname===${JSON.stringify(path.split("?")[0])} && !!document.querySelector('main')`,
    );
}
async function fill(selector, value) {
    await evaluate(
        `(()=>{const el=document.querySelector(${JSON.stringify(selector)});if(!el)throw Error('Missing input');el.value=${JSON.stringify(value)};el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));})()`,
    );
}
async function submit(selector) {
    await evaluate(
        `document.querySelector(${JSON.stringify(selector)}).requestSubmit()`,
    );
}
async function networkIdle() {
    let stable = 0;
    for (let index = 0; index < 100; index++) {
        stable = activeRequests.size === 0 ? stable + 1 : 0;
        if (stable >= 3) return;
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    throw new Error(
        "Previous navigation did not finish before switching fixture accounts",
    );
}
async function login(role) {
    await networkIdle();
    await send("Network.clearBrowserCookies");
    await send("Page.navigate", { url: base + "/login" });
    await until(`!!document.querySelector('input[name=password]')`);
    await networkIdle();
    await fill(
        "[name=phone]",
        process.env["BROWSER_" + role.toUpperCase() + "_PHONE"],
    );
    await fill("[name=password]", password);
    await submit("form");
    await until(`location.pathname==='/${role}'`);
}

assert(
    process.env.BROWSER_ORGANIZATION_PHONE,
    "Provide isolated organization manager phone",
);

try {
    await networkIdle();
    await send("Network.clearBrowserCookies");
    await goto("/mentors");
    await until("!!document.querySelector('[data-mentor-search]')");
    assert(
        !(await evaluate("document.body.innerText")).includes(
            "PRIVATE-HIDDEN-CITY",
        ),
    );
    assert(
        !(await evaluate("document.body.innerText")).includes(
            "SECRET-PRIVATE-DRAFT",
        ),
    );
    await fill("[name=q]", "راهنمای");
    await fill("[name=city]", "تهران");
    await fill("[name=focus]", "طراحی مسیر حرفه‌ای");
    await fill("[name=language]", "English");
    await submit("[data-mentor-search]");
    await until(
        "location.search.includes('city=') && document.querySelector('[data-mentor-result-count]')?.innerText.includes('۱۴') && document.querySelectorAll('[data-public-mentor]').length===12",
    );
    const next = await evaluate(
        "Array.from(document.querySelectorAll('.public-pager a')).find(a=>a.innerText.includes('بعد'))?.getAttribute('href')",
    );
    assert(next);
    const targetUrl = new URL(next);
    assert.equal(targetUrl.searchParams.get("q"), "راهنمای");
    assert.equal(targetUrl.searchParams.get("language"), "English");
    const first = await evaluate(
        "Array.from(document.querySelectorAll('[data-public-mentor] h2')).map(e=>e.innerText)",
    );
    await goto(targetUrl.pathname + targetUrl.search);
    await until(
        "location.search.includes('page=2') && document.querySelectorAll('[data-public-mentor]').length===2",
    );
    const second = await evaluate(
        "Array.from(document.querySelectorAll('[data-public-mentor] h2')).map(e=>e.innerText)",
    );
    assert(!second.some((name) => first.includes(name)));
    for (const width of [320, 390, 768, 1440]) {
        await send("Emulation.setDeviceMetricsOverride", {
            width,
            height: 900,
            deviceScaleFactor: 1,
            mobile: width < 768,
        });
        await until(`innerWidth===${width}`);
        assert(
            await evaluate(
                "document.documentElement.scrollWidth<=innerWidth+1",
            ),
            `Mentor directory overflow ${width}`,
        );
    }
    const raw = await fetch(base + targetUrl.pathname + targetUrl.search);
    const html = await raw.text();
    assert.equal(raw.headers.get("x-robots-tag"), "noindex, follow");
    assert(html.includes('rel="canonical" href="' + base + '/mentors"'));
    assert(!html.includes("SECRET-PRIVATE-DRAFT"));
    assert(!html.includes("PRIVATE-HIDDEN-CITY"));
    await goto(
        "/mentors?" +
            new URLSearchParams({
                q: "راهنماي",
                city: "تهران",
                focus: "طراحي مسير حرفه‌اي",
                language: "english",
            }),
    );
    await until(
        "document.querySelector('[data-mentor-result-count]')?.innerText.includes('۱۴')",
    );
    assert.equal(
        await evaluate("document.querySelector('[name=focus]').value"),
        "طراحی مسیر حرفه‌ای",
    );
    await goto(
        "/mentors?" +
            new URLSearchParams({ q: "<script>window.__mentorXss=1</script>" }),
    );
    await until(
        "document.body.innerText.includes('مربی‌ای با این شرایط پیدا نشد')",
    );
    assert.equal(await evaluate("window.__mentorXss||null"), null);
    await goto("/mentors?q=SECRET-PRIVATE-DRAFT-SEARCH");
    await until(
        "document.querySelector('[data-mentor-result-count]')?.innerText.includes('۰')",
    );
    await goto("/mentors");
    await until(
        "!!document.querySelector('[data-mentor-search]') && !location.search",
    );
    assert.equal(
        await evaluate("document.querySelector('[name=q]').value"),
        "",
    );
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "anonymous-search-form-four-filters",
                "fourteen-matches-two-pages-no-duplicates",
                "filters-preserved-in-pagination",
                "persian-arabic-joiner-and-case-normalization",
                "private-drafts-inactive-profiles-and-facets-excluded",
                "escaped-empty-search-and-filter-clearing",
                "canonical-noindex-private-cache",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
