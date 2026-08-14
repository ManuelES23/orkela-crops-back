// sentinel-back/face-service/server.js
import "dotenv/config";
import express from "express";
import multer from "multer";
// @vladmandic/face-api resuelve su entry point CJS por defecto
// (dist/face-api.node.js), que exige "@tensorflow/tfjs-node" incluso si no se
// usa; y su build ESM empaquetado (dist/face-api.esm.js) está pensado para
// bundlers de navegador (usa un require dinámico que no existe en ESM nativo
// de Node y revienta al cargar TextEncoder). El paquete también publica
// dist/face-api.node-wasm.js: un build CJS real para Node que depende solo de
// "@tensorflow/tfjs" + "@tensorflow/tfjs-backend-wasm" (WASM precompilado,
// sin node-gyp/Python/Visual Studio) — exactamente el runtime de este
// servicio, así que se importa ese archivo explícitamente.
import * as faceapi from "@vladmandic/face-api/dist/face-api.node-wasm.js";
import * as tf from "@tensorflow/tfjs";
import "@tensorflow/tfjs-backend-wasm";
import { Canvas, Image, ImageData, loadImage } from "@napi-rs/canvas";
import path from "node:path";
import { fileURLToPath } from "node:url";

const MODEL_VERSION = "faceapi-v1";
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const MODELS_PATH = path.join(
  __dirname,
  "node_modules",
  "@vladmandic",
  "face-api",
  "model"
);

// face-api.js espera un entorno tipo navegador (Canvas/Image/ImageData);
// @napi-rs/canvas provee binarios nativos precompilados (sin node-gyp/Python/
// Visual Studio) que implementan esa misma interfaz. Internamente face-api
// crea canvases "vacíos" con `new Canvas()` (sin width/height) y les asigna
// las dimensiones después; a diferencia del Canvas del navegador o de
// node-canvas, el constructor nativo de @napi-rs/canvas exige width/height
// numéricos, así que se envuelve con valores por defecto.
class PatchedCanvas extends Canvas {
  constructor(width = 300, height = 150, flag) {
    super(width, height, flag);
  }
}
faceapi.env.monkeyPatch({ Canvas: PatchedCanvas, Image, ImageData });

const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: 5 * 1024 * 1024 },
});

let modelsLoaded = false;

async function loadModels() {
  if (modelsLoaded) return;
  await tf.setBackend("wasm");
  await tf.ready();
  await faceapi.nets.ssdMobilenetv1.loadFromDisk(MODELS_PATH);
  await faceapi.nets.faceLandmark68Net.loadFromDisk(MODELS_PATH);
  await faceapi.nets.faceRecognitionNet.loadFromDisk(MODELS_PATH);
  modelsLoaded = true;
}

function requireToken(req, res, next) {
  const auth = req.headers.authorization || "";
  const token = auth.startsWith("Bearer ") ? auth.slice(7) : null;
  if (!token || token !== process.env.FACE_SERVICE_TOKEN) {
    return res.status(401).json({ error: "unauthorized" });
  }
  next();
}

export async function createApp() {
  await loadModels();
  const app = express();

  app.get("/health", (_req, res) => {
    res.json({ status: "ok", models_loaded: modelsLoaded });
  });

  app.post("/embed", requireToken, upload.single("photo"), async (req, res, next) => {
    if (!req.file) {
      return res.status(400).json({ error: "photo_required" });
    }

    let image;
    try {
      image = await loadImage(req.file.buffer);
    } catch {
      return res.status(400).json({ error: "invalid_image" });
    }

    try {
      // @napi-rs/canvas's loadImage() returns an Image, not a drawable
      // surface; face-api's detectAllFaces() wants something it can read
      // pixels from, so draw the decoded image onto a Canvas first (mirrors
      // the browser-style Canvas/Image/ImageData env that monkeyPatch() sets
      // up above).
      const canvas = new PatchedCanvas(image.width, image.height);
      const ctx = canvas.getContext("2d");
      ctx.drawImage(image, 0, 0, image.width, image.height);

      const detections = await faceapi
        .detectAllFaces(canvas)
        .withFaceLandmarks()
        .withFaceDescriptors();

      if (detections.length === 0) {
        return res.status(422).json({ error: "no_face" });
      }
      if (detections.length > 1) {
        return res.status(422).json({ error: "multiple_faces" });
      }

      const { descriptor, detection } = detections[0];
      return res.json({
        embedding: Array.from(descriptor),
        model_version: MODEL_VERSION,
        box: {
          x: Math.round(detection.box.x),
          y: Math.round(detection.box.y),
          width: Math.round(detection.box.width),
          height: Math.round(detection.box.height),
        },
      });
    } catch (err) {
      // Express 4 no reenvía rechazos de promesas de handlers async al
      // middleware de errores automáticamente; hay que hacerlo explícito
      // para no dejar la request colgada ni enmascarar el error.
      return next(err);
    }
  });

  // Errores inesperados (bugs, imágenes que pasan loadImage pero fallan al
  // tensorizar, etc.) se reportan como 500 en vez de enmascararse como
  // no_face, para no ocultar fallos reales del pipeline de detección.
  // eslint-disable-next-line no-unused-vars
  app.use((err, _req, res, _next) => {
    console.error("Error inesperado en /embed:", err);
    res.status(500).json({ error: "internal_error" });
  });

  return app;
}

// Arranque directo (no en tests)
if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const port = Number(process.env.FACE_SERVICE_PORT || 7601);
  createApp().then((app) => {
    app.listen(port, "127.0.0.1", () => {
      console.log(`face-service escuchando en 127.0.0.1:${port}`);
    });
  });
}
