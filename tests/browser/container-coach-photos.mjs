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
from container_runtime_probes import COACH_PHOTO_FIXTURE_STATE
spec=importlib.util.spec_from_file_location('recovery',root/'resources/operations/recovery.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
subprocess.run(['python3','/workspace/work/kardorost-autonomy-lease.py','touch',os.environ['KARDOROST_LEASE_OWNER']],check=True,stdout=subprocess.DEVNULL)
environment=Path(sys.argv[1]).resolve();assert environment.is_relative_to('/tmp') and environment.name.startswith('kardorost-compose-')
c=m.Composition(environment);assert c.config['name'].startswith('kardorost-validation-') and c.settings['SMS_DRIVER']=='disabled' and c.settings['ZIBAL_ENABLED']=='false' and c.settings['APP_URL']=='http://127.0.0.1:18089'
print(c.run(['exec','-T','app','php','-r',COACH_PHOTO_FIXTURE_STATE]))
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
const titles = [
    "عکس فعال کانتینر صرفاً آزمایشی۸۰",
    "عکس خصوصی کانتینر صرفاً آزمایشی۸۰",
    "عکس برداشته‌شده کانتینر صرفاً آزمایشی۸۰",
];
async function click(selector) {
    lease();
    await evaluate(
        `document.querySelector(${JSON.stringify(selector)}).click()`,
    );
    await networkIdle();
}
async function status(path) {
    return evaluate(
        `(async()=>{const r=await fetch(${JSON.stringify(path)});await r.arrayBuffer();return r.status})()`,
    );
}
function publicPath(s, index) {
    const p = s.photos[index];
    return `/mentor-photos/${s.profile.slug}/${p.key}/${p.sha}`;
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
        await evaluate(
            "new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))",
        );
        assert(
            await evaluate(
                "document.documentElement.scrollWidth<=innerWidth+1",
            ),
            "No overflow " + width,
        );
    }
}
async function screenshot(path) {
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 900,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await evaluate(
        "new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))",
    );
    await until(
        "!document.querySelector('.sidebar') || document.querySelector('.sidebar').getBoundingClientRect().left>=innerWidth-1",
    );
    lease();
    await writeFile(
        path,
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
}
async function upload(index) {
    await goto("/coach/profile-photos");
    await evaluate(
        "document.querySelector('[data-coach-photo-upload]').closest('details').open=true",
    );
    await fill("[name=photo_title]", titles[index - 1]);
    const bytes = await evaluate(
        `(()=>{const c=document.createElement('canvas');c.width=1536;c.height=1024;const x=c.getContext('2d');x.fillStyle='${index === 1 ? "#238d83" : "#5355bb"}';x.fillRect(0,0,c.width,c.height);x.fillStyle='#d7eeea';x.fillRect(200,200,300,300);return c.toDataURL('image/jpeg',.9).split(',')[1]})()`,
    );
    const path = `/tmp/kardorost-photo80-source${index}.jpg`;
    lease();
    await writeFile(path, Buffer.from(bytes, "base64"));
    const root = await send("DOM.getDocument");
    const input = await send("DOM.querySelector", {
        nodeId: root.root.nodeId,
        selector: "[name=photo_file]",
    });
    lease();
    await send("DOM.setFileInputFiles", {
        nodeId: input.nodeId,
        files: [path],
    });
    await until(
        "document.querySelector('[data-prepared-coach-photo]')?.complete && document.querySelector('[data-prepared-coach-photo]')?.naturalWidth===768",
    );
    await click("[name=photo_consent]");
    await submit("[data-coach-photo-upload]");
    await networkIdle();
    assert.equal(state().photos.length, index);
    assert.equal(state().photos[index - 1].width, 768);
    assert.equal(state().photos[index - 1].height, 512);
    assert.equal(state().photos[index - 1].file_name, "profile-photo.png");
    assert.equal(state().photos[index - 1].mime, "image/png");
}
async function savePhoto(index) {
    await goto("/coach/profile");
    await fill("[name=coach_photo]", state().photos[index].key);
    await submit("[data-coach-profile-editor]");
    await networkIdle();
}
async function submitProfile() {
    await goto("/coach/profile");
    await click("[name=coach_public_consent]");
    await submit("[data-coach-profile-submit]");
    await networkIdle();
    assert.equal(state().profile.state, "submitted");
}
async function review() {
    const s = state();
    await login("admin");
    await goto("/admin/coach-profiles/" + s.profile.id);
    await until("document.querySelector('[data-coach-profile-review]')!==null");
    await click("[name=coach_review_confirmed]");
    await submit("[data-coach-profile-review]");
    await networkIdle();
    assert.equal(state().profile.state, "approved");
}
async function imageCheck(s, index) {
    await goto("/mentors/" + s.profile.slug);
    await until(
        "document.querySelector('[data-public-coach-photo]')?.complete && document.querySelector('[data-public-coach-photo]')?.naturalWidth===768",
    );
    const path = publicPath(s, index);
    const d = await evaluate(
        `fetch(${JSON.stringify(path)},{credentials:'omit'}).then(async r=>({status:r.status,mime:r.headers.get('content-type'),sniff:r.headers.get('x-content-type-options'),cache:r.headers.get('cache-control'),disposition:r.headers.get('content-disposition'),sha:Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',await r.arrayBuffer()))).map(v=>v.toString(16).padStart(2,'0')).join('')}))`,
    );
    assert.equal(d.status, 200);
    assert.equal(d.sha, s.photos[index].sha);
    assert.equal(d.mime, "image/png");
    assert(d.sniff.includes("nosniff"));
    assert(d.cache.includes("no-store"));
    assert(!d.disposition.includes("profile-photo"));
    await responsive();
}
try {
    lease();
    let s = state();
    const initial = s;
    const financial = s.financial_hash;
    await login("coach");
    for (let index = s.photos.length + 1; index <= 3; index++) {
        await upload(index);
    }
    s = state();
    if (!s.profile) {
        await goto("/coach/profile");
        for (const [name, value] of Object.entries({
            coach_display_name: "مربی کانتینر صرفاً آزمایشی۸۰",
            coach_headline: "معرفی نرم‌افزاری بدون اعتبار واقعی۸۰",
            coach_bio:
                "این معرفی صرفاً برای آزمون بازیابی نرم‌افزار است و شرح حال شخص واقعی یا تأیید حرفه‌ای نیست.",
            coach_city: "شهر آزمایشی",
            coach_focus_0: "زمینه آزمایشی",
            coach_language_0: "فارسی",
            coach_photo: s.photos[2].key,
        })) {
            await fill("[name=" + name + "]", value);
        }
        await submit("[data-coach-profile-editor]");
        await networkIdle();
        s = state();
    }
    if (!s.profile.publications.length) {
        assert.equal(await status(publicPath(s, 2)), 404);
        if (s.profile.state !== "submitted") await submitProfile();
        assert.equal(await status(publicPath(state(), 2)), 404);
        await review();
        s = state();
        await imageCheck(s, 2);
    }
    s = state();
    const before = s.photos.map((p) => ({
        definition_hash: p.definition_hash,
        actual_hash: p.actual_hash,
    }));
    const firstVersion = s.profile.publications[0];
    if (!s.photos[2].withdrawn) {
        await login("coach");
        await goto("/coach/profile-photos");
        await submit(
            `[data-coach-photo="${s.photos[2].id}"] [data-coach-photo-withdraw]`,
        );
        await networkIdle();
        assert(state().photos[2].withdrawn);
    }
    s = state();
    assert.equal(await status(publicPath(s, 2)), 404);
    if (s.profile.publications.length === 1) {
        await login("coach");
        if (s.profile.draft.photo?.key !== s.photos[0].key) await savePhoto(0);
        s = state();
        assert.equal(await status(publicPath(s, 0)), 404);
        if (s.profile.state !== "submitted") await submitProfile();
        await review();
    }
    s = state();
    assert.equal(s.profile.publications.length, 2);
    assert.deepEqual(s.profile.publications[0], firstVersion);
    const versions = s.profile.publications;
    if (s.profile.draft.headline !== "عنوان خصوصی پایانی۸۰") {
        await login("coach");
        await goto("/coach/profile");
        await fill("[name=coach_photo]", s.photos[1].key);
        await fill("[name=coach_headline]", "عنوان خصوصی پایانی۸۰");
        await submit("[data-coach-profile-editor]");
        await networkIdle();
    }
    s = state();
    assert(s.fixture_complete);
    assert.equal(await status(publicPath(s, 1)), 404);
    assert.equal(await status(publicPath(s, 2)), 404);
    await imageCheck(s, 0);
    await screenshot("/tmp/kardorost-photo80-public-mobile.png");
    await goto("/mentors");
    await until(
        "document.querySelector('[data-public-coach-photo]')?.complete && document.querySelector('[data-public-coach-photo]')?.naturalWidth===768",
    );
    await responsive();
    await login("student");
    assert.equal(
        await status("/coach/profile-photos/" + s.photos[1].key + "/preview"),
        403,
    );
    await goto("/coach/profile-photos");
    assert(
        await evaluate(
            "document.body.innerText.includes('هنوز عکسی ثبت نشده است.')",
        ),
    );
    assert.equal(await status(publicPath(s, 0)), 200);
    await login("organization", process.env.BROWSER_ORGANIZATION_PHONE);
    assert.equal(
        await status("/coach/profile-photos/" + s.photos[1].key + "/preview"),
        403,
    );
    await goto("/coach/profile-photos");
    assert(
        await evaluate("!document.querySelector('[data-coach-photo-upload]')"),
    );
    await login("admin");
    await goto("/admin/coach-profiles/" + s.profile.id);
    await until(
        "document.querySelectorAll('[data-admin-profile-photos] [data-coach-photo]').length===3",
    );
    assert.equal(
        await status("/coach/profile-photos/" + s.photos[1].key + "/preview"),
        200,
    );
    assert.equal(
        await status("/coach/profile-photos/" + s.photos[2].key + "/preview"),
        404,
    );
    await responsive();
    await login("coach");
    await goto("/coach/profile-photos");
    await responsive();
    await screenshot("/tmp/kardorost-photo80-gallery-mobile.png");
    const final = state();
    assert(final.fixture_complete);
    assert.deepEqual(final.profile.publications, versions);
    assert.deepEqual(
        final.photos.map((p) => ({
            definition_hash: p.definition_hash,
            actual_hash: p.actual_hash,
        })),
        before,
    );
    assert.equal(final.financial_hash, financial);
    assert.deepEqual(exceptions, []);
    if (initial.fixture_complete) {
        assert.deepEqual(final, initial);
    }
    console.log(
        JSON.stringify({
            result: "passed",
            prepared_photos: 3,
            publications: 2,
            states: [
                "current-public-active",
                "unpublished-private-draft",
                "previously-public-terminal-withdrawn",
            ],
            prepared_size: "768x512",
            checks: [
                "actual-JPEG-canvas-resize-PNG-upload-and-same-version-consent-review",
                "current-reviewed-profile-only-public-image",
                "private-draft-preserves-public-version",
                "anonymous-SHA-MIME-nosniff-no-store",
                "withdrawn-public-and-private404-student-organization-private403",
                "source-bound-consent-ciphertext-prepared-bytes-publication-and-financial-hashes-retained",
                "all-four-panels-320-390-768-1440",
                "completed-fixture-readonly-replay",
            ],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
