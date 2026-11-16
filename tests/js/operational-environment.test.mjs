import test from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import {
    mkdtempSync,
    readFileSync,
    statSync,
    writeFileSync,
    rmSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
const script = "resources/operations/initialize-environment.py";
function fixture(run) {
    const path = mkdtempSync(join(tmpdir(), "kardorost-env-test-"));
    try {
        return run(path);
    } finally {
        rmSync(path, { recursive: true, force: true });
    }
}
function invoke(args) {
    return spawnSync("python3", [script, ...args], { encoding: "utf8" });
}
function values(path) {
    return Object.fromEntries(
        readFileSync(path, "utf8")
            .split("\n")
            .filter(
                (line) => line && !line.startsWith("#") && line.includes("="),
            )
            .map((line) => {
                const p = line.indexOf("=");
                return [line.slice(0, p), line.slice(p + 1)];
            }),
    );
}

test("fresh environment has private valid independent secrets without printing them", () =>
    fixture((dir) => {
        const output = join(dir, "runtime.env");
        const result = invoke([
            "--url",
            "https://kardorost.ir",
            "--output",
            output,
        ]);
        assert.equal(result.status, 0);
        assert.equal(statSync(output).mode & 0o777, 0o600);
        const env = values(output);
        assert(/^base64:[A-Za-z0-9+/]{43}=$/.test(env.APP_KEY));
        assert.equal(Buffer.from(env.APP_KEY.slice(7), "base64").length, 32);
        assert(
            env.DB_PASSWORD.length >= 48 &&
                env.DB_ADMIN_PASSWORD.length >= 48 &&
                env.DB_PASSWORD !== env.DB_ADMIN_PASSWORD,
        );
        for (const secret of [
            env.APP_KEY,
            env.DB_PASSWORD,
            env.DB_ADMIN_PASSWORD,
        ])
            assert(
                !result.stdout.includes(secret) &&
                    !result.stderr.includes(secret),
            );
        assert.equal(env.SMS_DRIVER, "disabled");
        assert.equal(env.ZIBAL_ENABLED, "false");
        assert.equal(env.SESSION_SECURE_COOKIE, "true");
    }));

test("existing environment and encryption key are preserved byte for byte", () =>
    fixture((dir) => {
        const output = join(dir, "runtime.env");
        writeFileSync(output, "PRESERVE-EXISTING-ENVIRONMENT\n", {
            mode: 0o600,
        });
        const result = invoke([
            "--url",
            "https://kardorost.ir",
            "--output",
            output,
        ]);
        assert.equal(result.status, 1);
        assert.equal(
            readFileSync(output, "utf8"),
            "PRESERVE-EXISTING-ENVIRONMENT\n",
        );
    }));

test("ordinary production initialization rejects HTTP placeholders credentials and query strings", () =>
    fixture((dir) => {
        for (const url of [
            "http://kardorost.ir",
            "https://kardorost.example.com",
            "https://127.0.0.1",
            "https://user:private-token@kardorost.ir",
            "https://kardorost.ir?token=private-token",
            "https://kardorost.ir/subpath",
            "https://bad..ir",
        ]) {
            const result = invoke([
                "--url",
                url,
                "--output",
                join(dir, "runtime.env"),
            ]);
            assert.notEqual(result.status, 0);
            assert(
                !result.stdout.includes("private-token") &&
                    !result.stderr.includes("private-token"),
            );
        }
    }));

test("local acceptance can use loopback HTTP with secure-cookie override and a matching high port", () =>
    fixture((dir) => {
        const output = join(dir, "runtime.env");
        const result = invoke([
            "--local",
            "--url",
            "http://127.0.0.1:18088",
            "--project",
            "kardorost-validation",
            "--image-tag",
            "test-local",
            "--output",
            output,
        ]);
        assert.equal(result.status, 0);
        const env = values(output);
        assert.equal(env.WEB_PORT, "18088");
        assert.equal(env.SESSION_SECURE_COOKIE, "false");
        assert.equal(env.APP_ENV, "production");
        assert.equal(env.APP_DEBUG, "false");
    }));

test("local override cannot allow public HTTP or inject compose interpolation", () =>
    fixture((dir) => {
        for (const args of [
            ["--url", "http://kardorost.ir", "--local"],
            ["--url", "https://kardorost.ir", "--project", "bad/project"],
            ["--url", "https://kardorost.ir", "--image-tag", "bad$tag"],
            ["--url", "https://kardorost.ir", "--web-port", "80"],
            ["--url", "https://kardorost.ir", "--web-port", "0"],
        ]) {
            assert.notEqual(
                invoke([...args, "--output", join(dir, "runtime.env")]).status,
                0,
            );
        }
    }));

test("separate initializations never reuse the key or either database password", () =>
    fixture((dir) => {
        const a = join(dir, "a.env"),
            b = join(dir, "b.env");
        assert.equal(
            invoke(["--url", "https://kardorost.ir", "--output", a]).status,
            0,
        );
        assert.equal(
            invoke(["--url", "https://kardorost.ir", "--output", b]).status,
            0,
        );
        const first = values(a),
            second = values(b);
        for (const key of ["APP_KEY", "DB_PASSWORD", "DB_ADMIN_PASSWORD"])
            assert(first[key] !== second[key]);
    }));
