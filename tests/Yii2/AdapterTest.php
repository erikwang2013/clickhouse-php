<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


declare(strict_types=1);

namespace Erikwang2013\ClickHouse\Tests\Yii2;

use Erikwang2013\ClickHouse\ClickHouse as Facade;
use Erikwang2013\ClickHouse\Client\Manager;
use Erikwang2013\ClickHouse\Tests\Yii2\Stub\Application;
use Erikwang2013\ClickHouse\Yii2\Bootstrap;
use Erikwang2013\ClickHouse\Yii2\ClickHouse;
use PHPUnit\Framework\TestCase;

// 必须在引用适配器类之前：yii\base\BaseObject 是它的父类，类声明时就要能解析到。
if (!class_exists(\yii\base\BaseObject::class)) {
    require_once __DIR__ . '/stubs.php';
}

class AdapterTest extends TestCase
{
    private const ENV_KEYS = ['CLICKHOUSE_CONNECTION', 'CLICKHOUSE_HOST', 'CLICKHOUSE_DB'];

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->envBackup[$key] = getenv($key);
        }

        putenv('CLICKHOUSE_HOST=10.0.0.9');
        putenv('CLICKHOUSE_DB=logs');
        $this->resetFacade();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            $value === false ? putenv($key) : putenv("$key=$value");
        }

        $this->resetFacade();
    }

    private function resetFacade(): void
    {
        $ref = new \ReflectionProperty(Facade::class, 'manager');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    /** 断言 Manager 实际吃到的配置，避免为了验证配置去真连一次服务器。 */
    private function managerConfig(Manager $manager): array
    {
        $ref = new \ReflectionProperty(Manager::class, 'config');
        $ref->setAccessible(true);

        return $ref->getValue($manager);
    }

    /**
     * 零配置路径：bootstrap 注册组件，组件按 CLICKHOUSE_* 环境变量建 Manager，
     * 顺带把 Manager 交给静态门面。
     */
    public function testBootstrapRegistersComponentAndWiresFacade(): void
    {
        $app = new Application();
        (new Bootstrap())->bootstrap($app);

        self::assertTrue($app->has('clickhouse'));

        $definition = $app->definition('clickhouse');
        self::assertSame(ClickHouse::class, $definition['class'], '裸类名字符串会被 ServiceLocator::set() 拒绝');

        $component = $app->get('clickhouse');
        self::assertInstanceOf(ClickHouse::class, $component);
        self::assertSame($component->manager, Facade::getManager(), '门面应与组件共用同一个 Manager');

        $config = $this->managerConfig($component->manager);
        self::assertSame('10.0.0.9', $config['connections']['default']['host']);
        self::assertSame('logs', $config['connections']['default']['database']);
    }

    /** 应用自己配了组件时，bootstrap 不能覆盖它。 */
    public function testBootstrapKeepsApplicationConfiguredComponent(): void
    {
        $app = new Application();
        $app->set('clickhouse', [
            'class' => ClickHouse::class,
            'config' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'http', 'host' => 'ch.internal', 'port' => 8123],
                ],
            ],
        ]);

        (new Bootstrap())->bootstrap($app);

        $config = $this->managerConfig($app->get('clickhouse')->manager);
        self::assertSame('ch.internal', $config['connections']['default']['host'], '显式配置应优先于环境变量');
    }

    /** __call 把门面调用转发到本组件的 Manager：编译 SQL 不需要真的连服务器。 */
    public function testComponentForwardsFacadeCalls(): void
    {
        $component = new ClickHouse();

        $sql = $component->table('events')->where('level', 'error')->toSql();

        self::assertStringContainsString('events', $sql);
        self::assertStringContainsString('WHERE', strtoupper($sql));
    }

    /** config 与 fromEnv 二选一：给了 config 就不读环境变量。 */
    public function testExplicitConfigDoesNotReadEnvironment(): void
    {
        $component = new ClickHouse([
            'config' => [
                'default' => 'analytics',
                'connections' => [
                    'analytics' => ['driver' => 'http', 'host' => 'explicit.host', 'port' => 8123],
                ],
            ],
        ]);

        $config = $this->managerConfig($component->manager);
        self::assertSame('analytics', $config['default']);
        self::assertSame('explicit.host', $config['connections']['analytics']['host']);
        self::assertNotContains('10.0.0.9', array_column($config['connections'], 'host'), '环境变量不该混进来');
        self::assertSame($component->manager, Facade::getManager());
    }
}
