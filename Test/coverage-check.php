<?php
/**
 * Fails when line coverage in a Clover report is below a threshold.
 *
 * Usage: php Test/coverage-check.php <clover.xml> <min-percent> [path-prefix]
 * With a path prefix (e.g. "Controller/"), only files under that directory are counted.
 */
declare(strict_types=1);

[$script, $cloverFile, $threshold, $prefix] = array_pad($argv, 4, '');

if ($cloverFile === '' || !is_file($cloverFile) || !is_numeric($threshold)) {
    fwrite(STDERR, "Usage: php {$script} <clover.xml> <min-percent> [path-prefix]\n");
    exit(2);
}

$root = rtrim(dirname(__DIR__), '/') . '/';
$prefix = trim($prefix, '/');
$covered = 0;
$total = 0;
$rows = [];

foreach ((new SimpleXMLElement(file_get_contents($cloverFile)))->xpath('//file') as $file) {
    $path = str_starts_with((string) $file['name'], $root) ? substr((string) $file['name'], strlen($root)) : (string) $file['name'];
    if ($prefix !== '' && !str_starts_with($path, $prefix . '/')) {
        continue;
    }
    $metrics = $file->metrics;
    $statements = (int) $metrics['statements'];
    $coveredStatements = (int) $metrics['coveredstatements'];
    $total += $statements;
    $covered += $coveredStatements;
    if ($statements > 0) {
        $rows[] = sprintf('  %6.2f%%  %s', 100 * $coveredStatements / $statements, $path);
    }
}

if ($total === 0) {
    fwrite(STDERR, "No executable lines found" . ($prefix !== '' ? " under {$prefix}/" : '') . ".\n");
    exit(1);
}

$percent = 100 * $covered / $total;
echo implode("\n", $rows), "\n";
printf("Line coverage%s: %.2f%% (%d/%d), minimum %.2f%%\n", $prefix !== '' ? " for {$prefix}" : '', $percent, $covered, $total, (float) $threshold);

exit($percent + 1e-9 >= (float) $threshold ? 0 : 1);
