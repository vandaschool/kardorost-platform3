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

const publicTags = [
        "برچسب کانتینر صرفاً آزمایشی۸۶",
        "<img src=x onerror=window.tagXss=1>",
    ],
    privateTag = "برچسب خصوصی کانتینر۸۶";
const python = String.raw`import importlib.util,json,os,subprocess,sys
from pathlib import Path
root=Path.cwd();sys.path.insert(0,str(root/'tests'))
from container_runtime_probes import CONTENT_MEDIA_FIXTURE_STATE,ARTICLE_TAGS_FIXTURE_STATE
spec=importlib.util.spec_from_file_location('recovery',root/'resources/operations/recovery.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
def beat():subprocess.run(['python3','/workspace/work/kardorost-autonomy-lease.py','touch',os.environ['KARDOROST_LEASE_OWNER']],check=True,stdout=subprocess.DEVNULL)
class Composition(m.Composition):
 def execute(self,*a,**kw):beat();return super().execute(*a,**kw)
environment=Path(sys.argv[1]).resolve();assert environment.is_relative_to('/tmp') and environment.name.startswith('kardorost-compose-')
c=Composition(environment);assert c.config['name'].startswith('kardorost-validation-') and c.settings['SMS_DRIVER']=='disabled' and c.settings['ZIBAL_ENABLED']=='false' and c.settings['APP_URL']=='http://127.0.0.1:18089'
print(json.dumps({key:json.loads(c.run(['exec','-T','app','php','-r',code]))for key,code in [('media',CONTENT_MEDIA_FIXTURE_STATE),('tags',ARTICLE_TAGS_FIXTURE_STATE)]}))
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
async function editor() {
    await goto("/admin/content/" + state().tags.id);
}
async function clearTags() {
    while (
        await evaluate(
            "document.querySelectorAll('[data-content-tag-row]').length>0",
        )
    )
        await click('[data-remove-content-tag="0"]');
}
async function setTags(tags) {
    await clearTags();
    for (const tag of tags) {
        const i = await evaluate(
            "document.querySelectorAll('[data-content-tag-row]').length",
        );
        await click("[data-add-content-tag]");
        await fill("[name=content_tag_" + i + "]", tag);
    }
}
async function setAlt(alt) {
    await evaluate(
        `(()=>{const e=document.querySelector('[data-content-document]');e.querySelector('figure img').alt=${JSON.stringify(alt)};e.dispatchEvent(new InputEvent('input',{bubbles:true}))})()`,
    );
}
async function save() {
    await submit("[data-content-draft]");
    await networkIdle();
    await editor();
}
function withAlt(definition, alt) {
    const result = structuredClone(definition),
        image = result.document.blocks.find((b) => b.type === "image");
    assert(image);
    result.body = result.body.replace(image.alt, alt);
    image.alt = alt;
    return result;
}
function preserved(current, initial) {
    assert.deepEqual(current.media.media, initial.media.media);
    assert.deepEqual(
        current.media.page.publications[0],
        initial.media.page.publications[0],
    );
    assert.deepEqual(
        current.media.page.schedules,
        initial.media.page.schedules,
    );
    assert.equal(current.media.financial_hash, initial.media.financial_hash);
}
try {
    lease();
    const initial = state();
    assert(initial.media.fixture_complete);
    assert([1, 2, 3].includes(initial.media.page.publications.length));
    const original = initial.media.page.publications[0].definition;
    assert(!Object.hasOwn(original, "tags"));
    assert.equal(
        original.document.blocks.find((b) => b.type === "image").alt,
        "شرح تصویر عمومی۷۸",
    );
    const privateOriginal = withAlt(original, "شرح خصوصی پایانی۷۸");
    const candidates = [
        privateOriginal,
        { ...original, tags: publicTags },
        { ...privateOriginal, tags: [privateTag] },
    ];
    if (
        initial.tags.fixture_complete &&
        initial.media.page.publications.length === 3
    ) {
        assert.equal(initial.tags.draft.category, "دسته خصوصی کانتینر۹۰");
        assert.equal(initial.tags.public.category, "دسته کانتینر آزمایشی۹۰");
        for (const candidate of candidates)
            candidate.category = initial.tags.draft.category;
    }
    assert(
        candidates.some(
            (d) => JSON.stringify(d) === JSON.stringify(initial.tags.draft),
        ),
        "Preserve unexpected private draft fields.",
    );
    await login("admin");
    await editor();
    if (initial.tags.current_version === 1) {
        await setAlt("شرح تصویر عمومی۷۸");
        await setTags(publicTags);
        await save();
        let current = state();
        assert.deepEqual(current.tags.draft, { ...original, tags: publicTags });
        assert.deepEqual(current.tags.public, original);
        preserved(current, initial);
        await click("[data-content-publish]");
        await editor();
        current = state();
        assert.equal(current.tags.current_version, 2);
        assert.deepEqual(current.tags.public, {
            ...original,
            tags: publicTags,
        });
        preserved(current, initial);
    }
    if (!state().tags.fixture_complete) {
        await editor();
        await setAlt("شرح خصوصی پایانی۷۸");
        await setTags([privateTag]);
        await save();
    }
    const frozen = state();
    assert(frozen.tags.fixture_complete);
    preserved(frozen, initial);
    assert.deepEqual(frozen.tags.draft, {
        ...privateOriginal,
        tags: [privateTag],
        ...(frozen.tags.draft.category
            ? { category: frozen.tags.draft.category }
            : {}),
    });
    assert.deepEqual(frozen.tags.public, {
        ...original,
        tags: publicTags,
        ...(frozen.tags.public.category
            ? { category: frozen.tags.public.category }
            : {}),
    });
    await goto("/journal/software-media78");
    assert.deepEqual(
        await evaluate(
            "Array.from(document.querySelectorAll('[data-public-article-tags] a')).map(e=>e.textContent.trim())",
        ),
        publicTags,
    );
    assert.equal(
        await evaluate(
            "document.querySelector('[data-content-image] img').alt",
        ),
        "شرح تصویر عمومی۷۸",
    );
    assert.equal(await evaluate("window.tagXss||0"), 0);
    assert.equal(
        await evaluate(
            "!!document.querySelector('[data-public-article-tags] img')",
        ),
        false,
    );
    assert.equal(
        await evaluate(
            `document.body.innerText.includes(${JSON.stringify(privateTag)})`,
        ),
        false,
    );
    await responsive();
    await evaluate(
        "document.querySelector('[data-public-article-tags]').scrollIntoView({block:'center'})",
    );
    await screenshot("/tmp/kardorost-tags86-public-mobile.png");
    await goto("/journal");
    await fill("[name=tag]", publicTags[1]);
    lease();
    await evaluate(
        "document.querySelector('[data-journal-tag-filter]').form.requestSubmit()",
    );
    await networkIdle();
    await until("location.search.includes('tag=')");
    assert.deepEqual(
        await evaluate(
            "Array.from(document.querySelectorAll('h3 a[href=\"/journal/software-media78\"]')).map(a=>a.getAttribute('href'))",
        ),
        ["/journal/software-media78"],
    );
    assert.equal(
        await evaluate(
            "!!document.querySelector('a[href=\"/journal/software-rich-content76\"]')",
        ),
        false,
    );
    assert.equal(
        await evaluate(
            "!!document.querySelector('option')&&Array.from(document.querySelectorAll('[name=tag] option')).some(o=>o.value=== " +
                JSON.stringify(privateTag) +
                ")",
        ),
        false,
    );
    const headers = await evaluate(
        "fetch(location.href).then(async r=>{await r.arrayBuffer();return {robots:r.headers.get('x-robots-tag')}})",
    );
    assert.equal(headers.robots, "noindex, follow");
    assert.equal(
        await evaluate("document.querySelector('link[rel=canonical]').href"),
        base + "/journal",
    );
    await responsive();
    for (const role of ["student", "coach", "organization"]) {
        await login(role);
        assert.equal(await status("/admin/content"), 403);
        await goto("/journal/software-media78");
        assert.equal(await evaluate("window.tagXss||0"), 0);
    }
    await login("admin");
    await editor();
    assert.equal(
        await evaluate("document.querySelector('[name=content_tag_0]').value"),
        privateTag,
    );
    await responsive();
    await evaluate(
        "document.querySelector('[data-content-tags-editor]').scrollIntoView({block:'center'})",
    );
    await screenshot("/tmp/kardorost-tags86-editor-mobile.png");
    assert.deepEqual(state(), frozen);
    assert.deepEqual(exceptions, []);
    assert.deepEqual(externalRequests, []);
    console.log(
        JSON.stringify({
            result: "passed",
            runtime: "production-nginx-fpm-pg",
            positive_tags_publication_executed:
                initial.tags.current_version === 1,
            completed_fixture_readonly_replay: initial.tags.fixture_complete,
            publications: frozen.media.page.publications.length,
            old_publication_media_bytes_ciphertexts_cancelled_schedule_and_private_image_fields_preserved: true,
            private_final_tags_not_public: true,
            three_nonadmin_403: true,
            widths: [320, 390, 768, 1440],
            external_requests: 0,
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
