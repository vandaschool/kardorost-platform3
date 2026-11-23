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
    new URL(base).port === "18089" &&
        new URL(base).hostname === "127.0.0.1" &&
        process.env.CONTAINER_ENV_FILE?.startsWith("/tmp/kardorost-compose-"),
    "Owned four-user production links fixture only",
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

const python = String.raw`import importlib.util,json,os,subprocess,sys
from pathlib import Path
root=Path.cwd();sys.path.insert(0,str(root/'tests'))
from container_runtime_probes import COACH_LINK_FIXTURE_STATE
spec=importlib.util.spec_from_file_location('recovery',root/'resources/operations/recovery.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
subprocess.run(['python3','/workspace/work/kardorost-autonomy-lease.py','touch',os.environ['KARDOROST_LEASE_OWNER']],check=True,stdout=subprocess.DEVNULL)
environment=Path(sys.argv[1]).resolve();assert environment.is_relative_to('/tmp') and environment.name.startswith('kardorost-compose-')
c=m.Composition(environment);assert c.config['name'].startswith('kardorost-validation-') and c.settings['SMS_DRIVER']=='disabled' and c.settings['ZIBAL_ENABLED']=='false' and c.settings['APP_URL']=='http://127.0.0.1:18089'
print(c.run(['exec','-T','app','php','-r',COACH_LINK_FIXTURE_STATE]))
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
        await fill("[name=coach_review_note]", "توضیح خصوصی صرفاً آزمایشی۸۲");
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

const publicLink = "https://example.test/coach-links82/public";
const literal = "https://example.test/coach-links82/literal?x=1&y=2";
const privateLink = "https://example.test/coach-links82/private";
async function clearLinks() {
    while (
        await evaluate(
            "document.querySelectorAll('[data-coach-link-row]').length>0",
        )
    )
        await click('[data-remove-coach-link="0"]');
}
try {
    lease();
    const initial = state();
    assert(initial.profile && initial.photos.length === 3);
    const oldPublications = initial.profile.publications.slice(0, 2);
    const photos = initial.photos;
    let invalidUrlSubmissionExecuted = false;
    let consentReviewExecuted = false;
    if (initial.profile.publications.length === 2) {
        await login("coach");
        if (state().profile.state !== "submitted") {
            await goto("/coach/profile");
            const publicDefinition = oldPublications[1].definition;
            await fill("[name=coach_headline]", publicDefinition.headline);
            await fill("[name=coach_photo]", photos[0].key);
            await clearLinks();
            await addLink("وب‌سایت صرفاً آزمایشی۸۲", publicLink);
            await addLink(
                '<img src=x onerror="window.coachLinkXss=true">',
                literal,
            );
            const beforeInvalid = state();
            await fill("[name=coach_link_url_0]", "javascript:alert(82)");
            await evaluate(
                "document.querySelector('[data-coach-profile-editor]').noValidate=true",
            );
            await save();
            await until("!!document.querySelector('.field-error')");
            assert.deepEqual(state(), beforeInvalid);
            invalidUrlSubmissionExecuted = true;
            await fill("[name=coach_link_url_0]", publicLink);
            await evaluate(
                "document.querySelector('[data-coach-profile-editor]').noValidate=false",
            );
            await save();
            assert.deepEqual(await publicLinks(), []);
            await consent();
        }
        assert.deepEqual(await publicLinks(), []);
        await review();
        consentReviewExecuted = true;
    }
    let s = state();
    assert.equal(s.profile.publications.length, 3);
    assert.deepEqual(s.profile.publications.slice(0, 2), oldPublications);
    if (!s.fixture_complete) {
        await login("coach");
        await goto("/coach/profile");
        await fill("[name=coach_headline]", "عنوان خصوصی پایانی۸۰");
        await fill("[name=coach_photo]", photos[1].key);
        await clearLinks();
        await addLink("پیوند خصوصی صرفاً آزمایشی۸۲", privateLink);
        await save();
    }
    s = state();
    assert(s.fixture_complete);
    const versions = s.profile.publications;
    const links = await publicLinks();
    assert.deepEqual(
        links.map((x) => x.href),
        [publicLink, literal],
    );
    assert(
        links.every(
            (x) =>
                x.target === "_blank" &&
                ["noopener", "noreferrer", "nofollow"].every((v) =>
                    x.rel.includes(v),
                ),
        ),
    );
    assert(
        await evaluate(
            "!window.coachLinkXss && !document.querySelector('[data-public-coach-links] img') && !document.body.innerText.includes('عنوان خصوصی پایانی۸۰') && !document.body.innerText.includes('پیوند خصوصی')",
        ),
    );
    assert.equal(links[1].text.includes("<img src=x"), true);
    const path = (index) =>
        `/mentor-photos/${s.profile.slug}/${s.photos[index].key}/${s.photos[index].sha}`;
    const anonymous = await evaluate(
        `fetch(${JSON.stringify("/mentors/" + s.profile.slug)},{credentials:'omit'}).then(async r=>({status:r.status,text:await r.text()}))`,
    );
    assert.equal(anonymous.status, 200);
    assert(anonymous.text.includes(publicLink));
    assert(!anonymous.text.includes(privateLink));
    assert.equal(await status(path(0)), 200);
    assert.equal(await status(path(1)), 404);
    assert.equal(await status(path(2)), 404);
    await evaluate(
        "document.querySelector('[data-public-coach-links]').scrollIntoView({block:'center'})",
    );
    await screenshot("/tmp/kardorost-links82-public-mobile.png");
    for (const role of ["student", "organization", "admin"]) {
        await login(role);
        assert.equal(await status("/coach/profile"), 403);
        if (role === "admin") {
            await goto("/admin/coach-profiles/" + s.profile.id);
            assert(
                await evaluate(
                    "Array.from(document.querySelectorAll('[data-coach-profile-links] a')).some(a=>a.getAttribute('href')===" +
                        JSON.stringify(publicLink) +
                        ")",
                ),
            );
            await responsive();
        }
    }
    await login("coach");
    await goto("/coach/profile");
    assert.equal(
        await evaluate(
            "document.querySelector('[name=coach_link_url_0]').value",
        ),
        privateLink,
    );
    await responsive();
    await evaluate(
        "document.querySelector('[data-coach-links-editor]').scrollIntoView({block:'center'})",
    );
    await screenshot("/tmp/kardorost-links82-private-mobile.png");
    const final = state();
    assert(final.fixture_complete);
    assert.deepEqual(final.profile.publications, versions);
    assert.deepEqual(final.profile.publications.slice(0, 2), oldPublications);
    assert.deepEqual(final.photos, photos);
    assert.equal(final.financial_hash, initial.financial_hash);
    if (initial.fixture_complete) assert.deepEqual(final, initial);
    assert.deepEqual(exceptions, []);
    assert.deepEqual(externalRequests, []);
    console.log(
        JSON.stringify({
            result: "passed",
            invalid_url_submission_executed: invalidUrlSubmissionExecuted,
            consent_review_executed: consentReviewExecuted,
            completed_fixture_readonly_replay: initial.fixture_complete,
            publications: 3,
            retained_historical_publications: 2,
            public_links: 2,
            private_links: 1,
            checks: [
                "actual-production-owner-consent-admin-review",
                "invalid-javascript-refused-with-exact-state-preserved",
                "draft-and-pending-links-private",
                "escaped-label-query-target-rel-no-external-requests",
                "historical-photos-consents-ciphertexts-and-financial-rows-preserved",
                "four-role-isolation-320-390-768-1440",
                "completed-fixture-readonly-replay",
            ],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
