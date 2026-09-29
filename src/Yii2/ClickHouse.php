<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


declare(strict_types=1);

namespace Erikwang2013\ClickHouse\Yii2;

use Erikwang2013\ClickHouse\ClickHouse as Facade;
use Erikwang2013\ClickHouse\Client\Manager;
use yii\base\BaseObject;

/**
 * Yii2 应用组件，注册成 Yii::$app->clickhouse 后查询构建器、Schema、迁移、ORM 都能用。
 *
 * 零配置（读 CLICKHOUSE_* 环境变量，见 README「环境变量」）：
 *
 *     'components' => [
 *         'clickhouse' => \Erikwang2013\ClickHouse\Yii2\ClickHouse::class,
 *     ],
 *
 * 多连接或自定义参数时，把 Manager 的配置数组交给 config：
 *
 *     'components' => [
 *         'clickhouse' => [
 *             'class' => \Erikwang2013\ClickHouse\Yii2\ClickHouse::class,
 *             'config' => require __DIR__ . '/clickhouse.php',
 *         ],
 *     ],
 *
 * 不想改 components 的话，改用 {@see Bootstrap} 一行接入。
 *
 * 基类用 BaseObject 而不是 Component：Component::__call 被 behaviors 占用，
 * 而这里要用 __call 转发静态门面的全部方法（连 behaviors 对本组件也没什么用）。
 */
class ClickHouse extends BaseObject
{
    /** Manager 配置数组；留空则用 CLICKHOUSE_* 环境变量。 */
    public array $config = [];

    private ?Manager $manager = null;

    public function getManager(): Manager
    {
        if ($this->manager === null) {
            $this->manager = $this->config === [] ? Manager::fromEnv() : new Manager($this->config);
            // 与 Laravel / ThinkPHP 适配器一致：容器入口与静态门面共用同一个 Manager。
            Facade::setManager($this->manager);
        }

        return $this->manager;
    }

    /**
     * 转发静态门面调用，于是 Yii::$app->clickhouse->table('logs')->get()
     * 与 ClickHouse::table('logs')->get() 等价。
     */
    public function __call(string $name, array $params): mixed
    {
        $this->getManager();

        return Facade::$name(...$params);
    }
}
