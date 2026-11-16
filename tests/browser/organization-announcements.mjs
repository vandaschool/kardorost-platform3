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

assert(
    process.env.BROWSER_ORGANIZATION_PHONE,
    "Provide isolated organization manager phone",
);

const php = process.env.BROWSER_PHP_BINARY;
const database = process.env.DB_DATABASE;
assert(
    php &&
        database ===
            "/tmp/kardorost-organization-announcements-browser.sqlite" &&
        new URL(base).port === "8028",
    "Use the isolated organization announcement fixture only.",
);
const fixture = JSON.parse(
    readFileSync(
        "/tmp/kardorost-organization-announcement-fixture.json",
        "utf8",
    ),
);
const environment = {
    ...process.env,
    DB_CONNECTION: "sqlite",
    DB_DATABASE: database,
    APP_URL: base,
    SMS_DRIVER: "disabled",
    ZIBAL_ENABLED: "false",
    MELIPAYAMAK_INVITATIONS_ENABLED: "false",
    MELIPAYAMAK_REMINDERS_ENABLED: "false",
    MELIPAYAMAK_DELIVERY_ENABLED: "false",
};
function inspect(id) {
    const code =
        "require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$a=\\App\\Models\\Announcement::findOrFail(" +
        id +
        ");$rows=$a->recipients()->orderBy('user_id')->get();$data=\\Illuminate\\Support\\Facades\\DB::table('notifications')->whereIn('id',$rows->pluck('notification_id')->filter())->pluck('data');echo json_encode(['status'=>$a->status,'recipients'=>$rows->map(fn($r)=>['user_id'=>$r->user_id,'status'=>$r->status,'failure_code'=>$r->failure_code])->all(),'ciphertext_only'=>$data->every(fn($v)=>str_contains($v,'private_payload')&&!str_contains($v,$a->title)&&!str_contains($v,$a->audience['label']))]);";
    return JSON.parse(
        execFileSync(php, ["-r", code], {
            cwd: process.cwd(),
            env: environment,
            encoding: "utf8",
        }),
    );
}
function worker() {
    execFileSync(
        php,
        [
            "artisan",
            "queue:work",
            "database",
            "--queue=default",
            "--stop-when-empty",
            "--tries=1",
            "--timeout=30",
            "--no-interaction",
        ],
        {
            cwd: process.cwd(),
            env: environment,
            encoding: "utf8",
            timeout: 120000,
        },
    );
}
const membersTitle = "خبر خصوصی اعضای مجموعه آزمایشی";
const managersTitle = "خبر خصوصی مدیران همان مجموعه";
const literal = "<script>window.orgAnnouncementInjected=true</script>";
async function create(role, title) {
    await goto("/admin/announcements");
    await until(
        "!!document.querySelector('[name=announcement_audience_type]')",
    );
    await fill("[name=announcement_title]", title);
    await fill(
        "[name=announcement_message]",
        "این متن فقط برای آزمون محدوده سازمانی است.\n" + literal,
    );
    await fill("[name=announcement_audience_type]", "organization");
    await until("!!document.querySelector('[name=announcement_organization]')");
    await fill("[name=announcement_organization]", fixture.company_id);
    assert.equal(
        await evaluate("!!document.querySelector('[name=audience_coach]')"),
        false,
    );
    assert.equal(
        await evaluate("!!document.querySelector('[name=audience_admin]')"),
        false,
    );
    await evaluate(`document.querySelector('[name=audience_${role}]').click()`);
    await submit("[data-testid=announcement-editor]");
    await until(
        "location.pathname.startsWith('/admin/announcements/') && !!document.querySelector('[data-testid=announcement-publish]')",
    );
    assert(
        (
            await evaluate(
                "document.querySelector('[data-announcement-audience]').innerText",
            )
        ).includes("<b>مجموعه محرمانه آزمایشی</b>"),
    );
    const id = Number((await evaluate("location.pathname")).split("/").at(-1));
    await evaluate(
        "document.querySelector('[name=announcement_reviewed]').click()",
    );
    await submit("[data-testid=announcement-publish]");
    await until("!!document.querySelector('[data-testid=announcement-stop]')");
    return id;
}
async function accept() {
    await goto("/organization-invitations");
    await until("!!document.querySelector('[data-invitation-id] form')");
    await evaluate(
        "document.querySelector('[data-invitation-id] form input[type=checkbox]').click()",
    );
    await submit("[data-invitation-id] form");
    await until(
        "document.querySelector('[data-invitation-id]').innerText.includes('پذیرفته‌شده')",
    );
}
async function student(phone) {
    process.env.BROWSER_STUDENT_PHONE = phone;
    await login("student");
}
async function contains(title) {
    return evaluate(
        `document.body.innerText.includes(${JSON.stringify(title)})`,
    );
}
try {
    await login("admin");
    const members = await create("student", membersTitle);
    const managers = await create("org_admin", managersTitle);
    assert.deepEqual(
        inspect(members).recipients.map((r) => r.user_id),
        [fixture.users[0], fixture.users[4], fixture.users[5]].sort(
            (a, b) => a - b,
        ),
    );
    assert.deepEqual(
        inspect(managers).recipients.map((r) => r.user_id),
        [fixture.users[3]],
    );
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
            `Org announcement editor overflow ${width}`,
        );
    }
    await student("09121234560");
    await goto("/organization-memberships");
    const oldKey = await evaluate(
        `JSON.parse(document.querySelector('script[data-page]').textContent).props.memberships.find(m=>m.id===${fixture.company_id}).membership_key`,
    );
    await evaluate(
        `document.querySelector('[data-membership="${fixture.company_id}"] details').open=true`,
    );
    await fill(
        `[data-membership="${fixture.company_id}"] [name=reason]`,
        "خروج آگاهانه برای بررسی پیام‌های منتظر",
    );
    await submit(`[data-membership="${fixture.company_id}"] form`);
    await until(
        `!document.querySelector('[data-membership="${fixture.company_id}"]')`,
    );
    await login("organization");
    await goto("/organization/invitations");
    await fill("[name=invite_phone]", "09121234560");
    await submit("form:has([name=invite_phone])");
    await until("document.querySelectorAll('.invitation-card').length===1");
    await student("09121234560");
    await accept();
    await goto("/organization-memberships");
    const newKey = await evaluate(
        `JSON.parse(document.querySelector('script[data-page]').textContent).props.memberships.find(m=>m.id===${fixture.company_id}).membership_key`,
    );
    assert.notEqual(oldKey, newKey);
    await login("organization");
    await goto("/organization/invitations");
    await fill("[name=invite_phone]", "09121234567");
    await submit("form:has([name=invite_phone])");
    await until("document.querySelectorAll('.invitation-card').length===2");
    await student("09121234567");
    await accept();
    worker();
    const result = inspect(members);
    assert.equal(result.status, "completed");
    assert.equal(result.ciphertext_only, true);
    assert.equal(
        result.recipients.find((r) => r.user_id === fixture.users[0]).status,
        "skipped",
    );
    assert.equal(
        result.recipients.find((r) => r.user_id === fixture.users[0])
            .failure_code,
        "scope_changed",
    );
    assert.equal(
        result.recipients.filter((r) => r.status === "delivered").length,
        2,
    );
    assert(!result.recipients.some((r) => r.user_id === fixture.users[7]));
    assert.equal(
        inspect(managers).recipients.filter((r) => r.status === "delivered")
            .length,
        1,
    );
    assert.equal(inspect(managers).ciphertext_only, true);
    worker();
    assert.deepEqual(inspect(members), result);
    await goto("/notifications");
    assert.equal(await contains(membersTitle), false);
    assert.equal(await contains(managersTitle), false);
    await student("09121234560");
    await goto("/notifications");
    assert.equal(await contains(membersTitle), false);
    await student("09121234564");
    await goto("/notifications");
    assert.equal(await contains(membersTitle), true);
    assert.equal(await contains(managersTitle), false);
    assert(
        (await evaluate("document.body.innerText")).includes(
            "<b>مجموعه محرمانه آزمایشی</b>",
        ),
    );
    assert((await evaluate("document.body.innerText")).includes(literal));
    assert.equal(
        await evaluate("window.orgAnnouncementInjected||false"),
        false,
    );
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
            `Private inbox overflow ${width}`,
        );
    }
    await student("09121234565");
    await goto("/notifications");
    assert.equal(await contains(membersTitle), true);
    assert.equal(await contains(managersTitle), false);
    await student("09121234566");
    await goto("/notifications");
    assert.equal(await contains(membersTitle), false);
    assert.equal(await contains(managersTitle), false);
    await login("organization");
    await goto("/notifications");
    assert.equal(await contains(managersTitle), true);
    assert.equal(await contains(membersTitle), false);
    await login("admin");
    await goto("/admin/announcements/" + members);
    await until(
        "document.querySelector('[data-testid=announcement-status]').innerText.includes('پایان‌یافته')",
    );
    assert(
        (await evaluate("document.body.innerText")).includes(
            "عضویت یا ثبت‌نام قبلی پایان یافته",
        ),
    );
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "admin-explicit-company-scope-and-two-role-choices",
                "member-manager-and-other-organization-manager-isolation",
                "actual-self-departure-invitation-and-fresh-consent-rejoin",
                "old-membership-and-postpublication-new-member-not-delivered",
                "actual-database-worker-private-encrypted-inbox-deliveries-once",
                "recipient-bound-readable-scope-label-escaped-name-and-script",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
