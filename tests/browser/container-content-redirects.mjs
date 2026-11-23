import { execFileSync } from "node:child_process";
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
    "Owned production loopback fixture only",
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
const activeRequests = new Map();
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
        activeRequests.set(m.params.requestId, m.params.request.url);
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
        "Previous navigation did not finish before switching fixture accounts: " +
            JSON.stringify([...activeRequests.values()]),
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

const source = "/journal/software-legacy-redirect88",
    destination = "/journal/software-media78";
const python = String.raw`import importlib.util,json,os,subprocess,sys
from pathlib import Path
root=Path.cwd();sys.path.insert(0,str(root/'tests'))
from container_runtime_probes import CMS_REDIRECT_FIXTURE_STATE,ARTICLE_TAGS_FIXTURE_STATE,JOURNAL_RSS_FIXTURE_STATE,COACH_LINK_FIXTURE_STATE,COACH_PHOTO_FIXTURE_STATE,CONTENT_MEDIA_FIXTURE_STATE,RICH_CONTENT_FIXTURE_STATE,DISCUSSION_ATTACHMENT_FIXTURE_STATE
spec=importlib.util.spec_from_file_location('r',root/'resources/operations/recovery.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
def beat():subprocess.run(['python3','/workspace/work/kardorost-autonomy-lease.py','touch',os.environ['KARDOROST_LEASE_OWNER']],check=True,stdout=subprocess.DEVNULL)
class Composition(m.Composition):
 def execute(self,*a,**kw):beat();return super().execute(*a,**kw)
environment=Path(sys.argv[1]).resolve();assert environment.is_relative_to('/tmp') and environment.name.startswith('kardorost-compose-')
c=Composition(environment);assert c.config['name'].startswith('kardorost-validation-') and c.settings['SMS_DRIVER']=='disabled' and c.settings['ZIBAL_ENABLED']=='false' and c.settings['APP_URL']=='http://127.0.0.1:18089'
r=json.loads(c.run(['exec','-T','app','php','-r',CMS_REDIRECT_FIXTURE_STATE]))
if sys.argv[2]=='all':r={'redirect':r,'preserved':[json.loads(c.run(['exec','-T','app','php','-r',code]))for code in [ARTICLE_TAGS_FIXTURE_STATE,JOURNAL_RSS_FIXTURE_STATE,COACH_LINK_FIXTURE_STATE,COACH_PHOTO_FIXTURE_STATE,CONTENT_MEDIA_FIXTURE_STATE,RICH_CONTENT_FIXTURE_STATE,DISCUSSION_ATTACHMENT_FIXTURE_STATE]]}
print(json.dumps(r))
`;
function state(all = false) {
    return JSON.parse(
        execFileSync(
            "python3",
            [
                "-c",
                python,
                process.env.CONTAINER_ENV_FILE,
                all ? "all" : "redirect",
            ],
            { encoding: "utf8" },
        ),
    );
}
async function editor() {
    await goto("/admin/content/" + state().page_id);
    await evaluate(
        "document.querySelector('[data-content-redirects]').open=true",
    );
}
async function navigateAlias() {
    await networkIdle();
    await evaluate("document.documentElement.dataset.qaNavigation='previous'");
    await send("Page.navigate", { url: base + source });
    await until(
        `location.pathname===${JSON.stringify(destination)} && document.documentElement.dataset.qaNavigation!=='previous' && document.readyState==='complete'`,
    );
    await networkIdle();
}
async function toggle() {
    await editor();
    const row = state().rows[0];
    await click('[data-toggle-content-redirect="' + row.id + '"]');
    await editor();
}
function headers() {
    const py = String.raw`import urllib.request,json,sys
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*a,**kw):return None
r=urllib.request.Request(sys.argv[1],headers={'Host':'untrusted.example'})
try:response=urllib.request.build_opener(NoRedirect).open(r)
except urllib.error.HTTPError as error:response=error
response.read();print(json.dumps({'status':response.status,'location':response.headers.get('Location'),'cache':response.headers.get('Cache-Control'),'robots':response.headers.get('X-Robots-Tag')}))
`;
    return JSON.parse(
        execFileSync(
            "python3",
            ["-c", py, base + source + "?next=https://evil.test&token=private"],
            { encoding: "utf8" },
        ),
    );
}
try {
    lease();
    const initial = state(true);
    assert(initial.preserved.every((s) => s.fixture_complete));
    await login("admin");
    if (!initial.redirect.fixture_complete) {
        await editor();
        if (!state().rows.length) {
            await fill(
                "[data-content-redirect-create] [name=from_slug]",
                "software-legacy-redirect88",
            );
            await submit("[data-content-redirect-create]");
            await networkIdle();
            await editor();
        }
        assert(
            [1, 2, 3].includes(state().rows[0].revision),
            "Preserve unexpected redirect decisions",
        );
        if (state().rows[0].revision === 1) {
            await navigateAlias();
            await responsive();
            await toggle();
            assert.equal(state().rows[0].enabled, false);
            assert.equal(await status(source), 404);
        }
        if (state().rows[0].revision === 2) {
            await toggle();
            assert(state().rows[0].enabled);
        }
        assert(state().fixture_complete);
        await editor();
        const unchanged = state();
        for (const value of [
            "software-legacy-redirect88",
            "https://evil.test",
        ]) {
            await fill(
                "[data-content-redirect-create] [name=from_slug]",
                value,
            );
            await evaluate(
                "document.querySelector('[data-content-redirect-create]').noValidate=true",
            );
            await submit("[data-content-redirect-create]");
            await networkIdle();
            assert.deepEqual(state(), unchanged);
            assert(
                await evaluate(
                    "!!document.querySelector('[data-content-redirect-create] [role=alert]')",
                ),
            );
        }
    }
    const frozen = state(true);
    assert(frozen.redirect.fixture_complete);
    assert.deepEqual(frozen.preserved, initial.preserved);
    const response = headers();
    assert.equal(response.status, 302);
    assert.equal(response.location, destination);
    assert(response.cache.includes("no-store"));
    assert.equal(response.robots, "noindex, follow");
    await navigateAlias();
    assert.equal(
        await evaluate(
            "document.querySelector('[data-content-image] img').alt",
        ),
        "شرح تصویر عمومی۷۸",
    );
    assert.equal(
        await evaluate(
            "document.body.innerText.includes('شرح خصوصی پایانی۷۸')||document.body.innerText.includes('برچسب خصوصی کانتینر۸۶')",
        ),
        false,
    );
    assert.equal(await evaluate("window.tagXss||0"), 0);
    await responsive();
    await screenshot("/tmp/kardorost-redirect88-public-mobile.png");
    for (const role of ["student", "coach", "organization"]) {
        await login(role);
        assert.equal(
            await status("/admin/content/" + frozen.redirect.page_id),
            403,
        );
        await navigateAlias();
        await responsive();
    }
    await login("admin");
    await editor();
    await evaluate(
        "document.querySelector('[data-content-redirects]').scrollIntoView({block:'center'})",
    );
    await responsive();
    await screenshot("/tmp/kardorost-redirect88-editor-mobile.png");
    assert.deepEqual(state(true), frozen);
    assert.deepEqual(exceptions, []);
    assert.deepEqual(externalRequests, []);
    console.log(
        JSON.stringify({
            result: "passed",
            runtime: "production-nginx-fpm-pg",
            positive_alias_lifecycle_executed:
                !initial.redirect.fixture_complete,
            completed_fixture_readonly_replay:
                initial.redirect.fixture_complete,
            retained_aliases: 1,
            revision: 3,
            relative302_query_omitted_untrusted_host_safe: true,
            seven_original_domain_snapshots_equal: true,
            no_republication_or_private_draft_change: true,
            three_nonadmin403: true,
            widths: [320, 390, 768, 1440],
            external_requests: 0,
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
