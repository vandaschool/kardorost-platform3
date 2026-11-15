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
    await send("Network.clearBrowserCookies");
    await send("Emulation.setDeviceMetricsOverride", {
        width: 1440,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: false,
    });
    await goto("/");
    await until("document.readyState === 'complete'");
    await evaluate("document.fonts.ready");
    assert(await evaluate("document.body.innerText.includes('کاردرست')"));
    assert.equal(await evaluate("document.documentElement.dir"), "rtl");
    assert(
        await evaluate("document.documentElement.scrollWidth <= innerWidth"),
    );
    const oldTheme = await evaluate("document.documentElement.dataset.theme");
    await evaluate("document.querySelector('[data-theme-toggle]').click()");
    await until(
        `document.documentElement.dataset.theme !== ${JSON.stringify(oldTheme)}`,
    );
    await evaluate(
        "document.documentElement.dataset.theme='light';localStorage.setItem('kd-theme','light')",
    );
    assert.equal(
        await evaluate(
            "Array.from(document.querySelectorAll('header .public-brand')).filter(el => getComputedStyle(el).display !== 'none').length",
        ),
        1,
        "One logo in light theme",
    );
    await evaluate("document.documentElement.dataset.theme='dark'");
    assert.equal(
        await evaluate(
            "Array.from(document.querySelectorAll('header .public-brand')).filter(el => getComputedStyle(el).display !== 'none').length",
        ),
        1,
        "One logo in dark theme",
    );
    await evaluate("document.documentElement.dataset.theme='light'");
    let screenshot = await send("Page.captureScreenshot", { format: "png" });
    await writeFile(
        "/tmp/kardorost-public-desktop.png",
        Buffer.from(screenshot.data, "base64"),
    );
    await goto("/catalog");
    const coursePath = new URL(
        await evaluate(
            "document.querySelector('[data-public-course] h3 a').href",
        ),
    ).pathname;
    await goto(coursePath);
    await until(
        "document.readyState === 'complete' && !!document.querySelector('time') && !document.querySelector('time').textContent.includes('میلادی')",
    );
    assert(
        !(await evaluate(
            "document.body.innerText.includes('https://meet.google.com/')",
        )),
    );
    await login("admin");
    await goto("/admin/content");
    await evaluate("document.querySelector('details').open=true");
    await fill("[name=slug]", "browser-public-article");
    await fill("[name=title]", "مقاله مسیر شغلی مرورگر");
    await fill("[name=excerpt]", "خلاصه یادداشت عمومی مرورگر");
    await fill(
        "[name=body]",
        "متن عمومی نسخه اول <script>window.cmsUnsafe=1</script>",
    );
    await fill("[name=meta_description]", "توضیح جستجوی مقاله مرورگر");
    await submit("[data-content-create]");
    await until("!!document.querySelector('[data-content-draft]')");
    const editorPath = await evaluate("location.pathname");
    assert.equal(
        await evaluate(
            "fetch('/journal/browser-public-article').then(r=>r.status)",
        ),
        404,
    );
    await evaluate("document.querySelector('[data-content-publish]').click()");
    await until("document.body.innerText.includes('نسخه عمومی ۱')");
    await goto("/journal/browser-public-article");
    assert(
        await evaluate(
            "document.body.innerText.includes('<script>window.cmsUnsafe=1</script>')",
        ),
    );
    assert.equal(await evaluate("typeof window.cmsUnsafe"), "undefined");
    assert.equal(
        await evaluate(
            "document.querySelector('meta[name=description]').content",
        ),
        "توضیح جستجوی مقاله مرورگر",
    );
    await goto(editorPath);
    await fill("[name=body]", "متن عمومی نسخه دوم مقاله مرورگر");
    assert(
        await evaluate(
            "document.querySelector('[data-content-publish]').disabled",
        ),
    );
    await submit("[data-content-draft]");
    await until("!document.querySelector('[data-content-publish]').disabled");
    await goto("/journal/browser-public-article");
    assert(
        !(await evaluate("document.body.innerText.includes('نسخه دوم مقاله')")),
    );
    await goto(editorPath);
    await evaluate("document.querySelector('[data-content-publish]').click()");
    await until("document.body.innerText.includes('نسخه عمومی ۲')");
    await evaluate(
        "document.querySelector('[data-content-visibility]').click()",
    );
    await until("document.body.innerText.includes('نمایش بسته')");
    assert.equal(
        await evaluate(
            "fetch('/journal/browser-public-article').then(r=>r.status)",
        ),
        404,
    );
    await evaluate(
        "document.querySelector('[data-content-visibility]').click()",
    );
    await until("document.body.innerText.includes('نمایش فعال')");
    for (const width of [320, 390, 768, 1440]) {
        await send("Emulation.setDeviceMetricsOverride", {
            width,
            height: 844,
            deviceScaleFactor: 1,
            mobile: width < 800,
        });
        for (const path of [
            "/",
            "/catalog",
            "/journal",
            "/journal/browser-public-article",
            coursePath,
        ]) {
            await goto(path);
            await until("document.readyState === 'complete'");
            await evaluate("document.fonts.ready");
            assert(
                await evaluate(
                    "document.documentElement.scrollWidth <= innerWidth",
                ),
                path + " at " + width,
            );
        }
    }
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await goto("/");
    await until("document.readyState === 'complete'");
    await evaluate("document.querySelector('[data-menu-toggle]').click()");
    await until(
        "document.querySelector('[data-menu-toggle]').getAttribute('aria-expanded') === 'true'",
    );
    assert(
        await evaluate(
            "getComputedStyle(document.querySelector('[data-public-menu]')).display !== 'none'",
        ),
    );
    await send("Input.dispatchKeyEvent", {
        type: "keyDown",
        key: "Escape",
        code: "Escape",
    });
    await until(
        "document.querySelector('[data-menu-toggle]').getAttribute('aria-expanded') === 'false'",
    );
    screenshot = await send("Page.captureScreenshot", { format: "png" });
    await writeFile(
        "/tmp/kardorost-public-mobile.png",
        Buffer.from(screenshot.data, "base64"),
    );
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            public_home: true,
            server_rendered_content: true,
            private_draft: true,
            immutable_publication: true,
            withdrawal_restore: true,
            escaped_html: true,
            local_jalali_date: true,
            responsive: [320, 390, 768, 1440],
            mobile_menu: true,
        }),
    );
} finally {
    socket.close();
}
