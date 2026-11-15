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

assert(
    process.env.BROWSER_PEER_PHONE,
    "Supply a second active member for the isolated cohort fixture.",
);
try {
    await send("Emulation.setDeviceMetricsOverride", {
        width: 1440,
        height: 900,
        deviceScaleFactor: 1,
        mobile: false,
    });
    await login("student");
    await goto("/discussions");
    const cohortPath = await evaluate(
        `document.querySelector('main a[href^="/cohorts/"]').getAttribute('href')`,
    );
    await goto(cohortPath);
    await evaluate(`document.querySelector('details').open=true`);
    await fill("[name=discussion_title]", "پرسش مرورگر درباره تمرین");
    await fill(
        "[name=discussion_body]",
        'متن اولیه پرسش <img src=x onerror="window.forumXss=true">',
    );
    await submit("[data-discussion-create]");
    await until(
        `location.pathname.startsWith('/discussions/') && !!document.querySelector('[data-discussion-post]')`,
    );
    const discussionPath = await evaluate("location.pathname");
    const firstPost = await evaluate(
        `document.querySelector('[data-discussion-post]').dataset.discussionPost`,
    );
    assert.equal(await evaluate("window.forumXss || false"), false);
    assert.equal(
        await evaluate(
            `document.querySelectorAll('[data-discussion-post] img').length`,
        ),
        0,
    );
    await evaluate(
        `Array.from(document.querySelectorAll('[data-discussion-post] button')).find(button=>button.innerText==='ویرایش پیام من').click()`,
    );
    await fill("[data-post-edit] textarea", "نسخه روشن‌تر پرسش مرورگر");
    await submit("[data-post-edit]");
    await until(
        `!document.querySelector('[data-post-edit]') && document.body.innerText.includes('نسخه روشن‌تر پرسش مرورگر')`,
    );
    await login("coach");
    await goto("/notifications");
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('نسخه روشن‌تر پرسش مرورگر')`,
        ),
        false,
    );
    await goto(discussionPath);
    await fill("[name=reply_body]", "پاسخ اولیه مدرس مرورگر");
    await submit("[data-discussion-reply]");
    await until(
        `document.querySelectorAll('[data-discussion-post]').length===2 && !document.querySelector('[name=reply_body]').value`,
    );
    const replyId = await evaluate(
        `Array.from(document.querySelectorAll('[data-discussion-post]')).at(-1).dataset.discussionPost`,
    );
    await login("student");
    await goto("/notifications");
    await until(
        `document.body.innerText.includes('پاسخ تازه در گفت‌وگوی دوره')`,
    );
    await evaluate(
        `Array.from(document.querySelectorAll('.notification-row')).find(row=>row.innerText.includes('پاسخ تازه در گفت‌وگوی دوره')).querySelector('a').click()`,
    );
    await until(
        `location.pathname===${JSON.stringify(discussionPath)} && location.hash==='#post-${replyId}'`,
    );
    await evaluate(
        `Array.from(document.querySelectorAll('[data-discussion-post="${replyId}"] button')).find(button=>button.innerText==='انتخاب این پاسخ').click()`,
    );
    await until(
        `document.querySelector('[data-discussion-post="${replyId}"]').innerText.includes('پاسخ منتخب')`,
    );
    assert.equal(
        await evaluate(
            `fetch(${JSON.stringify(discussionPath + "/posts/" + replyId + "/history")}).then(response=>response.status)`,
        ),
        403,
    );
    await login("coach");
    await goto(discussionPath);
    await evaluate(
        `Array.from(document.querySelectorAll('[data-discussion-post="${replyId}"] button')).find(button=>button.innerText==='ویرایش پیام من').click()`,
    );
    await fill(
        "[data-post-edit] textarea",
        "پاسخ اصلاح‌شده مدرس برای بررسی نمایش",
    );
    await submit("[data-post-edit]");
    await until(
        `!document.querySelector('[data-post-edit]') && !document.querySelector('[data-discussion-post="${replyId}"]').innerText.includes('پاسخ منتخب')`,
    );
    await goto(discussionPath + "/posts/" + replyId + "/history");
    await until(
        `document.body.innerText.includes('پاسخ اولیه مدرس مرورگر') && document.body.innerText.includes('پاسخ اصلاح‌شده مدرس برای بررسی نمایش')`,
    );
    await goto(discussionPath);
    await evaluate(
        `document.querySelector('[data-post-moderate="${replyId}"]').closest('details').open=true`,
    );
    await fill(
        `[data-post-moderate="${replyId}"] textarea`,
        "دلیل داخلی مدرس برای پنهان‌سازی",
    );
    await submit(`[data-post-moderate="${replyId}"]`);
    await until(
        `document.querySelector('[data-discussion-post="${replyId}"]').innerText.includes('پیام پنهان‌شده')`,
    );
    await evaluate(
        `document.querySelector('[data-discussion-moderate]').closest('details').open=true`,
    );
    await evaluate(
        `document.querySelector('[name=discussion_locked]').click();document.querySelector('[name=discussion_pinned]').click()`,
    );
    await fill(
        "[data-discussion-moderate] textarea",
        "بستن و سنجاق گفت‌وگوی مرورگر",
    );
    await submit("[data-discussion-moderate]");
    await until(`!document.querySelector('[data-discussion-reply]')`);
    await login("student");
    await goto(discussionPath);
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('پاسخ اصلاح‌شده مدرس برای بررسی نمایش')`,
        ),
        false,
    );
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('دلیل داخلی مدرس برای پنهان‌سازی')`,
        ),
        false,
    );
    assert.equal(
        await evaluate(`!!document.querySelector('[data-discussion-reply]')`),
        false,
    );
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await until(
        `document.querySelector('.sidebar').getBoundingClientRect().left>=window.innerWidth`,
    );
    for (const path of ["/discussions", cohortPath, discussionPath]) {
        await goto(path);
        assert.equal(
            await evaluate(
                "document.documentElement.scrollWidth<=window.innerWidth",
            ),
            true,
            path + " mobile width",
        );
    }
    await writeFile(
        "/tmp/kardorost-course-discussion-mobile.png",
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
    await login("coach");
    await goto(discussionPath);
    await evaluate(
        `document.querySelector('[data-discussion-moderate]').closest('details').open=true`,
    );
    await evaluate(
        `document.querySelector('[name=discussion_hidden]').click()`,
    );
    await fill(
        "[data-discussion-moderate] textarea",
        "پنهان‌سازی کل گفت‌وگو برای آزمون",
    );
    await submit("[data-discussion-moderate]");
    await until(
        `!document.querySelector('[data-discussion-moderate] textarea').value`,
    );
    const ownerPhone = process.env.BROWSER_STUDENT_PHONE;
    process.env.BROWSER_STUDENT_PHONE = process.env.BROWSER_PEER_PHONE;
    await login("student");
    assert.equal(
        await evaluate(
            `fetch(${JSON.stringify(discussionPath)}).then(response=>response.status)`,
        ),
        404,
    );
    await goto(cohortPath);
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('پرسش مرورگر درباره تمرین')`,
        ),
        false,
    );
    process.env.BROWSER_STUDENT_PHONE = ownerPhone;
    await login("student");
    await goto(discussionPath);
    await until(`document.body.innerText.includes('گفت‌وگوی پنهان‌شده')`);
    assert.equal(
        await evaluate(
            `document.querySelectorAll('[data-discussion-post]').length`,
        ),
        0,
    );
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('پرسش مرورگر درباره تمرین')`,
        ),
        false,
    );
    assert.deepEqual(exceptions, []);
    console.log(
        "PASS: cohort question, escaped text, own edit, staff reply, generic notification deep link, selected-answer invalidation, history, post/thread moderation, hidden peer isolation and mobile 390",
    );
} finally {
    socket.close();
}
