# face-service — Servicio interno de embeddings faciales

Convierte una foto en un embedding facial de 128 dimensiones. Uso exclusivo
interno de SENTINEL (Laravel lo consume vía `FaceRecognitionService`).
**No exponer a internet** — escucha solo en 127.0.0.1.

## Uso
    cp .env.example .env   # definir FACE_SERVICE_TOKEN (mismo valor que
                           # FACE_RECOGNITION_TOKEN en el .env de Laravel)
    npm install
    npm start              # escucha en 127.0.0.1:7601

## Endpoints
- `GET /health` → `{status, models_loaded}`
- `POST /embed` (Bearer token, multipart `photo`) →
  `{embedding[128], model_version, box}` | 422 `{error: no_face|multiple_faces}`
  | 401 sin token | 400 sin foto

## Notas
- Modelo: @vladmandic/face-api (ssdMobilenetv1 + landmarks + recognition),
  pesos incluidos en el paquete npm. `model_version = faceapi-v1`; si se
  cambia el modelo hay que re-enrolar todas las plantillas (ver spec).
- Sin persistencia: el servicio no guarda fotos ni embeddings. El buffer de
  la foto subida vive solo en memoria (`multer.memoryStorage()`) durante el
  request y nunca se escribe a disco.

## Variante de runtime usada: `@tensorflow/tfjs` + `@napi-rs/canvas` (no tfjs-node, no node-canvas)

Esta máquina de desarrollo (Windows) no tiene Python ni Visual Studio Build
Tools instalados, así que **ninguna** dependencia que requiera compilar con
`node-gyp` puede instalarse aquí:

1. `@tensorflow/tfjs-node` (opción primaria del brief) — falla al compilar:
   node-gyp no encuentra ningún Python utilizable.
2. `canvas` / node-canvas (fallback documentado en el brief) — falla por la
   misma razón: también requiere `node-gyp`.

Se optó por **`@napi-rs/canvas`** en lugar de `canvas`: es un paquete N-API
que distribuye binarios nativos **precompilados** para win32-x64 (entre otras
plataformas), así que `npm install` no compila nada localmente. Expone la
misma interfaz que face-api.js espera de su parche de entorno
(`Canvas`, `Image`, `ImageData`, `loadImage`), por lo que
`faceapi.env.monkeyPatch({ Canvas, Image, ImageData })` funciona igual que
con `canvas`.

Detalles de implementación no obvios (por si se retoca `server.js`):

- **Import de face-api.js:** el specifier normal (`import * as faceapi from
  "@vladmandic/face-api"`) resuelve al build CJS por defecto
  (`dist/face-api.node.js`), que hace `require("@tensorflow/tfjs-node")`
  incondicionalmente aunque no se use — revienta con `Cannot find module`.
  El build ESM empaquetado (`dist/face-api.esm.js`) tampoco sirve corriendo
  como ESM nativo de Node: usa un shim de `require` dinámico pensado para
  bundlers de navegador que revienta con `TypeError: this.util.TextEncoder is
  not a constructor` al cargar. La solución fue importar directamente
  `@vladmandic/face-api/dist/face-api.node-wasm.js` — el build CJS real para
  Node que el propio paquete publica para este escenario (Node sin
  `tfjs-node`), que depende solo de `@tensorflow/tfjs` +
  `@tensorflow/tfjs-backend-wasm` (WASM precompilado, sin compilación local).
- **Backend de tfjs:** hay que activar explícitamente el backend WASM antes
  de cargar los modelos (`await tf.setBackend("wasm"); await tf.ready();`) —
  si no, tfjs-core tira `The highest priority backend 'wasm' has not yet been
  initialized`.
- **Canvas "vacío":** face-api.js crea canvases internos con `new Canvas()`
  (sin width/height) y les asigna las dimensiones después — patrón normal
  con el `Canvas` del navegador o con node-canvas (tienen tamaño por
  defecto), pero el constructor nativo de `@napi-rs/canvas` exige
  width/height numéricos y revienta (`Failed to convert napi value Undefined
  into rust type 'i32'`) si se le llama sin argumentos. `server.js` envuelve
  `Canvas` en una subclase (`PatchedCanvas`) con valores por defecto
  (300×150) para ese caso.
- **Decodificación de imagen:** `@napi-rs/canvas`'s `loadImage(buffer)`
  devuelve un `Image`, no una superficie dibujable; se dibuja sobre un
  `PatchedCanvas` (`ctx.drawImage(image, 0, 0, ...)`) y ese canvas es lo que
  se pasa a `faceapi.detectAllFaces(canvas)`.
- **`npm test` / `node --test`:** en esta instalación de Node (v22.15.1,
  Windows), invocar `node --test test/` (con el directorio como argumento
  posicional) falla con un `MODULE_NOT_FOUND` espurio al intentar resolver el
  directorio como specifier CJS, en vez de auto-descubrir los archivos
  `*.test.js` dentro. El script `test` de `package.json` usa `node --test`
  **sin** argumento posicional (auto-discovery por defecto) más
  `--test-force-exit`, necesario porque el test del brief no cierra el
  servidor HTTP explícitamente al terminar (`server.close()`), lo que dejaría
  el proceso colgado indefinidamente sin ese flag.

Rendimiento: WASM puro es más lento que `tfjs-node` (que usa la librería
nativa de TensorFlow) pero suficiente — el enrolamiento no es tiempo-crítico
(ver brief). En la corrida de smoke test local, la detección + embedding de
una foto individual tomó ≈0.5s una vez cargados los modelos (la primera
carga de modelos toma varios segundos).
