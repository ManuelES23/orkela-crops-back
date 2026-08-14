import { test, before } from "node:test";
import assert from "node:assert";

process.env.FACE_SERVICE_TOKEN = "test-token";
process.env.FACE_SERVICE_PORT = "0"; // puerto efímero

const { createApp } = await import("../server.js");

let app;
let baseUrl;

before(async () => {
  app = await createApp();
  const server = app.listen(0);
  const { port } = server.address();
  baseUrl = `http://127.0.0.1:${port}`;
});

test("GET /health responde ok", async () => {
  const res = await fetch(`${baseUrl}/health`);
  assert.strictEqual(res.status, 200);
  const body = await res.json();
  assert.strictEqual(body.status, "ok");
});

test("POST /embed sin token responde 401", async () => {
  const res = await fetch(`${baseUrl}/embed`, { method: "POST" });
  assert.strictEqual(res.status, 401);
});

test("POST /embed sin foto responde 400", async () => {
  const res = await fetch(`${baseUrl}/embed`, {
    method: "POST",
    headers: { Authorization: "Bearer test-token" },
  });
  assert.strictEqual(res.status, 400);
});

test("POST /embed con imagen sin rostro responde 422 no_face", async () => {
  // PNG 1x1 blanco (base64) — garantizado sin rostro
  const whitePixel = Buffer.from(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==",
    "base64"
  );
  const form = new FormData();
  form.append("photo", new Blob([whitePixel], { type: "image/png" }), "blank.png");

  const res = await fetch(`${baseUrl}/embed`, {
    method: "POST",
    headers: { Authorization: "Bearer test-token" },
    body: form,
  });
  assert.strictEqual(res.status, 422);
  const body = await res.json();
  assert.strictEqual(body.error, "no_face");
});
