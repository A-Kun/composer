<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Installer;

use Composer\Installer\BinaryInstaller;
use Composer\Util\Filesystem;
use Composer\Test\TestCase;
use Composer\Util\ProcessExecutor;

class BinaryInstallerTest extends TestCase
{
    /**
     * @var string
     */
    protected $rootDir;

    /**
     * @var string
     */
    protected $vendorDir;

    /**
     * @var string
     */
    protected $binDir;

    /**
     * @var \Composer\IO\IOInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    protected $io;

    /**
     * @var \Composer\Util\Filesystem
     */
    protected $fs;

    protected function setUp(): void
    {
        $this->fs = new Filesystem;

        $this->rootDir = self::getUniqueTmpDirectory();
        $this->vendorDir = $this->rootDir.DIRECTORY_SEPARATOR.'vendor';
        $this->ensureDirectoryExistsAndClear($this->vendorDir);

        $this->binDir = $this->rootDir.DIRECTORY_SEPARATOR.'bin';
        $this->ensureDirectoryExistsAndClear($this->binDir);

        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->fs->removeDirectory($this->rootDir);
    }

    /**
     * @dataProvider executableBinaryProvider
     */
    public function testInstallAndExecBinaryWithFullCompat(string $contents): void
    {
        $package = $this->createPackageMock();
        $package->expects($this->any())
            ->method('getBinaries')
            ->willReturn(['binary']);

        $this->ensureDirectoryExistsAndClear($this->vendorDir.'/foo/bar');
        file_put_contents($this->vendorDir.'/foo/bar/binary', $contents);

        $installer = new BinaryInstaller($this->io, $this->binDir, 'full', $this->fs);
        $installer->installBinaries($package, $this->vendorDir.'/foo/bar');

        $proc = new ProcessExecutor();
        $proc->execute($this->binDir.'/binary arg', $output);
        self::assertEquals('', $proc->getErrorOutput());
        self::assertEquals('success arg', $output);
    }

    /**
     * @requires function symlink
     */
    public function testInstallBinaryRejectsSymlinkEscapingPackageDir(): void
    {
        $package = $this->createPackageMock();
        $package->method('getBinaries')->willReturn(['bin/command']);

        $victim = $this->rootDir.'/victim.sh';
        file_put_contents($victim, 'outside package');
        chmod($victim, 0600);
        clearstatcache();
        $modeBefore = fileperms($victim);

        $installPath = $this->vendorDir.'/attacker/pkg';
        self::ensureDirectoryExistsAndClear($installPath.'/bin');
        if (!@symlink('../../../../victim.sh', $installPath.'/bin/command')) {
            $this->markTestSkipped('Symbolic links are not supported on this platform');
        }

        $this->io->expects($this->once())->method('writeError')->with(
            '    <warning>Skipped installation of bin bin/command for package '.$package->getName().': the bin resolves to a path outside of the package directory</warning>'
        );

        $installer = new BinaryInstaller($this->io, $this->binDir, 'full', $this->fs);
        $installer->installBinaries($package, $installPath);

        self::assertFileDoesNotExist($this->binDir.'/command');
        self::assertFileDoesNotExist($this->binDir.'/command.bat');
        clearstatcache();
        self::assertSame($modeBefore, fileperms($victim));
    }

    public function testInstallBinaryRejectsTraversingBinPath(): void
    {
        $package = $this->createPackageMock();
        $package->method('getBinaries')->willReturn(['../../../victim.sh']);

        $victim = $this->rootDir.'/victim.sh';
        file_put_contents($victim, 'outside package');
        chmod($victim, 0600);
        clearstatcache();
        $modeBefore = fileperms($victim);

        $installPath = $this->vendorDir.'/attacker/pkg';
        self::ensureDirectoryExistsAndClear($installPath);
        $this->io->expects($this->once())->method('writeError')->with(
            '    <warning>Skipped installation of bin ../../../victim.sh for package '.$package->getName().': the bin resolves to a path outside of the package directory</warning>'
        );

        $installer = new BinaryInstaller($this->io, $this->binDir, 'full', $this->fs);
        $installer->installBinaries($package, $installPath);

        self::assertFileDoesNotExist($this->binDir.'/victim.sh');
        self::assertFileDoesNotExist($this->binDir.'/victim.sh.bat');
        clearstatcache();
        self::assertSame($modeBefore, fileperms($victim));
    }

