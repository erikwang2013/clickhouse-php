# clickhouse-php 代码审查报告

**审查日期**: 2026-08-02
**审查范围**: 全部源码 (src/) 与测试 (tests/)
**测试状态**: 34/34 通过, 51 断言

---

## 一、严重问题 (Critical)

### 1.1 Migration Repository - 表名 SQL 注入风险

**文件**: `src/Migration/Repository.php`
**行号**: 22-29, 34, 39, 45, 50, 55
**严重程度**: Critical
**类别**: 安全

`Repository` 类中所有 SQL 语句使用字符串插值拼接 `$this->table`，该值通过构造函数传入，无任何校验。

```php
// 行22: 直接拼接表名
"CREATE TABLE IF NOT EXISTS {$this->table} (...)"
```

虽然默认值是 `'migrations'`，但调用方可传入任意字符串导致 SQL 注入。ClickHouse 不支持真正的 prepared statements，但应对表名做白名单校验或使用 `quoteTable` 风格的反引号转义。

**建议修复**:
```php
private function quoteTable(): string
{
    return implode('.', array_map(fn($p) => "`$p`", explode('.', $this->table)));
}
```
然后在所有 SQL 语句中使用 `{$this->quoteTable()}` 替代 `{$this->table}`。

---

## 二、高危问题 (High)

### 2.1 Builder 列名未转义

**文件**: `src/Query/Grammar.php`
**行号**: 18, 95, 103-106
**严重程度**: High
**类别**: 安全/健壮性

`compileSelect()` 中 `$builder->columns` 直接拼入 SQL，未用反引号包裹列名。`selectRaw()` 和 `select()` 中的列别名可以正常工作，但普通列名如果是 ClickHouse 保留字会出错。

同样的问题存在于 `compileGroups()` 和 `compileOrders()`。

### 2.2 Builder::from 为空时生成无效 SQL

**文件**: `src/Query/Grammar.php`
**行号**: 19
**严重程度**: High
**类别**: Bug 风险

如果忘记调用 `table()` 就执行查询，生成的 SQL 会是 `SELECT * FROM`，导致 ClickHouse 语法错误。没有前置校验。

**建议**: 在 `compileSelect()` 开头增加校验。

### 2.3 Schema Builder alter() 未校验空 Blueprint

**文件**: `src/Schema/Builder.php`
**行号**: 38-43
**严重程度**: High
**类别**: Bug 风险

`create()` 方法有空列检查（行25-27），但 `alter()` 没有。如果传入空的 callback，会生成无操作的 `ALTER TABLE` 语句。

### 2.4 Manager 连接缓存与连接池互斥

**文件**: `src/Client/Manager.php`
**行号**: 36-44
**严重程度**: High
**类别**: 设计缺陷

`connection()` 方法先检查 `$this->connections` 缓存，再检查 `$this->pools`。一旦连接被创建并缓存，即使后续调用 `setPool()` 配置了连接池，也永远不会走池化路径。

### 2.5 Migration::rollback() 不检查是否有迁移可回滚

**文件**: `src/Migration/Migrator.php`
**行号**: 50-69
**严重程度**: High
**类别**: 健壮性

当 `getLastBatch()` 返回 0（无已执行迁移）时，rollback 静默返回空数组，无任何提示。

---

## 三、中等问题 (Medium)

### 3.1 三处重复的 escape/quote 逻辑

**文件**: 
- `src/Query/Grammar.php:127-136` (`quote()`)
- `src/Client/HttpClient.php:83-89` (`escape()`)
- `src/Transport/HttpTransport.php:90-102` (`quoteValue()`)

**严重程度**: Medium
**类别**: 代码质量

三段代码逻辑几乎完全相同（处理 null/int/float/bool/string 转义），应提取到共享 trait 或工具类。

### 3.2 两处重复的 quoteTable 逻辑

**文件**:
- `src/Query/Grammar.php:122-125`
- `src/Schema/Grammar.php:70-73`
- `src/Client/HttpClient.php:60`

**严重程度**: Medium
**类别**: 代码质量

表名反引号转义逻辑重复三次。

### 3.3 连接池默认参数不一致

**文件**: `src/Pool/WorkermanPool.php:26`, `src/Pool/SwoolePool.php:26`, `src/Pool/SwowPool.php:26`
**严重程度**: Medium
**类别**: 一致性问题

