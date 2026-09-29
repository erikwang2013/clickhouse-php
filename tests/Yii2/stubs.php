<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


declare(strict_types=1);

/*
 * 最小 Yii2 替身：本包不依赖 yiisoft/yii2，测试环境里没有真的 yii\base\*，
 * 就用这里的实现验证适配器行为。装了真 yii2 的机器不会加载本文件
 * （见 AdapterTest.php 的 class_exists 判断）。
 *
 * 这些类不带 yii\ 的 PSR-4 映射，故意不能自动加载：只有测试会 require 它。
 */

namespace yii\base {

    class BaseObject
    {
        public function __construct(array $config = [])
        {
            foreach ($config as $name => $value) {
                $this->$name = $value;
            }

            $this->init();
        }

        public function init(): void
        {
        }

        public function __get(string $name): mixed
        {
            $getter = 'get' . $name;

            if (method_exists($this, $getter)) {
                return $this->$getter();
            }

            throw new \Exception('Getting unknown property: ' . static::class . '::' . $name);
        }
    }

    interface BootstrapInterface
    {
        public function bootstrap($app);
    }
}

namespace Erikwang2013\ClickHouse\Tests\Yii2\Stub {

    /**
     * 复刻 yii\di\ServiceLocator 与 Yii::createObject 中与本适配器相关的那部分行为。
     *
     * 重点是 set() 的入参校验：裸类名字符串不是 callable，会被拒——所以适配器必须写
     * ['class' => ...]，不能写 ClickHouse::class。
     */
    class Application
    {
        private array $definitions = [];

        public function has(string $id): bool
        {
            return isset($this->definitions[$id]);
        }

        public function set(string $id, mixed $definition): void
        {
            if (is_array($definition) && isset($definition['class'])) {
                $this->definitions[$id] = $definition;

                return;
            }

            if (is_object($definition) || (is_string($definition) && is_callable($definition))) {
                $this->definitions[$id] = $definition;

                return;
            }

            throw new \InvalidArgumentException(
                "The configuration for the \"$id\" component must contain a \"class\" element.",
            );
        }

        public function get(string $id): object
        {
            if (!isset($this->definitions[$id])) {
                throw new \InvalidArgumentException("Unknown component: $id");
            }

            $definition = $this->definitions[$id];

            if (is_object($definition)) {
                return $definition;
            }

            if (is_string($definition)) {
                return $definition();
            }

            $class = $definition['class'];
            unset($definition['class']);

            $object = new $class();

            foreach ($definition as $name => $value) {
                $object->$name = $value;
            }

            return $object;
        }

        public function definition(string $id): mixed
        {
            return $this->definitions[$id] ?? null;
        }
    }
}
