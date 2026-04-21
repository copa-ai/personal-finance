Tu configuración está **casi correcta**, pero hay varios detalles clave por los que Chrome en Android **no muestra el prompt de instalación (PWA)**. Te explico lo importante y cómo arreglarlo 👇

---

## ✅ Requisitos para que aparezca el “Instalar app”

Chrome solo muestra el diálogo si se cumplen TODOS:

1. ✅ HTTPS (lo tienes)
2. ⚠️ **Service Worker activo y controlando la página**
3. ⚠️ **Manifest válido y correctamente enlazado**
4. ⚠️ **Iconos adecuados (mínimo 192x192 y 512x512)**
5. ⚠️ **El usuario ha interactuado con la web**
6. ⚠️ **No estar en modo incógnito**
7. ⚠️ **La app debe parecer “instalable” (heurística de Chrome)**

---

## 🔴 Problemas en tu caso

### 1. ❌ Falta icono de 192x192

Solo tienes:

```json
"sizes": "512x512"
```

👉 Chrome requiere también uno de **192x192**.

✔️ Solución:

```json
"icons": [
  {
    "src": "logo-192.png",
    "sizes": "192x192",
    "type": "image/png"
  },
  {
    "src": "logo-512.png",
    "sizes": "512x512",
    "type": "image/png"
  }
]
```

---

### 2. ⚠️ Probablemente no estás registrando el Service Worker

Tu `sw.js` está bien, pero falta esto en tu frontend:

```js
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js')
    .then(() => console.log('SW registrado'));
}
```

👉 Sin esto, **Chrome no considera la app instalable**.

---

### 3. ⚠️ El manifest puede no estar enlazado

Asegúrate de tener en tu `<head>`:

```html
<link rel="manifest" href="/manifest.json">
```

---

### 4. ⚠️ Estás entrando en `/login`

Tu manifest tiene:

```json
"start_url": "/"
```

👉 Problema:

* Si entras directamente a `/login`, Chrome puede no considerar esa página como “instalable”

✔️ Opciones:

```json
"start_url": "/login"
```

o mejor:

```json
"start_url": "/?source=pwa"
```

---

### 5. ⚠️ Falta `display: standalone` (recomendado)

Tienes:

```json
"display": "fullscreen"
```

👉 Esto funciona, pero es más seguro usar:

```json
"display": "standalone"
```

---

### 6. ⚠️ No has disparado el evento `beforeinstallprompt`

Chrome ya **NO siempre muestra el banner automáticamente**.

Debes capturarlo manualmente:

```js
let deferredPrompt;

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;

  // Mostrar tu botón de instalar
  document.getElementById('installBtn').style.display = 'block';
});

function installApp() {
  deferredPrompt.prompt();
  deferredPrompt.userChoice.then(() => {
    deferredPrompt = null;
  });
}
```

---

## 🧪 Cómo comprobar rápido

En Chrome Android:

1. Abre tu web
2. Menú (⋮)
3. Busca:

   * “Instalar app”
   * o “Añadir a pantalla de inicio”

👉 Si aparece ahí pero no popup → todo OK, solo falta UX

---

## ✅ Checklist final

* [ ] Manifest enlazado
* [ ] Iconos 192 + 512
* [ ] Service Worker registrado
* [ ] HTTPS (✔️)
* [ ] Navegación real del usuario
* [ ] `beforeinstallprompt` manejado

---

## 🚀 Si quieres

Pásame tu HTML principal (`<head>` + scripts) y te digo exactamente qué línea falta.
