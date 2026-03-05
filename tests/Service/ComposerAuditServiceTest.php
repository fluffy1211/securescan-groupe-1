<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ComposerAuditService;
use PHPUnit\Framework\TestCase;

/**
 * Pour lancer ces tests :
 *   composer require --dev phpunit/phpunit
 *   php vendor/bin/phpunit tests/Service/ComposerAuditServiceTest.php
 */
class ComposerAuditServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/composer_audit_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    // -------------------------------------------------------------------------
    // Test 1 — Vulnérabilités détectées
    // -------------------------------------------------------------------------

    public function testScanReturnsNormalizedAdvisoriesWhenVulnerabilitiesFound(): void
    {
        $this->createComposerFiles($this->tmpDir);

        $fakeJson = json_encode([
            'advisories' => [
                'symfony/http-kernel' => [
                    [
                        'advisoryId'       => 'PKSA-2024-0001',
                        'packageName'      => 'symfony/http-kernel',
                        'affectedVersions' => '>=5.4.0,<5.4.47',
                        'title'            => 'HTTP kernel allows injection via request parameters',
                        'cve'              => 'CVE-2024-50341',
                        'url'              => 'https://symfony.com/cve-2024-50341',
                        'reportedAt'       => '2024-10-15T00:00:00+00:00',
                        'severity'         => 'high',
                    ],
                ],
            ],
            'metadata' => [
                'affectedPackagesCount'  => 1,
                'safePackagesCount'      => 142,
                'totalDependenciesCount' => 143,
            ],
        ]);

        $service = $this->makeServiceWithOutput($fakeJson);
        $result  = $service->scan($this->tmpDir);

        $this->assertCount(1, $result);

        $advisory = $result[0];
        $this->assertSame('composer_audit', $advisory['tool']);
        $this->assertSame('symfony/http-kernel', $advisory['package']);
        $this->assertSame('>=5.4.0,<5.4.47', $advisory['version']);
        $this->assertSame('CVE-2024-50341', $advisory['cve']);
        $this->assertSame('HTTP kernel allows injection via request parameters', $advisory['title']);
        $this->assertSame('high', $advisory['severity']);
        $this->assertSame('https://symfony.com/cve-2024-50341', $advisory['url']);
        $this->assertSame('2024-10-15', $advisory['reported_at']);
        $this->assertNull($advisory['owasp']);
        $this->assertSame('composer require symfony/http-kernel:^5.4.47', $advisory['fix']);
    }

    // -------------------------------------------------------------------------
    // Test 2 — Aucune vulnérabilité → tableau vide, pas d'exception
    // -------------------------------------------------------------------------

    public function testScanReturnsEmptyArrayWhenNoVulnerabilities(): void
    {
        $this->createComposerFiles($this->tmpDir);

        $fakeJson = json_encode([
            'advisories' => [],
            'metadata'   => [
                'affectedPackagesCount'  => 0,
                'safePackagesCount'      => 142,
                'totalDependenciesCount' => 142,
            ],
        ]);

        $service = $this->makeServiceWithOutput($fakeJson);
        $result  = $service->scan($this->tmpDir);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // Test 3 — composer.lock absent → RuntimeException
    // -------------------------------------------------------------------------

    public function testScanThrowsWhenComposerLockIsMissing(): void
    {
        // Créer uniquement composer.json, sans composer.lock
        file_put_contents($this->tmpDir . '/composer.json', '{}');

        $service = $this->makeServiceWithOutput('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer.lock manquant');

        $service->scan($this->tmpDir);
    }

    // -------------------------------------------------------------------------
    // Test bonus — dossier inexistant → RuntimeException
    // -------------------------------------------------------------------------

    public function testScanThrowsWhenDirectoryDoesNotExist(): void
    {
        $service = $this->makeServiceWithOutput('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Dossier introuvable/');

        $service->scan('/chemin/qui/nexiste/pas');
    }

    // -------------------------------------------------------------------------
    // Test bonus — composer.json absent → RuntimeException
    // -------------------------------------------------------------------------

    public function testScanThrowsWhenComposerJsonIsMissing(): void
    {
        // Dossier vide, pas de composer.json
        $service = $this->makeServiceWithOutput('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer.json introuvable');

        $service->scan($this->tmpDir);
    }

    // -------------------------------------------------------------------------
    // Test bonus — plusieurs packages vulnérables
    // -------------------------------------------------------------------------

    public function testScanNormalizesMultiplePackages(): void
    {
        $this->createComposerFiles($this->tmpDir);

        $fakeJson = json_encode([
            'advisories' => [
                'vendor/pkg-a' => [
                    [
                        'packageName'      => 'vendor/pkg-a',
                        'affectedVersions' => '>=1.0.0,<1.2.3',
                        'title'            => 'Vuln A',
                        'cve'              => 'CVE-2024-0001',
                        'url'              => 'https://example.com/a',
                        'reportedAt'       => '2024-01-01T00:00:00+00:00',
                        'severity'         => 'medium',
                    ],
                ],
                'vendor/pkg-b' => [
                    [
                        'packageName'      => 'vendor/pkg-b',
                        'affectedVersions' => '>=2.0.0,<2.5.0',
                        'title'            => 'Vuln B',
                        'cve'              => null,
                        'url'              => null,
                        'reportedAt'       => null,
                        'severity'         => 'low',
                    ],
                ],
            ],
            'metadata' => ['affectedPackagesCount' => 2],
        ]);

        $service = $this->makeServiceWithOutput($fakeJson);
        $result  = $service->scan($this->tmpDir);

        $this->assertCount(2, $result);
        $this->assertSame('composer require vendor/pkg-a:^1.2.3', $result[0]['fix']);
        $this->assertSame('composer require vendor/pkg-b:^2.5.0', $result[1]['fix']);
        $this->assertNull($result[1]['cve']);
        $this->assertNull($result[1]['reported_at']);
    }

    public function testFixExtractionHandlesLessThanOrEqualAndUnknownFormats(): void
    {
        $this->createComposerFiles($this->tmpDir);

        $fakeJson = json_encode([
            'advisories' => [
                'vendor/pkg-c' => [
                    [
                        'packageName'      => 'vendor/pkg-c',
                        'affectedVersions' => '<=3.4.5',
                        'title'            => 'Vuln C',
                        'severity'         => 'HIGH',
                    ],
                ],
                'vendor/pkg-d' => [
                    [
                        'packageName'      => 'vendor/pkg-d',
                        'affectedVersions' => '>=4.0',
                        'title'            => 'Vuln D',
                        'severity'         => 'Critical',
                    ],
                ],
            ],
        ]);

        $service = $this->makeServiceWithOutput($fakeJson);
        $result  = $service->scan($this->tmpDir);

        $this->assertCount(2, $result);
        $this->assertSame('composer require vendor/pkg-c:^3.4.5', $result[0]['fix']);
        $this->assertSame('high', $result[0]['severity']);
        $this->assertNull($result[1]['fix']);
        $this->assertSame('critical', $result[1]['severity']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Retourne un service dont runAudit() est remplacé par un stub.
     */
    private function makeServiceWithOutput(string $fakeOutput): ComposerAuditService
    {
        return new class($fakeOutput) extends ComposerAuditService {
            public function __construct(private readonly string $fakeOutput)
            {
            }

            protected function isComposerAvailable(): bool
            {
                return true;
            }

            protected function runAudit(string $path): string
            {
                return $this->fakeOutput;
            }
        };
    }

    private function createComposerFiles(string $dir): void
    {
        file_put_contents($dir . '/composer.json', '{}');
        file_put_contents($dir . '/composer.lock', '{"packages":[]}');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . '/' . $item;
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($dir);
    }
}
