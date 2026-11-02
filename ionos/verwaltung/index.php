<?php
/*
 * Galerie-Verwaltung – Lohnunternehmen Heiligmann
 *
 * Einfache Verwaltung für die Galerie-Seite. Läuft auf jedem IONOS-Webhosting mit PHP (GD-Erweiterung).
 * - Passwort steht als Hash in passwort.php (kein Klartext, keine Datenbank).
 * - Neues Passwort: im Ordner ionos/ "php verwaltung/passwort-setzen.php" ausführen und passwort.php hochladen.
 * - Daten: ../data/galerie.json  ·  Hochgeladene Fotos: ../uploads/galerie/
 */

declare(strict_types=1);

const MAX_KANTE = 1800;      // große Ansicht (Pixel, längste Seite)
const VORSCHAU_KANTE = 760;  // Kachel in der Galerie
const JPEG_QUALITAET = 82;

$ROOT      = dirname(__DIR__);
$PW_DATEI  = __DIR__ . '/passwort.php';
$JSON      = $ROOT . '/data/galerie.json';
$START     = $ROOT . '/data/galerie.start.json';
$UPLOAD    = $ROOT . '/uploads/galerie';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => dirname($_SERVER['SCRIPT_NAME']) . '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_name('hm_verwaltung');
session_start();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function weiter(string $meldung = '', string $art = 'ok'): void {
    if ($meldung !== '') $_SESSION['flash'] = [$art, $meldung];
    header('Location: ./', true, 303);
    exit;
}

function lade_daten(string $json, string $start): array {
    $datei = is_file($json) ? $json : $start;
    $d = json_decode((string)@file_get_contents($datei), true);
    if (!is_array($d)) $d = [];
    $d['kategorien'] = $d['kategorien'] ?? [];
    $d['bilder'] = $d['bilder'] ?? [];
    return $d;
}

function speichere_daten(string $json, array $d): bool {
    $d['bilder'] = array_values($d['bilder']);
    $tmp = $json . '.tmp';
    $ok = file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $ok !== false && rename($tmp, $json);
}

/** Lädt ein Bild, dreht es nach EXIF richtig und gibt eine GD-Ressource zurück. */
function bild_laden(string $pfad): ?GdImage {
    $info = @getimagesize($pfad);
    if (!$info) return null;
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $im = @imagecreatefromjpeg($pfad); break;
        case IMAGETYPE_PNG:  $im = @imagecreatefrompng($pfad); break;
        case IMAGETYPE_WEBP: $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($pfad) : false; break;
        default: return null;
    }
    if (!$im) return null;
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($pfad);
        $o = (int)($exif['Orientation'] ?? 1);
        if ($o === 3) $im = imagerotate($im, 180, 0);
        elseif ($o === 6) $im = imagerotate($im, -90, 0);
        elseif ($o === 8) $im = imagerotate($im, 90, 0);
    }
    return $im;
}

function bild_speichern(GdImage $src, string $ziel, int $kante): bool {
    $w = imagesx($src); $h = imagesy($src);
    $f = min(1, $kante / max($w, $h));
    $nw = max(1, (int)round($w * $f)); $nh = max(1, (int)round($h * $f));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // transparente PNGs auf Weiß
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imageinterlace($dst, true);
    $ok = imagejpeg($dst, $ziel, JPEG_QUALITAET);
    imagedestroy($dst);
    return $ok;
}

