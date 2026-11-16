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
    await send("Page.navigate", { url: base + path });
    await until(
        `location.pathname===${JSON.stringify(path.split("?")[0])} && !!document.querySelector('main')`,
    );
}
async function fill(selector, value) {
    await evaluate(
        `(()=>{const el=document.querySelector(${JSON.stringify(selector)});if(!el)throw Error('Missing input');el.value=${JSON.stringify(value)};el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));})()`,
    );
}
async function submit(selector) {
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
async function login(role) {
    await networkIdle();
    await send("Network.clearBrowserCookies");
    await send("Page.navigate", { url: base + "/login" });
    await until(`!!document.querySelector('input[name=password]')`);
    await networkIdle();
    await fill(
        "[name=phone]",
        process.env["BROWSER_" + role.toUpperCase() + "_PHONE"],
    );
    await fill("[name=password]", password);
    await submit("form");
    await until(`location.pathname==='/${role}'`);
}

const php = process.env.BROWSER_PHP_BINARY;
assert(
    php &&
        new URL(base).port === "8029" &&
        process.env.DB_DATABASE ===
            "/tmp/kardorost-cohort-announcements-browser.sqlite",
    "Use the isolated cohort fixture only.",
);
const env = {
    ...process.env,
    DB_CONNECTION: "sqlite",
    DB_DATABASE: process.env.DB_DATABASE,
    SMS_DRIVER: "disabled",
    ZIBAL_ENABLED: "false",
};
const bootstrap = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();`;
const setup =
    bootstrap +
    String.raw`if(config('database.default')!=='sqlite'||config('database.connections.sqlite.database')!=='/tmp/kardorost-cohort-announcements-browser.sqlite'){throw new RuntimeException('Only the isolated fixture can be extended.');}if(\App\Models\User::where('phone','989122220001')->exists()){throw new RuntimeException('Revision fixture already exists; inspect before rerunning.');}$target=\App\Models\User::factory()->create(['name'=>'Browser-access-revision-target','phone'=>'989122220001']);$target->roles()->attach(\App\Models\Role::where('key','student')->value('id'));echo json_encode(['target_id'=>$target->id]);`;
const fixture = JSON.parse(
    execFileSync(php, ["-r", setup], {
        cwd: process.cwd(),
        env,
        encoding: "utf8",
    }),
);
function inspect() {
    return JSON.parse(
        execFileSync(
            php,
            [
                "-r",
                bootstrap +
                    String.raw`$u=\App\Models\User::findOrFail(` +
                    fixture.target_id +
                    String.raw`);echo json_encode(['revision'=>$u->access_revision,'active'=>$u->is_active,'roles'=>$u->roles()->orderBy('key')->pluck('key')->all(),'audit_count'=>\App\Models\AuditEvent::where('action','user.access_updated')->where('subject_id',$u->id)->count()]);`,
            ],
            { cwd: process.cwd(), env, encoding: "utf8" },
        ),
    );
}
let second;
async function secondTab(path) {
    const target = await (
        await fetch(
            "http://127.0.0.1:9222/json/new?" + encodeURIComponent(base + path),
            { method: "PUT" },
        )
    ).json();
    const socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((r) =>
        socket.addEventListener("open", r, { once: true }),
    );
    let sequence = 0;
    const waiting = new Map();
    socket.addEventListener("message", (event) => {
        const m = JSON.parse(event.data);
        if (m.method === "Runtime.exceptionThrown")
            exceptions.push(m.params.exceptionDetails.text);
        if (m.id) {
            const p = waiting.get(m.id);
            if (m.error) p.reject(m.error);
            else p.resolve(m.result);
            waiting.delete(m.id);
        }
    });
    function call(method, params = {}) {
        return new Promise((resolve, reject) => {
            const id = ++sequence;
            waiting.set(id, { resolve, reject });
            socket.send(JSON.stringify({ id, method, params }));
        });
    }
    async function evaluate(expression) {
        const r = await call("Runtime.evaluate", {
            expression,
            returnByValue: true,
            awaitPromise: true,
        });
        if (r.exceptionDetails) throw Error(JSON.stringify(r.exceptionDetails));
        return r.result.value;
    }
    async function until(expression) {
        for (let i = 0; i < 100; i++) {
            if (await evaluate(expression)) return;
            await new Promise((r) => setTimeout(r, 100));
        }
        throw Error(
            "Second tab timed out: " +
                expression +
                "\n" +
                (await evaluate("document.body.innerText")),
        );
    }
    await call("Runtime.enable");
    await call("Page.enable");
    return { target, socket, call, evaluate, until };
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
            `Access revision form overflow ${width}`,
        );
    }
}
try {
    await login("admin");
    const path = "/admin/users?user_id=" + fixture.target_id;
    await goto(path);
    await until(
        `!!document.querySelector('[data-user-id="${fixture.target_id}"]')`,
    );
    await evaluate(
        `document.querySelector('[data-user-id="${fixture.target_id}"] button').click()`,
    );
    await until("!!document.querySelector('[data-testid=user-access]')");
    assert.equal(inspect().revision, 1);
    second = await secondTab(path);
    await second.until(
        `!!document.querySelector('[data-user-id="${fixture.target_id}"] button')`,
    );
    await second.evaluate(
        `document.querySelector('[data-user-id="${fixture.target_id}"] button').click()`,
    );
    await second.until("!!document.querySelector('[data-testid=user-access]')");
    await second.evaluate(
        "document.querySelector('[name=access_coach]').click()",
    );
    await second.evaluate(
        "document.querySelector('[data-testid=user-access]').requestSubmit()",
    );
    await second.until(
        "!document.querySelector('[data-testid=user-access]') && document.body.innerText.includes('دسترسی و وضعیت کاربر به‌روز شد.')",
    );
    assert.deepEqual(inspect(), {
        revision: 2,
        active: true,
        roles: ["coach", "student"],
        audit_count: 1,
    });
    await evaluate("document.querySelector('[name=access_active]').click()");
    await submit("[data-testid=user-access]");
    await until(
        "!!document.querySelector('[data-testid=refresh-user-access]')",
    );
    assert(
        (
            await evaluate(
                "document.querySelector('[data-testid=user-access]').innerText",
            )
        ).includes("دسترسی این حساب تغییر کرده است"),
    );
    assert.deepEqual(inspect(), {
        revision: 2,
        active: true,
        roles: ["coach", "student"],
        audit_count: 1,
    });
    await responsive();
    await evaluate(
        "document.querySelector('[data-testid=refresh-user-access]').click()",
    );
    await until(
        "!document.querySelector('[data-testid=user-access]') && !!document.querySelector('[data-user-id]')",
    );
    await networkIdle();
    assert(
        (
            await evaluate(
                `document.querySelector('[data-user-id="${fixture.target_id}"]').innerText`,
            )
        ).includes("مربی"),
    );
    await evaluate(
        `document.querySelector('[data-user-id="${fixture.target_id}"] button').click()`,
    );
    await until("!!document.querySelector('[data-testid=user-access]')");
    assert.equal(
        await evaluate("document.querySelector('[name=access_coach]').checked"),
        true,
    );
    await evaluate("document.querySelector('[name=access_active]').click()");
    await submit("[data-testid=user-access]");
    await until(
        "!document.querySelector('[data-testid=user-access]') && document.body.innerText.includes('دسترسی و وضعیت کاربر به‌روز شد.')",
    );
    assert.deepEqual(inspect(), {
        revision: 3,
        active: false,
        roles: ["coach", "student"],
        audit_count: 2,
    });
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "two-real-admin-tabs-original-forms-share-revision-one",
                "second-tab-accepted-coach-grant-advances-revision-two",
                "first-tab-stale-deactivation-rejected-without-role-session-or-audit-overwrite",
                "actual-refresh-button-discards-stale-form-and-reloads-current-roles",
                "explicit-reviewed-fresh-save-advances-revision-three",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    if (second) {
        second.socket.close();
        await fetch("http://127.0.0.1:9222/json/close/" + second.target.id);
    }
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
