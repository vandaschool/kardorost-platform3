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
    await goto("/consultations");
    await until(
        `document.querySelectorAll('.consultation-card textarea').length>=3`,
    );
    await fill(
        ".consultation-grid .consultation-card textarea",
        "Browser reservation topic",
    );
    await submit(".consultation-grid .consultation-card form");
    await until(
        `document.body.innerText.includes('Browser reservation topic') && document.querySelectorAll('.status-badge').length>0`,
    );
    await fill(
        "main > .consultation-card textarea",
        "Browser coordination topic",
    );
    // Inputs are nested in separate labels.
    await evaluate(
        `(()=>{let a=document.querySelectorAll('main > .consultation-card textarea');a[1].value='Evenings';a[1].dispatchEvent(new Event('input',{bubbles:true}));})()`,
    );
    await submit("main > .consultation-card form");
    await until(
        `document.body.innerText.includes('Browser coordination topic')`,
    );
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await until(
        `document.querySelector('.sidebar').getBoundingClientRect().left >= window.innerWidth`,
    );
    assert.equal(
        await evaluate(
            "document.documentElement.scrollWidth <= window.innerWidth",
        ),
        true,
        "No mobile horizontal overflow",
    );
    assert.equal(await evaluate(`document.documentElement.dir`), "rtl");
    await writeFile(
        "/tmp/kardorost-consultations-mobile.png",
        Buffer.from(
            (
                await send("Page.captureScreenshot", {
                    format: "png",
                    captureBeyondViewport: true,
                })
            ).data,
            "base64",
        ),
    );
    await evaluate(`document.querySelector('.mobile-toggle').click()`);
    await until(
        `document.querySelector('.sidebar').classList.contains('is-open')`,
    );
    await evaluate(
        `document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))`,
    );
    await evaluate(`document.querySelector('.mobile-scrim')?.click()`);
    await send("Emulation.setDeviceMetricsOverride", {
        width: 1440,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: false,
    });
    await login("coach");
    await goto("/consultations?view=coach");
    await until(
        `document.body.innerText.includes('Browser reservation topic')`,
    );
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('Browser coordination topic')`,
        ),
        false,
        "Coach must not see unassigned human requests",
    );
    await login("admin");
    await goto("/consultations?view=admin");
    await until(
        `document.body.innerText.includes('Browser coordination topic')`,
    );
    await writeFile(
        "/tmp/kardorost-consultations-admin.png",
        Buffer.from(
            (
                await send("Page.captureScreenshot", {
                    format: "png",
                    captureBeyondViewport: true,
                })
            ).data,
            "base64",
        ),
    );
    assert.deepEqual(exceptions, [], "No uncaught browser exceptions");
    console.log(
        "PASS: student reservation/request, coach isolation, admin queue, mobile RTL layout, browser exceptions",
    );
} finally {
    socket.close();
}
