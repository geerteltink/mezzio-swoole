<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-swoole for the canonical source repository
 * @copyright Copyright (c) 2018 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-swoole/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace ZendTest\Expressive\Swoole;

use PHPUnit\Framework\TestCase;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Container\ContainerInterface;
use Swoole\Process;
use Zend\Expressive\Swoole\Exception\InvalidArgumentException;
use Zend\Expressive\Swoole\HttpServerFactory;

use function json_decode;
use function json_encode;

use const SWOOLE_BASE;
use const SWOOLE_PROCESS;
use const SWOOLE_SOCK_TCP;
use const SWOOLE_SOCK_TCP6;

class HttpServerFactoryTest extends TestCase
{

    /** @var ContainerInterface|ObjectProphecy */
    private $container;

    public function setUp()
    {
        parent::setUp();
        $this->container = $this->prophesize(ContainerInterface::class);
    }

    public function testFactoryCanCreateServerWithDefaultConfiguration() : void
    {
        /**
         * Initialise servers inside a process or subsequent tests will fail
         * @see https://github.com/swoole/swoole-src/issues/1754
         */
        $process = new Process(function (Process $worker) {
            $this->container->get('config')->willReturn([]);
            $factory = new HttpServerFactory();
            $swooleServer = $factory($this->container->reveal());
            $this->assertSame(HttpServerFactory::DEFAULT_HOST, $swooleServer->host);
            $this->assertSame(HttpServerFactory::DEFAULT_PORT, $swooleServer->port);
            $this->assertSame(SWOOLE_BASE, $swooleServer->mode);
            $this->assertSame(SWOOLE_SOCK_TCP, $swooleServer->type);
            $worker->write('Process Complete');
            $worker->exit(0);
        });
        $process->start();
        $this->assertSame('Process Complete', $process->read());
        Process::wait(true);
    }

    public function testFactorySetsPortAndHostAsConfigured() : void
    {
        $process = new Process(function (Process $worker) {
            $this->container->get('config')->willReturn([
                'zend-expressive-swoole' => [
                    'swoole-http-server' => [
                        'host' => '0.0.0.0',
                        'port' => 8081,
                        'mode' => SWOOLE_BASE,
                        'protocol' => SWOOLE_SOCK_TCP6,
                    ],
                ],
            ]);
            $factory = new HttpServerFactory();
            $swooleServer = $factory($this->container->reveal());
            $worker->write(json_encode([
                'host' => $swooleServer->host,
                'port' => $swooleServer->port,
                'mode' => $swooleServer->mode,
                'type' => $swooleServer->type,
            ]));
            $worker->exit(0);
        });
        $process->start();
        $data = $process->read();
        Process::wait(true);

        $result = json_decode($data, true);
        $this->assertSame([
            'host' => '0.0.0.0',
            'port' => 8081,
            'mode' => SWOOLE_BASE,
            'type' => SWOOLE_SOCK_TCP6,
        ], $result);
    }

    public function getInvalidPortNumbers() : array
    {
        return [
            [-1],
            [0],
            [65536],
            [999999],
        ];
    }

    /**
     * @dataProvider getInvalidPortNumbers
     * @param int $port
     */
    public function testExceptionThrownForOutOfRangePortNumber(int $port) : void
    {
        $this->container->get('config')->willReturn([
            'zend-expressive-swoole' => [
                'swoole-http-server' => [
                    'port' => $port,
                ],
            ],
        ]);
        $factory = new HttpServerFactory();
        try {
            $factory($this->container->reveal());
            $this->fail('An exception was not thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Invalid port', $e->getMessage());
        }
    }

    public function invalidServerModes() : array
    {
        return [
            [0],
            [(string) SWOOLE_BASE],
            [(string) SWOOLE_PROCESS],
            [10],
        ];
    }

    /**
     * @dataProvider invalidServerModes
     * @param mixed $mode
     */
    public function testExceptionThrownForInvalidServerMode($mode) : void
    {
        $this->container->get('config')->willReturn([
            'zend-expressive-swoole' => [
                'swoole-http-server' => [
                    'mode' => $mode,
                ],
            ],
        ]);
        $factory = new HttpServerFactory();
        try {
            $factory($this->container->reveal());
            $this->fail('An exception was not thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Invalid server mode', $e->getMessage());
        }
    }

    public function invalidSocketTypes() : array
    {
        return [
            [0],
            [(string) SWOOLE_SOCK_TCP],
            [(string) SWOOLE_SOCK_TCP6],
            [10],
        ];
    }

    /**
     * @dataProvider invalidSocketTypes
     * @param mixed $type
     */
    public function testExceptionThrownForInvalidSocketType($type) : void
    {
        $this->container->get('config')->willReturn([
            'zend-expressive-swoole' => [
                'swoole-http-server' => [
                    'protocol' => $type,
                ],
            ],
        ]);
        $factory = new HttpServerFactory();
        try {
            $factory($this->container->reveal());
            $this->fail('An exception was not thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Invalid server protocol', $e->getMessage());
        }
    }
}
