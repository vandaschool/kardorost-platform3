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
    new URL(base).port === "8038" &&
        process.env.DB_DATABASE === "/tmp/kardorost-media-browser.sqlite" &&
        process.env.BROWSER_PHP_BINARY,
    "Only the owned four-user media fixture.",
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

const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==4||\App\Models\User::where('name','like','بررسی رسانه صرفاً آزمایشی%')->count()!==4||\App\Models\Payment::count()||\App\Models\Enrollment::count()||\App\Models\ContentMedia::count()>2||\App\Models\ContentPage::count()>1){throw new RuntimeException('Owned software-only four-user fixture.');}$service=app(\App\Services\ContentMediaLibrary::class);$media=\App\Models\ContentMedia::orderBy('id')->get()->map(function($a)use($service){$m=$service->metadata($a);if(!$m||!in_array($m['title'],['تصویر عمومی صرفاً آزمایشی۷۷','تصویر خصوصی صرفاً آزمایشی۷۷'],true)){throw new RuntimeException('Preserve unrelated images.');}return ['id'=>$a->id,'key'=>$a->public_key,'title'=>$m['title'],'sha'=>$m['sha256'],'actual_hash'=>hash_file('sha256',\Illuminate\Support\Facades\Storage::disk('learning')->path($a->file_path)),'definition_hash'=>hash('sha256',$a->getRawOriginal('definition')),'published'=>(bool)$a->published_at,'withdrawn'=>(bool)$a->withdrawn_at];})->all();$p=\App\Models\ContentPage::first();if($p&&($p->slug!=='software-media77'||$p->kind!=='article')){throw new RuntimeException('Preserve unrelated page.');}echo json_encode(['media'=>$media,'page'=>$p?['id'=>$p->id,'version'=>$p->current_version,'draft'=>$p->draft,'publications'=>$p->publications()->get()->map(fn($v)=>['definition'=>$v->definition,'hash'=>hash('sha256',json_encode($v->getAttributes(),JSON_THROW_ON_ERROR))])->all()]:null],JSON_THROW_ON_ERROR);`;
function state() {
    return JSON.parse(
        execFileSync(process.env.BROWSER_PHP_BINARY, ["-r", code], {
            encoding: "utf8",
            env: {
                ...process.env,
                DB_CONNECTION: "sqlite",
                SMS_DRIVER: "disabled",
                ZIBAL_ENABLED: "false",
            },
        }),
    );
}
async function click(selector) {
    lease();
    await evaluate(
        `document.querySelector(${JSON.stringify(selector)}).click()`,
    );
    await networkIdle();
}
async function upload(index) {
    await goto("/admin/media");
    await evaluate(
        "document.querySelector('[data-media-upload]').closest('details').open=true",
    );
    await fill(
        "[data-media-upload] [name=title]",
        index === 1
            ? "تصویر عمومی صرفاً آزمایشی۷۷"
            : "تصویر خصوصی صرفاً آزمایشی۷۷",
    );
    const root = await send("DOM.getDocument");
    const n = await send("DOM.querySelector", {
        nodeId: root.root.nodeId,
        selector: "[data-media-upload] input[type=file]",
    });
    lease();
    await send("DOM.setFileInputFiles", {
        nodeId: n.nodeId,
        files: [
            index === 1
                ? "/tmp/kardorost-media77.png"
                : "/tmp/kardorost-media77.jpg",
        ],
    });
    await click("[data-media-upload] [name=rights_confirmed]");
    await submit("[data-media-upload]");
    await networkIdle();
    await goto("/admin/media");
    assert.equal(state().media.length, index);
}
async function status(path) {
    return evaluate(
        `(async()=>{const r=await fetch(${JSON.stringify(path)});await r.arrayBuffer();return r.status})()`,
    );
}
async function addImage(media, alt) {
    await click("[data-media-picker-toggle]");
    await until("!!document.querySelector('[data-media-option]')");
    await fill('[aria-label="جست‌وجوی تصویر"]', "عمومی");
    await click("[data-media-search]");
    await until("document.querySelectorAll('[data-media-option]').length===1");
    await click('[data-media-option="' + media.key + '"]');
    await fill('[aria-label="شرح تصویر"]', alt);
    await fill('[aria-label="زیرنویس تصویر"]', "زیرنویس نرم‌افزاری۷۷");
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
                "(()=>{const h=document.querySelector('.public-heading h1');if(!h)return true;const range=document.createRange();range.selectNodeContents(h);return Array.from(range.getClientRects()).every(r=>r.left>=-1 && r.right<=innerWidth+1)})()",
            ),
            "Public heading text fits " + width,
        );
        assert(
            await evaluate(
                "document.documentElement.scrollWidth<=innerWidth+1",
            ),
            "No overflow " + width,
        );
    }
}
const alt = "تصویر نمونه صرفاً آزمایشی۷۷",
    path = "/journal/software-media77";
try {
    lease();
    await login("admin");
    let s = state();
    if (!s.media.length) await upload(1);
    s = state();
    let image = s.media[0];
    const imageHash = image.definition_hash;
    const byteHash = image.actual_hash;
    assert.equal(byteHash, image.sha);
    if (!image.published && !image.withdrawn) {
        await goto("/admin/media");
        assert.equal(
            await status("/media/" + image.key + "/" + image.sha),
            404,
        );
        await click(
            `[data-media-card="${image.id}"] [data-media-publish] input[type=checkbox]`,
        );
        await submit(`[data-media-card="${image.id}"] [data-media-publish]`);
        await networkIdle();
        assert(state().media[0].published);
    }
    if (state().media.length === 1) await upload(2);
    s = state();
    const privateImage = s.media[1];
    assert(!privateImage.published && !privateImage.withdrawn);
    assert.equal(
        await status("/media/" + privateImage.key + "/" + privateImage.sha),
        404,
    );
    if (!s.page) {
        await goto("/admin/content");
        await evaluate(
            "document.querySelector('[data-content-create]').closest('details').open=true",
        );
        for (const [name, value] of Object.entries({
            kind: "article",
            slug: "software-media77",
            title: "مقاله رسانه صرفاً آزمایشی۷۷",
            excerpt: "بررسی نرم‌افزاری تصویر و سابقه.",
            body: "متن مقاله رسانه صرفاً آزمایشی۷۷",
        }))
            await fill("[data-content-create] [name=" + name + "]", value);
        await submit("[data-content-create]");
        await networkIdle();
        s = state();
    }
    await goto("/admin/content/" + s.page.id);
    if (!s.page.version) {
        if (!s.page.draft.document) await click("[data-toggle-content-format]");
        if (!s.page.draft.document?.blocks.some((b) => b.type === "image")) {
            await addImage(image, alt);
            await save();
        }
        assert(!state().page.draft.body.includes("حذف تصویر از متن"));
        await click("[data-content-publish]");
        assert.equal(state().page.version, 1);
    }
    s = state();
    const publicationHash = s.page.publications[0].hash;
    assert.equal(s.page.publications.length, 1);
    if (!s.media[0].withdrawn) {
        await goto(path);
        await until(
            "document.querySelector('[data-content-image] img')?.complete && document.querySelector('[data-content-image] img')?.naturalWidth===32",
        );
        assert.equal(
            await evaluate(
                "document.querySelector('[data-content-image] img').getAttribute('alt')",
            ),
            alt,
        );
        assert.equal(
            await evaluate(
                "document.querySelector('[data-content-image] figcaption').textContent",
            ),
            "زیرنویس نرم‌افزاری۷۷",
        );
        await responsive();
        const download = await evaluate(
            `fetch('/media/${image.key}/${image.sha}').then(async r=>({status:r.status,mime:r.headers.get('content-type'),sniff:r.headers.get('x-content-type-options'),cache:r.headers.get('cache-control'),hash:Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',await r.arrayBuffer()))).map(v=>v.toString(16).padStart(2,'0')).join('')}))`,
        );
        assert.equal(download.status, 200);
        assert.equal(download.mime, "image/png");
        assert.equal(download.sniff, "nosniff");
        assert(download.cache.includes("no-store"));
        assert.equal(download.hash, byteHash);
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
            "/tmp/kardorost-media77-public-mobile.png",
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
        await goto("/admin/content/" + s.page.id);
        if (
            s.page.draft.document.blocks.some(
                (b) => b.type === "image" && b.alt === alt,
            )
        ) {
            await click("[data-content-document] figure button");
            await save();
            assert(
                !state().page.draft.document.blocks.some(
                    (b) => b.type === "image",
                ),
            );
        }
        if (
            !state().page.draft.document.blocks.some((b) => b.type === "image")
        ) {
            await addImage(image, "شرح خصوصی بعدی۷۷");
            await save();
        }
        assert.equal(state().page.publications[0].hash, publicationHash);
        await goto(path);
        assert.equal(
            await evaluate(
                "document.querySelector('[data-content-image] img').getAttribute('alt')",
            ),
            alt,
        );
        await goto("/admin/media");
        await responsive();
        await submit(`[data-media-card="${image.id}"] [data-media-withdraw]`);
        await networkIdle();
        assert(state().media[0].withdrawn);
    }
    await goto(path);
    assert(
        await evaluate("!!document.querySelector('[data-unavailable-media]')"),
    );
    assert.equal(await status("/media/" + image.key + "/" + image.sha), 404);
    await responsive();
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
        "/tmp/kardorost-media77-withdrawn-public-mobile.png",
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
    await login("organization", process.env.BROWSER_ORGANIZATION_PHONE);
    assert.equal(await status("/admin/media/options"), 403);
    assert.equal(await status("/media/" + image.key + "/" + image.sha), 404);
    await login("admin");
    await goto("/admin/media");
    await responsive();
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 900,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await until(
        "!document.querySelector('.sidebar') || document.querySelector('.sidebar').getBoundingClientRect().left>=innerWidth-1",
    );
    lease();
    await writeFile(
        "/tmp/kardorost-media77-library-mobile.png",
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
    await goto("/admin/content/" + state().page.id);
    await until(
        "!!document.querySelector('[data-content-document] [data-unavailable-media-preview]')",
    );
    await evaluate(
        "Array.from(document.querySelectorAll('details')).filter(d=>d.querySelector('[data-content-document-preview]')).forEach(d=>d.open=true);document.querySelector('[data-content-document-preview]')?.scrollIntoView({block:'center'})",
    );
    await until(
        "!!document.querySelector('[data-content-document-preview] [data-unavailable-media-preview]')",
    );
    await evaluate(
        `(()=>{const d=new DataTransfer();d.setData('text/plain','متن رهاشده امن۷۷');d.setData('text/html','<img src=x onerror="window.dropXss=true">');document.querySelector('[data-content-document]').dispatchEvent(new DragEvent('drop',{bubbles:true,cancelable:true,dataTransfer:d}))})()`,
    );
    assert(
        await evaluate(
            "document.querySelector('[data-content-document]').textContent.includes('متن رهاشده امن۷۷')",
        ),
    );
    assert.equal(
        await evaluate(
            "Boolean(window.dropXss) || !!document.querySelector('[data-content-document] img[onerror]')",
        ),
        false,
    );
    const final = state();
    assert.equal(final.page.publications[0].hash, publicationHash);
    assert.equal(final.media[0].definition_hash, imageHash);
    assert.equal(final.media[0].actual_hash, byteHash);
    assert.equal(final.media[1].definition_hash, privateImage.definition_hash);
    assert.equal(final.media[1].actual_hash, privateImage.actual_hash);
    assert.equal(final.media.length, 2);
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            published_then_withdrawn_image: 1,
            retained_private_jpeg: 1,
            immutable_publication: 1,
            checks: [
                "actual-upload-explicit-publication-picker-alt-caption-save-render",
                "original-image-decoded32x24-SHA-mime-nosniff-no-store",
                "remove-and-reinsert-in-private-draft-preserves-public-version",
                "withdraw-public-image404-and-public-page-placeholder-with-history-and-bytes-retained",
                "private-image-not-public-or-listed-for-student-organization",
                "320-390-768-1440",
                "completed-fixture-replay-no-reset",
                "withdrawn-admin-editor-and-history-preview-message-text-only-drop-without-saving",
            ],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
