<?php

use Bredala\Database\DBInterface;
use Bredala\Database\PDO\DB;
use Bredala\Database\QueryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Contract-level checks only. PDO\DB's behavior needs a live connection and is
 * out of scope for this suite.
 */
class DBInterfaceTest extends TestCase
{
    private function method(string $name): ReflectionMethod
    {
        return new ReflectionMethod(DBInterface::class, $name);
    }

    public function testDbImplementsTheInterface()
    {
        self::assertTrue((new ReflectionClass(DB::class))->implementsInterface(DBInterface::class));
    }

    public function testHookConstants()
    {
        self::assertSame('before_query', DB::HOOK_BEFORE_QUERY);
        self::assertSame('after_query', DB::HOOK_AFTER_QUERY);
    }

    /**
     * @dataProvider noArgumentFetchProvider
     */
    public function testTheFetchMethodsTakeNoArguments(string $name)
    {
        // An older call style like one(false) raises an ArgumentCountError.
        self::assertSame(0, $this->method($name)->getNumberOfParameters(), $name);
    }

    public static function noArgumentFetchProvider(): array
    {
        return [
            'one' => ['one'],
            'all' => ['all'],
            'next' => ['next'],
            'count' => ['count'],
        ];
    }

    public function testExecTakesAQuery()
    {
        $parameters = $this->method('exec')->getParameters();

        self::assertCount(1, $parameters);
        self::assertSame(QueryInterface::class, (string) $parameters[0]->getType());
    }

    /**
     * @dataProvider chainableProvider
     */
    public function testTheMutatorsReturnTheInterfaceSoCallsChain(string $name)
    {
        self::assertSame(DBInterface::class, (string) $this->method($name)->getReturnType(), $name);
    }

    public static function chainableProvider(): array
    {
        return [
            'transaction' => ['transaction'],
            'commit' => ['commit'],
            'rollback' => ['rollback'],
            'use' => ['use'],
            'disableFkCheck' => ['disableFkCheck'],
            'enableFkCheck' => ['enableFkCheck'],
            'addHook' => ['addHook'],
        ];
    }

    public function testGetIdReturnsAnInt()
    {
        self::assertSame('int', (string) $this->method('getId')->getReturnType());
    }
}
