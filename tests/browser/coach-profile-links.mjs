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
assert(
    new URL(base).port === "8040" &&
        process.env.DB_DATABASE ===
            "/tmp/kardorost-coach-links-browser.sqlite" &&
        process.env.BROWSER_PHP_BINARY,
    "Owned four-user links fixture only",
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
let loginRetryAfter = 0;
const externalRequests = [];
socket.addEventListener("message", (event) => {
    const m = JSON.parse(event.data);
    if (
        m.method === "Network.requestWillBeSent" &&
        /^https?:/.test(m.params.request.url) &&
        new URL(m.params.request.url).origin !== new URL(base).origin
    )
        externalRequests.push(m.params.request.url);
    if (
        m.method === "Network.responseReceived" &&
        m.params.response.status === 429 &&
        new URL(m.params.response.url).pathname === "/login"
    ) {
        const entry = Object.entries(m.params.response.headers).find(
            ([name]) => name.toLowerCase() === "retry-after",
        );
        const seconds = Number(entry?.[1]);
        if (Number.isInteger(seconds) && seconds > 0 && seconds <= 60)
            loginRetryAfter = seconds;
    }

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
    for (let attempt = 0; attempt < 3; attempt++) {
        await networkIdle();
        await send("Page.navigate", { url: "about:blank" });
        await until("location.href==='about:blank'");
        await send("Network.clearBrowserCookies");
        await send("Page.navigate", { url: base + "/login" });
        await until("!!document.querySelector('input[name=password]')");
        await networkIdle();
        await fill("[name=phone]", phone);
        await fill("[name=password]", password);
        loginRetryAfter = 0;
        await submit("form");
        for (let index = 0; index < 100; index++) {
            if (await evaluate(`location.pathname==='/${role}'`)) return;
            if (loginRetryAfter > 0) break;
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
        assert(
            loginRetryAfter > 0,
            "Fixture login must either finish or return an actual bounded Retry-After.",
        );
        await networkIdle();
        console.log(
            "Honoring actual login Retry-After: " +
                loginRetryAfter +
                " seconds.",
        );
        const deadline = Date.now() + loginRetryAfter * 1000 + 250;
        while (Date.now() < deadline)
            await new Promise((resolve) =>
                setTimeout(resolve, Math.min(20000, deadline - Date.now())),
            );
    }
    throw new Error(
        "Fixture login remained rate limited after three bounded attempts.",
    );
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

const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==4||\App\Models\User::where('name','like','بررسی پیوند مربی صرفاً آزمایشی%')->count()!==4||\App\Models\Payment::count()||\App\Models\Enrollment::count()||\App\Models\CoachProfile::count()>1){throw new RuntimeException('Preserve unrelated fixture');}$owner=\App\Models\User::where('phone','989121234561')->firstOrFail();$p=\App\Models\CoachProfile::first();if($p&&($p->user_id!==$owner->id||$p->draft['display_name']!=='مربی پیوند صرفاً آزمایشی۸۱')){throw new RuntimeException('Preserve unrelated profile');}$hash=fn($v)=>hash('sha256',json_encode($v->getAttributes(),JSON_THROW_ON_ERROR));$pubs=$p?$p->publications()->orderBy('id')->get():collect();$subs=$p?$p->submissions()->orderBy('id')->get():collect();if($pubs->count()>3||$subs->count()>4){throw new RuntimeException('Preserve unrelated history');}foreach($pubs->pluck('definition')->push($p?->draft)->filter()as$d){foreach($d['links']??[]as$l){if(!str_starts_with($l['url'],'https://example.test/coach-links81/')){throw new RuntimeException('Preserve unrelated links');}}}echo json_encode(['owner_id'=>$owner->id,'roles'=>$owner->roleKeys(),'profile'=>$p?['id'=>$p->id,'slug'=>$p->slug,'revision'=>$p->revision,'state'=>$p->state,'public'=>$p->public_enabled,'draft'=>$p->draft,'submission_status'=>$p->latestSubmission?->status,'publications'=>$pubs->map(fn($v)=>['number'=>$v->number,'definition'=>$v->definition,'hash'=>$hash($v)])->all(),'submissions'=>$subs->map(fn($v)=>['status'=>$v->status,'hash'=>$hash($v)])->all()]:null],JSON_THROW_ON_ERROR);`;
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
const first = "https://example.test/coach-links81/first",
    second = "https://example.test/coach-links81/second",
    privateLink = "https://example.test/coach-links81/private",
    literal = "https://example.test/coach-links81/literal?x=1&y=2";
async function addLink(label, url) {
    const n = await evaluate(
        "document.querySelectorAll('[data-coach-link-row]').length",
    );
    await click("[data-add-coach-link]");
    await fill(`[name=coach_link_label_${n}]`, label);
    await fill(`[name=coach_link_url_${n}]`, url);
}
async function save() {
    await submit("[data-coach-profile-editor]");
    await networkIdle();
}
async function consent() {
    await goto("/coach/profile");
    await click("[name=coach_public_consent]");
    await submit("[data-coach-profile-submit]");
    await networkIdle();
    assert.equal(state().profile.state, "submitted");
}
async function review(action = "publish") {
    await login("admin");
    await goto("/admin/coach-profiles/" + state().profile.id);
    if (action === "reject") {
        await fill("[name=coach_review_action]", "reject");
        await fill("[name=coach_review_note]", "توضیح خصوصی صرفاً آزمایشی۸۱");
    } else await click("[name=coach_review_confirmed]");
    await submit("[data-coach-profile-review]");
    await networkIdle();
    assert.equal(
        state().profile.state,
        action === "publish" ? "approved" : "rejected",
    );
}
async function publicLinks() {
    await goto("/mentors/" + state().profile.slug);
    await responsive();
    return evaluate(
        "Array.from(document.querySelectorAll('[data-public-coach-links] a')).map(a=>({href:a.getAttribute('href'),target:a.target,rel:a.rel,text:a.textContent}))",
    );
}
try {
    lease();
    let s = state();
    const initial = s;
    if (!s.profile) {
        await login("coach");
        await goto("/coach/profile");
        for (const [name, value] of Object.entries({
            coach_display_name: "مربی پیوند صرفاً آزمایشی۸۱",
            coach_headline: "معرفی پیوندهای صرفاً آزمایشی۸۱",
            coach_bio:
                "این معرفی فقط برای بررسی نرم‌افزار است و شرح حال شخص واقعی یا تأیید اعتبار حرفه‌ای نیست.",
            coach_city: "شهر آزمایشی",
            coach_focus_0: "زمینه آزمایشی",
            coach_language_0: "فارسی",
        })) {
            await fill("[name=" + name + "]", value);
        }
        await addLink("وب‌سایت آزمایشی۸۱", first);
        await addLink(
            '<img src=x onerror="window.coachLinkXss=true">',
            literal,
        );
        for (let n = 2; n < 5; n++) await click("[data-add-coach-link]");
        assert(
            await evaluate(
                "document.querySelector('[data-add-coach-link]').disabled",
            ),
        );
        for (let n = 4; n >= 2; n--)
            await click(`[data-remove-coach-link="${n}"]`);
        await fill("[name=coach_link_url_0]", "javascript:alert(81)");
        await evaluate(
            "document.querySelector('[data-coach-profile-editor]').noValidate=true",
        );
        await save();
        await until("!!document.querySelector('.field-error')");
        assert.equal(state().profile, null);
        await fill("[name=coach_link_url_0]", first);
        await evaluate(
            "document.querySelector('[data-coach-profile-editor]').noValidate=false",
        );
        await save();
        s = state();
        assert.equal(s.profile.draft.links.length, 2);
    }
    if (!s.profile.publications.length) {
        await login("coach");
        assert.equal(await status("/mentors/" + s.profile.slug), 404);
        if (s.profile.state !== "submitted") await consent();
        assert.equal(await status("/mentors/" + s.profile.slug), 404);
        await review();
        const links = await publicLinks();
        assert.deepEqual(
            links.map((x) => x.href),
            [first, literal],
        );
        assert(
            links.every(
                (x) =>
                    x.target === "_blank" &&
                    x.rel.includes("noopener") &&
                    x.rel.includes("noreferrer"),
            ),
        );
        assert(
            await evaluate(
                "!window.coachLinkXss && !document.querySelector('[data-public-coach-links] img')",
            ),
        );
        await screenshot("/tmp/kardorost-links81-public-mobile.png");
        s = state();
    }
    const firstPublication = s.profile.publications[0];
    if (s.profile.publications.length === 1) {
        await login("coach");
        if (!s.profile.submissions.some((x) => x.status === "rejected")) {
            if (s.profile.state !== "submitted") {
                await goto("/coach/profile");
                await fill("[name=coach_link_url_0]", privateLink);
                await save();
                assert((await publicLinks()).some((x) => x.href === first));
                await consent();
            }
            assert((await publicLinks()).some((x) => x.href === first));
            await review("reject");
            assert((await publicLinks()).some((x) => x.href === first));
            assert(
                await evaluate(
                    "!document.body.innerText.includes('توضیح خصوصی')",
                ),
            );
        }
        await login("coach");
        if (
            s.profile.state !== "submitted" ||
            state().profile.draft.links[0].url !== second
        ) {
            await goto("/coach/profile");
            await fill("[name=coach_link_url_0]", second);
            await save();
            await consent();
        }
        await review();
        s = state();
        assert.deepEqual(s.profile.publications[0], firstPublication);
        const links = await publicLinks();
        assert.deepEqual(
            links.map((x) => x.href),
            [second, literal],
        );
        assert(!links.some((x) => x.href === first));
    }
    s = state();
    const firstTwo = s.profile.publications.slice(0, 2);
    if (s.profile.publications.length === 2) {
        await login("coach");
        if (s.profile.state !== "submitted") {
            await goto("/coach/profile");
            while (
                await evaluate(
                    "document.querySelectorAll('[data-coach-link-row]').length>0",
                )
            )
                await click('[data-remove-coach-link="0"]');
            await save();
            assert.deepEqual(
                (await publicLinks()).map((x) => x.href),
                [second, literal],
            );
            await consent();
        }
        await review();
        s = state();
        assert.equal(s.profile.publications.length, 3);
        assert.deepEqual(s.profile.publications.slice(0, 2), firstTwo);
        assert.deepEqual(await publicLinks(), []);
    }
    s = state();
    const publications = s.profile.publications;
    if (s.profile.draft.headline !== "عنوان خصوصی پایانی۸۱") {
        await login("coach");
        await goto("/coach/profile");
        await addLink("پیوند پیش‌نویس خصوصی۸۱", privateLink);
        await fill("[name=coach_headline]", "عنوان خصوصی پایانی۸۱");
        await save();
        assert.deepEqual(await publicLinks(), []);
    }
    s = state();
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
    await goto("/coach/profile");
    assert(
        await evaluate(
            "!document.querySelector('[data-coach-profile-editor]')",
        ),
    );
    if (s.profile.public) {
        await submit("[data-coach-profile-withdraw]");
        await networkIdle();
        assert.equal(state().profile.public, false);
    }
    await responsive();
    await screenshot("/tmp/kardorost-links81-history-mobile.png");
    assert.equal(await status("/mentors/" + s.profile.slug), 404);
    for (const [role, phone] of [
        ["student", process.env.BROWSER_STUDENT_PHONE],
        ["organization", process.env.BROWSER_ORGANIZATION_PHONE],
        ["admin", process.env.BROWSER_ADMIN_PHONE],
    ]) {
        await login(role, phone);
        assert.equal(await status("/coach/profile"), 403);
        await responsive();
    }
    await login("admin");
    await goto("/admin/coach-profiles/" + s.profile.id);
    assert(
        await evaluate(
            "document.querySelectorAll('[data-coach-profile-links] a').length>0",
        ),
    );
    assert(
        await evaluate(
            "!window.coachLinkXss && !document.querySelector('[data-coach-profile-links] img')",
        ),
    );
    await responsive();
    const final = state();
    assert.deepEqual(final.profile.publications, publications);
    assert.deepEqual(final.profile.publications[0], firstPublication);
    assert.deepEqual(exceptions, []);
    assert.deepEqual(externalRequests, []);
    if (
        initial.profile?.publications.length === 3 &&
        !initial.profile.public &&
        !initial.roles.includes("coach")
    )
        assert.deepEqual(final, initial);
    console.log(
        JSON.stringify({
            result: "passed",
            immutable_publications: 3,
            private_draft_links: 1,
            external_requests: 0,
            checks: [
                "actual-five-link-editor-bound-add-remove",
                "server-refuses-bypassed-javascript-URL-without-creating-draft",
                "same-version-owner-consent-and-admin-review-required",
                "draft-pending-and-rejected-links-preserve-old-public-version",
                "plain-HTML-label-escaped-query-retained-no-execution-safe-new-tab",
                "second-publication-switches-links-old-snapshot-ciphertext-retained",
                "reviewed-removal-omits-link-section-legacy-shaped-definition",
                "private-next-draft-never-public",
                "actual-owner-role-loss-private-history-and-withdrawal-retained",
                "other-three-roles-own-editor403",
                "320-390-768-1440",
                "completed-fixture-readonly-replay",
            ],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
