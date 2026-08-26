# clickhouse-php 单元测试报告

**报告日期**: 2026-08-27
**执行方式**: 测试团队(6 名 PHP 测试工程师并行,按模块域划分)
**测试框架**: PHPUnit 10.5.63 / PHP 8.3.7 / Mockery 1.5 / pcov
**基准**: 51 tests / 71 assertions(全绿)
**结果**: **317 tests / 553 assertions,全部通过**

---

## 一、总览

| 指标 | 基准 | 本次 | 变化 |
|------|------|------|------|
| 测试数 | 51 | 317 | +266 |
| 断言数 | 71 | 553 | +482 |
| 类覆盖率 | — | 56.1% (23/41) | — |
| 方法覆盖率 | — | 84.5% (196/232) | — |
| 行覆盖率 | — | 70.8% (587/829) | — |

新增 16 个测试文件,扩展 6 个既有测试文件。覆盖缺口全部为框架集成类(Laravel/Webman/ThinkPHP/Hyperf,依赖未安装的框架基类)与接口类,详见第五节。

## 二、各模块覆盖率

### 100% 行+方法覆盖率(22 类)

| 模块 | 类 | 方法 / 行 |
|------|-----|----------|
| Core | ClickHouse | 6/6, 13/13 |
| Query | Builder / Grammar / Expression / Result | 28/28, 62/62 · 8/8, 54/54 · 3/3 · 10/10 |
| Schema | Blueprint / Column / Grammar / Builder | 36/36, 38/38 · 2/2 · 7/7, 35/35 · 7/7, 17/17 |
| Pool | NoPool / SwoolePool / SwowPool / WorkermanPool | 5/5, 13/13 · 5/5, 23/23 · 5/5, 22/22 · 5/5, 23/23 |
| ORM | Model / Collection | 13/13, 17/17 · 13/13 |
| Migration | Migration / Migrator / Repository | 2/2 · 7/7, 56/56 · 9/9, 28/28 |
| Support | Arr / Config / Quoter | 1/1 · 3/3 · 3/3, 14/14 |
| Transport | TcpTransport | 3/3, 7/7 |
| Exceptions | QueryException | 3/3 |

### 部分覆盖(既有测试,未扩展)

| 类 | 方法 | 行 | 缺口原因 |
|----|------|-----|---------|
| HttpTransport | 83.3% (5/6) | 97.1% (67/69) | 无效 JSON 分支会触发 PHP warning,无实际行为 |
| HttpClient | 50% (3/6) | 85.7% (30/35) | 既有测试仅覆盖主路径 |
| Manager | 40% (2/5) | 84.2% (16/19) | 同上 |
| PooledClient | 40% (2/5) | 30.8% (4/13) | 同上 |

## 三、关键技术方案

- **SwoolePool**: 本机已装 swoole 扩展,`class_alias` 不可用,改为在真实 `Swoole\Coroutine\run()` 协程内测试(验证了真实 Channel 的 push/pop/close 语义);`connection_timeout => 0.01` 避免默认 5s 超时拖慢测试。
- **SwowPool / WorkermanPool**: 扩展未安装,用 `tests/Pool/ChannelStub.php` + `class_alias` 在测试内注册 stub Channel(带 `class_exists($target, false)` 守卫);若将来装了真实扩展自动回退为 skip。
- **Migrator**: 临时迁移文件放 `sys_get_temp_dir()` + uniqid,tearDown 清理;用 Mockery 断言注入的 Client 收到的确切 SQL。
- **静态状态隔离**: `ClickHouse` 门面的静态 manager 用 `ReflectionProperty` 在 setUp/tearDown 重置,避免用例间串扰。

## 四、src 中发现的问题(未修复,超出本次范围)

| # | 文件:行 | 严重度 | 描述 |
|---|---------|--------|------|
| 1 | `src/ORM/Model.php:80` | **高** | `where()` 无条件转发 3 个参数,文档中的 2 参形式 `Model::where('id', 1)` 会以 `operator='1'` 进入 Builder → 抛错;`find()`(行 63)连带失效。修复:改为 `func_get_args()` 转发 |
| 2 | `src/Migration/Migrator.php:107,116` | **高** | `resolve()` 以 1 个参数构造 `QueryException`,但其构造器要求 2 参(message + sql) → ArgumentCountError;`run()` 中被吞并重包(报错信息误导),`rollback()` 中直接泄漏 |
| 3 | `src/Query/Builder.php:51-63` | **中** | `where()` 允许 `in/not in/between/not between` 操作符但一律推入 `type: basic`,`Grammar` 只按子句类型处理这些操作符 → `where('level', 'in', [...])` 编译出 `WHERE \`level\` in 'Array'`(触发 PHP 警告)。修复:要么拒绝这些操作符,要么映射到对应子句类型 |
| 4 | `src/Query/Result.php:19` | 低 | `$rowCount ?: count($data)` 把显式 0 与"未提供"混为一谈,`new Result($rows, 0)` 被静默覆盖 |
| 5 | `src/Schema/Column.php:21` | 低 | `toSql()` 直接插值列名,含反引号的列名产生非法 SQL(表名走 Quoter 转义,列名没有) |
| 6 | `src/Schema/Grammar.php:74` | 低 | `compileTableList` 手动包反引号,无转义,与 Quoter 不一致 |
| 7 | `src/Support/Str.php` | 提示 | 空占位类,无任何方法 — 疑似未完成工作 |

> 注:第 1、2 条为"测试编码了实际(错误)行为" — 修复 src 后需同步更新对应测试断言。

## 五、未覆盖范围与原因

- **框架集成类**(`src/Laravel/*`、`src/ThinkPHP/*`、`src/Webman/*`、`src/Hyperf/*`):继承未安装的框架基类(`Illuminate\Support\ServiceProvider`、`think\Service` 等,composer 中为 suggest)。在当前环境无法加载,不做 stub 伪造。如需覆盖,先 `composer require --dev` 对应框架。
- **接口类**(`ClientInterface`、`PoolInterface`、`TransportInterface`):无行为可测。
- **无效 JSON 解析路径**(HttpTransport):触发 PHP warning,无有意义分支。

## 六、运行方式

```bash
# 全量
vendor/bin/phpunit
# 覆盖率(文本)
vendor/bin/phpunit --coverage-text
# 单模块
vendor/bin/phpunit --no-coverage tests/Query
```

## 七、新增/变更文件清单

**新增(16)**: `tests/ClickHouseTest.php`、`tests/Exceptions/ExceptionTest.php`、`tests/Migration/{MigratorTest,RepositoryTest}.php`、`tests/ORM/CollectionTest.php`、`tests/Pool/{ChannelStub,ChannelPoolTest}.php`、`tests/Query/{GrammarTest,ExpressionTest,ResultTest}.php`、`tests/Schema/{BlueprintTest,ColumnTest,GrammarTest}.php`、`tests/Support/{StrTest,ArrTest,QuoterTest}.php`、`tests/Transport/TcpTransportTest.php`

**扩展(6)**: `tests/ORM/ModelTest.php`、`tests/Pool/PoolTest.php`、`tests/Query/BuilderTest.php`、`tests/Schema/BuilderTest.php`、`tests/Support/ConfigTest.php`、`tests/Transport/HttpTransportTest.php`

**建议的下一步**: 修复第四节问题 1、2(高严重度,破坏公开 API),其余按优先级处理。
