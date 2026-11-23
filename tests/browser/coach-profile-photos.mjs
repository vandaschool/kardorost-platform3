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

assert(
    new URL(base).port === "8039" &&
        process.env.DB_DATABASE ===
            "/tmp/kardorost-coach-photos-browser.sqlite" &&
        process.env.BROWSER_PHP_BINARY,
    "Owned five-user photo fixture only",
);
const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==5||\App\Models\User::where('name','like','بررسی عکس مربی صرفاً آزمایشی%')->count()!==5||\App\Models\Payment::count()||\App\Models\Enrollment::count()||\App\Models\CoachProfile::count()>1||\App\Models\CoachProfilePhoto::count()>2){throw new RuntimeException('Preserve unrelated fixture');}$u=\App\Models\User::where('phone','989121234561')->firstOrFail();$service=app(\App\Services\CoachProfilePhotos::class);$photos=\App\Models\CoachProfilePhoto::orderBy('id')->get()->map(function($p)use($service,$u){$m=$service->metadata($p);if(!$m||$p->user_id!==$u->id||!in_array($m['title'],['عکس نخست صرفاً آزمایشی۷۹','عکس دوم صرفاً آزمایشی۷۹'],true)){throw new RuntimeException('Unrelated photo');}return ['id'=>$p->id,'key'=>$p->public_key,'sha'=>$m['sha256'],'width'=>$m['width'],'height'=>$m['height'],'file_name'=>$m['file_name'],'mime'=>$m['mime'],'cipher'=>hash('sha256',$p->getRawOriginal('definition')),'bytes'=>hash_file('sha256',\Illuminate\Support\Facades\Storage::disk('learning')->path($p->file_path)),'withdrawn'=>(bool)$p->withdrawn_at,'withdrawal'=>$service->withdrawal($p)];})->all();$p=\App\Models\CoachProfile::first();echo json_encode(['owner_id'=>$u->id,'roles'=>$u->roleKeys(),'photos'=>$photos,'profile'=>$p?['id'=>$p->id,'slug'=>$p->slug,'state'=>$p->state,'revision'=>$p->revision,'draft'=>$p->draft,'public'=>$p->public_enabled,'publications'=>$p->publications()->orderBy('id')->get()->map(fn($r)=>['photo'=>$r->definition['photo']??null,'hash'=>hash('sha256',json_encode($r->getAttributes(),JSON_THROW_ON_ERROR))])->all()]:null],JSON_THROW_ON_ERROR);`;
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
    await fill(
        "[name=photo_title]",
        index === 1 ? "عکس نخست صرفاً آزمایشی۷۹" : "عکس دوم صرفاً آزمایشی۷۹",
    );
    const bytes = await evaluate(
        `(()=>{const c=document.createElement('canvas');c.width=1536;c.height=1024;const x=c.getContext('2d');x.fillStyle='${index === 1 ? "#238d83" : "#5355bb"}';x.fillRect(0,0,c.width,c.height);x.fillStyle='#d7eeea';x.fillRect(200,200,300,300);return c.toDataURL('image/jpeg',.9).split(',')[1]})()`,
    );
    const path = `/tmp/kardorost-photo79-source${index}.jpg`;
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
    assert.equal(d.sniff, "nosniff");
    assert(d.cache.includes("no-store"));
    assert(!d.disposition.includes("profile-photo"));
    await responsive();
}
try {
    lease();
    let s = state();
    const initial = s;
    if (!s.photos.length) {
        await login("coach");
        await upload(1);
        s = state();
    }
    if (!s.profile) {
        await login("coach");
        await goto("/coach/profile");
        for (const [name, value] of Object.entries({
            coach_display_name: "مربی نرم‌افزاری صرفاً آزمایشی۷۹",
            coach_headline: "پروفایل نرم‌افزاری بدون اعتبار واقعی",
            coach_bio:
                "این معرفی صرفاً برای آزمون نرم‌افزاری است و شرح حال فرد واقعی یا تأیید اعتبار حرفه‌ای نیست.",
            coach_city: "شهر آزمایشی",
            coach_focus_0: "زمینه آزمایشی",
            coach_language_0: "فارسی",
            coach_photo: s.photos[0].key,
        })) {
            await fill("[name=" + name + "]", value);
        }
        await submit("[data-coach-profile-editor]");
        await networkIdle();
        s = state();
    }
    if (!s.profile.publications.length) {
        await login("coach");
        assert.equal(await status(publicPath(s, 0)), 404);
        if (s.profile.state !== "submitted") await submitProfile();
        assert.equal(await status(publicPath(state(), 0)), 404);
        await review();
        await imageCheck(state(), 0);
        await screenshot("/tmp/kardorost-photo79-public-mobile.png");
        s = state();
    }
    const firstHash = s.profile.publications[0].hash;
    if (s.photos.length === 1) {
        await login("coach");
        await upload(2);
        s = state();
    }
    if (s.profile.publications.length === 1) {
        await login("coach");
        assert.equal(await status(publicPath(s, 1)), 404);
        if (s.profile.draft.photo?.key !== s.photos[1].key) await savePhoto(1);
        s = state();
        assert.equal(s.profile.publications[0].hash, firstHash);
        assert.equal(await status(publicPath(s, 0)), 200);
        assert.equal(await status(publicPath(s, 1)), 404);
        if (s.profile.state !== "submitted") await submitProfile();
        await review();
        s = state();
        await imageCheck(s, 1);
        assert.equal(await status(publicPath(s, 0)), 404);
    }
    s = state();
    const publications = s.profile.publications;
    assert.equal(publications.length, 2);
    assert.equal(publications[0].hash, firstHash);
    const retained = s.photos.map((p) => ({
        cipher: p.cipher,
        bytes: p.bytes,
    }));
    if (!s.photos[1].withdrawn) {
        await login("coach");
        await goto("/coach/profile-photos");
        await submit(
            `[data-coach-photo="${s.photos[1].id}"] [data-coach-photo-withdraw]`,
        );
        await networkIdle();
        assert(state().photos[1].withdrawn);
    }
    await goto("/mentors/" + s.profile.slug);
    assert.equal(await status(publicPath(s, 1)), 404);
    assert(
        await evaluate("!document.querySelector('[data-public-coach-photo]')"),
    );
    if (s.roles.includes("coach")) {
        await login("admin");
        await goto("/admin/users");
        await click(`[data-user-id="${s.owner_id}"] button`);
        await click("[name=access_student]");
        await click("[name=access_coach]");
        await submit("[data-testid=user-access]");
        await networkIdle();
        assert.deepEqual(state().roles, ["student"]);
    }
    s = state();
    await login("student", process.env.BROWSER_COACH_PHONE);
    await goto("/coach/profile-photos");
    assert(
        await evaluate("!document.querySelector('[data-coach-photo-upload]')"),
    );
    if (!s.photos[0].withdrawn) {
        await submit(
            `[data-coach-photo="${s.photos[0].id}"] [data-coach-photo-withdraw]`,
        );
        await networkIdle();
        assert(state().photos[0].withdrawn);
    }
    await responsive();
    await screenshot("/tmp/kardorost-photo79-owner-history-mobile.png");
    for (const p of s.photos) {
        assert.equal(
            await status("/coach/profile-photos/" + p.key + "/preview"),
            404,
        );
        assert.equal(
            await status(
                "/mentor-photos/" + s.profile.slug + "/" + p.key + "/" + p.sha,
            ),
            404,
        );
    }
    await login("student");
    assert.equal(
        await status("/coach/profile-photos/" + s.photos[0].key + "/preview"),
        403,
    );
    await goto("/coach/profile-photos");
    assert(
        await evaluate(
            "document.body.innerText.includes('هنوز عکسی ثبت نشده است.')",
        ),
    );
    await login("coach", process.env.BROWSER_PEER_PHONE);
    assert.equal(
        await status("/coach/profile-photos/" + s.photos[1].key + "/preview"),
        403,
    );
    await goto("/coach/profile-photos");
    await responsive();
    await login("organization", process.env.BROWSER_ORGANIZATION_PHONE);
    await goto("/coach/profile-photos");
    assert(
        await evaluate("!document.querySelector('[data-coach-photo-upload]')"),
    );
    await login("admin");
    await goto("/admin/coach-profiles/" + s.profile.id);
    await until(
        "document.querySelectorAll('[data-admin-profile-photos] [data-coach-photo]').length===2",
    );
    await responsive();
    const final = state();
    assert.deepEqual(final.profile.publications, publications);
    assert.deepEqual(
        final.photos.map((p) => ({ cipher: p.cipher, bytes: p.bytes })),
        retained,
    );
    assert(final.photos.every((p) => p.withdrawn && p.withdrawal));
    assert.deepEqual(exceptions, []);
    if (
        initial.photos.length === 2 &&
        initial.photos.every((p) => p.withdrawn)
    ) {
        assert.deepEqual(final, initial);
    }
    console.log(
        JSON.stringify({
            result: "passed",
            photos: 2,
            immutable_publications: 2,
            prepared_size: "768x512",
            checks: [
                "actual-JPEG-local-resize-PNG-upload-explicit-consent",
                "private-until-same-owner-profile-consent-and-admin-review",
                "draft-and-pending-photo-do-not-change-publication",
                "second-review-switches-public-URL-old-URL404",
                "anonymous-PNG-SHA-nosniff-no-store-opaque-name",
                "terminal-withdrawal-retains-bytes-ciphertext-consent-snapshots",
                "actual-role-removal-owner-can-withdraw-from-student-panel",
                "peer-student403-all-four-own-pages-empty-scoped",
                "320-390-768-1440",
                "completed-fixture-readonly-replay",
            ],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