    /**
     * @dataProvider provideSymlinkedInstallPaths
     * @requires function symlink
     */
    public function testInstallBinaryAllowsInPackageSymlink(bool $symlinkInstallPath): void
    {
        $package = $this->createPackageMock();
        $package->method('getBinaries')->willReturn(['bin/command']);

        $realInstallPath = $this->rootDir.'/package';
        self::ensureDirectoryExistsAndClear($realInstallPath.'/bin');
        file_put_contents($realInstallPath.'/command.php', '<?php echo "success";');
        if (!@symlink('../command.php', $realInstallPath.'/bin/command')) {
            $this->markTestSkipped('Symbolic links are not supported on this platform');
        }

        $installPath = $realInstallPath;
        if ($symlinkInstallPath) {
            $installPath = $this->vendorDir.'/linked-package';
            if (!@symlink($realInstallPath, $installPath)) {
                $this->markTestSkipped('Symbolic links are not supported on this platform');
            }
        }

        $this->io->expects($this->never())->method('writeError');
        $installer = new BinaryInstaller($this->io, $this->binDir, 'full', $this->fs);
        $installer->installBinaries($package, $installPath);

        self::assertFileExists($this->binDir.'/command');
        self::assertFileExists($this->binDir.'/command.bat');
        $process = new ProcessExecutor();
        self::assertSame(0, $process->execute([PHP_BINARY, $this->binDir.'/command'], $output));
        self::assertSame('success', $output);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function provideSymlinkedInstallPaths(): array
    {
        return [
            'regular package directory' => [false],
            'symlinked path repository' => [true],
        ];
    }

    public function testIsBinPathInsidePackageChecksResolvedDirectoryBoundaries(): void
    {
        $installPath = $this->vendorDir.'/pkg';
        $siblingPath = $this->vendorDir.'/pkg-other';
        self::ensureDirectoryExistsAndClear($installPath);
        self::ensureDirectoryExistsAndClear($siblingPath);
        file_put_contents($installPath.'/command', 'inside package');
        file_put_contents($siblingPath.'/command', 'outside package');

        self::assertTrue(BinaryInstaller::isBinPathInsidePackage($installPath, $installPath.'/command'));
        self::assertFalse(BinaryInstaller::isBinPathInsidePackage($installPath, $siblingPath.'/command'));
        self::assertFalse(BinaryInstaller::isBinPathInsidePackage($installPath, $installPath.'/missing'));
        self::assertFalse(BinaryInstaller::isBinPathInsidePackage($this->vendorDir.'/missing', $installPath.'/command'));
        self::assertFalse(BinaryInstaller::isBinPathInsidePackage($installPath, $installPath));
    }

    public static function executableBinaryProvider(): array
    {
        return [
            'simple php file' => [<<<'EOL'
<?php

echo 'success '.$_SERVER['argv'][1];
EOL
            ],
            'php file with shebang' => [<<<'EOL'
#!/usr/bin/env php
<?php

echo 'success '.$_SERVER['argv'][1];
EOL
            ],
            'phar file' => [
                base64_decode('IyEvdXNyL2Jpbi9lbnYgcGhwCjw/cGhwCgpQaGFyOjptYXBQaGFyKCd0ZXN0LnBoYXInKTsKCnJlcXVpcmUgJ3BoYXI6Ly90ZXN0LnBoYXIvcnVuLnBocCc7CgpfX0hBTFRfQ09NUElMRVIoKTsgPz4NCj4AAAABAAAAEQAAAAEACQAAAHRlc3QucGhhcgAAAAAHAAAAcnVuLnBocCoAAADb9n9hKgAAAMUDDWGkAQAAAAAAADw/cGhwIGVjaG8gInN1Y2Nlc3MgIi4kX1NFUlZFUlsiYXJndiJdWzFdO1SOC0IE3+UN0yzrHIwyspp9slhmAgAAAEdCTUI='),
            ],
            'shebang with strict types declare' => [<<<'EOL'
#!/usr/bin/env php
<?php declare(strict_types=1);

echo 'success '.$_SERVER['argv'][1];
EOL
            ],
        ];
    }

    /**
     * @return \Composer\Package\PackageInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    protected function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\Package')
            ->setConstructorArgs([bin2hex(random_bytes(5)), '1.0.0.0', '1.0.0'])
            ->getMock();
    }
}
