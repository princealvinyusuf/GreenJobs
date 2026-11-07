<?php
declare(strict_types=1);

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store');

function text(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

function category(array $row): string
{
    $values = [
        text($row['kategori green'] ?? ''),
        text($row['Kategori GTI Narrow'] ?? ''),
        text($row['Kategori GTI broad'] ?? ''),
    ];
    $label = strtolower(implode(' ', $values));

    if (preg_match('/potens|potential|broad/', $label)) {
        return 'p';
    }
    if (preg_match('/non[\s_-]*(green|hijau)|tidak[\s_-]*(green|hijau)/', $label)) {
        return 'n';
    }
    if (preg_match('/green|hijau|narrow|murni/', $label)) {
        return 'g';
    }

    $narrow = strtolower(text($row['GTI narrow'] ?? ''));
    $broad = strtolower(text($row['GTI broad'] ?? ''));
    $isYes = static fn(string $v): bool => in_array($v, ['1', 'ya', 'yes', 'true', 'y'], true);
    if ($isYes($narrow)) {
        return 'g';
    }
    if ($isYes($broad)) {
        return 'p';
    }
    return 'n';
}

function top(array $values, int $limit): array
{
    arsort($values, SORT_NUMERIC);
    $result = [];
    foreach (array_slice($values, 0, $limit, true) as $name => $value) {
        $result[] = [$name, $value];
    }
    return $result;
}

function add(array &$values, string $name, float|int $amount): void
{
    $name = trim($name);
    if ($name !== '') {
        $values[$name] = ($values[$name] ?? 0) + $amount;
    }
}

function skillList(string $value): array
{
    $value = trim($value, " \t\n\r\0\x0B[]\"'");
    if ($value === '') {
        return [];
    }
    $parts = preg_split('/\s*(?:[,;|\n\r]+|\.\s+(?=[A-Z]))\s*/u', $value) ?: [];
    $skills = [];
    foreach ($parts as $part) {
        $part = trim($part, " \t\n\r\0\x0B-•.\"'");
        if ($part !== '' && mb_strlen($part) <= 100) {
            $skills[mb_strtolower($part)] = $part;
        }
    }
    return array_values($skills);
}

function island(string $province): string
{
    $p = strtolower($province);
    return match (true) {
        str_contains($p, 'sumatera'), str_contains($p, 'aceh'), str_contains($p, 'riau'),
        str_contains($p, 'jambi'), str_contains($p, 'bengkulu'), str_contains($p, 'lampung'),
        str_contains($p, 'bangka') => 'Sumatera',
        str_contains($p, 'jawa'), str_contains($p, 'jakarta'), str_contains($p, 'banten'),
        str_contains($p, 'yogyakarta') => 'Jawa',
        str_contains($p, 'kalimantan') => 'Kalimantan',
        str_contains($p, 'sulawesi'), str_contains($p, 'gorontalo') => 'Sulawesi',
        str_contains($p, 'nusa tenggara'), str_contains($p, 'bali') => 'Nusa Tenggara',
        str_contains($p, 'maluku') => 'Maluku',
        str_contains($p, 'papua') => 'Papua',
        default => 'Lainnya',
    };
}

try {
    $db = require __DIR__ . '/config.php';
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['name'], $db['charset']);
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $columns = [
        'quota', 'total_pelamar', 'judul_pekerjaan', 'Uraian KBJI 4', 'kbji',
        'kbli 1 clean', 'industri', 'Provinsi', 'Kabkot', 'keahlian',
        'jenis_kelamin', 'kondisi_fisik', 'kategori green',
        'GTI narrow', 'Kategori GTI Narrow', 'GTI broad', 'Kategori GTI broad',
    ];
    $quoted = implode(', ', array_map(static fn(string $c): string => "`$c`", $columns));
    $rows = $pdo->query("SELECT $quoted FROM `green_job`")->fetchAll();

    $T = ['pos' => 0, 'kuota' => 0, 'lam' => 0];
    $metrics = [
        'g' => ['pos' => 0, 'kuota' => 0, 'lam' => 0],
        'p' => ['pos' => 0, 'kuota' => 0, 'lam' => 0],
        'n' => ['pos' => 0, 'kuota' => 0, 'lam' => 0],
    ];
    $jobs = ['g' => [], 'p' => []];
    $sectors = ['g' => [], 'p' => []];
    $provinces = [];
    $cities = [];
    $skillCounts = ['g' => [], 'p' => []];
    $gender = ['g' => [], 'p' => []];
    $physical = ['g' => [], 'p' => []];
    $jobStats = ['g' => [], 'p' => []];
    $rowSkills = [];

    foreach ($rows as $index => $row) {
        $cat = category($row);
        $quota = max(0, (int) ($row['quota'] ?? 0));
        $applicants = max(0, (int) ($row['total_pelamar'] ?? 0));
        $T['pos']++;
        $T['kuota'] += $quota;
        $T['lam'] += $applicants;
        $metrics[$cat]['pos']++;
        $metrics[$cat]['kuota'] += $quota;
        $metrics[$cat]['lam'] += $applicants;

        if ($cat === 'n') {
            continue;
        }

        $job = text($row['Uraian KBJI 4']) ?: (text($row['kbji']) ?: text($row['judul_pekerjaan']));
        $sector = text($row['kbli 1 clean']) ?: text($row['industri']);
        $province = text($row['Provinsi']) ?: 'Tidak diketahui';
        $city = text($row['Kabkot']) ?: 'Tidak diketahui';
        add($jobs[$cat], $job, $quota);
        add($sectors[$cat], $sector, $quota);

        $provinces[$province] ??= ['g' => 0, 'p' => 0];
        $provinces[$province][$cat] += $quota;
        $cities[$province][$city] ??= ['quota' => 0, 'applicants' => 0];
        $cities[$province][$city]['quota'] += $quota;
        $cities[$province][$city]['applicants'] += $applicants;

        add($gender[$cat], text($row['jenis_kelamin']) ?: 'Tidak diketahui', $quota);
        add($physical[$cat], text($row['kondisi_fisik']) ?: 'Tidak diketahui', $quota);
        $jobStats[$cat][$job] ??= ['quota' => 0, 'applicants' => 0];
        $jobStats[$cat][$job]['quota'] += $quota;
        $jobStats[$cat][$job]['applicants'] += $applicants;

        $skills = skillList(text($row['keahlian']));
        $rowSkills[$index] = [$cat, $job, $quota, $skills];
        foreach ($skills as $skill) {
            add($skillCounts[$cat], $skill, 1);
        }
    }

    $combine = static function (array $a, array $b): array {
        foreach ($b as $key => $value) {
            $a[$key] = ($a[$key] ?? 0) + $value;
        }
        return $a;
    };
    $jobs['c'] = $combine($jobs['g'], $jobs['p']);
    $sectors['c'] = $combine($sectors['g'], $sectors['p']);
    $skillCounts['c'] = $combine($skillCounts['g'], $skillCounts['p']);

    $PV = [];
    foreach ($provinces as $name => $value) {
        $PV[] = [$name, island($name), $value['g'] + $value['p'], $value['g']];
    }
    usort($PV, static fn(array $a, array $b): int => $b[2] <=> $a[2]);

    $KB = [];
    foreach ($cities as $province => $provinceCities) {
        uasort($provinceCities, static fn(array $a, array $b): int => $b['quota'] <=> $a['quota']);
        foreach (array_slice($provinceCities, 0, 5, true) as $name => $value) {
            if ($value['quota'] > 0) {
                $KB[$province][] = [
                    $name,
                    $value['quota'],
                    number_format($value['applicants'] / $value['quota'], 1, ',', '.'),
                ];
            }
        }
    }

    $distribution = static function (array $values, array $patterns, int $total): array {
        $result = array_fill(0, count($patterns), 0);
        foreach ($values as $label => $quota) {
            $matched = false;
            foreach ($patterns as $i => $pattern) {
                if (preg_match($pattern, strtolower($label))) {
                    $result[$i] += $quota;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $result[0] += $quota;
            }
        }
        return array_map(static fn(int $v): float => $total > 0 ? round($v / $total * 100, 1) : 0, $result);
    };

    $GEN = $DIS = [];
    foreach (['g', 'p'] as $cat) {
        $GEN[$cat] = $distribution(
            $gender[$cat],
            ['/(semua|bebas|laki.*perempuan|pria.*wanita)/u', '/(laki|pria|male)/u', '/(perempuan|wanita|female)/u'],
            $metrics[$cat]['kuota']
        );
        $DIS[$cat] = $distribution(
            $physical[$cat],
            ['/(non.?disabil|normal|sehat)/u', '/(semua|bebas|disabil.*non)/u', '/(disabil|difabel)/u'],
            $metrics[$cat]['kuota']
        );
    }
    $combinedPercent = static function (array $a, int $ta, array $b, int $tb): array {
        $total = $ta + $tb;
        return array_map(
            static fn(int $i): float => $total > 0 ? round(($a[$i] * $ta + $b[$i] * $tb) / $total, 1) : 0,
            array_keys($a)
        );
    };
    $GEN['c'] = $combinedPercent($GEN['g'], $metrics['g']['kuota'], $GEN['p'], $metrics['p']['kuota']);
    $DIS['c'] = $combinedPercent($DIS['g'], $metrics['g']['kuota'], $DIS['p'], $metrics['p']['kuota']);

    $PJ = [];
    foreach (['g', 'p'] as $cat) {
        foreach ($jobStats[$cat] as $name => $value) {
            if ($value['quota'] >= 100) {
                $PJ[] = [
                    $name,
                    mb_strlen($name) > 28 ? mb_substr($name, 0, 26) . '…' : $name,
                    $cat,
                    $value['quota'],
                    $value['applicants'] / $value['quota'],
                ];
            }
        }
    }
    usort($PJ, static fn(array $a, array $b): int => $b[3] <=> $a[3]);
    $PJ = array_slice($PJ, 0, 30);

    $combinedSkills = $combine($skillCounts['g'], $skillCounts['p']);
    $SK = array_column(top($combinedSkills, 8), 0);
    $topJobs = array_column(top($combine($jobs['g'], $jobs['p']), 10), 0);
    $matrix = [];
    foreach ($topJobs as $job) {
        $matrix[$job] = array_fill(0, count($SK), 0);
    }
    foreach ($rowSkills as [$cat, $job, $quota, $skills]) {
        if (!isset($matrix[$job])) {
            continue;
        }
        $lowerSkills = array_map('mb_strtolower', $skills);
        foreach ($SK as $skillIndex => $skill) {
            if (in_array(mb_strtolower($skill), $lowerSkills, true)) {
                $matrix[$job][$skillIndex] += $quota;
            }
        }
    }
    $HM = [];
    foreach ($topJobs as $job) {
        $jobCat = isset($jobs['g'][$job]) && (!isset($jobs['p'][$job]) || $jobs['g'][$job] >= $jobs['p'][$job]) ? 'g' : 'p';
        $HM[] = [$job, $jobCat, ($jobs['g'][$job] ?? 0) + ($jobs['p'][$job] ?? 0), $matrix[$job]];
    }

    $data = [
        'T' => $T,
        'metrics' => $metrics,
        'jobs' => ['g' => top($jobs['g'], 7), 'p' => top($jobs['p'], 7), 'c' => top($jobs['c'], 7)],
        'sec' => ['g' => top($sectors['g'], 10), 'p' => top($sectors['p'], 10), 'c' => top($sectors['c'], 10)],
        'PV' => $PV,
        'KB' => $KB,
        'sk' => ['g' => top($skillCounts['g'], 7), 'p' => top($skillCounts['p'], 7), 'c' => top($skillCounts['c'], 7)],
        'GEN' => $GEN,
        'DIS' => $DIS,
        'PJ' => $PJ,
        'SK' => $SK,
        'HM' => $HM,
    ];

    echo 'window.DB_DATA=', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ';';
} catch (Throwable $error) {
    error_log($error->__toString());
    echo 'window.DB_DATA=null;window.DB_ERROR=',
        json_encode('Database tidak dapat dimuat: ' . $error->getMessage(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ';';
}
