<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use ZipArchive;

/**
 * E-mail 5b · Os executáveis não se anexam: recusados pela extensão (também a última de um nome como
 * "fatura.pdf.exe") e pelo conteúdo (programas Windows, Linux e macOS, scripts com "#!", e os pacotes
 * Java e Android, que são ZIP com um manifesto). Um executável com o nome trocado não passa.
 */
final class ExecutableGuard
{
    public const EXTENSIONS = [
        'exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'msp', 'msix', 'appx', 'js', 'jse', 'vbs', 'vbe', 'wsf', 'wsh', 'ps1', 'psm1',
        'jar', 'apk', 'dmg', 'pkg', 'app', 'sh', 'bash', 'zsh', 'csh', 'command', 'run', 'cpl', 'hta', 'lnk', 'reg', 'pif',
        'gadget', 'dll', 'sys', 'deb', 'rpm', 'appimage', 'scpt', 'xbap', 'application', 'ipa',
    ];

    /** O motivo da recusa, ou null se pode seguir. */
    public static function refusal(string $name, string $content): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, self::EXTENSIONS, true)) {
            return "Os ficheiros .{$ext} não se podem anexar (são programas ou scripts).";
        }
        if (self::looksExecutable($content)) {
            return "O ficheiro \"{$name}\" é um programa ou um script e não se pode anexar.";
        }

        return null;
    }

    public static function looksExecutable(string $content): bool
    {
        $head = substr($content, 0, 4);
        if (str_starts_with($head, 'MZ') || $head === "\x7fELF" || str_starts_with($content, '#!')) {
            return true; // Windows (PE), Linux (ELF), script
        }
        if (in_array(bin2hex($head), ['feedface', 'feedfacf', 'cefaedfe', 'cffaedfe', 'cafebabe'], true)) {
            return true; // macOS (Mach-O) e Java (class)
        }
        if (strlen($content) >= 512 && str_contains(substr($content, -512), 'koly')) {
            return true; // imagem de disco do macOS (.dmg)
        }

        return str_starts_with($content, "PK\x03\x04") && self::isJavaOrAndroidPackage($content);
    }

    private static function isJavaOrAndroidPackage(string $content): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xpzip');
        file_put_contents($tmp, $content);
        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                return false;
            }
            $android = $zip->locateName('AndroidManifest.xml') !== false || $zip->locateName('classes.dex') !== false;
            $java = false;
            if ($zip->locateName('META-INF/MANIFEST.MF') !== false) {
                for ($i = 0; $i < $zip->numFiles && ! $java; $i++) {
                    $java = str_ends_with((string) $zip->getNameIndex($i), '.class');
                }
            }
            $zip->close();

            return $android || $java;
        } finally {
            @unlink($tmp);
        }
    }
}
