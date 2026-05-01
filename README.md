# SphinxClient PHP API (SphinxQL)

基于 SphinxQL（MySQL 协议）的 PHP Sphinx searchd 客户端，兼容原始 `sphinxapi.php` 的全部公共方法签名和返回值格式，可直接替换原有调用代码。

A PHP Sphinx searchd client based on SphinxQL (MySQL protocol), fully compatible with the original `sphinxapi.php` public method signatures and return value formats — drop-in replacement.

## 背景 / Background

Sphinx 官方已推荐使用 SphinxQL（TCP 9306 端口，MySQL 协议）替代原生二进制协议（TCP 9312 端口）。本项目将 `SphinxClient` 的内部实现从 `fsockopen` + 二进制打包改为 PDO + SphinxQL，上层代码无需任何修改。

Sphinx officially recommends SphinxQL (TCP port 9306, MySQL protocol) over the native binary protocol (TCP port 9312). This project rewrites `SphinxClient` internals from `fsockopen` + binary packing to PDO + SphinxQL, requiring no changes to upper-layer code.

## 要求 / Requirements

- PHP 7.0+（需启用 PDO + pdo_mysql 扩展 / with PDO and pdo_mysql extensions enabled）
- Sphinx/Manticore Search 开启 SphinxQL 监听（默认端口 9306 / with SphinxQL enabled, default port 9306）

## 安装 / Installation

```bash
composer require zround/sphinxapi
```

## 使用方式 / Usage

```php
require_once 'vendor/autoload.php';

use Zround\SphinxClient;

$cl = new SphinxClient();
$cl->SetServer('127.0.0.1', 9306);
$cl->SetConnectTimeout(3);

// Ping
if ($cl->Ping()) {
    echo "OK\n";
}

// 查询 / Query
$cl->SetLimits(0, 20);
$cl->SetArrayResult(true);
$result = $cl->Query('关键词', '索引名');

print_r($result);
```

## API 方法 / API Methods

### 连接 / Connection

| 方法 / Method | 说明 / Description |
|------|------|
| `SetServer($host, $port)` | 设置服务地址，默认端口 9306 / Set server address, default port 9306 |
| `SetConnectTimeout($timeout)` | 连接超时（秒）/ Connection timeout (seconds) |
| `Open()` | 保持持久连接 / Open persistent connection |
| `Close()` | 关闭持久连接 / Close persistent connection |
| `Ping()` | 检测服务是否存活 / Check if server is alive |

### 查询参数 / Query Parameters

| 方法 / Method | 说明 / Description |
|------|------|
| `SetLimits($offset, $limit, $max, $cutoff)` | 分页与截断 / Pagination and cutoff |
| `SetMaxQueryTime($ms)` | 最大查询时间 / Max query time |
| `SetRankingMode($ranker, $rankexpr)` | 排名模式 / Ranking mode |
| `SetSortMode($mode, $sortby)` | 排序模式 / Sort mode |
| `SetWeights($weights)` | 字段权重（旧接口）/ Field weights (deprecated) |
| `SetFieldWeights($weights)` | 字段权重 / Field weights |
| `SetIndexWeights($weights)` | 索引权重 / Index weights |
| `SetIDRange($min, $max)` | 文档 ID 范围 / Document ID range |
| `SetFilter($attr, $values, $exclude)` | 整数过滤 / Integer filter |
| `SetFilterRange($attr, $min, $max, $exclude)` | 范围过滤 / Range filter |
| `SetFilterFloatRange($attr, $min, $max, $exclude)` | 浮点范围过滤 / Float range filter |
| `SetFilterString($attr, $value, $exclude)` | 字符串过滤 / String filter |
| `SetFilterStringList($attr, $value, $exclude)` | 字符串列表过滤 / String list filter |
| `SetGroupBy($attr, $func, $groupsort)` | 分组 / Grouping |
| `SetGroupDistinct($attr)` | 去重计数 / Distinct count |
| `SetRetries($count, $delay)` | 重试 / Retries |
| `SetArrayResult($on)` | 结果用数字索引 / Use numeric index for results |
| `SetSelect($select)` | 自定义 SELECT 列 / Custom SELECT columns |
| `SetQueryFlag($name, $value)` | 查询标志位 / Query flags |
| `SetOuterSelect($orderby, $offset, $limit)` | 外层子查询 / Outer subquery |
| `ResetFilters()` | 重置过滤 / Reset filters |
| `ResetGroupBy()` | 重置分组 / Reset group-by |
| `ResetQueryFlag()` | 重置查询标志 / Reset query flags |
| `ResetOuterSelect()` | 重置外层查询 / Reset outer select |

### 执行查询 / Query Execution

| 方法 / Method | 说明 / Description |
|------|------|
| `Query($query, $index, $comment)` | 单次查询 / Single query |
| `AddQuery($query, $index, $comment)` | 添加到批量队列 / Add to batch queue |
| `RunQueries()` | 执行批量队列 / Execute batch queue |

### 辅助方法 / Helper Methods

| 方法 / Method | 说明 / Description |
|------|------|
| `BuildExcerpts($docs, $index, $words, $opts)` | 高亮摘要 / Build excerpts |
| `BuildKeywords($query, $index, $hits)` | 关键词分词 / Tokenize keywords |
| `EscapeString($string)` | Sphinx 查询语法转义 / Escape Sphinx query syntax |
| `UpdateAttributes($index, $attrs, $values, ...)` | 即时更新属性 / Update attributes in-place |
| `Status($session)` | 服务状态 / Server status |
| `FlushAttributes()` | 刷写属性到磁盘 / Flush attributes to disk |
| `GetLastError()` | 最近错误 / Last error |
| `GetLastWarning()` | 最近警告 / Last warning |
| `IsConnectError()` | 是否连接错误 / Whether connection error |

## 与原版的差异 / Differences from Original

| 项目 / Item | 说明 / Description |
|------|------|
| 默认端口 / Default port | 9312 → 9306 |
| 批量查询 / Batch queries | 原版单次 TCP 发送全部查询，现改为逐条执行 / Originally sent all queries in one TCP call, now executes sequentially |
| FlushAttributes | 无法返回 flush tag，返回 0 / Cannot return flush tag, returns 0 |
| SetTokenFilter | 无 SphinxQL 等价，调用时设警告忽略 / No SphinxQL equivalent, issues warning and ignores |
| SPH_SORT_TIME_SEGMENTS | 用 INTERVAL() 近似，行为有差异 / Uses INTERVAL() approximation, behavior differs |

## 文件 / Files

- `src/SphinxClient.php` — Zround\SphinxClient 类实现 / Class implementation
