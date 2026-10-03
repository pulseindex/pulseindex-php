<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * What `composer require` actually downloads.
 *
 * `.gitattributes` decides the dist archive, so this checks that the paths the
 * package reads at runtime survive it.
 */
final class ShippedArchiveTest extends TestCase
{
    /** Paths the installed package reads at runtime, relative to its root. */
    private const MUST_SHIP = [
        'composer.json',
        'src/Client.php',
        'src/QueryBuilder.php',
        'src/Entity.php',
        'src/Geo/GeoHash.php',
        'generated/PulseIndex/Engine/V1/SearchEngineServiceClient.php',
        // Grpc\Health\V1\HealthCheckResponse calls GPBMetadata\Health::initOnce()
        // on construction. The class was deleted by a build rather than by a
        // decision and nothing regenerated it, so every health check from
        // v3.1.0 onward threw "Class GPBMetadata\Health not found".
        'generated/GPBMetadata/Health.php',
        'generated/GPBMetadata/PulseIndex/Engine.php',
        'generated/Grpc/Health/V1/HealthClient.php',
    ];

    /** Paths that must not, because a consumer has no use for them. */
    private const MUST_NOT_SHIP = [
        'tests',
        'scripts',
        '.github',
        'proto',
        'phpunit.xml',
        'Dockerfile',
    ];

    public function test_the_archive_carries_everything_the_package_reads(): void
    {
        $shipped = self::shippedPaths();
        self::assertNotEmpty($shipped, 'git archive produced nothing');

        foreach (self::MUST_SHIP as $path) {
            $found = array_filter($shipped, static fn (string $p): bool => str_starts_with($p, $path));
            self::assertNotEmpty($found, sprintf(
                '%s is read by the installed package but is not in the dist archive; check .gitattributes',
                $path,
            ));
        }
    }

    public function test_the_archive_leaves_out_what_a_consumer_cannot_use(): void
    {
        $shipped = self::shippedPaths();

        foreach (self::MUST_NOT_SHIP as $path) {
            $found = array_filter($shipped, static fn (string $p): bool => str_starts_with($p, $path));
            self::assertEmpty($found, sprintf('%s should not be in the dist archive', $path));
        }
    }

    /**
     * The package reads no path from disk, so `.gitattributes` cannot break it.
     * A `__DIR__` in shipped code is a path someone has to remember to ship.
     */
    public function test_nothing_shipped_reads_a_path_from_disk(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (['src', 'generated'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($it as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                if (str_contains((string) file_get_contents($file->getPathname()), '__DIR__')) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        self::assertSame([], $offenders, sprintf(
            'these read a path relative to their own location, so the dist archive has to carry it: %s',
            implode(', ', $offenders),
        ));
    }

    /**
     * Every GPBMetadata class the generated code initialises must exist.
     *
     * protobuf's generated messages call `\GPBMetadata\X::initOnce()` from
     * their constructor, so a missing metadata class is not a warning, it is a
     * fatal on first use. compile-proto.sh wipes the whole GPBMetadata
     * directory, so both protos have to be regenerated.
     */
    public function test_every_metadata_class_the_generated_code_calls_exists(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/generated'));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('#\\\\GPBMetadata\\\\([A-Za-z0-9_\\\\]+)::initOnce#', (string) file_get_contents($file->getPathname()), $m);
            foreach ($m[1] as $class) {
                $found[$class] = true;
            }
        }

        self::assertNotEmpty($found, 'no GPBMetadata initOnce calls found at all');

        foreach (array_keys($found) as $class) {
            $path = $root . '/generated/GPBMetadata/' . str_replace('\\', '/', $class) . '.php';
            self::assertFileExists($path, sprintf(
                'generated code calls GPBMetadata\\%s::initOnce() and the class is not there',
                $class,
            ));
        }
    }

    /** @return list<string> */
    private static function shippedPaths(): array
    {
        $root = dirname(__DIR__, 2);
        $tree = trim((string) shell_exec(sprintf('git -C %s write-tree 2>/dev/null', escapeshellarg($root))));
        if ($tree === '') {
            self::markTestSkipped('not a git checkout, or git is unavailable');
        }

        $listing = (string) shell_exec(sprintf(
            'git -C %s archive %s 2>/dev/null | tar -t 2>/dev/null',
            escapeshellarg($root),
            escapeshellarg($tree),
        ));

        return array_values(array_filter(array_map('trim', explode("\n", $listing))));
    }
}
