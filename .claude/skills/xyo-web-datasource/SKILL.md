---
name: xyo-web-datasource
description: Create and use XYO.Web data sources — Table descriptors, Query (joined) descriptors, and the load/save/where/order API. Use when adding a database table, defining a joined read model, or writing CRUD/query code in an XYO.Web PHP project (classes extending \XYO\Web\DataSource\Table or \XYO\Web\DataSource\Query, files under a _site/data-source folder, config with a "dataSource" connection).
---

# XYO.Web Data Sources (Table & Query)

XYO.Web is a PHP framework. A **data source** is a plain PHP class whose static
`descriptor()` method describes a database schema. The framework turns the
descriptor into DDL (CREATE TABLE) and parameterized SQL at runtime, across
SQLite / MySQL / PostgreSQL, so you never write SQL by hand.

There are two kinds:

- **Table** — one physical table. Read/write a single row set, CRUD, DDL. Extends `\XYO\Web\DataSource\Table`.
- **Query** — a read-only joined view over a base table plus LEFT OUTER JOINed tables. Extends `\XYO\Web\DataSource\Query`.

## Where files go & how they are named

Data-source classes live together in a data-source folder (in the example:
`_site/data-source/`) and use a shared namespace (the example uses
`namespace DataSource;`). One class per file, kebab-cased filename matching the
class:

- `table-user.php` → class `TableUser`
- `table-counter.php` → class `TableCounter`
- `query-user.php` → class `QueryUser`

Every file starts with the framework guard and namespace:

```php
<?php

namespace DataSource;

defined("XYO_WEB") or die("Forbidden");
```

## 1. Table descriptor

Declare each column as a **public property** (so callers can read/write it) and
describe the schema in `descriptor()`:

```php
class TableUser extends \XYO\Web\DataSource\Table
{
    public $id;
    public $counter_id;
    public $name;

    public static function descriptor($info)
    {
        $info->name = "user";           // table name (a connection prefix is prepended automatically)
        $info->primaryKey = "id";       // primary-key field, or null for none
        $info->fields = array(
            "id"         => array("bigint", "DEFAULT", "unsigned", "autoIncrement"),
            "counter_id" => array("bigint", 0, "unsigned"),
            "name"       => array("varchar", null, 255)
        );
        // Optional single-column indexes (index name becomes "<table>_<field>"):
        // $info->indexes = array("counter_id", "name");
    }
}
```

### Field-descriptor array format

Each field is `array(type, default, arg3, arg4)`. Trailing elements are
optional — supply only as many as you need.

| Position | Meaning | Notes |
|---|---|---|
| `[0]` type | `"bigint"`, `"int"`, `"varchar"`, `"datetime"` (other SQL types pass through as-is) | required |
| `[1]` default | column default | see rules below |
| `[2]` arg3 | for `varchar` = **length**; for numeric = a **modifier** such as `"unsigned"` | |
| `[3]` arg4 | `"autoIncrement"` for the auto key, or another modifier (upper-cased into the DDL) | |

**Default value (`[1]`) rules:**
- `"DEFAULT"` — omit the column on INSERT and let the database assign it. Use this for an auto-increment primary key.
- `null` — no default / nullable.
- an integer (e.g. `0`) — numeric `DEFAULT 0`.
- a string — quoted string default.

**Type notes:**
- An **auto-increment primary key** must be `int`/`bigint` with `"autoIncrement"` as `[3]`. On SQLite this maps to `INTEGER PRIMARY KEY AUTOINCREMENT`; MySQL `AUTO_INCREMENT`; PostgreSQL `SERIAL`/`BIGSERIAL`. After `insert()`/`save()` the generated id is written back onto the object.
- `varchar` uses `[2]` as its length: `array("varchar", null, 255)`.
- `datetime` is portable across the three drivers.

### Using a Table

Obtain an instance from any Module/Page/Component via `getDataSource()` — never
`new` it directly (it needs a live connection):

```php
$user = $this->getDataSource(\DataSource\TableUser::class);
$user->createStorage();      // CREATE TABLE IF NOT EXISTS (+ indexes)

// --- create / update (save = UPDATE when primary key is set, else INSERT) ---
$user->clear();
$user->counter_id = 1;
$user->name = "Hello";
$user->save();               // $user->id now holds the new auto id

// --- read one row by example (set the fields you want to match, then load) ---
$user->clear();
$user->id = 1;
if ($user->load(0, 1)) {     // load(start, length); loads first matching row
    echo $user->name;
}

// --- iterate a result set ---
$user->clear();
for ($user->load(0, 100); $user->loadIsValid(); $user->loadNext()) {
    echo $user->name . "\n";
}

// --- delete (by primary key if set, else by the non-empty example fields) ---
$user->clear();
$user->id = 1;
$user->delete();
```

**Key semantics**

- `clear()` resets every field to the "empty" marker so nothing is treated as a WHERE condition. **Always `clear()` before building a fresh operation.** `clear("field")` clears just one field (and any order/group/function on it).
- Any field left non-empty becomes an `AND field = value` in the WHERE clause. Assigning an **array** to a field means `field IN (...)` (OR of equalities).
- `load($start, $length)` runs the SELECT and fetches the first row; `loadIsValid()` reports whether a row was loaded; `loadNext()` advances; `loadHasNext()` peeks.
- `count()` returns the row count for the current WHERE/group.
- `save()` / `insert()` / `delete()` / `update([...])` / `select([...])` perform writes and projections.
- Atomic helpers: `atomicIncrement($field)`, `atomicAdd($field, $n)`, `atomicSub($field, $n)` (transaction-wrapped, require a primary-key value).

