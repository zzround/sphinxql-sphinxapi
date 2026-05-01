# SphinxClient PHP API (SphinxQL)

基于 SphinxQL（MySQL 协议）的 PHP Sphinx searchd 客户端，兼容原始 `sphinxapi.php` 的全部公共方法签名和返回值格式，可直接替换原有调用代码。

## 背景

Sphinx 官方已推荐使用 SphinxQL（TCP 9306 端口，MySQL 协议）替代原生二进制协议（TCP 9312 端口）。本项目将 `SphinxClient` 的内部实现从 `fsockopen` + 二进制打包改为 PDO + SphinxQL，上层代码无需任何修改。

## 要求

- PHP 7.4+（需启用 PDO + pdo_mysql 扩展）
- Sphinx/Manticore Search 开启 SphinxQL 监听（默认端口 9306）

## 安装

```bash
composer require zround/sphinxapi
```

## 使用方式

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

// 查询
$cl->SetLimits(0, 20);
$cl->SetArrayResult(true);
$result = $cl->Query('关键词', '索引名');

print_r($result);
```

## API 方法

### 连接

| 方法 | 说明 |
|------|------|
| `SetServer($host, $port)` | 设置服务地址，默认端口 9306 |
| `SetConnectTimeout($timeout)` | 连接超时（秒） |
| `Open()` | 保持持久连接 |
| `Close()` | 关闭持久连接 |
| `Ping()` | 检测服务是否存活 |

### 查询参数

| 方法 | 说明 |
|------|------|
| `SetLimits($offset, $limit, $max, $cutoff)` | 分页与截断 |
| `SetMaxQueryTime($ms)` | 最大查询时间 |
| `SetRankingMode($ranker, $rankexpr)` | 排名模式 |
| `SetSortMode($mode, $sortby)` | 排序模式 |
| `SetWeights($weights)` | 字段权重（旧接口） |
| `SetFieldWeights($weights)` | 字段权重 |
| `SetIndexWeights($weights)` | 索引权重 |
| `SetIDRange($min, $max)` | 文档 ID 范围 |
| `SetFilter($attr, $values, $exclude)` | 整数过滤 |
| `SetFilterRange($attr, $min, $max, $exclude)` | 范围过滤 |
| `SetFilterFloatRange($attr, $min, $max, $exclude)` | 浮点范围过滤 |
| `SetFilterString($attr, $value, $exclude)` | 字符串过滤 |
| `SetFilterStringList($attr, $value, $exclude)` | 字符串列表过滤 |
| `SetGroupBy($attr, $func, $groupsort)` | 分组 |
| `SetGroupDistinct($attr)` | 去重计数 |
| `SetRetries($count, $delay)` | 重试 |
| `SetArrayResult($on)` | 结果用数字索引 |
| `SetSelect($select)` | 自定义 SELECT 列 |
| `SetQueryFlag($name, $value)` | 查询标志位 |
| `SetOuterSelect($orderby, $offset, $limit)` | 外层子查询 |
| `ResetFilters()` | 重置过滤 |
| `ResetGroupBy()` | 重置分组 |
| `ResetQueryFlag()` | 重置查询标志 |
| `ResetOuterSelect()` | 重置外层查询 |

### 执行查询

| 方法 | 说明 |
|------|------|
| `Query($query, $index, $comment)` | 单次查询 |
| `AddQuery($query, $index, $comment)` | 添加到批量队列 |
| `RunQueries()` | 执行批量队列 |

### 辅助方法

| 方法 | 说明 |
|------|------|
| `BuildExcerpts($docs, $index, $words, $opts)` | 高亮摘要 |
| `BuildKeywords($query, $index, $hits)` | 关键词分词 |
| `EscapeString($string)` | Sphinx 查询语法转义 |
| `UpdateAttributes($index, $attrs, $values, ...)` | 即时更新属性 |
| `Status($session)` | 服务状态 |
| `FlushAttributes()` | 刷写属性到磁盘 |
| `GetLastError()` | 最近错误 |
| `GetLastWarning()` | 最近警告 |
| `IsConnectError()` | 是否连接错误 |

## 与原版的差异

| 项目 | 说明 |
|------|------|
| 默认端口 | 9312 → 9306 |
| 批量查询 | 原版单次 TCP 发送全部查询，现改为逐条执行 |
| FlushAttributes | 无法返回 flush tag，返回 0 |
| SetTokenFilter | 无 SphinxQL 等价，调用时设警告忽略 |
| SPH_SORT_TIME_SEGMENTS | 用 INTERVAL() 近似，行为有差异 |

## 文件

- `src/SphinxClient.php` — Zround\SphinxClient 类实现
- `test.php` — 简单测试脚本
