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
    await goto("/organization-memberships");
    const organizationId = await evaluate(
        "document.querySelector('[data-membership]').dataset.membership",
    );
    await evaluate("document.querySelector('details').open=true");
    await fill("[name=reason]", "خروج آگاهانه دانشجوی مرورگر");
    await submit("form");
    await until(
        "!document.querySelector('[data-membership]') && document.body.innerText.includes('خروج آگاهانه دانشجوی مرورگر')",
    );
    await login("organization");
    await goto("/organization");
    assert(
        !(await evaluate(
            "document.body.innerText.includes('بررسی مرورگر student')",
        )),
    );
    await goto("/organization/invitations");
    await evaluate(
        "Array.from(document.querySelectorAll('button')).find(el=>el.textContent.includes('دعوت دوباره')).click()",
    );
    await until("document.body.innerText.includes('منتظر پاسخ')");
    await login("student");
    await goto("/organization-invitations");
    await evaluate("document.querySelector('input[type=checkbox]').click()");
    await submit("form");
    await until("document.body.innerText.includes('پذیرفته‌شده')");
    await goto("/organization-memberships");
    assert(await evaluate("!!document.querySelector('[data-membership]')"));
    await login("organization");
    await goto("/organization");
    const studentCard = await evaluate(
        "Array.from(document.querySelectorAll('[data-member]')).find(el=>el.innerText.includes('بررسی مرورگر student')).dataset.member",
    );
    await evaluate(
        `document.querySelector('[data-member="${studentCard}"] details').open=true`,
    );
    await fill(
        '[data-member="' + studentCard + '"] [name=reason]',
        "پایان عضویت توسط مدیر مرورگر",
    );
    await submit('[data-member="' + studentCard + '"] form');
    await until(`!document.querySelector('[data-member="${studentCard}"]')`);
    await login("admin");
    await goto("/admin/organization-managers?organization=" + organizationId);
    const peerId = await evaluate(
        "Array.from(document.querySelectorAll('[data-member]')).find(el=>el.innerText.includes('عضو دوم مرورگر')).dataset.member",
    );
    await evaluate(
        `document.querySelector('[data-member="${peerId}"] button').click()`,
    );
    await submit("form:has([name=phone])");
    await until(
        `document.querySelector('[data-member="${peerId}"]').innerText.includes('پایان دسترسی مدیریتی')`,
    );
    const managerId = await evaluate(
        "Array.from(document.querySelectorAll('[data-member]')).find(el=>el.innerText.includes('بررسی مرورگر org_admin')).dataset.member",
    );
    await evaluate(
        `document.querySelector('[data-member="${managerId}"] details').open=true`,
    );
    await fill(
        '[data-member="' + managerId + '"] [name=reason]',
        "جایگزینی مدیر اول مرورگر",
    );
    await submit('[data-member="' + managerId + '"] form');
    await until(`!document.querySelector('[data-member="${managerId}"]')`);
    await evaluate(
        `document.querySelector('[data-member="${peerId}"] details').open=true`,
    );
    await fill(
        '[data-member="' + peerId + '"] [name=reason]',
        "تلاش حذف آخرین مدیر",
    );
    await submit('[data-member="' + peerId + '"] form');
    await until("document.body.innerText.includes('ابتدا مدیر فعال دیگری')");
    assert(
        await evaluate(`!!document.querySelector('[data-member="${peerId}"]')`),
    );
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
    await login("student");
    await goto("/organization-memberships");
    assert(
        await evaluate(
            "document.body.innerText.includes('پایان عضویت توسط مدیر مرورگر')",
        ),
    );
    assert(
        await evaluate("document.documentElement.scrollWidth <= innerWidth"),
    );
    const screenshot = await send("Page.captureScreenshot", { format: "png" });
    await writeFile(
        "/tmp/kardorost-membership-mobile.png",
        Buffer.from(screenshot.data, "base64"),
    );
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            self_exit: true,
            renewed_consent: true,
            manager_removal: true,
            manager_promotion: true,
            last_manager_guard: true,
            mobile390: true,
        }),
    );
} finally {
    socket.close();
}