### WHERE operators, ordering, grouping, aggregates

Beyond example-field matching you can add explicit operators:

```php
use \XYO\Web\DataSource\Order;

$user->clear();
$user->pushOperator("(");
$user->setOperator("counter_id", ">", 5);
$user->pushOperator("or");
$user->setOperator("name", "like", "Hel");
$user->pushOperator(")");
$user->setOrder("name", Order::ASCENDENT);   // ASCENDENT | DESCENDENT | NONE
$user->setGroup("counter_id", true);
$user->setFunctionAs("id", "COUNT", "total"); // adds COUNT(id) AS total
$user->load(0, 50);
```

`setOperator($key, $op, $v1, $v2, $v1IsKey, $v2IsKey)` supported `$op` values:
`=`, `!=`, `<`, `>`, `<=`, `>=`, `between`, `not-between`, `is-null`,
`is-not-null`, `like`. `pushOperator("and"|"or"|"("|")")` joins/groups
conditions. Aggregate functions for `setFunctionAs`: `COUNT`, `SUM`, `AVG`,
`MIN`, `MAX`. All values are bound as prepared-statement parameters — never
string-concatenated — so descriptors are injection-safe by construction.

## 2. Query descriptor (joins)

A Query is a **read-only** joined model. Declare a public property for every
field it exposes, then describe a base table and zero or more outer (LEFT
OUTER JOIN) tables:

```php
class QueryUser extends \XYO\Web\DataSource\Query
{
    public $id;
    public $counter_id;
    public $name;
    public $count;

    public static function descriptor($info)
    {
        // base: [ alias, TableClass, fields ]
        //   fields = "*"  → all of the table's fields
        //          | array(exposedName => tableFieldName, ...)
        $info->base = array("user", TableUser::class, "*");

        // outer: joinAlias => [ TableClass, fields, joinCondition ]
        //   fields         = "*" or array(exposedName => tableFieldName)
        //   joinCondition  = array(thisField, array(otherAlias, otherField))
        //                    → JOIN ON  <joinAlias>.thisField = <otherAlias>.otherField
        $info->outer = array(
            "counter" => array(
                TableCounter::class,
                array("count" => "count"),
                array("id", array("user", "counter_id"))
            )
        );
    }
}
```

This produces roughly:
`SELECT user_.id AS id, ..., counter_.count AS count
 FROM counter_table AS user_ LEFT OUTER JOIN counter_table AS counter_
 ON counter_.id = user_.counter_id`

Using a Query is like a Table but read-only (`load`, `loadNext`,
`loadIsValid`, `count`, `setOperator`, `setOrder`, `setGroup`,
`setFunctionAs`, `clear` — no `save`/`delete`):

```php
$q = $this->getDataSource(\DataSource\QueryUser::class);
for ($q->load(); $q->loadIsValid(); $q->loadNext()) {
    echo $q->id . " " . $q->name . " " . $q->count . "\n";
}
```

## 3. Connection & config

Tables/queries are resolved through a named connection defined in the site
config. `getDataSource($class)` uses the default connection `"db"`;
`getDataSource($class, "other")` selects another.

```php
// config.php
return array(
    "dataSource" => array(
        "connection" => array(
            "db" => array(
                "type"     => "sqlite",              // sqlite | mysql | postgresql
                "database" => "./_repository/example.sqlite",
                "prefix"   => ""                     // prepended to every table name
            )
        )
    )
);
```

## Schema lifecycle helpers (on a Table)

- `createStorage()` — CREATE TABLE IF NOT EXISTS plus declared indexes. Safe to call every request (typical in a Page/Component `init()` or a setup service).
- `destroyStorage()` / `recreateStorage()` — DROP, and DROP+CREATE.
- `storageCheckTable()` — true when the live schema matches the descriptor.
- `storageUpdateTable()` — migrate the live schema toward the descriptor (add/drop/retype columns, reconcile indexes). Back up first; it may rebuild the table.
- `storageRemoveField($name)`, `storageRenameField($old,$new)`, `storageUpdateField($name)` — targeted column changes.

## Checklist for adding a new table

1. Create `table-<name>.php` in the data-source folder, `namespace DataSource;`, with the `defined("XYO_WEB")` guard.
2. `class Table<Name> extends \XYO\Web\DataSource\Table`.
3. Add a `public $field;` for each column.
4. In `descriptor($info)` set `$info->name`, `$info->primaryKey`, `$info->fields` (and `$info->indexes` if needed).
5. Wherever the table is first used, call `getDataSource(Table<Name>::class)` then `createStorage()`.
6. To expose a joined read model, add a matching `query-<name>.php` extending `\XYO\Web\DataSource\Query`.

## Common mistakes

- Forgetting `clear()` before a new load/save — stale field values silently become WHERE conditions or get written.
- `new Table(...)` directly — always go through `getDataSource()`.
- Using `"DEFAULT"` as the default for a non-auto column when you meant a real value — `"DEFAULT"` makes INSERT omit the column.
- Putting the varchar length in `[3]` instead of `[2]` — for `varchar`, length is `[2]`.
- Expecting to write through a Query — Queries are read-only; write via the underlying Table.

See the sibling **xyo-web-ui** skill for building the Pages/Components that consume these data sources.
