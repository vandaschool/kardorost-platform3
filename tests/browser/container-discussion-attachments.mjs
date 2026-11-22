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
from container_runtime_probes import DISCUSSION_ATTACHMENT_FIXTURE_STATE
spec=importlib.util.spec_from_file_location('recovery',root/'resources/operations/recovery.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
subprocess.run(['python3','/workspace/work/kardorost-autonomy-lease.py','touch',os.environ['KARDOROST_LEASE_OWNER']],check=True,stdout=subprocess.DEVNULL)
environment=Path(sys.argv[1]).resolve();assert environment.is_relative_to('/tmp') and environment.name.startswith('kardorost-compose-')
c=m.Composition(environment);assert c.config['name'].startswith('kardorost-validation-') and c.settings['SMS_DRIVER']=='disabled' and c.settings['ZIBAL_ENABLED']=='false' and c.settings['APP_URL']=='http://127.0.0.1:18089'
print(c.run(['exec','-T','app','php','-r',DISCUSSION_ATTACHMENT_FIXTURE_STATE]))
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
async function click(expression) {
    lease();
    await evaluate(expression);
}
async function status(path) {
    return await evaluate(
        `fetch(${JSON.stringify(path)}).then(async r=>{await r.text();return r.status})`,
    );
}
async function download(file) {
    const r = await evaluate(
        `fetch('/discussion-attachments/${file.id}/file').then(async r=>({status:r.status,cache:r.headers.get('cache-control'),sniff:r.headers.get('x-content-type-options'),robots:r.headers.get('x-robots-tag'),type:r.headers.get('content-type'),hash:Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',await r.arrayBuffer()))).map(v=>v.toString(16).padStart(2,'0')).join('')}))`,
    );
    assert.equal(r.status, 200);
    assert.equal(r.hash, file.sha256);
    assert(r.cache.includes("private") && r.cache.includes("no-store"));
    assert(r.sniff.includes("nosniff"));
    assert(r.robots.includes("noindex"));
    assert(r.type.startsWith("application/octet-stream"));
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
            "Production private attachment layout " + width,
        );
    }
}
async function upload(post, id) {
    const selector = `[data-attachment-upload="${post}"]`;
    await until(`!!document.querySelector(${JSON.stringify(selector)})`);
    await evaluate(
        `document.querySelector(${JSON.stringify(selector)}).closest('details').open=true`,
    );
    const name =
        id === 1
            ? "container-attachment74-active.pdf"
            : "container-attachment74-withdrawn.pdf";
    const path = "/tmp/" + name;
    lease();
    await writeFile(
        path,
        "%PDF-1.4\n% SOFTWARE-ONLY-NO-REAL-DOCUMENT-CONTAINER74-" +
            id +
            "\n%%EOF\n",
    );
    const root = await send("DOM.getDocument");
    const node = await send("DOM.querySelector", {
        nodeId: root.root.nodeId,
        selector: selector + " input[type=file]",
    });
    await send("DOM.setFileInputFiles", { nodeId: node.nodeId, files: [path] });
    await click(
        `document.querySelector(${JSON.stringify(selector + " input[type=checkbox]")}).click()`,
    );
    await submit(selector);
    await until(
        `document.querySelectorAll('[data-discussion-attachment]').length===${id}`,
    );
    await networkIdle();
}
try {
    let s = state();
    const beforeFinancial = s.financial_hash;
    if (!s.course) {
        await login("admin");
        await goto("/admin/courses");
        await click("document.querySelector('.page-intro button').click()");
        await until("!!document.querySelector('form.form-grid')");
        await fill(
            'form.form-grid input[maxlength="150"]',
            "دوره فایل کانتینر صرفاً آزمایشی۷۴",
        );
        await fill(
            "form.form-grid textarea",
            "دوره ساختگی برای بررسی فایل خصوصی در runtime واقعی کانتینر",
        );
        await fill('form.form-grid input[type=number][min="1"]', "10");
        await click(
            "document.querySelector('form.form-grid input[type=checkbox]').click()",
        );
        await submit("form.form-grid");
        await until("!document.querySelector('form.form-grid')");
        s = state();
    }
    if (s.course.coach_id !== s.coach_id) {
        await login("admin");
        await goto("/admin/courses");
        await evaluate(
            "(()=>{const row=Array.from(document.querySelectorAll('.simple-row')).find(r=>r.querySelector('strong')?.textContent==='دوره فایل کانتینر صرفاً آزمایشی۷۴');if(!row)throw Error('Owned course row missing');row.id='container-course74';})()",
        );
        await fill("#container-course74 .coach-select", String(s.coach_id));
        await networkIdle();
        s = state();
        assert.equal(s.course.coach_id, s.coach_id);
    }
    await login("student");
    if (!s.enrollment) {
        await goto("/courses");
        await evaluate(
            "(()=>{const card=Array.from(document.querySelectorAll('.course-card')).find(c=>c.innerText.includes('دوره فایل کانتینر صرفاً آزمایشی۷۴'));if(!card)throw Error('Owned course card missing');card.id='container-course74';})()",
        );
        await click(
            "document.querySelector('#container-course74 .cohort-checkout button').click()",
        );
        await until("location.pathname==='/learning'");
        await networkIdle();
        s = state();
    }
    if (!s.discussion) {
        await goto(`/cohorts/${s.cohort_id}/discussions`);
        await evaluate("document.querySelector('main details').open=true");
        await fill(
            "[name=discussion_title]",
            "گفت‌وگوی فایل کانتینر صرفاً آزمایشی۷۴",
        );
        await fill(
            "[name=discussion_body]",
            "فقط بررسی نرم‌افزاری فایل خصوصی و بازیابی؛ هیچ سند واقعی نیست.",
        );
        await submit("[data-discussion-create]");
        await until(
            "location.pathname.startsWith('/discussions/')&&!!document.querySelector('[data-discussion-post]')",
        );
        s = state();
    }
    const path = "/discussions/" + s.discussion.id;
    await goto(path);
    if (s.files.length === 0) {
        await upload(s.post.id, 1);
        s = state();
    }
    if (s.files.length === 1) {
        await upload(s.post.id, 2);
        s = state();
    }
    assert.equal(s.files.length, 2);
    const original = s.files.map((f) => [
        f.id,
        f.sha256,
        f.definition_cipher_hash,
        f.definition_hash,
    ]);
    const active = s.files.find(
        (f) => f.file_name === "container-attachment74-active.pdf",
    );
    const retired = s.files.find(
        (f) => f.file_name === "container-attachment74-withdrawn.pdf",
    );
    assert(!active.withdrawn);
    await download(active);
    if (!retired.withdrawn) {
        await goto("/discussion-attachments");
        await until(
            `!!document.querySelector('[data-own-attachment="${retired.id}"] form')`,
        );
        await submit(`[data-own-attachment="${retired.id}"] form`);
        await until(
            `!document.querySelector('[data-own-attachment="${retired.id}"] form')`,
        );
        await networkIdle();
        s = state();
    }
    assert(s.fixture_complete);
    assert.equal(
        await status("/discussion-attachments/" + retired.id + "/file"),
        404,
    );
    await download(active);
    await responsive();
    await login("organization");
    assert.equal(
        await status("/discussion-attachments/" + active.id + "/file"),
        403,
    );
    await goto("/discussion-attachments");
    assert(
        !(await evaluate(
            "document.body.innerText.includes('container-attachment74-active.pdf')",
        )),
    );
    await login("coach");
    await download(active);
    assert.equal(
        await status("/discussion-attachments/" + retired.id + "/file"),
        404,
    );
    await goto(`${path}/posts/${s.post.id}/history`);
    assert(
        await evaluate(
            "document.body.innerText.includes('container-attachment74-active.pdf')&&document.body.innerText.includes('container-attachment74-withdrawn.pdf')",
        ),
    );
    await login("student");
    await goto("/discussion-attachments");
    await responsive();
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await until(
        'document.querySelector(".sidebar").getBoundingClientRect().left>=innerWidth',
    );
    lease();
    await writeFile(
        "/tmp/kardorost-container-discussion74-mobile.png",
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
    s = state();
    assert(s.fixture_complete);
    assert.deepEqual(
        s.files.map((f) => [
            f.id,
            f.sha256,
            f.definition_cipher_hash,
            f.definition_hash,
        ]),
        original,
    );
    assert.equal(s.financial_hash, beforeFinancial);
    assert.equal(s.enrollment.status, "active");
    assert.equal(s.enrollment.amount, 0);
    assert.equal(exceptions.length, 0, exceptions.join("\n"));
    console.log(
        JSON.stringify({
            result: "passed",
            runtime: "production-nginx-fpm-pg",
            fixture: "four-owned-software-only-users",
            complete_fixture: true,
            active_attachment_download: true,
            withdrawn_attachment404: true,
            manager403: true,
            original_bytes_metadata_consent_retained: true,
            revision_history_retained: true,
            financial_rows_unchanged: true,
            widths: [320, 390, 768, 1440],
            live_services_verified: false,
        }),
    );
} finally {
    await send("Emulation.clearDeviceMetricsOverride");
    await send("Page.close");
    socket.close();
}
