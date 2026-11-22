import { execFileSync } from "node:child_process";
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
    await networkIdle();
    await evaluate("document.documentElement.dataset.qaNavigation='previous'");
    await send("Page.navigate", { url: base + path });
    await until(
        `location.pathname===${JSON.stringify(path.split("?")[0])} && !!document.querySelector('main') && document.documentElement.dataset.qaNavigation!=='previous' && document.readyState==='complete'`,
    );
    await networkIdle();
}

async function fill(selector, value) {
    await evaluate(
        `(()=>{const el=document.querySelector(${JSON.stringify(selector)});if(!el)throw Error('Missing input');el.value=${JSON.stringify(value)};el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));})()`,
    );
}
async function submit(selector) {
    lease();
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
async function login(
    role,
    phone = process.env["BROWSER_" + role.toUpperCase() + "_PHONE"],
) {
    await networkIdle();
    await send("Page.navigate", { url: "about:blank" });
    await until("location.href==='about:blank'");
    await send("Network.clearBrowserCookies");
    await send("Page.navigate", { url: base + "/login" });
    await until(`!!document.querySelector('input[name=password]')`);
    await networkIdle();
    await fill("[name=phone]", phone);
    await fill("[name=password]", password);
    await submit("form");
    await until(`location.pathname==='/${role}'`);
}

assert(
    new URL(base).port === "18089" &&
        new URL(base).hostname === "127.0.0.1" &&
        process.env.CONTAINER_ENV_FILE?.startsWith("/tmp/kardorost-compose-"),
    "Only the owned four-user production-mode loopback fixture.",
);

function lease() {
    assert(
        process.env.KARDOROST_LEASE_OWNER,
        "Supply the active delivery lease owner.",
    );
    execFileSync(
        "python3",
        [
            "/workspace/work/kardorost-autonomy-lease.py",
            "touch",
            process.env.KARDOROST_LEASE_OWNER,
        ],
        { stdio: "pipe" },
    );
}

const python = String.raw`import importlib.util,json,os,subprocess,sys
from pathlib import Path
root=Path.cwd();sys.path.insert(0,str(root/'tests'))
from container_runtime_probes import RICH_CONTENT_FIXTURE_STATE
spec=importlib.util.spec_from_file_location('recovery',root/'resources/operations/recovery.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
subprocess.run(['python3','/workspace/work/kardorost-autonomy-lease.py','touch',os.environ['KARDOROST_LEASE_OWNER']],check=True,stdout=subprocess.DEVNULL)
environment=Path(sys.argv[1]).resolve();assert environment.is_relative_to('/tmp') and environment.name.startswith('kardorost-compose-')
c=m.Composition(environment);assert c.config['name'].startswith('kardorost-validation-') and c.settings['SMS_DRIVER']=='disabled' and c.settings['ZIBAL_ENABLED']=='false' and c.settings['APP_URL']=='http://127.0.0.1:18089'
print(c.run(['exec','-T','app','php','-r',RICH_CONTENT_FIXTURE_STATE]))
`;

function state() {
    return JSON.parse(
        execFileSync(
            "python3",
            ["-c", python, process.env.CONTAINER_ENV_FILE],
            { encoding: "utf8" },
        ),
    );
}
async function click(selector) {
    lease();
    await evaluate(
        `document.querySelector(${JSON.stringify(selector)}).click()`,
    );
    await networkIdle();
}
async function toolbar(label) {
    await evaluate(
        `(()=>{const b=Array.from(document.querySelectorAll('[role=toolbar] button')).find(b=>b.textContent.trim()===${JSON.stringify(label)});if(!b)throw Error('Missing formatting command');b.click()})()`,
    );
}
async function select(text) {
    await evaluate(
        `(()=>{const e=document.querySelector('[data-content-document]');e.focus();const w=document.createTreeWalker(e,NodeFilter.SHOW_TEXT);let n;while(n=w.nextNode()){const i=n.textContent.indexOf(${JSON.stringify(text)});if(i>=0){const r=document.createRange();r.setStart(n,i);r.setEnd(n,i+${text.length});const s=getSelection();s.removeAllRanges();s.addRange(r);e.dispatchEvent(new MouseEvent('mouseup',{bubbles:true}));return;}}throw Error('Selection text missing')})()`,
    );
}
async function save() {
    await submit("[data-content-draft]");
    await networkIdle();
    await goto("/admin/content/" + state().id);
}
async function responsive() {
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
            "No overflow at " + width,
        );
    }
}
async function heading(text) {
    await evaluate(
        `(()=>{const e=document.querySelector('[data-content-document]');e.querySelector('h2').textContent=${JSON.stringify(text)};e.dispatchEvent(new InputEvent('input',{bubbles:true,inputType:'insertText'}))})()`,
    );
    await save();
}
async function populateRich() {
    await evaluate(
        `(()=>{const e=document.querySelector('[data-content-document]');e.replaceChildren(...${JSON.stringify([publicHeading, "واژه پررنگ و واژه لینک برای بررسی نرم‌افزاری.", "فهرست اول", "فهرست دوم", "نقل‌قول صرفاً آزمایشی", '<img src=x onerror="window.richCmsXss=true">'])}.map(text=>{const p=document.createElement('p');p.textContent=text;return p}));e.dispatchEvent(new InputEvent('input',{bubbles:true}))})()`,
    );
    await select(publicHeading);
    await toolbar("تیتر");
    await select("پررنگ");
    await toolbar("پررنگ");
    await select("نرم‌افزاری");
    await toolbar("تأکید");
    await select("فهرست اول");
    await toolbar("فهرست نشانه‌دار");
    await select("نقل‌قول صرفاً آزمایشی");
    await toolbar("نقل‌قول");
    await select("لینک");
    await fill('[aria-label="نشانی لینک متن"]', "javascript:alert(1)");
    await evaluate(
        "Array.from(document.querySelectorAll('button')).find(b=>b.textContent.trim()==='ثبت یا برداشتن لینک').click()",
    );
    await until(
        "document.body.innerText.includes('نشانی وب معتبر یا مسیر داخلی وارد کنید.')",
    );
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-content-document] a').length",
        ),
        0,
    );
    await fill(
        '[aria-label="نشانی لینک متن"]',
        "https://example.test/path?q=1&b=2",
    );
    await evaluate(
        "Array.from(document.querySelectorAll('button')).find(b=>b.textContent.trim()==='ثبت یا برداشتن لینک').click()",
    );
}
const path = "/journal/software-rich-content76";
const publicHeading = "تیتر قالب‌دار صرفاً آزمایشی۷۶";
try {
    lease();
    const initialFinancial = state().financial_hash;
    await login("admin");
    let current = state();
    if (!current.id) {
        await goto("/admin/content");
        await evaluate(
            "document.querySelector('[data-content-create]').closest('details').open=true",
        );
        for (const [name, value] of Object.entries({
            kind: "article",
            slug: "software-rich-content76",
            title: "مقاله قالب‌دار صرفاً آزمایشی۷۶",
            excerpt: "بررسی نرم‌افزاری نسخه قالب‌دار.",
            body: "متن ساده نسخه نخست صرفاً آزمایشی.",
        }))
            await fill("[data-content-create] [name=" + name + "]", value);
        await submit("[data-content-create]");
        await networkIdle();
        current = state();
        assert(current);
    }
    await goto("/admin/content/" + current.id);
    if (!current.version) {
        await click("[data-content-publish]");
        current = state();
        assert.equal(current.version, 1);
    }
    const legacyHash = current.publications[0].hash;
    if (current.version === 1) {
        if (!current.draft.document) {
            await click("[data-toggle-content-format]");
            await populateRich();
            await save();
        }
        const saved = state();
        assert(saved.draft.document);
        assert.equal(saved.publications[0].hash, legacyHash);
        await goto(path);
        assert.equal(
            await evaluate("!!document.querySelector('[data-rich-document]')"),
            false,
        );
        await goto("/admin/content/" + saved.id);
        await click("[data-content-publish]");
        current = state();
        assert.equal(current.version, 2);
    }
    current = state();
    const previousHashes = current.publications.map((p) => p.hash);
    if (
        !current.publications
            .at(-1)
            .definition.document.blocks.some((b) => b.type === "bullet_list")
    ) {
        await goto("/admin/content/" + current.id);
        await populateRich();
        await save();
        await click("[data-content-publish]");
        current = state();
    }
    const richHash = current.publications.at(-1).hash;
    await goto(path);
    assert.equal(
        await evaluate(
            "document.querySelector('[data-rich-document] h2')?.textContent",
        ),
        publicHeading,
    );
    for (const selector of ["strong", "em", "ul li", "blockquote", "a"])
        assert(
            await evaluate(
                `!!document.querySelector('[data-rich-document] ${selector}')`,
            ),
            selector,
        );
    assert.equal(
        await evaluate(
            "document.querySelector('[data-rich-document] a').getAttribute('href')",
        ),
        "https://example.test/path?q=1&b=2",
    );
    assert.equal(
        await evaluate(
            "document.querySelector('[data-rich-document] p').textContent",
        ),
        "واژه پررنگ و واژه لینک برای بررسی نرم‌افزاری.",
    );
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-rich-document] img').length",
        ),
        0,
    );
    assert.equal(await evaluate("Boolean(window.richCmsXss)"), false);
    await responsive();
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 900,
        deviceScaleFactor: 1,
        mobile: true,
    });
    lease();
    await writeFile(
        "/tmp/kardorost-rich-content76-mobile.png",
        Buffer.from(
            (
                await send("Page.captureScreenshot", {
                    format: "png",
                    captureBeyondViewport: false,
                })
            ).data,
            "base64",
        ),
    );
    await goto("/admin/content/" + current.id);
    await responsive();
    if (!current.schedules.length) {
        await heading("تیتر خصوصی بعدی۷۶");
        const parts = Object.fromEntries(
            new Intl.DateTimeFormat("en-US-u-ca-persian-nu-latn", {
                year: "numeric",
                month: "numeric",
                day: "numeric",
                timeZone: "Asia/Tehran",
            })
                .formatToParts(new Date(Date.now() + 2 * 86400000))
                .map((p) => [p.type, p.value]),
        );
        for (const [prefix, key] of [
            ["سال", "year"],
            ["ماه", "month"],
            ["روز", "day"],
        ])
            await fill(
                `select[aria-label="${prefix} انتشار محتوا"]`,
                String(Number(parts[key])),
            );
        await fill("[name=content_schedule_time]", "13:00");
        await click("[name=content_schedule_reviewed]");
        await submit("[data-content-schedule]");
        await networkIdle();
        current = state();
        assert.equal(current.schedules.length, 1);
        assert.equal(current.schedules[0].status, "pending");
    }
    if (state().schedules[0].status === "pending") {
        await goto("/admin/content/" + current.id);
        await heading("تیتر خصوصی پایانی۷۶");
    }
    current = state();
    assert.equal(current.schedules.length, 1);
    assert.equal(current.schedules[0].status, "cancelled");
    assert(
        current.schedules[0].definition.document.blocks.some(
            (b) =>
                b.type === "heading" &&
                b.children.some((r) => r.text === "تیتر خصوصی بعدی۷۶"),
        ),
    );
    assert.equal(current.publications[0].hash, legacyHash);
    assert.equal(current.publications.at(-1).hash, richHash);
    for (const [i, hash] of previousHashes.entries())
        assert.equal(current.publications[i].hash, hash);
    await login("student");
    lease();
    assert.equal(
        await evaluate(
            `(async()=>{const r=await fetch('/admin/content/${current.id}',{headers:{Accept:'application/json'}});await r.text();return r.status})()`,
        ),
        403,
    );
    await goto(path);
    assert.equal(
        await evaluate(
            "document.querySelector('[data-rich-document] h2').textContent",
        ),
        publicHeading,
    );
    await login("admin");
    await goto("/admin/content/" + current.id);
    const savedDraft = JSON.stringify(state().draft);
    await select("فهرست دوم");
    await toolbar("فهرست شماره‌دار");
    assert(
        await evaluate(
            "!!document.querySelector('[data-content-document] ol li')",
        ),
    );
    await toolbar("جداکننده");
    assert(
        await evaluate(
            "!!document.querySelector('[data-content-document] hr')",
        ),
    );
    await select("نقل‌قول صرفاً آزمایشی");
    await evaluate(
        `(()=>{const data=new DataTransfer();data.setData('text/plain','متن چسبانده‌شده صرفاً آزمایشی');data.setData('text/html','<img src=x onerror="window.richCmsXss=true">');document.querySelector('[data-content-document]').dispatchEvent(new ClipboardEvent('paste',{bubbles:true,cancelable:true,clipboardData:data}))})()`,
    );
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-content-document] img').length",
        ),
        0,
    );
    assert(
        await evaluate(
            "document.querySelector('[data-content-document]').textContent.includes('متن چسبانده‌شده صرفاً آزمایشی')",
        ),
    );
    await click("[data-toggle-content-format]");
    assert(
        await evaluate(
            "document.querySelector('[name=body]').value.includes('متن چسبانده‌شده صرفاً آزمایشی')",
        ),
    );
    await goto("/admin/content/" + current.id);
    assert.equal(JSON.stringify(state().draft), savedDraft);
    assert.equal(state().financial_hash, initialFinancial);
    assert.equal(state().fixture_complete, true);
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            mode: "production-image-rich-CMS",
            publications: current.publications.length,
            cancelled_schedule: 1,
            checks: [
                "actual-toolbar-heading-bold-italic-list-quote-link",
                "unsaved-ordered-list-divider-text-only-paste-and-plain-conversion-preserve-saved-draft",
                "unsafe-link-refused-and-literal-html-escaped",
                "legacy-publication-and-rich-publication-immutable",
                "draft-edit-cancels-frozen-rich-schedule-with-history",
                "student-editor403",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
