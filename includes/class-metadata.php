<?php
defined('ABSPATH') || exit;

/** Bounded metadata-only transformation. No decoder, encoder, DB or source writes. */
final class WP_Seed_Pixel_Metadata {
    const VERSION = 'metadata-filter-1';
    // Private.2 uses the certified single-image SQL-authority transaction path.
    const WRITE_CERTIFIED = true;
    const MAX_FILE = 33554432;
    const MAX_METADATA = 2097152;
    const MAX_BLOCKS = 4096;

    private static function refuse($code = 'METADATA_REVIEW') { throw new RuntimeException($code); }
    private static function slice($s, $offset, $length) {
        if ($offset < 0 || $length < 0 || $offset > strlen($s) || $length > strlen($s) - $offset) { self::refuse('METADATA_INVALID'); }
        return substr($s, $offset, $length);
    }
    private static function provenance($s) {
        if (preg_match('/c2pa|jumbf|content[ _-]?credentials|contentauthenticity|\bcaBX\b/i', $s)) { self::refuse('METADATA_PROVENANCE'); }
    }
    private static function category(&$categories, $name) { $categories[$name] = true; }

    public static function read($path) {
        clearstatcache(true, $path);
        $st = @lstat($path);
        if (!$st || is_link($path) || !is_file($path) || $st['nlink'] !== 1 || $st['size'] < 1 || $st['size'] > self::MAX_FILE) { return new WP_Error('METADATA_LIMIT'); }
        $memory = function_exists('wp_convert_hr_to_bytes') ? wp_convert_hr_to_bytes(ini_get('memory_limit')) : 0;
        if ($memory > 0 && memory_get_usage(true) + 4 * $st['size'] + 16777216 > $memory) { return new WP_Error('METADATA_LIMIT'); }
        $s = @file_get_contents($path);
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_string($s) || strlen($s) !== $st['size'] || !$after) { return new WP_Error('SOURCE_CHANGED'); }
        // Reading may update atime; it is not source identity or write evidence.
        foreach (array('dev','ino','mode','nlink','uid','gid','rdev','size','mtime','ctime') as $key) {
            if ($after[$key] !== $st[$key]) { return new WP_Error('SOURCE_CHANGED'); }
        }
        return self::transform($s);
    }

    public static function transform($s) {
        try {
            if (!is_string($s) || strlen($s) < 8 || strlen($s) > self::MAX_FILE) { self::refuse('METADATA_LIMIT'); }
            $categories = array();
            if (substr($s, 0, 2) === "\xff\xd8") { $result = self::jpeg($s, $categories); }
            elseif (substr($s, 0, 8) === "\x89PNG\r\n\x1a\n") { $result = self::png($s, $categories); }
            else { self::refuse('UNSUPPORTED_FORMAT'); }
            $result['categories'] = array_keys($categories); sort($result['categories']);
            $result['sha256'] = hash('sha256', $s);
            $result['filtered_sha256'] = hash('sha256', $result['data']);
            $result['removed_bytes'] = strlen($s) - strlen($result['data']);
            $result['bytes'] = strlen($result['data']);
            return $result;
        } catch (RuntimeException $e) { return new WP_Error($e->getMessage()); }
    }

    private static function jpeg($s, &$categories) {
        $p = 2; $out = "\xff\xd8"; $critical = hash_init('sha256'); $icc = array(); $color = hash_init('sha256');
        $sof = null; $scans = 0; $blocks = 0; $metadata = 0; $tables = array(); $exif_seen = false; $icc_count = null;
        while ($p < strlen($s)) {
            if (++$blocks > self::MAX_BLOCKS) { self::refuse('METADATA_LIMIT'); }
            $start = $p;
            if (self::slice($s, $p++, 1) !== "\xff") { self::refuse('METADATA_INVALID'); }
            while ($p < strlen($s) && $s[$p] === "\xff") { $p++; }
            $marker = ord(self::slice($s, $p++, 1));
            if ($marker === 0xd9) {
                if (!$sof || !$scans || $p !== strlen($s) || empty($tables[0xdb]) || empty($tables[0xc4])) { self::refuse('METADATA_INVALID'); }
                $raw = substr($s, $start, $p - $start); $out .= $raw; hash_update($critical, $raw);
                break;
            }
            $n = unpack('n', self::slice($s, $p, 2))[1];
            if ($n < 2) { self::refuse('METADATA_INVALID'); }
            $payload = self::slice($s, $p + 2, $n - 2); $p += $n;
            $raw = substr($s, $start, $p - $start); $keep = $raw;
            if ($marker >= 0xe0 && $marker <= 0xef || $marker === 0xfe) {
                $metadata += $n; if ($metadata > self::MAX_METADATA) { self::refuse('METADATA_LIMIT'); }
                self::provenance($payload);
                if ($marker === 0xe0 && substr($payload, 0, 5) === "JFIF\0") {
                    if (strlen($payload) < 14 || ord($payload[7]) > 2 || ord($payload[12]) || ord($payload[13]) || strlen($payload) !== 14) { self::refuse(); }
                    hash_update($color, $raw);
                } elseif ($marker === 0xee && substr($payload, 0, 5) === 'Adobe') {
                    if (strlen($payload) !== 12 || ord($payload[11]) > 1) { self::refuse(); }
                    hash_update($color, $raw);
                } elseif ($marker === 0xe2 && substr($payload, 0, 12) === "ICC_PROFILE\0") {
                    if (strlen($payload) < 15) { self::refuse('METADATA_INVALID'); }
                    $seq = ord($payload[12]); $count = ord($payload[13]);
                    if (!$count || $seq !== count($icc) + 1 || ($icc_count !== null && $count !== $icc_count)) { self::refuse('METADATA_INVALID'); }
                    $icc_count = $count; $icc[] = substr($payload, 14); hash_update($color, $raw);
                } elseif ($marker === 0xe1 && substr($payload, 0, 6) === "Exif\0\0") {
                    if ($exif_seen) { self::refuse('METADATA_INVALID'); } $exif_seen = true;
                    $tiff = self::exif(substr($payload, 6), $categories);
                    $keep = $tiff === '' ? '' : "\xff\xe1" . pack('n', strlen($tiff) + 8) . "Exif\0\0" . $tiff;
                    hash_update($color, $tiff);
                } elseif ($marker === 0xe1 && substr($payload, 0, 29) === "http://ns.adobe.com/xap/1.0/\0") {
                    self::xmp(substr($payload, 29), $categories); $keep = '';
                } elseif ($marker === 0xed && substr($payload, 0, 14) === "Photoshop 3.0\0") {
                    self::iptc(substr($payload, 14), $categories); $keep = '';
                } elseif ($marker === 0xfe) { self::category($categories, 'comments'); $keep = ''; }
                elseif ($marker === 0xeb) { self::refuse('METADATA_PROVENANCE'); }
                else { self::refuse(); }
            } else {
                if (!in_array($marker, array(0xc0, 0xc1, 0xc2, 0xc4, 0xdb, 0xdd, 0xda), true)) { self::refuse('METADATA_INVALID'); }
                $tables[$marker] = true;
                if ($marker === 0xdb || $marker === 0xc4) {
                    $table_offset=0;
                    while ($table_offset<strlen($payload)) {
                        $selector=ord(self::slice($payload,$table_offset++,1));
                        if (($selector & 15)>3 || ($selector>>4)>1) { self::refuse('METADATA_INVALID'); }
                        if ($marker===0xdb) { $length=64*(1+($selector>>4)); }
                        else {
                            $counts=self::slice($payload,$table_offset,16); $table_offset+=16;
                            $length=array_sum(unpack('C*',$counts));
                            if (!$length || $length>256) { self::refuse('METADATA_INVALID'); }
                        }
                        self::slice($payload,$table_offset,$length); $table_offset+=$length;
                    }
                    if (!$table_offset) { self::refuse('METADATA_INVALID'); }
                }
                if (in_array($marker, array(0xc0, 0xc1, 0xc2), true)) {
                    if ($sof || strlen($payload) < 9 || ord($payload[0]) !== 8) { self::refuse('METADATA_INVALID'); }
                    $components = ord($payload[5]);
                    if (!in_array($components, array(1, 3), true) || strlen($payload) !== 6 + $components * 3) { self::refuse(); }
                    $sof = array('height' => unpack('n', substr($payload, 1, 2))[1], 'width' => unpack('n', substr($payload, 3, 2))[1]);
                    self::dimensions($sof['width'], $sof['height']);
                }
                if ($marker === 0xdd && strlen($payload) !== 2) { self::refuse('METADATA_INVALID'); }
                hash_update($critical, $raw);
                if ($marker === 0xda) {
                    if (!$sof || !$payload || strlen($payload) !== 4 + 2 * ord($payload[0]) || !ord($payload[0]) || ord($payload[0]) > 3) { self::refuse('METADATA_INVALID'); }
                    $scans++; $entropy = $p;
                    while ($p < strlen($s)) {
                        if ($s[$p] !== "\xff") { $p++; continue; }
                        $q = $p + 1; while ($q < strlen($s) && $s[$q] === "\xff") { $q++; }
                        $next = ord(self::slice($s, $q, 1));
                        if ($next === 0 || $next >= 0xd0 && $next <= 0xd7) { $p = $q + 1; continue; }
                        break;
                    }
                    if ($p >= strlen($s)) { self::refuse('METADATA_INVALID'); }
                    $scan = substr($s, $entropy, $p - $entropy); hash_update($critical, $scan); $keep .= $scan;
                }
            }
            $out .= $keep;
        }
        if ($p !== strlen($s) || substr($s, -2) !== "\xff\xd9" || !$sof || !$scans) { self::refuse('METADATA_INVALID'); }
        if ($icc) { if (count($icc) !== $icc_count) { self::refuse('METADATA_INVALID'); } self::icc(implode('', $icc)); }
        return array_merge($sof, array('format' => 'jpeg', 'data' => $out, 'image_sha256' => hash_final($critical), 'color_sha256' => hash_final($color)));
    }

    private static function dimensions($w, $h) {
        if ($w < 1 || $h < 1 || $w > 16000 || $h > 16000 || $w * $h > 16000000) { self::refuse('METADATA_LIMIT'); }
    }
    private static function icc($s) {
        if (strlen($s) < 132 || strlen($s) > 262144 || unpack('N', substr($s, 0, 4))[1] !== strlen($s)
            || substr($s, 36, 4) !== 'acsp' || !in_array(substr($s, 16, 4), array('RGB ', 'GRAY'), true)) { self::refuse(); }
        $count = unpack('N', substr($s, 128, 4))[1];
        if ($count > 256 || 132 + 12 * $count > strlen($s)) { self::refuse(); }
        for ($i = 0; $i < $count; $i++) {
            $entry = unpack('Noffset/Nsize', substr($s, 136 + 12 * $i, 8)); self::slice($s, $entry['offset'], $entry['size']);
            if ($entry['offset'] < 132 + 12*$count || $entry['size']<8 || $entry['offset']%4) { self::refuse(); }
        }
    }
    private static function inflate($s) {
        if (!$s || strlen($s) > 262144 || !function_exists('gzuncompress')) { self::refuse(); }
        $out = @gzuncompress($s, min(262144, max(4096, strlen($s) * 100)));
        if (!is_string($out)) { self::refuse('METADATA_INVALID'); } return $out;
    }
    private static function png($s, &$categories) {
        $p = 8; $out = substr($s, 0, 8); $critical = hash_init('sha256'); $color = hash_init('sha256'); $idat = hash_init('sha256');
        $seen = array(); $color_values = array(); $blocks = 0; $metadata = 0; $header = null; $data_seen = false; $data_ended = false; $palette = 0;
        while ($p < strlen($s)) {
            if (++$blocks > self::MAX_BLOCKS) { self::refuse('METADATA_LIMIT'); }
            $n = unpack('N', self::slice($s, $p, 4))[1]; $type = self::slice($s, $p + 4, 4);
            if (!preg_match('/^[A-Za-z]{2}[A-Z][A-Za-z]$/D', $type)) { self::refuse('METADATA_INVALID'); }
            $data = self::slice($s, $p + 8, $n); $raw = self::slice($s, $p, $n + 12); $p += $n + 12;
            if (hash('crc32b', $type . $data) !== bin2hex(substr($raw, -4))) { self::refuse('METADATA_INVALID'); }
            if (!$header && $type !== 'IHDR') { self::refuse('METADATA_INVALID'); }
            if (!in_array($type,array('IDAT','tEXt','zTXt','iTXt'),true) && isset($seen[$type])) { self::refuse('METADATA_INVALID'); }
            $seen[$type] = true; $keep = $raw;
            if ($type !== 'IDAT' && $data_seen) { $data_ended = true; }
            if ($type === 'IHDR') {
                if ($n !== 13) { self::refuse('METADATA_INVALID'); }
                $header = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $data); self::dimensions($header['width'], $header['height']);
                $depths = array(0 => array(1,2,4,8,16), 2 => array(8,16), 3 => array(1,2,4,8), 4 => array(8,16), 6 => array(8,16));
                if (!isset($depths[$header['color']]) || !in_array($header['depth'], $depths[$header['color']], true) || $header['compression'] || $header['filter'] || $header['interlace'] > 1) { self::refuse('METADATA_INVALID'); }
            } elseif ($type === 'IDAT') {
                if ($data_ended || ($header['color'] === 3 && !$palette)) { self::refuse('METADATA_INVALID'); }
                $data_seen = true; hash_update($idat, $data);
            } elseif ($type === 'IEND') {
                if ($n || !$data_seen || $p !== strlen($s)) { self::refuse('METADATA_INVALID'); }
            } elseif ($type === 'PLTE') {
                if ($data_seen || !$n || $n % 3 || $n > 768 || in_array($header['color'], array(0,4), true)) { self::refuse('METADATA_INVALID'); } $palette = intdiv($n, 3);
            } elseif ($type === 'tRNS') {
                if ($data_seen || !in_array($header['color'], array(0,2,3), true) || ($header['color'] === 0 && $n !== 2) || ($header['color'] === 2 && $n !== 6) || ($header['color'] === 3 && (!$palette || !$n || $n > $palette))) { self::refuse('METADATA_INVALID'); }
            } elseif (in_array($type, array('iCCP','sRGB','gAMA','cHRM','pHYs'), true)) {
                if ($data_seen) { self::refuse('METADATA_INVALID'); }
                if ($type === 'iCCP') {
                    $z = strpos($data, "\0"); if ($z === false || $z < 1 || $z > 79 || self::slice($data, $z + 1, 1) !== "\0" || isset($seen['sRGB'])) { self::refuse(); }
                    self::icc(self::inflate(substr($data, $z + 2)));
                }
                if ($type === 'sRGB' && ($n !== 1 || ord($data[0]) > 3 || isset($seen['iCCP']))) { self::refuse(); }
                if ($type === 'gAMA' && ($n !== 4 || unpack('N', $data)[1] === 0)) { self::refuse(); }
                if ($type === 'cHRM' && $n !== 32 || $type === 'pHYs' && ($n !== 9 || ord($data[8]) > 1)) { self::refuse(); }
                if ($type==='cHRM') {
                    $xy=array_values(unpack('N8',$data));
                    for ($i=0;$i<8;$i+=2) { if (!$xy[$i+1] || $xy[$i]>100000 || $xy[$i+1]>100000 || $xy[$i]+$xy[$i+1]>100000) { self::refuse(); } }
                }
                $color_values[$type] = $data;
                hash_update($color, $raw);
            } else {
                $metadata += $n; if ($metadata > self::MAX_METADATA) { self::refuse('METADATA_LIMIT'); }
                if ($type === 'caBX') { self::refuse('METADATA_PROVENANCE'); }
                if ($type === 'eXIf') {
                    $tiff = self::exif($data, $categories); $keep = $tiff === '' ? '' : self::chunk('eXIf', $tiff); hash_update($color, $tiff);
                } elseif (in_array($type, array('tEXt','zTXt','iTXt'), true)) { self::text($type, $data, $categories); $keep = ''; }
                elseif ($type === 'tIME') {
                    if ($n !== 7) { self::refuse('METADATA_INVALID'); }
                    $time=unpack('nyear/Cmonth/Cday/Chour/Cminute/Csecond',$data);
                    if (!checkdate($time['month'],$time['day'],$time['year']) || $time['hour']>23 || $time['minute']>59 || $time['second']>60) { self::refuse('METADATA_INVALID'); }
                    self::category($categories, 'date'); $keep = '';
                } else { self::refuse(); }
            }
            if (in_array($type, array('IHDR','PLTE','tRNS','IEND'), true)) { hash_update($critical, $raw); }
            $out .= $keep;
        }
        if (!isset($seen['IEND']) || !$header || !$data_seen) { self::refuse('METADATA_INVALID'); }
        if (isset($seen['sRGB']) && ((isset($color_values['gAMA']) && unpack('N',$color_values['gAMA'])[1]!==45455)
            || (isset($color_values['cHRM']) && $color_values['cHRM']!==pack('N8',31270,32900,64000,33000,30000,60000,15000,6000)))) { self::refuse(); }
        hash_update($critical, hash_final($idat));
        return array('format' => 'png', 'data' => $out, 'width' => $header['width'], 'height' => $header['height'], 'image_sha256' => hash_final($critical), 'color_sha256' => hash_final($color));
    }
    private static function chunk($type, $s) { return pack('N', strlen($s)) . $type . $s . hex2bin(hash('crc32b', $type . $s)); }
    private static function text($type, $s, &$categories) {
        $z = strpos($s, "\0"); if ($z === false || $z < 1 || $z > 79) { self::refuse('METADATA_INVALID'); }
        $key = substr($s, 0, $z); $text = substr($s, $z + 1);
        if ($type === 'zTXt') { if (self::slice($text, 0, 1) !== "\0") { self::refuse('METADATA_INVALID'); } $text = self::inflate(substr($text, 1)); }
        if ($type === 'iTXt') {
            $compressed = ord(self::slice($text, 0, 1)); if ($compressed > 1 || self::slice($text, 1, 1) !== "\0") { self::refuse('METADATA_INVALID'); }
            $parts = explode("\0", substr($text, 2), 3); if (count($parts) !== 3 || strlen($parts[0]) > 79 || strlen($parts[1]) > 1024) { self::refuse('METADATA_INVALID'); }
            $text = $compressed ? self::inflate($parts[2]) : $parts[2]; if (!preg_match('//u', $text)) { self::refuse('METADATA_INVALID'); }
        }
        self::provenance($text);
        if ($key === 'XML:com.adobe.xmp') { self::xmp($text, $categories); return; }
        if (strpos($text, "\0") !== false) { self::refuse(); }
        $keys = array('author'=>'author','creator'=>'author','copyright'=>'author','title'=>'description','description'=>'description','comment'=>'comments','software'=>'software','creation time'=>'date','date:create'=>'date','date:modify'=>'date','location'=>'gps','gps'=>'gps','keywords'=>'description','disclaimer'=>'description','warning'=>'description');
        if (!isset($keys[strtolower($key)])) { self::refuse(); } self::category($categories, $keys[strtolower($key)]);
    }

    private static function exif($s, &$categories) {
        self::provenance($s);
        if (strlen($s) < 8 || strlen($s) > 262144 || !in_array(substr($s, 0, 2), array('II','MM'), true)) { self::refuse('METADATA_INVALID'); }
        $little = substr($s, 0, 2) === 'II';
        $u16 = static function ($p) use ($s,$little) { return unpack($little ? 'v' : 'n', self::slice($s,$p,2))[1]; };
        $u32 = static function ($p) use ($s,$little) { return unpack($little ? 'V' : 'N', self::slice($s,$p,4))[1]; };
        if ($u16(2) !== 42) { self::refuse('METADATA_INVALID'); }
        $queue = array(array($u32(4), 'main')); $visited = array(); $technical = array(); $entries = 0; $ranges=array(array(0,8));
        $range=static function($start,$length) use (&$ranges) {
            foreach ($ranges as $existing) { if ($start<$existing[0]+$existing[1] && $existing[0]<$start+$length) { self::refuse('METADATA_INVALID'); } }
            $ranges[]=array($start,$length);
        };
        $types = array(1=>1,2=>1,3=>2,4=>4,5=>8,7=>1,9=>4,10=>8);
        $privacy = array(0x010e=>'description',0x010f=>'device',0x0110=>'device',0x0131=>'software',0x0132=>'date',0x013b=>'author',0x8298=>'author',0x9003=>'date',0x9004=>'date',0x9010=>'date',0x9011=>'date',0x9012=>'date',0x927c=>'device',0x9286=>'comments',0x9290=>'date',0x9291=>'date',0x9292=>'date',0xa420=>'device',0xa430=>'author',0xa431=>'device',0xa432=>'device',0xa433=>'device',0xa434=>'device',0xa435=>'device');
        while ($queue) {
            list($offset,$kind) = array_shift($queue);
            if ($offset < 8 || isset($visited[$offset]) || count($visited) >= 16) { self::refuse('METADATA_INVALID'); } $visited[$offset] = true;
            $count = $u16($offset); if ($count > 256 || ($entries += $count) > 1024) { self::refuse('METADATA_LIMIT'); }
            self::slice($s, $offset + 2, $count * 12 + 4); $tags = array();
            $range($offset,2+$count*12+4);
            for ($i = 0; $i < $count; $i++) {
                $p = $offset + 2 + 12 * $i; $tag = $u16($p); $type = $u16($p+2); $num = $u32($p+4);
                if (isset($tags[$tag]) || !isset($types[$type]) || !$num || $num > 65536) { self::refuse('METADATA_INVALID'); } $tags[$tag] = true;
                $length = $num * $types[$type]; $value_offset = $length <= 4 ? $p+8 : $u32($p+8); $value = self::slice($s, $value_offset, $length);
                if ($length > 4 && $value_offset < 8) { self::refuse('METADATA_INVALID'); }
                if ($length>4) { $range($value_offset,$length); }
                if ($kind === 'gps') {
                    if ($tag > 31) { self::refuse(); } self::category($categories,'gps'); continue;
                }
                if ($tag === 0x8769 || $tag === 0x8825) {
                    if ($type !== 4 || $num !== 1) { self::refuse('METADATA_INVALID'); } $queue[] = array($u32($value_offset), $tag === 0x8825 ? 'gps' : 'exif'); continue;
                }
                if ($tag === 0x0112) {
                    if ($type !== 3 || $num !== 1 || $u16($value_offset) < 1 || $u16($value_offset) > 8) { self::refuse('METADATA_INVALID'); }
                    if ($u16($value_offset) !== 1) { self::refuse('METADATA_ORIENTATION'); } continue;
                }
                if (isset($privacy[$tag])) { self::category($categories,$privacy[$tag]); continue; }
                if (in_array($tag, array(0x011a,0x011b,0x0128,0xa001), true)) {
                    if ($tag === 0xa001 && ($type !== 3 || $num !== 1 || $u16($value_offset) !== 1)) { self::refuse(); }
                    if ($tag === 0x0128 && ($type !== 3 || $num !== 1 || !in_array($u16($value_offset),array(1,2,3),true))) { self::refuse(); }
                    if (in_array($tag,array(0x011a,0x011b),true) && ($type !== 5 || $num !== 1 || !$u32($value_offset+4))) { self::refuse(); }
                    // Canonical little-endian scalar/rational encoding, without private payloads.
                    $canonical = $type === 3 ? pack('v',$u16($value_offset)) : pack('V2',$u32($value_offset),$u32($value_offset+4));
                    $technical[$tag] = array($type,$num,$canonical); continue;
                }
                if (in_array($tag,array(0x9000,0xa000),true)) {
                    if ($type!==7 || $num!==4 || !in_array($value,array('0100','0200','0210','0220','0230','0231','0232','0300'),true)) { self::refuse(); } continue;
                }
                if (in_array($tag,array(0xa002,0xa003),true)) { if ($num!==1 || !in_array($type,array(3,4),true)) { self::refuse(); } continue; }
                // Thumbnail offsets and opaque/interoperability dependencies need a separate certified classifier.
                self::refuse();
            }
            if ($u32($offset+2+$count*12)) { self::refuse(); }
        }
        if (!$technical) { return ''; }
        // Preserve known rendering declarations in one minimal TIFF. ColorSpace belongs in ExifIFD.
        $main = $technical; $sub = array();
        if (isset($main[0xa001])) { $sub[0xa001] = $main[0xa001]; unset($main[0xa001]); }
        $main_count = count($main) + ($sub ? 1 : 0); $sub_offset = 8 + 2 + 12*$main_count + 4;
        if ($sub) { $main[0x8769] = array(4,1,pack('V',$sub_offset)); }
        $extra_offset = $sub_offset + ($sub ? 2+12*count($sub)+4 : 0); $extra = '';
        $build = static function ($set) use (&$extra,$extra_offset) {
            ksort($set); $out = pack('v',count($set));
            foreach ($set as $tag=>$entry) { list($type,$num,$value) = $entry;
                $slot = strlen($value) <= 4 ? str_pad($value,4,"\0") : pack('V',$extra_offset+strlen($extra));
                if (strlen($value)>4) { $extra .= $value; } $out .= pack('vvV',$tag,$type,$num).$slot;
            } return $out.pack('V',0);
        };
        $body = $build($main); if ($sub) { $body .= $build($sub); }
        return "II\x2a\0".pack('V',8).$body.$extra;
    }

    private static function iptc($s, &$categories) {
        $p = 0; $blocks = 0;
        while ($p < strlen($s)) {
            if (++$blocks > 256 || self::slice($s,$p,4) !== '8BIM') { self::refuse(); }
            $id = unpack('n',self::slice($s,$p+4,2))[1]; $p += 6; $n = ord(self::slice($s,$p,1)); $p += 1+$n; if ((1+$n)%2) { $p++; }
            $length = unpack('N',self::slice($s,$p,4))[1]; $p += 4; $data = self::slice($s,$p,$length); $p += $length+($length%2);
            if ($id !== 0x0404) { self::refuse(); }
            $q=0;
            while ($q < strlen($data)) {
                if (self::slice($data,$q,1) !== "\x1c" || ord(self::slice($data,$q+1,1)) !== 2) { self::refuse(); }
                $dataset=ord(self::slice($data,$q+2,1)); $len=unpack('n',self::slice($data,$q+3,2))[1];
                if ($len & 0x8000 || !in_array($dataset,array(5,7,10,15,20,25,40,55,60,62,63,65,70,75,80,85,90,92,95,100,101,103,105,110,115,116,120,122),true)) { self::refuse(); }
                self::provenance(self::slice($data,$q+5,$len)); $q += 5+$len; self::category($categories,'iptc');
            }
        }
        if ($p !== strlen($s)) { self::refuse('METADATA_INVALID'); }
    }

    private static function xmp($s, &$categories) {
        self::provenance($s);
        if (strlen($s)>262144 || strpos($s,"\0")!==false || preg_match('/<!DOCTYPE|<!ENTITY/i',$s) || !class_exists('DOMDocument')) { self::refuse(); }
        $allowed=array('adobe:ns:meta/'=>array('xmpmeta'), 'http://www.w3.org/1999/02/22-rdf-syntax-ns#'=>array('RDF','Description','Bag','Seq','Alt','li','about'),
            'http://purl.org/dc/elements/1.1/'=>array('creator','description','title','subject','rights'),
            'http://ns.adobe.com/xap/1.0/'=>array('CreatorTool','CreateDate','ModifyDate','MetadataDate','Label','Rating'),
            'http://ns.adobe.com/photoshop/1.0/'=>array('AuthorsPosition','CaptionWriter','City','Country','Credit','DateCreated','Headline','Instructions','Source','State','TransmissionReference','Urgency'),
            'http://ns.adobe.com/tiff/1.0/'=>array('Make','Model','Software','DateTime','Artist','ImageDescription','Copyright'),
            'http://www.w3.org/XML/1998/namespace'=>array('lang'));
        $old=libxml_use_internal_errors(true); $doc=new DOMDocument(); $doc->resolveExternals=false; $doc->substituteEntities=false;
        try { if (!$doc->loadXML($s,LIBXML_NONET) || !$doc->documentElement) { self::refuse('METADATA_INVALID'); }
            self::provenance($doc->textContent);
            $xpath=new DOMXPath($doc);
            foreach ($xpath->query('//namespace::*') as $namespace) { if (!isset($allowed[$namespace->nodeValue])) { self::refuse(); } }
            foreach ($xpath->query('//processing-instruction()') as $instruction) { if ($instruction->nodeName!=='xpacket') { self::refuse(); } }
            $nodes=0;
            foreach ($doc->getElementsByTagName('*') as $node) {
                if (++$nodes>1024 || !isset($allowed[$node->namespaceURI]) || !in_array($node->localName,$allowed[$node->namespaceURI],true)) { self::refuse(); }
                foreach ($node->attributes as $attr) {
                    if ($attr->namespaceURI==='http://www.w3.org/2000/xmlns/') { if (!isset($allowed[$attr->value])) { self::refuse(); } continue; }
                    if ($node->namespaceURI==='adobe:ns:meta/' && $attr->name==='x:xmptk') { continue; }
                    if (!isset($allowed[$attr->namespaceURI]) || !in_array($attr->localName,$allowed[$attr->namespaceURI],true) || ($attr->localName==='about' && $attr->value!=='')) { self::refuse(); }
                }
            }
        } finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
        self::category($categories,'xmp');
    }

    /** All other public files must already be clean; otherwise admission blocks before any swap. */
    public static function graph(array $before) {
        if (count($before['files']) > 256) { return new WP_Error('METADATA_LIMIT'); }
        $master = null; $witness = array();
        $total = 0;
        foreach ($before['files'] as $relative=>$file) {
            if (!isset($file['sha256']) || !is_string($file['sha256'])) { return new WP_Error('INVENTORY_INCOMPLETE'); }
            $path=WP_Seed_Pixel_Files::path(wp_upload_dir(null,false)['basedir'].'/'.$relative); if (is_wp_error($path)) { return $path; }
            $total += filesize($path);
            if ($total > 134217728) { return new WP_Error('METADATA_LIMIT'); }
            $result=self::read($path); if (is_wp_error($result)) { return $result; }
            if ($result['sha256']!==$file['sha256']) { return new WP_Error('SOURCE_CHANGED'); }
            if ($relative===$before['relative']) { $master=$result; }
            elseif ($result['categories'] || $result['removed_bytes']) { return new WP_Error('METADATA_PUBLIC_COPY'); }
            $witness[$relative]=$result['sha256'];
        }
        if (!$master) { return new WP_Error('INVENTORY_INCOMPLETE'); }
        ksort($witness); unset($master['data']);
        return array('master'=>$master,'files'=>$witness,'signature'=>hash('sha256',wp_json_encode(array($before['attachment_id'],$witness))));
    }

    public static function create($source,$target) {
        $before=self::read($source); if (is_wp_error($before)) { return $before; }
        if (!$before['categories']) { return new WP_Error('METADATA_ALREADY_CLEAN'); }
        $after=self::transform($before['data']);
        if (is_wp_error($after) || $after['categories'] || $after['removed_bytes'] || $after['image_sha256']!==$before['image_sha256'] || $after['color_sha256']!==$before['color_sha256']) { return new WP_Error('VERIFY_FAILED'); }
        if (file_exists($target) || is_link($target) || is_link(dirname($target)) || !WP_Seed_Pixel_Authority::valid_all()) { return new WP_Error('CLAIM_CONFLICT'); }
        if (!function_exists('imagecreatefromstring')) { return new WP_Error('BACKEND_UNAVAILABLE'); }
        $memory=function_exists('wp_convert_hr_to_bytes')?wp_convert_hr_to_bytes(ini_get('memory_limit')):0;
        if ($memory > 0 && memory_get_usage(true) + 8*$before['width']*$before['height'] + 16777216 > $memory) { return new WP_Error('METADATA_LIMIT'); }
        $decoded=@imagecreatefromstring($before['data']);
        if (!$decoded || imagesx($decoded)!==$before['width'] || imagesy($decoded)!==$before['height']) { return new WP_Error('METADATA_INVALID'); }
        unset($decoded);
        $f=@fopen($target,'xb'); if (!$f) { return new WP_Error('CANDIDATE_INVALID'); }
        try { $ok=fwrite($f,$before['data'])===$before['bytes'] && fflush($f) && fsync($f); } finally { fclose($f); }
        if (!$ok || hash_file('sha256',$target)!==$before['filtered_sha256']) { return new WP_Error('VERIFY_FAILED'); }
        return array('bytes'=>$before['bytes'],'sha256'=>$before['filtered_sha256'],'width'=>$before['width'],'height'=>$before['height'],
            'processor'=>self::VERSION,'encoded'=>0,'categories'=>$before['categories'],'image_sha256'=>$before['image_sha256'],'color_sha256'=>$before['color_sha256']);
    }
}
