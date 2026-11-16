import { readFileSync } from "node:fs";
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

const fixture = JSON.parse(
    readFileSync("/tmp/kardorost-survey-export-fixture.json", "utf8"),
);
async function download(dataset, publication = fixture.aggregate) {
    return evaluate(
        `(async()=>{const r=await fetch('/survey-publications/'+${publication}+'/export?dataset='+${JSON.stringify(dataset)},{headers:{Accept:'application/json'}});const b=new Uint8Array(await r.arrayBuffer());return {status:r.status,bom:Array.from(b.slice(0,3)),text:new TextDecoder().decode(b),cache:r.headers.get('cache-control'),disposition:r.headers.get('content-disposition')}})()`,
    );
}
try {
    await login("coach");
    await goto("/survey-publications/" + fixture.aggregate + "/report");
    await until("!!document.querySelector('[data-survey-exports]')");
    let links = await evaluate(
        "Array.from(document.querySelectorAll('[data-survey-exports] a')).map(a=>a.href)",
    );
    assert.equal(links.length, 2);
    assert(links.some((link) => link.includes("dataset=summary")));
    assert(
        links.some((link) =>
            link.includes("dataset=comments&question=comment"),
        ),
    );
    const summary = await download("summary&page=2");
    assert.equal(summary.status, 200);
    assert.deepEqual(summary.bom, [239, 187, 191]);
    assert(summary.cache.includes("no-store"));
    assert(summary.disposition.includes("attachment"));
    assert(!summary.text.includes("نام مصنوعی"));
    assert(!summary.text.includes("WITHHELD-PRIVATE-COMMENT"));
    const comments = await download("comments&question=comment&page=2");
    assert.equal(comments.status, 200);
    assert(comments.text.includes("نظر CSV شماره 15"));
    assert(comments.text.includes("'=FORMULA()"));
    assert(!comments.text.includes("نام مصنوعی"));
    assert(!comments.text.includes("WITHHELD-PRIVATE-COMMENT"));
    await evaluate(
        "(()=>{const el=document.querySelector('main select');el.value='sparse';el.dispatchEvent(new Event('change',{bubbles:true}));})()",
    );
    await networkIdle();
    await until(
        "!Array.from(document.querySelectorAll('[data-survey-exports] a')).some(a=>a.href.includes('dataset=comments'))",
    );
    assert.equal((await download("comments&question=sparse")).status, 422);
    assert.equal((await download("responses")).status, 422);
    await goto("/survey-publications/" + fixture.locked + "/report");
    assert.equal(
        await evaluate("document.querySelector('[data-survey-exports]')"),
        null,
    );
    assert.equal((await download("summary", fixture.locked)).status, 422);
    await login("admin");
    await goto("/survey-publications/" + fixture.identified + "/report");
    await until("!!document.querySelector('[data-survey-exports]')");
    const identified = await download("responses", fixture.identified);
    assert.equal(identified.status, 200);
    assert(identified.text.includes("نام مصنوعی پاسخ‌دهنده CSV identified"));
    assert(identified.text.includes("زمان ثبت به وقت تهران"));
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
            `Survey CSV overflow ${width}`,
        );
    }
    await login("student");
    assert.equal((await download("summary")).status, 403);
    await login("organization");
    assert.equal((await download("summary")).status, 403);
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "assigned-coach-and-admin-csv-links",
                "all-fifteen-comments-independent-of-page",
                "threshold-global-and-per-question",
                "identified-name-snapshot-vs-aggregate-privacy",
                "utf8-bom-csv-formula-protection-private-download-headers",
                "student-organization-isolation",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
