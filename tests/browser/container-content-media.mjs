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
from container_runtime_probes import CONTENT_MEDIA_FIXTURE_STATE
spec=importlib.util.spec_from_file_location('recovery',root/'resources/operations/recovery.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
subprocess.run(['python3','/workspace/work/kardorost-autonomy-lease.py','touch',os.environ['KARDOROST_LEASE_OWNER']],check=True,stdout=subprocess.DEVNULL)
environment=Path(sys.argv[1]).resolve();assert environment.is_relative_to('/tmp') and environment.name.startswith('kardorost-compose-')
c=m.Composition(environment);assert c.config['name'].startswith('kardorost-validation-') and c.settings['SMS_DRIVER']=='disabled' and c.settings['ZIBAL_ENABLED']=='false' and c.settings['APP_URL']=='http://127.0.0.1:18089'
print(c.run(['exec','-T','app','php','-r',CONTENT_MEDIA_FIXTURE_STATE]))
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
const titles = [
    "تصویر فعال کانتینر صرفاً آزمایشی۷۸",
    "تصویر خصوصی کانتینر صرفاً آزمایشی۷۸",
    "تصویر برداشته‌شده کانتینر صرفاً آزمایشی۷۸",
];
const fixtureBytes = {
    png: "iVBORw0KGgoAAAANSUhEUgAAACAAAAAYCAYAAACbU/80AAAAOUlEQVR4AezRMQ0AIBADwM8jAAFoQRCi0AoyulyT7s2159ov2a5wDCBAgACBHvdUsi4gQIAAgbjABwAA//9mw+yTAAAABklEQVQDABoNThGFVA2/AAAAAElFTkSuQmCC",
    jpeg: "/9j/4AAQSkZJRgABAQAAAQABAAD/4gHYSUNDX1BST0ZJTEUAAQEAAAHIAAAAAAQwAABtbnRyUkdCIFhZWiAH4AABAAEAAAAAAABhY3NwAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQAA9tYAAQAAAADTLQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAlkZXNjAAAA8AAAACRyWFlaAAABFAAAABRnWFlaAAABKAAAABRiWFlaAAABPAAAABR3dHB0AAABUAAAABRyVFJDAAABZAAAAChnVFJDAAABZAAAAChiVFJDAAABZAAAAChjcHJ0AAABjAAAADxtbHVjAAAAAAAAAAEAAAAMZW5VUwAAAAgAAAAcAHMAUgBHAEJYWVogAAAAAAAAb6IAADj1AAADkFhZWiAAAAAAAABimQAAt4UAABjaWFlaIAAAAAAAACSgAAAPhAAAts9YWVogAAAAAAAA9tYAAQAAAADTLXBhcmEAAAAAAAQAAAACZmYAAPKnAAANWQAAE9AAAApbAAAAAAAAAABtbHVjAAAAAAAAAAEAAAAMZW5VUwAAACAAAAAcAEcAbwBvAGcAbABlACAASQBuAGMALgAgADIAMAAxADb/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAAYACADASIAAhEBAxEB/8QAFgABAQEAAAAAAAAAAAAAAAAAAAgJ/8QAGBABAAMBAAAAAAAAAAAAAAAAABhmpeP/xAAXAQEAAwAAAAAAAAAAAAAAAAAABgcI/8QAGhEBAAEFAAAAAAAAAAAAAAAAAAIFFlOi0v/aAAwDAQACEQMRAD8AzyFGRBtub1Ig23N6pmra76Jn1nynMUZEG25vUiDbc3qF30TPrPlRgAzaAA//2Q==",
};
async function upload(index) {
    await goto("/admin/media");
    await evaluate(
        "document.querySelector('[data-media-upload]').closest('details').open=true",
    );
    await fill("[data-media-upload] [name=title]", titles[index]);
    const path =
        "/tmp/container-media78-" +
        ["active.png", "private.jpg", "withdrawn.png"][index];
    const bytes = Buffer.from(
        fixtureBytes[index === 1 ? "jpeg" : "png"],
        "base64",
    );
    try {
        assert(
            readFileSync(path).equals(bytes),
            "Preserve an unrelated raster fixture.",
        );
    } catch (e) {
        if (e.code !== "ENOENT") throw e;
        lease();
        await writeFile(path, bytes, { mode: 0o600 });
    }
    const root = await send("DOM.getDocument");
    const node = await send("DOM.querySelector", {
        nodeId: root.root.nodeId,
        selector: "[data-media-upload] input[type=file]",
    });
    lease();
    await send("DOM.setFileInputFiles", { nodeId: node.nodeId, files: [path] });
    await click("[data-media-upload] [name=rights_confirmed]");
    await submit("[data-media-upload]");
    await networkIdle();
    await goto("/admin/media");
    assert.equal(state().media.length, index + 1);
}
async function approve(media) {
    await goto("/admin/media");
    await click(
        `[data-media-card="${media.id}"] [data-media-publish] input[type=checkbox]`,
    );
    await submit(`[data-media-card="${media.id}"] [data-media-publish]`);
    await networkIdle();
}
async function status(path) {
    return evaluate(
        `(async()=>{const r=await fetch(${JSON.stringify(path)});await r.arrayBuffer();return r.status})()`,
    );
}
async function addImage(media) {
    await click("[data-media-picker-toggle]");
    await until("!!document.querySelector('[data-media-option]')");
    await fill('[aria-label="جست‌وجوی تصویر"]', "فعال کانتینر");
    await click("[data-media-search]");
    await until("document.querySelectorAll('[data-media-option]').length===1");
    await click(`[data-media-option="${media.key}"]`);
    await fill('[aria-label="شرح تصویر"]', "شرح تصویر عمومی۷۸");
    await fill(
        '[aria-label="زیرنویس تصویر"]',
        "زیرنویس عمومی صرفاً نرم‌افزاری۷۸",
    );
    await click("[data-media-insert]");
    await until(
        "!!document.querySelector('[data-content-document] figure img')",
    );
}
async function save() {
    await submit("[data-content-draft]");
    await networkIdle();
    await goto("/admin/content/" + state().page.id);
}
async function setAlt(alt) {
    await evaluate(
        `(()=>{const e=document.querySelector('[data-content-document]');e.querySelector('figure img').alt=${JSON.stringify(alt)};e.dispatchEvent(new InputEvent('input',{bubbles:true}))})()`,
    );
    await save();
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
            "new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))",
        );
        assert(
            await evaluate(
                "document.documentElement.scrollWidth<=innerWidth+1",
            ),
            "No overflow " + width,
        );
        assert(
            await evaluate(
                "(()=>{const h=document.querySelector('.public-heading h1');if(!h)return true;const r=document.createRange();r.selectNodeContents(h);return Array.from(r.getClientRects()).every(x=>x.left>=-1&&x.right<=innerWidth+1)})()",
            ),
            "Heading fits " + width,
        );
    }
}
const publicPath = "/journal/software-media78";
try {
    lease();
    assert(process.env.BROWSER_ORGANIZATION_PHONE);
    const initialFinancial = state().financial_hash;
    await login("admin");
    let current = state();
    for (let i = current.media.length; i < 3; i++) await upload(i);
    current = state();
    let active = current.media[0],
        privateImage = current.media[1],
        withdrawn = current.media[2];
    assert(!privateImage.published && !privateImage.withdrawn);
    assert.equal(
        await status("/media/" + privateImage.key + "/" + privateImage.sha),
        404,
    );
    if (!active.published) {
        assert(!active.withdrawn);
        await approve(active);
    }
    if (!withdrawn.published) await approve(withdrawn);
    if (!state().media[2].withdrawn) {
        await goto("/admin/media");
        const before = state().media[2];
        await submit(
            `[data-media-card="${withdrawn.id}"] [data-media-withdraw]`,
        );
        await networkIdle();
        assert.equal(state().media[2].definition_hash, before.definition_hash);
        assert.equal(state().media[2].actual_hash, before.actual_hash);
    }
    current = state();
    assert(current.media[0].published && !current.media[0].withdrawn);
    assert(current.media[2].withdrawn);
    if (!current.page) {
        await goto("/admin/content");
        await evaluate(
            "document.querySelector('[data-content-create]').closest('details').open=true",
        );
        for (const [name, value] of Object.entries({
            kind: "article",
            slug: "software-media78",
            title: "مقاله رسانه کانتینر صرفاً آزمایشی۷۸",
            excerpt: "بررسی تصویر فعال، خصوصی و برداشته‌شده.",
            body: "متن مقاله تصویری کانتینر صرفاً آزمایشی۷۸",
        }))
            await fill("[data-content-create] [name=" + name + "]", value);
        await submit("[data-content-create]");
        await networkIdle();
        current = state();
    }
    await goto("/admin/content/" + current.page.id);
    if (!current.page.version) {
        if (!current.page.draft.document)
            await click("[data-toggle-content-format]");
        if (
            !current.page.draft.document?.blocks.some((b) => b.type === "image")
        ) {
            await addImage(active);
            await save();
        }
        await click("[data-content-publish]");
        assert.equal(state().page.version, 1);
    }
    current = state();
    assert([1, 2, 3].includes(current.page.publications.length));
    const publicHash = current.page.publications[0].hash;
    if (!current.page.schedules.length) {
        await setAlt("شرح خصوصی برنامه۷۸");
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
        await fill("[name=content_schedule_time]", "14:20");
        await click("[name=content_schedule_reviewed]");
        await submit("[data-content-schedule]");
        await networkIdle();
        assert.equal(state().page.schedules[0].status, "pending");
    }
    if (state().page.schedules[0].status === "pending") {
        await goto("/admin/content/" + current.page.id);
        await setAlt("شرح خصوصی پایانی۷۸");
    }
    current = state();
    assert(current.fixture_complete);
    assert.equal(current.page.publications[0].hash, publicHash);
    assert(
        current.page.schedules[0].definition.document.blocks.some(
            (b) =>
                b.type === "image" &&
                b.alt === "شرح خصوصی برنامه۷۸" &&
                b.sha256 === active.sha,
        ),
    );
    const frozen = state();
    await goto(publicPath);
    await until(
        "document.querySelector('[data-content-image] img')?.complete && document.querySelector('[data-content-image] img')?.naturalWidth===32",
    );
    assert.equal(
        await evaluate(
            "document.querySelector('[data-content-image] img').alt",
        ),
        "شرح تصویر عمومی۷۸",
    );
    assert.equal(
        await evaluate(
            "document.body.innerText.includes('شرح خصوصی پایانی۷۸')",
        ),
        false,
    );
    await responsive();
    const downloaded = await evaluate(
        `fetch('/media/${active.key}/${active.sha}',{credentials:'omit'}).then(async r=>({status:r.status,mime:r.headers.get('content-type'),sniff:r.headers.get('x-content-type-options'),cache:r.headers.get('cache-control'),hash:Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',await r.arrayBuffer()))).map(v=>v.toString(16).padStart(2,'0')).join('')}))`,
    );
    assert.equal(downloaded.status, 200);
    assert.equal(downloaded.mime, "image/png");
    assert(downloaded.sniff.includes("nosniff"));
    assert(downloaded.cache.includes("no-store"));
    assert.equal(downloaded.hash, active.sha);
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 900,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await evaluate(
        "new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))",
    );
    lease();
    await writeFile(
        "/tmp/kardorost-container-media78-mobile.png",
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
    await login("student");
    assert.equal(await status("/admin/media"), 403);
    assert.equal(
        await status("/admin/media/" + privateImage.key + "/preview"),
        403,
    );
    assert.equal(
        await status("/media/" + privateImage.key + "/" + privateImage.sha),
        404,
    );
    assert.equal(
        await status("/media/" + withdrawn.key + "/" + withdrawn.sha),
        404,
    );
    await login("organization", process.env.BROWSER_ORGANIZATION_PHONE);
    assert.equal(await status("/admin/media/options"), 403);
    assert.equal(
        await status("/media/" + withdrawn.key + "/" + withdrawn.sha),
        404,
    );
    await login("admin");
    await goto("/admin/media");
    await responsive();
    assert.equal(
        await status("/admin/media/" + withdrawn.key + "/preview"),
        404,
    );
    assert.equal(state().financial_hash, initialFinancial);
    assert.deepEqual(state(), frozen);
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            runtime: "production-nginx-fpm-pg",
            active_public_image: 1,
            private_jpeg: 1,
            withdrawn_image: 1,
            immutable_image_publications: current.page.publications.length,
            cancelled_image_schedule: 1,
            all_media_rows_ciphertexts_original_bytes_and_CMS_history_preserved: true,
            financial_rows_unchanged: true,
            widths: [320, 390, 768, 1440],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
