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
    $green = strtolower(text($row['kategori_green'] ?? ''));
    if (preg_match('/non[\s_-]*(green|hijau)|tidak[\s_-]*(green|hijau)/', $green)) {
        return 'n';
    }
    if (preg_match('/potens|potential|broad/', $green)) {
        return 'p';
    }
    if (preg_match('/green|hijau|narrow|murni/', $green)) {
        return 'g';
    }

    $narrowLabel = strtolower(text($row['kategori_gti_narrow'] ?? ''));
    $broadLabel = strtolower(text($row['kategori_gti_broad'] ?? ''));
    $narrow = strtolower(text($row['gti_narrow'] ?? ''));
    $broad = strtolower(text($row['GTI_broad'] ?? ''));
    $isYes = static fn(string $v): bool => in_array($v, ['1', 'ya', 'yes', 'true', 'y'], true);
    if ($isYes($narrow) || preg_match('/green|hijau|narrow|murni/', $narrowLabel)) {
        return 'g';
    }
    if ($isYes($broad) || preg_match('/potens|potential|broad|green|hijau/', $broadLabel)) {
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
        'jumlah_lowongan_orang', 'total_pelamar', 'jabatan_clean', 'uraian_kbji_4',
        'sektor', 'industri', 'Provinsi', 'kab_kota', 'keahlian',
        'jenis_kelamin', 'kondisi', 'kategori_green',
        'gti_narrow', 'kategori_gti_narrow', 'GTI_broad', 'kategori_gti_broad',
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
    $sectorStats = ['g' => [], 'p' => []];
    $provinces = [];
    $cities = [];
    $skillCounts = ['g' => [], 'p' => []];
    $gender = ['g' => [], 'p' => []];
    $physical = ['g' => [], 'p' => []];
    $jobStats = ['g' => [], 'p' => []];
    $rowSkills = [];

    foreach ($rows as $index => $row) {
        $cat = category($row);
        $quota = max(0, (int) ($row['jumlah_lowongan_orang'] ?? 0));
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

        $job = text($row['uraian_kbji_4']) ?: text($row['jabatan_clean']);
        $sector = text($row['sektor']) ?: text($row['industri']);
        $province = text($row['Provinsi']) ?: 'Tidak diketahui';
        $city = text($row['kab_kota']) ?: 'Tidak diketahui';
        add($jobs[$cat], $job, $quota);
        add($sectors[$cat], $sector, $quota);
        if ($sector !== '') {
            $sectorStats[$cat][$sector] ??= [
                'postings' => 0,
                'quota' => 0,
                'applicants' => 0,
                'jobs' => [],
                'provinces' => [],
                'cities' => [],
            ];
            $sectorStats[$cat][$sector]['postings']++;
            $sectorStats[$cat][$sector]['quota'] += $quota;
            $sectorStats[$cat][$sector]['applicants'] += $applicants;
            add($sectorStats[$cat][$sector]['jobs'], $job, $quota);
            add($sectorStats[$cat][$sector]['provinces'], $province, $quota);
            add($sectorStats[$cat][$sector]['cities'], $city, $quota);
        }

        $provinces[$province] ??= ['g' => 0, 'p' => 0];
        $provinces[$province][$cat] += $quota;
        $cities[$province][$city] ??= [
            'g' => ['quota' => 0, 'applicants' => 0],
            'p' => ['quota' => 0, 'applicants' => 0],
        ];
        $cities[$province][$city][$cat]['quota'] += $quota;
        $cities[$province][$city][$cat]['applicants'] += $applicants;

        add($gender[$cat], text($row['jenis_kelamin']) ?: 'Tidak diketahui', $quota);
        add($physical[$cat], text($row['kondisi']) ?: 'Tidak diketahui', $quota);
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
    $sectorStats['c'] = $sectorStats['g'];
    foreach ($sectorStats['p'] as $sector => $value) {
        $sectorStats['c'][$sector] ??= [
            'postings' => 0,
            'quota' => 0,
            'applicants' => 0,
            'jobs' => [],
            'provinces' => [],
            'cities' => [],
        ];
        $sectorStats['c'][$sector]['postings'] += $value['postings'];
        $sectorStats['c'][$sector]['quota'] += $value['quota'];
        $sectorStats['c'][$sector]['applicants'] += $value['applicants'];
        $sectorStats['c'][$sector]['jobs'] = $combine($sectorStats['c'][$sector]['jobs'], $value['jobs']);
        $sectorStats['c'][$sector]['provinces'] = $combine($sectorStats['c'][$sector]['provinces'], $value['provinces']);
        $sectorStats['c'][$sector]['cities'] = $combine($sectorStats['c'][$sector]['cities'], $value['cities']);
    }
    $sectorDetail = [];
    foreach (['g', 'p', 'c'] as $cat) {
        foreach ($sectorStats[$cat] as $sector => $value) {
            $sectorDetail[$cat][$sector] = [
                'postings' => $value['postings'],
                'quota' => $value['quota'],
                'applicants' => $value['applicants'],
                'jobs' => top($value['jobs'], 5),
                'provinces' => top($value['provinces'], 5),
                'cities' => top($value['cities'], 5),
            ];
        }
    }

    $PV = [];
    foreach ($provinces as $name => $value) {
        $PV[] = [$name, island($name), $value['g'] + $value['p'], $value['g']];
    }
    usort($PV, static fn(array $a, array $b): int => $b[2] <=> $a[2]);

    $KB = ['g' => [], 'p' => [], 'c' => []];
    foreach ($cities as $province => $provinceCities) {
        foreach (['g', 'p', 'c'] as $cat) {
            $values = [];
            foreach ($provinceCities as $name => $value) {
                $quota = $cat === 'c'
                    ? $value['g']['quota'] + $value['p']['quota']
                    : $value[$cat]['quota'];
                $applicants = $cat === 'c'
                    ? $value['g']['applicants'] + $value['p']['applicants']
                    : $value[$cat]['applicants'];
                if ($quota > 0) {
                    $values[$name] = ['quota' => $quota, 'applicants' => $applicants];
                }
            }
            uasort($values, static fn(array $a, array $b): int => $b['quota'] <=> $a['quota']);
            foreach (array_slice($values, 0, 5, true) as $name => $value) {
                $KB[$cat][$province][] = [
                    $name,
                    $value['quota'],
                    number_format($value['applicants'] / $value['quota'], 1, ',', '.'),
                ];
            }
        }
    }

    $distribution = static function (array $values, array $patterns): array {
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
        return $result;
    };

    $GENQ = $DISQ = [];
    foreach (['g', 'p'] as $cat) {
        $GENQ[$cat] = $distribution(
            $gender[$cat],
            ['/(semua|bebas|laki.*perempuan|pria.*wanita)/u', '/(laki|pria|male)/u', '/(perempuan|wanita|female)/u']
        );
        $DISQ[$cat] = $distribution(
            $physical[$cat],
            ['/^(non.?disabil|normal|sehat)$/u', '/(semua|bebas|disabil.*non|non.*disabil.*disabil)/u', '/(disabil|difabel)/u']
        );
    }
    $combineDistribution = static function (array $a, array $b): array {
        return array_map(static fn(int $i): int => $a[$i] + $b[$i], array_keys($a));
    };
    $GENQ['c'] = $combineDistribution($GENQ['g'], $GENQ['p']);
    $DISQ['c'] = $combineDistribution($DISQ['g'], $DISQ['p']);
    $percentDistribution = static function (array $values): array {
        $total = array_sum($values);
        return array_map(
            static fn(int $value): float => $total > 0 ? round($value / $total * 100, 1) : 0,
            $values
        );
    };
    $GEN = $DIS = [];
    foreach (['g', 'p', 'c'] as $cat) {
        $GEN[$cat] = $percentDistribution($GENQ[$cat]);
        $DIS[$cat] = $percentDistribution($DISQ[$cat]);
    }

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
        'sectorDetail' => $sectorDetail,
        'PV' => $PV,
        'KB' => $KB,
        'sk' => ['g' => top($skillCounts['g'], 7), 'p' => top($skillCounts['p'], 7), 'c' => top($skillCounts['c'], 7)],
        'GEN' => $GEN,
        'DIS' => $DIS,
        'GENQ' => $GENQ,
        'DISQ' => $DISQ,
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
