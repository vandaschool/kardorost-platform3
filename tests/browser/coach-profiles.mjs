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

const publicName = "مربی آزمایشی مرورگر";
const oldHeadline = "معرفی آزمایشی مسیر حرفه‌ای";
const newHeadline = "عنوان تازه پس از بررسی دوم";
const privateDraft = "عنوان تازه در پیش‌نویس خصوصی";
const bio =
    "این معرفی صرفاً برای بررسی فرایند پروفایل است.\n<script>globalThis.publicProfileUnsafe=true</script>";
async function approve(id) {
    await login("admin");
    await goto("/admin/coach-profiles/" + id);
    await until("!!document.querySelector('[data-coach-profile-review]')");
    await evaluate(
        "document.querySelector('[name=coach_review_confirmed]').click()",
    );
    await submit("[data-coach-profile-review]");
    await until(
        "document.body.innerText.includes('نتیجه بررسی همین نسخه ثبت شد') && !document.querySelector('[data-coach-profile-review]')",
    );
}
try {
    await login("coach");
    await goto("/coach/profile");
    await fill("[name=coach_display_name]", publicName);
    await fill("[name=coach_headline]", oldHeadline);
    await fill("[name=coach_bio]", bio);
    await fill("[name=coach_city]", "شهر آزمایشی");
    await fill("[name=coach_focus_0]", "موضوع آزمایشی");
    await fill("[name=coach_language_0]", "فارسی");
    await submit("[data-coach-profile-editor]");
    await until("document.body.innerText.includes('پیش‌نویس ذخیره شد')");
    await goto("/coach/profile");
    const profile = await evaluate(
        "JSON.parse(document.querySelector('script[data-page]').textContent).props.profile",
    );
    const publicPath = new URL(profile.public_url).pathname;
    assert.equal((await fetch(base + publicPath)).status, 404);
    await evaluate(
        "document.querySelector('[name=coach_public_consent]').click()",
    );
    await submit("[data-coach-profile-submit]");
    await until("document.body.innerText.includes('همین نسخه با رضایت شما')");
    assert.equal((await fetch(base + publicPath)).status, 404);
    await approve(profile.id);
    let html = await (await fetch(base + publicPath)).text();
    assert(html.includes(publicName) && html.includes(oldHeadline));
    assert(!html.includes("989121234561"));
    assert(!html.includes("<script>globalThis.publicProfileUnsafe"));
    await goto(publicPath);
    assert.equal(
        await evaluate("globalThis.publicProfileUnsafe === true"),
        false,
    );
    for (const width of [320, 390, 768, 1440]) {
        await send("Emulation.setDeviceMetricsOverride", {
            width,
            height: 900,
            deviceScaleFactor: 1,
            mobile: width < 600,
        });
        await goto("/mentors");
        assert(
            await evaluate("document.documentElement.scrollWidth<=innerWidth"),
        );
        assert.equal(
            await evaluate(
                "document.querySelectorAll('[data-public-mentor]').length",
            ),
            1,
        );
        if (width === 390) {
            const shot = await send("Page.captureScreenshot", {
                format: "png",
            });
            await writeFile(
                "/tmp/kardorost-mentor-public-mobile.png",
                Buffer.from(shot.data, "base64"),
            );
        }
    }
    await goto(publicPath);
    assert(await evaluate("document.documentElement.scrollWidth<=innerWidth"));
    await login("coach");
    await goto("/coach/profile");
    await fill("[name=coach_headline]", privateDraft);
    await send("Network.emulateNetworkConditions", {
        offline: false,
        latency: 500,
        downloadThroughput: -1,
        uploadThroughput: -1,
    });
    await submit("[data-coach-profile-editor]");
    await fill("[name=coach_headline]", newHeadline);
    await networkIdle();
    await send("Network.emulateNetworkConditions", {
        offline: false,
        latency: 0,
        downloadThroughput: -1,
        uploadThroughput: -1,
    });
    assert.equal(
        await evaluate(
            "document.querySelector('[data-coach-profile-submit] button').disabled",
        ),
        true,
        "Edits made while saving must remain unsaved",
    );
    html = await (await fetch(base + publicPath)).text();
    assert(
        html.includes(oldHeadline) &&
            !html.includes(privateDraft) &&
            !html.includes(newHeadline),
    );
    await submit("[data-coach-profile-editor]");
    await networkIdle();
    await evaluate(
        "document.querySelector('[name=coach_public_consent]').click()",
    );
    await submit("[data-coach-profile-submit]");
    await until("document.body.innerText.includes('همین نسخه با رضایت شما')");
    await approve(profile.id);
    html = await (await fetch(base + publicPath)).text();
    assert(html.includes(newHeadline) && !html.includes(oldHeadline));
    const sitemap = await (await fetch(base + "/sitemap.xml")).text();
    assert(sitemap.includes(publicPath));
    await login("coach");
    await goto("/coach/profile");
    await fill("[name=coach_withdraw_reason]", "انصراف آزمایشی از انتشار");
    await submit("[data-coach-profile-withdraw]");
    await until(
        "document.body.innerText.includes('نمایش عمومی و بررسی در انتظار این پروفایل بسته شدند')",
    );
    assert.equal((await fetch(base + publicPath)).status, 404);
    assert(
        !(await (await fetch(base + "/sitemap.xml")).text()).includes(
            publicPath,
        ),
    );
    assert.equal(
        await evaluate(
            "document.querySelector('[name=coach_public_consent]').checked",
        ),
        false,
    );
    assert(
        await evaluate(
            "document.body.innerText.includes('آخرین نسخه منتشرشده')",
        ),
    );
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            coach_private_draft_and_consent: true,
            admin_exact_snapshot_review: true,
            public_server_rendering_and_escape: true,
            no_private_phone: true,
            draft_does_not_replace_public_copy: true,
            editing_during_save_stays_dirty: true,
            second_reviewed_publication: true,
            owner_withdrawal_and_sitemap_removal: true,
            fresh_consent_after_withdrawal: true,
            widths320_390_768_1440: true,
        }),
    );
} finally {
    socket.close();
}
