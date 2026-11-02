<?php
/*
 * Neues Passwort für die Galerie-Verwaltung setzen (nur über die Kommandozeile).
 *   php verwaltung/passwort-setzen.php
 * Ohne Angabe wird ein sicheres Zufallspasswort erzeugt und einmal angezeigt.
 * Danach die Datei verwaltung/passwort.php auf den Server hochladen.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

echo "Neues Passwort eingeben (leer lassen = Zufallspasswort erzeugen): ";
$pw = trim((string)fgets(STDIN));
if ($pw === '') {
    $z = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $teile = [];
    for ($t = 0; $t < 4; $t++) { $s = ''; for ($i = 0; $i < 5; $i++) $s .= $z[random_int(0, strlen($z) - 1)]; $teile[] = $s; }
    $pw = implode('-', $teile);
    echo "\nIhr neues Passwort:  $pw\n(Bitte sicher aufbewahren, z. B. im Passwort-Manager.)\n";
} elseif (strlen($pw) < 12) {
    fwrite(STDERR, "Zu kurz: mindestens 12 Zeichen.\n"); exit(1);
}
$code = "<?php\n// Passwort-Hash für die Galerie-Verwaltung. Neues Passwort: php verwaltung/passwort-setzen.php\nreturn " . var_export(password_hash($pw, PASSWORD_DEFAULT), true) . ";\n";
file_put_contents(__DIR__ . '/passwort.php', $code);
echo "Gespeichert in verwaltung/passwort.php\n";