/* ---------- Passwort festlegen (nur wenn noch keins existiert) ---------- */
$hatPasswort = is_file($PW_DATEI);
$istAngemeldet = !empty($_SESSION['ok']);
$fehler = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        weiter('Die Fotos waren zusammen zu groß (Server-Grenze ' . ini_get('post_max_size') . '). Bitte weniger Fotos auf einmal hochladen.', 'fehler');
    }
    $aktion = $_POST['aktion'] ?? '';

    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) {
        weiter('Sitzung abgelaufen, bitte noch einmal versuchen.', 'fehler');
    }

    if ($aktion === 'login' && $hatPasswort) {
        $hash = require $PW_DATEI;
        if (is_string($hash) && password_verify((string)($_POST['pw'] ?? ''), $hash)) {
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            weiter();
        }
        sleep(2); // bremst Rateversuche
        weiter('Falsches Passwort.', 'fehler');
    }

    if ($aktion === 'logout') { $_SESSION = []; session_destroy(); header('Location: ./', true, 303); exit; }

    if (!$istAngemeldet) weiter();

    $d = lade_daten($JSON, $START);
    $katIds = array_column($d['kategorien'], 'id');
    $finde = function (string $id) use (&$d): int {
        foreach ($d['bilder'] as $i => $b) if ($b['id'] === $id) return $i;
        return -1;
    };

    if ($aktion === 'hochladen') {
        $kat = in_array($_POST['kategorie'] ?? '', $katIds, true) ? $_POST['kategorie'] : ($katIds[0] ?? 'erd');
        $text = trim((string)($_POST['alt'] ?? ''));
        if (!is_dir($UPLOAD) && !@mkdir($UPLOAD, 0755, true)) weiter('Upload-Ordner konnte nicht angelegt werden.', 'fehler');
        $files = $_FILES['fotos'] ?? null;
        $anzahl = 0; $probleme = [];
        if ($files && is_array($files['name'])) {
            $neu = [];
            foreach ($files['name'] as $i => $name) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                if (in_array($files['error'][$i], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) { $probleme[] = $name . ' (größer als ' . ini_get('upload_max_filesize') . ')'; continue; }
                if ($files['error'][$i] !== UPLOAD_ERR_OK) { $probleme[] = $name . ' (Übertragung abgebrochen)'; continue; }
                $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
                if (function_exists('imagecreatetruecolor')) {
                    $im = bild_laden($files['tmp_name'][$i]);
                    if (!$im) { $probleme[] = $name . ' (kein JPG/PNG/WebP)'; continue; }
                    $ok = bild_speichern($im, "$UPLOAD/$id.jpg", MAX_KANTE) && bild_speichern($im, "$UPLOAD/$id-klein.jpg", VORSCHAU_KANTE);
                    imagedestroy($im);
                    $gross = "$id.jpg"; $klein = "$id-klein.jpg";
                } else {
                    // Ohne GD (z. B. lokales PHP unter Windows): bereits im Browser verkleinertes Bild unverändert übernehmen.
                    $typ = @getimagesize($files['tmp_name'][$i])[2] ?? 0;
                    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$typ] ?? null;
                    if (!$ext) { $probleme[] = $name . ' (kein JPG/PNG/WebP)'; continue; }
                    $ok = move_uploaded_file($files['tmp_name'][$i], "$UPLOAD/$id.$ext");
                    $gross = $klein = "$id.$ext";
                }
                if (!$ok) { $probleme[] = $name . ' (Speichern fehlgeschlagen)'; continue; }
                $neu[] = ['id' => $id, 'bild' => "uploads/galerie/$gross", 'vorschau' => "uploads/galerie/$klein",
                          'alt' => $text !== '' ? $text : 'Foto aus der Galerie', 'kategorie' => $kat];
                $anzahl++;
            }
            // Neue Fotos stehen vorne in ihrem Bereich
            $pos = 0;
            foreach ($d['bilder'] as $i => $b) if ($b['kategorie'] === $kat) { $pos = $i; break; } else $pos = $i + 1;
            array_splice($d['bilder'], $pos, 0, $neu);
        }
        if ($anzahl && !speichere_daten($JSON, $d)) weiter('Fotos hochgeladen, aber galerie.json konnte nicht gespeichert werden.', 'fehler');
        $msg = $anzahl === 1 ? '1 Foto hinzugefügt.' : "$anzahl Fotos hinzugefügt.";
        if ($probleme) weiter(($anzahl ? $msg . ' ' : '') . 'Nicht geklappt: ' . implode(', ', $probleme), $anzahl ? 'ok' : 'fehler');
        weiter($anzahl ? $msg : 'Bitte mindestens ein Foto auswählen.', $anzahl ? 'ok' : 'fehler');
    }

    $i = $finde((string)($_POST['id'] ?? ''));
    if ($i < 0) weiter('Foto nicht gefunden.', 'fehler');

    if ($aktion === 'aendern') {
        $d['bilder'][$i]['alt'] = trim((string)($_POST['alt'] ?? '')) ?: 'Foto aus der Galerie';
        if (in_array($_POST['kategorie'] ?? '', $katIds, true)) $d['bilder'][$i]['kategorie'] = $_POST['kategorie'];
        speichere_daten($JSON, $d) ? weiter('Gespeichert.') : weiter('Speichern fehlgeschlagen.', 'fehler');
    }

    if ($aktion === 'hoch' || $aktion === 'runter') {
        $kat = $d['bilder'][$i]['kategorie'];
        $schritt = $aktion === 'hoch' ? -1 : 1;
        for ($j = $i + $schritt; $j >= 0 && $j < count($d['bilder']); $j += $schritt) {
            if ($d['bilder'][$j]['kategorie'] === $kat) {
                [$d['bilder'][$i], $d['bilder'][$j]] = [$d['bilder'][$j], $d['bilder'][$i]];
                break;
            }
        }
        speichere_daten($JSON, $d) ? weiter() : weiter('Speichern fehlgeschlagen.', 'fehler');
    }

    if ($aktion === 'loeschen') {
        $b = $d['bilder'][$i];
        array_splice($d['bilder'], $i, 1);
        if (!speichere_daten($JSON, $d)) weiter('Löschen fehlgeschlagen.', 'fehler');
        // Nur selbst hochgeladene Dateien wirklich entfernen; Original-Fotos der Website bleiben liegen.
        foreach (['bild', 'vorschau'] as $k) {
            $p = (string)($b[$k] ?? '');
            if (str_starts_with($p, 'uploads/galerie/') && basename($p) === substr($p, 16)) @unlink("$ROOT/$p");
        }
        weiter('Foto entfernt.');
    }

    weiter();
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$d = $istAngemeldet ? lade_daten($JSON, $START) : null;
$maxUpload = ini_get('upload_max_filesize');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Galerie verwalten – Heiligmann</title>
<link rel="icon" href="../logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="../fonts/site.css">
<style>
  :root { --gruen:#2E7D32; --dunkel:#1a1e25; --grau:#55606b; --linie:#e2e0d8; --bg:#F6F4EE; --rot:#B3261E; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--dunkel); font:17px/1.5 'Source Serif 4', Georgia, serif; }
  h1, h2, h3, button, label, .knopf { font-family:'Barlow', system-ui, sans-serif; }
  a { color:var(--gruen); }
  .kopf { background:#fff; border-bottom:1px solid var(--linie); }
  .kopf > div { max-width:1100px; margin:0 auto; padding:12px 16px; display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap; }
  .kopf img { height:40px; display:block; }
  main { max-width:1100px; margin:0 auto; padding:24px 16px 80px; }
  h1 { font-size:32px; margin:0 0 4px; letter-spacing:-.01em; }
  h2 { font-size:22px; margin:36px 0 14px; display:flex; gap:10px; align-items:center; }
  h2 small { font-weight:500; color:var(--grau); font-size:15px; }
  .karte { background:#fff; border:1px solid var(--linie); border-radius:8px; padding:20px; }
  label { display:block; font-weight:700; font-size:15px; margin:0 0 6px; }
  input[type=text], input[type=password], select { width:100%; min-height:48px; font:inherit; padding:10px 12px; border:1.5px solid #c9c6bc; border-radius:6px; background:#fff; }
  input[type=file] { font:inherit; width:100%; padding:14px; border:2px dashed #b9c4b4; border-radius:8px; background:#f4f8f2; }
  button, .knopf { min-height:48px; padding:0 20px; border-radius:6px; border:0; font-weight:700; font-size:16px; cursor:pointer; background:var(--gruen); color:#fff; display:inline-flex; align-items:center; justify-content:center; gap:6px; text-decoration:none; }
  button.leise { background:#fff; color:var(--dunkel); border:1.5px solid #c9c6bc; }
  button.weg { background:#fff; color:var(--rot); border:1.5px solid #e3b4b0; }
  button:hover { filter:brightness(.95); }
  .reihe { display:grid; gap:14px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); align-items:end; }
  .meldung { padding:14px 16px; border-radius:6px; margin:0 0 20px; font-family:'Barlow'; font-weight:600; }
  .meldung.ok { background:#e5f2e3; color:#1e5a22; }
  .meldung.fehler { background:#fbe5e3; color:#8c1d16; }
  .raster { display:grid; gap:16px; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); }
  .foto { background:#fff; border:1px solid var(--linie); border-radius:8px; overflow:hidden; display:flex; flex-direction:column; }
  .foto img { width:100%; aspect-ratio:4/3; object-fit:cover; display:block; background:#e6e2d8; }
  .foto form { margin:0; }
  .foto .inhalt { padding:12px; display:grid; gap:8px; }
  .foto .knoepfe { display:flex; gap:8px; padding:0 12px 12px; }
  .foto .knoepfe form { flex:1; }
  .foto .knoepfe button { width:100%; padding:0 8px; }
  .hinweis { color:var(--grau); font-size:15px; margin:6px 0 0; }
  .schmal { max-width:420px; margin:10vh auto; }
</style>
</head>
<body>
<header class="kopf"><div>
  <a href="../galerie.html" title="Zur Galerie"><img src="../logo.svg" alt="Lohnunternehmen Heiligmann"></a>
  <?php if ($istAngemeldet): ?>
  <div style="display:flex;gap:10px;padding:0;">
    <a class="knopf" href="../galerie.html" target="_blank" rel="noopener" style="background:#fff;color:var(--dunkel);border:1.5px solid #c9c6bc;">Galerie ansehen ↗</a>
    <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><button class="leise" name="aktion" value="logout">Abmelden</button></form>
  </div>
  <?php endif; ?>
</div></header>

<main>
<?php if ($flash): ?><div class="meldung <?= h($flash[0]) ?>" role="status"><?= h($flash[1]) ?></div><?php endif; ?>

<?php if (!$hatPasswort): ?>
  <div class="karte schmal">
    <h1>Kein Passwort eingerichtet</h1>
    <p class="hinweis">Die Datei <code>verwaltung/passwort.php</code> fehlt. Neues Passwort setzen mit:<br><code>php verwaltung/passwort-setzen.php</code></p>
  </div>

<?php elseif (!$istAngemeldet): ?>
  <div class="karte schmal">
    <h1>Galerie verwalten</h1>
    <p class="hinweis">Bitte melden Sie sich an.</p>
    <form method="post" style="display:grid;gap:14px;margin-top:18px;">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
      <div><label for="pw">Passwort</label><input id="pw" type="password" name="pw" required autofocus autocomplete="current-password"></div>
      <button name="aktion" value="login">Anmelden</button>
    </form>
  </div>

<?php else: ?>
  <h1>Galerie verwalten</h1>
  <p class="hinweis" style="margin-bottom:22px;">Neue Fotos erscheinen sofort auf der Galerie-Seite.</p>

  <form class="karte" method="post" enctype="multipart/form-data" id="upload">
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
    <h2 style="margin-top:0;">Fotos hinzufügen</h2>
    <div style="margin-bottom:14px;">
      <label for="fotos">Fotos auswählen (auch mehrere auf einmal)</label>
      <input id="fotos" type="file" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple required>
      <p class="hinweis">JPG, PNG oder WebP, direkt vom Handy. Große Fotos werden automatisch verkleinert.</p>
    </div>
    <div class="reihe">
      <div><label for="kat">Bereich</label>
        <select id="kat" name="kategorie"><?php foreach ($d['kategorien'] as $k): ?><option value="<?= h($k['id']) ?>"><?= h($k['titel']) ?></option><?php endforeach; ?></select></div>
      <div><label for="alt">Kurze Beschreibung <span style="font-weight:400">(optional)</span></label>
        <input id="alt" type="text" name="alt" maxlength="140" placeholder="z. B. Pflasterarbeiten in Jettingen"></div>
      <div><button name="aktion" value="hochladen" style="width:100%;">Hochladen</button></div>
    </div>
  </form>

  <?php foreach ($d['kategorien'] as $k):
    $liste = array_values(array_filter($d['bilder'], fn($b) => $b['kategorie'] === $k['id'])); ?>
    <h2><?= h($k['titel']) ?> <small><?= count($liste) ?> Fotos</small></h2>
    <?php if (!$liste): ?><p class="hinweis">Noch keine Fotos in diesem Bereich.</p><?php endif; ?>
    <div class="raster">
    <?php foreach ($liste as $n => $b): ?>
      <div class="foto">
        <img src="../<?= h($b['vorschau'] ?: $b['bild']) ?>" alt="" loading="lazy">
        <form method="post" class="inhalt">
          <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="id" value="<?= h($b['id']) ?>">
          <input type="text" name="alt" value="<?= h($b['alt']) ?>" maxlength="140" aria-label="Beschreibung">
          <select name="kategorie" aria-label="Bereich"><?php foreach ($d['kategorien'] as $kk): ?><option value="<?= h($kk['id']) ?>"<?= $kk['id'] === $b['kategorie'] ? ' selected' : '' ?>><?= h($kk['titel']) ?></option><?php endforeach; ?></select>
          <button class="leise" name="aktion" value="aendern">Änderung speichern</button>
        </form>
        <div class="knoepfe">
          <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="id" value="<?= h($b['id']) ?>"><button class="leise" name="aktion" value="hoch" title="Nach vorne"<?= $n === 0 ? ' disabled style="opacity:.4"' : '' ?>>←</button></form>
          <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="id" value="<?= h($b['id']) ?>"><button class="leise" name="aktion" value="runter" title="Nach hinten"<?= $n === count($liste) - 1 ? ' disabled style="opacity:.4"' : '' ?>>→</button></form>
          <form method="post" onsubmit="return confirm('Dieses Foto wirklich aus der Galerie entfernen?');"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="id" value="<?= h($b['id']) ?>"><button class="weg" name="aktion" value="loeschen">Löschen</button></form>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
</main>
<?php if ($istAngemeldet): ?>
<script>
// Fotos schon im Browser verkleinern: schneller bei schwachem Netz und keine Probleme mit Upload-Grenzen.
// Klappt das nicht (z. B. altes Gerät oder HEIC-Datei), wird das Formular ganz normal abgeschickt.
(function () {
  var form = document.getElementById('upload');
  if (!form || !window.fetch || !window.createImageBitmap || !window.FormData) return;
  var btn = form.querySelector('button[value=hochladen]');
  function verkleinern(file) {
    return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
      var k = Math.min(1, 2400 / Math.max(bmp.width, bmp.height));
      var c = document.createElement('canvas');
      c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
      var ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
      ctx.drawImage(bmp, 0, 0, c.width, c.height);
      return new Promise(function (ok, fail) { c.toBlob(function (b) { b ? ok(b) : fail(); }, 'image/jpeg', 0.88); });
    });
  }
  form.addEventListener('submit', function (e) {
    var files = Array.prototype.slice.call(form.querySelector('#fotos').files);
    if (!files.length) return;
    e.preventDefault();
    btn.disabled = true;
    var done = 0;
    btn.textContent = 'Wird vorbereitet …';
    Promise.all(files.map(function (f) {
      return verkleinern(f).then(function (b) { btn.textContent = 'Wird vorbereitet … ' + (++done) + '/' + files.length; return [b, f.name.replace(/\.[^.]+$/, '') + '.jpg']; });
    })).then(function (list) {
      var fd = new FormData(form);
      fd.delete('fotos[]');
      list.forEach(function (x) { fd.append('fotos[]', x[0], x[1]); });
      fd.set('aktion', 'hochladen');
      btn.textContent = 'Wird hochgeladen …';
      return fetch(form.action || './', { method: 'POST', body: fd, credentials: 'same-origin', redirect: 'manual' });
    }).then(function () { location.href = './'; })
      .catch(function () {
        var a = document.createElement('input'); a.type = 'hidden'; a.name = 'aktion'; a.value = 'hochladen'; form.appendChild(a);
        btn.textContent = 'Wird hochgeladen …'; HTMLFormElement.prototype.submit.call(form);
      });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
