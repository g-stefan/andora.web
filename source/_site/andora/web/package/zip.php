<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Package;

defined("ANDORA") or die("Forbidden");

// Zip files of the packages: read (list + extract one entry) and write.
// Uses ZipArchive (ext-zip) when it is loaded, else its own reader / writer
// on zlib: stored and deflate entries, no zip64, no encryption, no multi disk.
//
// Zip::open() returns a Zip or null; entries() lists
// [name, size, compressedSize, isDir, isLink, isEncrypted, method]; read($index)
// returns the content (null if it can not be read, or its size or CRC do not
// match the central directory).
class Zip
{
    public const MAX_ENTRY_SIZE = 67108864; // 64 MB

    // false: never use ZipArchive (the tests use the own reader / writer)
    public static $useZipArchive = true;

    protected $file = null;
    protected $handle = null;
    protected $archive = null;
    protected $entries = array();

    public static function isSupported()
    {
        return self::hasZipArchive() || function_exists("gzinflate");
    }

    protected static function hasZipArchive()
    {
        return self::$useZipArchive && class_exists("ZipArchive");
    }

    public static function open($file, &$reason)
    {
        $zip = new Zip();
        if (!$zip->load($file, $reason)) {
            $zip->close();
            return null;
        }
        return $zip;
    }

    public function entries()
    {
        return $this->entries;
    }

    public function close()
    {
        if (!is_null($this->handle)) {
            fclose($this->handle);
            $this->handle = null;
        }
        if (!is_null($this->archive)) {
            $this->archive->close();
            $this->archive = null;
        }
    }

    protected function load($file, &$reason)
    {
        $this->file = $file;
        if (!is_file($file)) {
            $reason = "invalid-zip";
            return false;
        }
        if (self::hasZipArchive()) {
            return $this->loadZipArchive($reason);
        }
        if (!function_exists("gzinflate")) {
            $reason = "zip-not-supported";
            return false;
        }
        return $this->loadOwn($reason);
    }

