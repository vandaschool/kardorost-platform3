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
async function login(
    role,
    phone = process.env["BROWSER_" + role.toUpperCase() + "_PHONE"],
) {
    await send("Network.clearBrowserCookies");
    await send("Page.navigate", { url: base + "/login" });
    await until(`!!document.querySelector('input[name=password]')`);
    await fill("[name=phone]", phone);
    await fill("[name=password]", password);
    await submit("form");
    await until(`location.pathname==='/${role}'`);
}

assert(
    process.env.BROWSER_ORGANIZATION_PHONE,
    "Provide isolated organization manager phone",
);

const studentId = Number(process.env.BROWSER_INVITED_STUDENT_ID);
const secondPhone = process.env.BROWSER_INVITED_SECOND_PHONE;
assert(
    studentId > 0 && secondPhone,
    "Provide isolated invited student ID and second student phone.",
);
async function uploadCsv(content) {
    await writeFile("/tmp/kardorost-invitation-browser.csv", content);
    const root = await send("DOM.getDocument");
    const node = await send("DOM.querySelector", {
        nodeId: root.root.nodeId,
        selector: "[name=invitation_csv]",
    });
    await send("DOM.setFileInputFiles", {
        nodeId: node.nodeId,
        files: ["/tmp/kardorost-invitation-browser.csv"],
    });
    await evaluate(
        `document.querySelector('[name=invitation_csv]').form.requestSubmit()`,
    );
}
try {
    await login("organization");
    await goto("/organization/invitations");
    await until(`!!document.querySelector('[name=invitation_csv]')`);
    await uploadCsv("phone\n09121234560\nbad-phone\n");
    await until(
        `!!document.querySelector('.invitation-import-preview .field-error')`,
    );
    assert.equal(
        await evaluate(
            `!!document.querySelector('.invitation-import-preview form')`,
        ),
        false,
        "Invalid rows block the whole confirmation",
    );
    assert.equal(
        await evaluate(`document.querySelectorAll('.invitation-card').length`),
        0,
    );
    const oldPreview = await evaluate("location.search");
    await uploadCsv("\uFEFFphone\n۰۹۱۲۱۲۳۴۵۶۰\n+989121234560\n09123334455\n");
    await until(
        `location.search !== ${JSON.stringify(oldPreview)} && !!document.querySelector('.invitation-import-preview form')`,
    );
    assert.equal(
        await evaluate(`document.querySelectorAll('.invitation-card').length`),
        0,
        "Preview alone does not create invitations",
    );
    await evaluate(
        `document.querySelector('.invitation-import-preview details').open=true`,
    );
    assert.equal(
        await evaluate(
            `document.querySelector('.invitation-import-preview').innerText.includes('تکراری در فایل')`,
        ),
        true,
    );
    await evaluate(
        `document.querySelector('.invitation-import-preview input[type=checkbox]').click()`,
    );
    await submit(".invitation-import-preview form");
    await until(
        `document.querySelectorAll('.invitation-card').length === 2 && !document.querySelector('.invitation-import-preview form')`,
    );
    assert.equal(
        await evaluate(
            `(async()=> (await fetch('/organization/members/${studentId}')).status)()`,
        ),
        403,
        "Pending invitation cannot expose a personal report",
    );
    await fill("[name=invite_phone]", secondPhone);
    await evaluate(
        `document.querySelector('[name=invite_phone]').form.requestSubmit()`,
    );
    await until(`document.querySelectorAll('.invitation-card').length === 3`);
    await login("student");
    await goto("/organization-invitations");
    await until(`!!document.querySelector('.invitation-card form')`);
    assert.equal(
        await evaluate(`document.querySelectorAll('.invitation-card').length`),
        1,
        "Student list only includes their own phone",
    );
    await evaluate(
        `document.querySelector('.invitation-card input[type=checkbox]').click()`,
    );
    await submit(".invitation-card form");
    await until(
        `!document.querySelector('.invitation-card form') && document.querySelector('.status-badge').innerText === 'پذیرفته‌شده'`,
    );
    assert.equal(
        await evaluate(`(async()=> (await fetch('/organization')).status)()`),
        403,
        "Membership does not grant organization management",
    );
    await login("student", secondPhone);
    await goto("/organization-invitations");
    await until(`!!document.querySelector('.invitation-card form')`);
    await evaluate(
        `Array.from(document.querySelectorAll('.invitation-card button')).find(b=>b.innerText==='رد دعوت').click()`,
    );
    await until(
        `!document.querySelector('.invitation-card form') && document.querySelector('.status-badge').innerText === 'ردشده توسط فرد'`,
    );
    const declinedId = await evaluate(
        `document.querySelector('.invitation-card').dataset.invitationId`,
    );
    await login("organization");
    await goto("/organization");
    await until(
        `!!document.querySelector('a[href="/organization/members/${studentId}"]')`,
    );
    assert.equal(
        await evaluate(
            `(async()=> (await fetch('/organization/members/${studentId}')).status)()`,
        ),
        200,
    );
    await goto("/organization/invitations");
    await until(
        `!!document.querySelector('[data-invitation-id="${declinedId}"]')`,
    );
    await evaluate(
        `Array.from(document.querySelectorAll('[data-invitation-id="${declinedId}"] button')).find(b=>b.innerText.includes('دعوت دوباره')).click()`,
    );
    await until(
        `document.querySelector('[data-invitation-id="${declinedId}"] .status-badge').innerText==='منتظر پاسخ'`,
    );
    await evaluate(
        `Array.from(document.querySelectorAll('[data-invitation-id="${declinedId}"] button')).find(b=>b.innerText==='لغو دعوت').click()`,
    );
    await until(
        `document.querySelector('[data-invitation-id="${declinedId}"] .status-badge').innerText==='لغوشده توسط سازمان'`,
    );
    await login("student", secondPhone);
    await goto("/organization-invitations");
    await until(
        `document.querySelector('.status-badge')?.innerText==='لغوشده توسط سازمان'`,
    );
    assert.equal(
        await evaluate(`!!document.querySelector('.invitation-card form')`),
        false,
    );
    await login("student");
    await goto("/organization-invitations");
    await until(
        `document.querySelector('.status-badge')?.innerText==='پذیرفته‌شده'`,
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
    );
    await writeFile(
        "/tmp/kardorost-invitations-mobile.png",
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
    assert.deepEqual(exceptions, []);
    console.log(
        "PASS: invalid CSV preview, normalization/deduplication, explicit atomic import, consent-gated report access, single invite, decline/renew/revoke, role isolation and mobile layout",
    );
} finally {
    socket.close();
}