Workerman 池的 `min_connections` 默认为 1，而 Swoole/Swow 默认为 2。

### 3.4 HttpTransport 硬编码 HTTP 协议

**文件**: `src/Transport/HttpTransport.php:25`
**严重程度**: Medium
**类别**: 功能缺失

`base_uri` 硬编码 `http://`，无法使用 HTTPS 连接 ClickHouse（通常端口 8443）。

### 3.5 Model 无 UPDATE 支持

**文件**: `src/ORM/Model.php:51-54`
**严重程度**: Medium
**类别**: 功能缺失

`save()` 方法直接调用 `insert()`，没有判断是新增还是更新。

### 3.6 Collection::map() 返回类型不一致

**文件**: `src/ORM/Collection.php:42-45`
**严重程度**: Medium
**类别**: API 一致性

`filter()` 返回 `static`（可链式调用），但 `map()` 返回 `array`，无法链式调用。

### 3.7 ScrollPool/SwowPool timeout 单位不一致

**文件**: `src/Pool/WorkermanPool.php:33-34`, `src/Pool/SwowPool.php:33-34`
**严重程度**: Medium
**类别**: Bug 风险

Workerman 的 Channel 期望 `float` 秒，Swow 的 Channel 期望 `int` 毫秒。SwowPool 做了 `(int) ($this->connectionTimeout * 1000)` 转换，但 WorkermanPool 直接将 float 传给 Channel。需确认 Workerman Channel API 的时间单位。

---

## 四、低危问题 (Low)

### 4.1 TCP Transport 未实现

**文件**: `src/Transport/TcpTransport.php:22-29`
**严重程度**: Low
**类别**: 功能缺失

`send()` 直接抛出异常 "Native TCP transport not yet implemented"，但 README 和文档中列出了 Native TCP 作为支持的协议。

### 4.2 Migration::down() 默认为空

**文件**: `src/Migration/Migration.php:23-24`
**严重程度**: Low
**类别**: 设计

`down()` 方法体为空。如果用户忘记覆盖，rollback 不会有实际效果但迁移记录会被删除。

### 4.3 Hyperf release() 硬编码连接名

**文件**: `src/Hyperf/ClickHouseConnection.php:48`
**严重程度**: Low
**类别**: Bug

`$pool = $this->poolFactory->getPool('default')` 始终使用 `'default'`。如果使用了非 default 连接，release 时会归还到错误的池。

### 4.4 部分方法缺少返回类型声明

**文件**: 多处
**严重程度**: Low
**类别**: 代码质量

PHP 8.1+ 支持完整的返回类型声明，部分方法未声明（如 Builder 的 `select()`、`where()` 等返回 `static`）。

### 4.5 测试覆盖缺口

**严重程度**: Low
**类别**: 测试

以下路径缺少测试：
- `orWhere()` SQL 生成
- `whereNotIn()` SQL 生成
- `offset()` SQL 生成
- `delete()` SQL 生成
- `alter()` SQL 生成
- `selectRaw()` 多列场景
- `HttpTransport::send()` GuzzleException 非连接异常路径
- `Manager::setPool()` 与连接池交互

---

## 五、优化建议

### 5.1 性能

- Grammar 中列名拼接建议添加反引号处理
- Connection 缓存可引入 TTL 过期机制

### 5.2 架构

- 建议引入 `Connection` 抽象层，将 `HttpClient` + `Transport` + `Config` 统一管理
- 连接池与 Manager 的交互模式需重构，避免缓存与池化互斥
- 为 `Builder` 添加 `reset()` 方法，方便复用实例

### 5.3 文档

- TCP Transport 状态应在 README 中注明 "开发中"
- Migration `down()` 方法的可选性需在文档中强调

---

## 六、总结

| 等级 | 数量 |
|------|------|
| Critical | 1 |
| High | 5 |
| Medium | 7 |
| Low | 5 |

**整体评价**：代码结构清晰，测试覆盖了核心路径（34 测试全部通过）。主要风险集中在 Migration Repository 的 SQL 注入和 Manager 连接池与缓存的互斥问题。三处重复的转义逻辑是维护隐患。建议优先修复 Critical 和 High 级别问题后即可安全用于生产环境。