    protected function loadZipArchive(&$reason)
    {
        $archive = new \ZipArchive();
        if ($archive->open($this->file, \ZipArchive::RDONLY) !== true) {
            $reason = "invalid-zip";
            return false;
        }
        $this->archive = $archive;
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $stat = $archive->statIndex($index);
            if ($stat === false) {
                $reason = "invalid-zip";
                return false;
            }
            $opsys = 0;
            $attributes = 0;
            $archive->getExternalAttributesIndex($index, $opsys, $attributes);
            $this->entries[] = array(
                "name" => $stat["name"],
                "size" => intval($stat["size"]),
                "compressedSize" => intval($stat["comp_size"]),
                "isDir" => substr($stat["name"], -1) === "/",
                "isLink" => ($opsys == \ZipArchive::OPSYS_UNIX) && ((($attributes >> 16) & 0170000) == 0120000),
                "isEncrypted" => intval($stat["encryption_method"] ?? 0) != 0,
                "method" => intval($stat["comp_method"]),
                "crc" => intval($stat["crc"])
            );
        }
        return true;
    }

    protected function loadOwn(&$reason)
    {
        $reason = "invalid-zip";
        $this->handle = fopen($this->file, "rb");
        if ($this->handle === false) {
            $this->handle = null;
            return false;
        }
        $fileSize = filesize($this->file);
        if ($fileSize < 22) {
            return false;
        }

        // End of central directory: 22 bytes + a comment of up to 65535 bytes
        $tailSize = min($fileSize, 22 + 65535);
        fseek($this->handle, $fileSize - $tailSize);
        $tail = fread($this->handle, $tailSize);
        $position = strrpos($tail, "PK\x05\x06");
        if (($position === false) || (strlen($tail) - $position < 22)) {
            return false;
        }
        $end = unpack("vdisk/vdiskStart/vdiskEntries/ventries/VcdSize/VcdOffset/vcommentLength", substr($tail, $position + 4, 18));
        if (($end["disk"] != 0) || ($end["diskStart"] != 0) || ($end["diskEntries"] != $end["entries"])) {
            $reason = "zip-not-supported";
            return false;
        }
        if (($end["entries"] == 0xFFFF) || ($end["cdOffset"] == 0xFFFFFFFF) || ($end["cdSize"] == 0xFFFFFFFF)) {
            $reason = "zip-not-supported"; // zip64
            return false;
        }
        if ($end["cdOffset"] + $end["cdSize"] > $fileSize) {
            return false;
        }

        fseek($this->handle, $end["cdOffset"]);
        $directory = $end["cdSize"] > 0 ? fread($this->handle, $end["cdSize"]) : "";
        if (strlen($directory) != $end["cdSize"]) {
            return false;
        }
        $offset = 0;
        for ($k = 0; $k < $end["entries"]; $k++) {
            if ((strlen($directory) - $offset < 46) || (substr($directory, $offset, 4) !== "PK\x01\x02")) {
                return false;
            }
            $header = unpack("vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/VcompressedSize/Vsize/vnameLength/vextraLength/vcommentLength/vdiskStart/vinternal/Vexternal/VlocalOffset", substr($directory, $offset + 4, 42));
            $name = substr($directory, $offset + 46, $header["nameLength"]);
            if (strlen($name) != $header["nameLength"]) {
                return false;
            }
            $offset += 46 + $header["nameLength"] + $header["extraLength"] + $header["commentLength"];
            if ($offset > strlen($directory)) {
                return false;
            }
            $this->entries[] = array(
                "name" => $name,
                "size" => $header["size"],
                "compressedSize" => $header["compressedSize"],
                "isDir" => substr($name, -1) === "/",
                "isLink" => (($header["madeBy"] >> 8) == 3) && ((($header["external"] >> 16) & 0170000) == 0120000),
                "isEncrypted" => ($header["flags"] & 1) != 0,
                "method" => $header["method"],
                "crc" => $header["crc"],
                "localOffset" => $header["localOffset"]
            );
        }
        $reason = "";
        return true;
    }

    // The content of an entry, checked against its size and CRC
    public function read($index)
    {
        if (!array_key_exists($index, $this->entries)) {
            return null;
        }
        $entry = $this->entries[$index];
        if ($entry["isDir"] || $entry["isLink"] || $entry["isEncrypted"] || ($entry["size"] > self::MAX_ENTRY_SIZE)) {
            return null;
        }
        if (!is_null($this->archive)) {
            $data = $this->archive->getFromIndex($index);
        } else {
            $data = $this->readOwn($entry);
        }
        if (!is_string($data) || (strlen($data) != $entry["size"])) {
            return null;
        }
        if ((crc32($data) & 0xFFFFFFFF) != ($entry["crc"] & 0xFFFFFFFF)) {
            return null;
        }
        return $data;
    }

    protected function readOwn($entry)
    {
        if (($entry["method"] != 0) && ($entry["method"] != 8)) {
            return null;
        }
        if ($entry["compressedSize"] > self::MAX_ENTRY_SIZE) {
            return null;
        }
        fseek($this->handle, $entry["localOffset"]);
        $local = fread($this->handle, 30);
        if ((strlen($local) != 30) || (substr($local, 0, 4) !== "PK\x03\x04")) {
            return null;
        }
        $header = unpack("vnameLength/vextraLength", substr($local, 26, 4));
        fseek($this->handle, $entry["localOffset"] + 30 + $header["nameLength"] + $header["extraLength"]);
        $data = ($entry["compressedSize"] > 0) ? fread($this->handle, $entry["compressedSize"]) : "";
        if (strlen($data) != $entry["compressedSize"]) {
            return null;
        }
        if ($entry["method"] == 0) {
            return $data;
        }
        if ($entry["size"] == 0) {
            return "";
        }
        // Never more than the size the central directory gives (zip bomb)
        $data = @gzinflate($data, $entry["size"]);
        return ($data === false) ? null : $data;
    }

    // Write $files (name in the zip => file on disk) to $file
    public static function write($file, $files, &$reason)
    {
        if (self::hasZipArchive()) {
            $archive = new \ZipArchive();
            if ($archive->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                $reason = "unable-to-write-zip";
                return false;
            }
            foreach ($files as $name => $path) {
                if (!$archive->addFile($path, $name)) {
                    $archive->close();
                    $reason = "unable-to-write-zip";
                    return false;
                }
            }
            if (!$archive->close()) {
                $reason = "unable-to-write-zip";
                return false;
            }
            return true;
        }
        if (!function_exists("gzdeflate")) {
            $reason = "zip-not-supported";
            return false;
        }
        return self::writeOwn($file, $files, $reason);
    }

    protected static function writeOwn($file, $files, &$reason)
    {
        $reason = "unable-to-write-zip";
        if (count($files) >= 0xFFFF) {
            $reason = "zip-not-supported";
            return false;
        }
        $handle = fopen($file, "wb");
        if ($handle === false) {
            return false;
        }
        $directory = "";
        $offset = 0;
        $time = getdate();
        $dosTime = ($time["hours"] << 11) | ($time["minutes"] << 5) | intval($time["seconds"] / 2);
        $dosDate = ((max(1980, $time["year"]) - 1980) << 9) | ($time["mon"] << 5) | $time["mday"];
        foreach ($files as $name => $path) {
            $data = file_get_contents($path);
            if ($data === false) {
                fclose($handle);
                return false;
            }
            $crc = crc32($data) & 0xFFFFFFFF;
            $size = strlen($data);
            $method = 0;
            $compressed = $data;
            if ($size > 0) {
                $deflated = gzdeflate($data, 6);
                if (($deflated !== false) && (strlen($deflated) < $size)) {
                    $method = 8;
                    $compressed = $deflated;
                }
            }
            $compressedSize = strlen($compressed);
            if (($offset + 30 + strlen($name) + $compressedSize) > 0xFFFFFFFF) {
                fclose($handle);
                $reason = "zip-not-supported";
                return false;
            }
            // utf-8 names (flag 0x0800)
            $local = "PK\x03\x04" . pack("vvvvvVVVvv", 20, 0x0800, $method, $dosTime, $dosDate, $crc, $compressedSize, $size, strlen($name), 0) . $name;
            if ((fwrite($handle, $local) === false) || (fwrite($handle, $compressed) === false)) {
                fclose($handle);
                return false;
            }
            // made by unix (3), regular file 0644
            $directory .= "PK\x01\x02" . pack("vvvvvvVVVvvvvvVV", (3 << 8) | 20, 20, 0x0800, $method, $dosTime, $dosDate, $crc, $compressedSize, $size, strlen($name), 0, 0, 0, 0, (0100644 << 16), $offset) . $name;
            $offset += strlen($local) + $compressedSize;
        }
        $end = "PK\x05\x06" . pack("vvvvVVv", 0, 0, count($files), count($files), strlen($directory), $offset, 0);
        if ((fwrite($handle, $directory) === false) || (fwrite($handle, $end) === false)) {
            fclose($handle);
            return false;
        }
        fclose($handle);
        $reason = "";
        return true;
    }
}
