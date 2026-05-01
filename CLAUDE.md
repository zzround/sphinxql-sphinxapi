# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A Composer PHP library (`zround/sphinxapi`) that reimplements the classic `SphinxClient` API to use **SphinxQL (MySQL protocol, port 9306)** instead of the deprecated native binary protocol (port 9312). Drop-in replacement — maintains full backward compatibility with the original public method signatures and return value formats.

## Requirements

- PHP 7.4+ with PDO + pdo_mysql extension
- Sphinx/Manticore with SphinxQL enabled (default port 9306)

## Development Commands

```bash
# Install dependencies and generate autoloader
composer install

# Syntax check
php -l src/SphinxClient.php

# Run integration test (requires Sphinx/Manticore at 127.0.0.1:9306)
php test.php
```

## Architecture

Composer package with PSR-4 autoloading. Namespace: `Zround`.

```
src/SphinxClient.php  — Zround\SphinxClient class + namespace-level constants
test.php              — Integration test script
```

The `SphinxClient` class internally translates legacy API calls into SphinxQL queries executed via PDO:

- **Connection**: `_Connect` / `_DisconnectAfterQuery` — PDO + mysql driver, supports TCP and Unix socket, persistent connections
- **Query building**: `_BuildSphinxQL` orchestrates `_buildGroupByExpr`, `_buildOrderBy`, `_buildOptions`, `_translateGroupSort` to produce a `SELECT` statement from internal state (filters, sort, limits, ranking, group-by)
- **Query execution**: `Query` / `AddQuery` / `RunQueries` / `_ExecuteSingleQuery`
- **Result transformation**: `_TransformRows` + `_GetIndexSchema` (DESCRIBE) + `_FetchMeta` (SHOW META) + `_ExtractWordStats` → legacy return format (matches with id/weight/attrs, total, total_found, time, words)
- **SphinxQL helpers**: `BuildExcerpts` → `CALL SNIPPETS`, `BuildKeywords` → `CALL KEYWORDS`, `UpdateAttributes` → `UPDATE`, `Status` → `SHOW STATUS`, `FlushAttributes` → `FLUSH ATTRIBUTES`
- **SQL escaping**: `_quoteAttr` (backtick identifiers), `_quoteIndex` (multi-index), `_pdoQuote` (values via PDO::quote with addslashes fallback)
- **Utility functions**: `sphFixUint()` (32-bit uint handling), `sphSetBit()` (bit flags) — namespace-level functions

Constants (`SEARCHD_OK`, `SPH_RANK_*`, `SPH_SORT_*`, etc.) are namespace-level constants under `Zround\`.

## Known Differences from Original

- Default port: 9306 (was 9312)
- Batch queries via `AddQuery`/`RunQueries` execute sequentially, not single TCP send
- `FlushAttributes` returns 0 (no flush tag available in SphinxQL)
- `SetTokenFilter` unsupported (issues warning)
- `SPH_SORT_TIME_SEGMENTS` uses INTERVAL() approximation
