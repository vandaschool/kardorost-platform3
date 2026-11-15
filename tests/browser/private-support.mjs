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
socket.addEventListener("message", (event) => {
    const m = JSON.parse(event.data);
    if (m.method === "Runtime.consoleAPICalled" && m.params.type === "error")
        exceptions.push(
            m.params.args.map((arg) => arg.description || arg.value).join(" "),
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
async function login(role) {
    await send("Network.clearBrowserCookies");
    await send("Page.navigate", { url: base + "/login" });
    await until(`!!document.querySelector('input[name=password]')`);
    await fill(
        "[name=phone]",
        process.env["BROWSER_" + role.toUpperCase() + "_PHONE"],
    );
    await fill("[name=password]", password);
    await submit("form");
    await until(`location.pathname==='/${role}'`);
}

try {
    await login("student");
    await goto("/support");
    await fill("[name=subject]", "پیام آموزشی خصوصی مرورگر");
    await fill("[name=category]", "academic");
    const cohortId = await evaluate(
        "document.querySelector('[name=cohort_id] option[value]:not([value=\"\"])').value",
    );
    await fill("[name=cohort_id]", cohortId);
    await fill("[name=body]", "پرسش خصوصی دانشجو <script>unsafe</script>");
    const documentNode = await send("DOM.getDocument");
    const input = await send("DOM.querySelector", {
        nodeId: documentNode.root.nodeId,
        selector: "input[name=file]",
    });
    await send("DOM.setFileInputFiles", {
        nodeId: input.nodeId,
        files: ["/tmp/kardorost-support-upload.txt"],
    });
    await until(
        "document.querySelector('input[name=file]').files.length === 1",
    );
    await submit("form");
    await until(
        "location.pathname.startsWith('/support/') && document.body.innerText.includes('دریافت پیوست')",
    );
    const ticketPath = await evaluate("location.pathname");
    assert(
        await evaluate(
            "document.body.innerText.includes('<script>unsafe</script>')",
        ),
    );
    assert(
        await evaluate(
            "!!document.querySelector('a[href*=\"/support-files/\"]')",
        ),
    );
    await login("coach");
    await goto("/support?view=coach");
    assert(
        await evaluate(
            "document.body.innerText.includes('پیام آموزشی خصوصی مرورگر')",
        ),
    );
    await goto(ticketPath);
    await fill("[name=body]", "پاسخ خصوصی مدرس مرورگر");
    await submit("form");
    await until(
        "document.body.innerText.includes('پاسخ خصوصی مدرس مرورگر') && !document.querySelector('textarea[name=body]').value",
    );
    await login("admin");
    await goto(ticketPath);
    await fill("[name=body]", "یادداشت داخلی محرمانه مرورگر");
    await evaluate("document.querySelector('[name=internal_note]').click()");
    await submit("form");
    await until("document.querySelectorAll('[data-message]').length === 3");
    assert(
        await evaluate(
            "document.body.innerText.includes('یادداشت داخلی محرمانه مرورگر')",
        ),
    );
    await fill("[name=body]", "پاسخ عمومی پشتیبانی مرورگر");
    await submit("form");
    await until("document.querySelectorAll('[data-message]').length === 4");
    await login("student");
    await goto(ticketPath);
    assert(
        !(await evaluate(
            "document.body.innerText.includes('یادداشت داخلی محرمانه مرورگر')",
        )),
    );
    assert.equal(
        await evaluate("document.querySelectorAll('[data-message]').length"),
        3,
    );
    await fill("[name=reason]", "درخواست با پاسخ مدرس حل شد");
    await submit("form:has([name=action])");
    await until(
        "document.body.innerText.includes('حل‌شده') && !document.querySelector('textarea[name=body]')",
    );
    assert.equal(
        await evaluate("document.querySelector('[name=action]').value"),
        "reopen",
    );
    await fill("[name=reason]", "نیاز به ادامه پیگیری");
    await submit("form:has([name=action])");
    await until("!!document.querySelector('textarea[name=body]')");
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await until(
        "!document.querySelector('.sidebar') || document.querySelector('.sidebar').getBoundingClientRect().left >= innerWidth",
    );
    assert(
        await evaluate("document.documentElement.scrollWidth <= innerWidth"),
    );
    const screenshot = await send("Page.captureScreenshot", { format: "png" });
    await writeFile(
        "/tmp/kardorost-support-mobile.png",
        Buffer.from(screenshot.data, "base64"),
    );
    await goto("/support");
    assert(
        await evaluate("document.documentElement.scrollWidth <= innerWidth"),
    );
    await send("Emulation.clearDeviceMetricsOverride");
    await goto("/cohorts/" + cohortId + "/discussions");
    const next = await evaluate(
        "Array.from(document.querySelectorAll('nav[aria-label=صفحه‌بندی] a')).find(a=>a.textContent.includes('صفحه بعد'))?.getAttribute('href')",
    );
    assert(next, "Legacy link-array paginator must offer the next page");
    await evaluate(
        "Array.from(document.querySelectorAll('nav[aria-label=صفحه‌بندی] a')).find(a=>a.textContent.includes('صفحه بعد')).click()",
    );
    await until("location.search.includes('page=2')");
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            private_thread: true,
            attachment: true,
            coach_reply: true,
            admin_note_isolation: true,
            resolve_reopen: true,
            mobile390: true,
            legacy_next_page: true,
        }),
    );
} finally {
    socket.close();
}
