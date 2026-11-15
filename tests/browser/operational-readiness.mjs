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

assert(
    process.env.BROWSER_ORGANIZATION_PHONE,
    "Provide isolated organization manager phone",
);

const php = process.env.BROWSER_PHP_BINARY;
assert(
    php &&
        process.env.DB_DATABASE &&
        process.env.DB_DATABASE.startsWith("/tmp/"),
    "Use the isolated SQLite browser database and explicit PHP binary",
);
function consoleCommand(args) {
    return execFileSync(php, ["artisan", ...args], {
        cwd: process.cwd(),
        env: {
            ...process.env,
            DB_CONNECTION: "sqlite",
            APP_URL: base,
            SMS_DRIVER: "disabled",
        },
        encoding: "utf8",
        timeout: 45000,
    });
}
try {
    await login("admin");
    await goto("/admin/readiness");
    await until("!!document.querySelector('[data-readiness-summary]')");
    for (const signal of ["scheduler", "queue.default", "queue.sms"]) {
        assert.equal(
            await evaluate(
                `document.querySelector('[data-readiness-check="${signal}"]').dataset.state`,
            ),
            "fail",
        );
    }
    const first = await evaluate(
        "JSON.parse(document.querySelector('script[data-page]').textContent).props.report",
    );
    assert(
        first.blocking_count > 0 &&
            !first.live_services_verified &&
            !first.deployment_verified,
    );
    await networkIdle();
    consoleCommand(["schedule:run", "--no-interaction"]);
    await goto("/admin/readiness");
    await until(
        "document.querySelector('[data-readiness-check=scheduler]').dataset.state==='pass'",
    );
    for (const signal of ["queue.default", "queue.sms"]) {
        assert.equal(
            await evaluate(
                `document.querySelector('[data-readiness-check="${signal}"]').dataset.state`,
            ),
            "fail",
        );
    }
    const output = consoleCommand([
        "queue:work",
        "database",
        "--queue=default,sms",
        "--stop-when-empty",
        "--tries=1",
        "--timeout=30",
        "--max-time=30",
        "--sleep=1",
        "--no-interaction",
    ]);
    assert(output.includes("RecordOperationalHeartbeat"));
    await goto("/admin/readiness");
    for (const signal of ["scheduler", "queue.default", "queue.sms"]) {
        await until(
            `document.querySelector('[data-readiness-check="${signal}"]').dataset.state==='pass'`,
        );
    }
    assert.equal(
        await evaluate(
            "document.querySelector('[data-readiness-check=otp_configuration]').dataset.state",
        ),
        "fail",
    );
    assert.equal(
        await evaluate(
            "JSON.parse(document.querySelector('script[data-page]').textContent).props.report.live_services_verified",
        ),
        false,
    );
    assert(
        !(await evaluate("document.body.innerText")).includes("/workspace/"),
    );
    for (const width of [320, 390, 768, 1440]) {
        await send("Emulation.setDeviceMetricsOverride", {
            width,
            height: 900,
            deviceScaleFactor: 1,
            mobile: width < 768,
        });
        assert(
            await evaluate(
                "document.documentElement.scrollWidth<=innerWidth+1",
            ),
            `Readiness overflow ${width}`,
        );
        if (width === 390) {
            await until(
                "document.querySelector('.sidebar').getBoundingClientRect().left>=innerWidth-1",
            );
            const shot = await send("Page.captureScreenshot", {
                format: "png",
            });
            await writeFile(
                "/tmp/kardorost-readiness-mobile.png",
                Buffer.from(shot.data, "base64"),
            );
        }
    }
    for (const role of ["student", "coach", "organization"]) {
        await login(role);
        const status = await evaluate(
            "(async()=>{const r=await fetch('/admin/readiness',{headers:{Accept:'application/json'}});await r.text();return r.status})()",
        );
        assert.equal(status, 403);
    }
    await networkIdle();
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "missing-heartbeat-red-states",
                "actual-local-schedule-run",
                "queued-probe-does-not-imply-worker-execution",
                "actual-database-default-and-sms-worker-probes",
                "disabled-otp-remains-blocked",
                "no-live-acceptance-claim",
                "three-role-isolation",
                "320-390-768-1440-layouts",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
