<?php

use Bredala\Database\Exception;
use PHPUnit\Framework\TestCase;

class ExceptionTest extends TestCase
{
    public function testExtendsTheBuiltInException()
    {
        self::assertInstanceOf(\Exception::class, new Exception('x'));
    }

    public function testCodeConstants()
    {
        self::assertSame(1, Exception::CONNECT);
        self::assertSame(2, Exception::PREPARE);
        self::assertSame(3, Exception::BIND);
        self::assertSame(4, Exception::EXECUTE);
        self::assertSame(5, Exception::TRANSACTION);
    }

    /**
     * @dataProvider factoryProvider
     */
    public function testTheFactoriesReturnAnExceptionCarryingTheMatchingCode(string $factory, int $code)
    {
        // These are FACTORIES: they build and return, they do not throw. Callers
        // must write 'throw Exception::connect(...)'.
        $exception = Exception::{$factory}('boom');

        self::assertInstanceOf(Exception::class, $exception);
        self::assertSame('boom', $exception->getMessage());
        self::assertSame($code, $exception->getCode());
        self::assertNull($exception->getPrevious());
    }

    public static function factoryProvider(): array
    {
        return [
            'connect' => ['connect', Exception::CONNECT],
            'prepare' => ['prepare', Exception::PREPARE],
            'bind' => ['bind', Exception::BIND],
            'execute' => ['execute', Exception::EXECUTE],
            'transaction' => ['transaction', Exception::TRANSACTION],
        ];
    }

    /**
     * @dataProvider factoryProvider
     */
    public function testTheFactoriesForwardThePreviousThrowable(string $factory)
    {
        $previous = new RuntimeException('root cause');

        self::assertSame($previous, Exception::{$factory}('boom', $previous)->getPrevious());
    }

    public function testAFactoryResultIsThrowable()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(Exception::CONNECT);
        $this->expectExceptionMessage('boom');

        throw Exception::connect('boom');
    }

    public function testCallingAFactoryWithoutThrowingIsSilent()
    {
        // This is the mistake FB::addFk() makes: the exception is built and
        // discarded, so the guard has no effect.
        Exception::prepare('nobody will see this');

        self::assertTrue(true);
    }
}
