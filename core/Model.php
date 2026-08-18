<?php

declare(strict_types=1);

namespace Core;

/**
 * Active Record style base model.
 */
abstract class Model
{
    protected static string $table = '';

    protected static string $primaryKey = 'id';

    /** @var array<string, mixed> */
    protected array $attributes = [];

    /** @var array<string, mixed> */
    protected array $original = [];

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
        $this->original = $this->attributes;
    }

    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
        return $this;
    }

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * Required so empty()/isset()/?? work on magic attributes.
     * Without this, empty($model->is_default) is always true even when the
     * DB value is 1, because PHP never calls __get for empty()/isset().
     */
    public function __isset(string $name): bool
    {
        // isset() (not array_key_exists): null attributes must be "not set" so
        // empty()/?? behave like normal properties and views show defaults.
        return isset($this->attributes[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->attributes[$name]);
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function getKey(): mixed
    {
        return $this->attributes[static::$primaryKey] ?? null;
    }

    public static function find(int|string $id): ?static
    {
        $table = static::$table;
        $pk = static::$primaryKey;

        $row = Database::getInstance()->fetchOne(
            "SELECT * FROM `{$table}` WHERE `{$pk}` = ? LIMIT 1",
            [$id]
        );

        return $row ? new static($row) : null;
    }

    /** @return array<int, static> */
    public static function all(int $limit = 100, int $offset = 0): array
    {
        $table = static::$table;
        $rows = Database::getInstance()->fetchAll(
            "SELECT * FROM `{$table}` LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        return array_map(fn ($row) => new static($row), $rows);
    }

    public function save(): bool
    {
        $db = Database::getInstance();
        $table = static::$table;
        $pk = static::$primaryKey;

        if ($this->getKey() !== null) {
            $data = array_diff_assoc($this->attributes, $this->original);
            unset($data[$pk]);

            if (empty($data)) {
                return true;
            }

            $db->update($table, $data, "`{$pk}` = ?", [$this->getKey()]);
            $this->original = $this->attributes;
            return true;
        }

        $data = $this->attributes;
        unset($data[$pk]);

        $id = $db->insert($table, $data);
        $this->attributes[$pk] = $id;
        $this->original = $this->attributes;

        return true;
    }

    public function delete(): bool
    {
        $pk = static::$primaryKey;
        $key = $this->getKey();

        if ($key === null) {
            return false;
        }

        Database::getInstance()->delete(static::$table, "`{$pk}` = ?", [$key]);
        return true;
    }

    /** @return array<int, static> */
    public static function where(string $column, mixed $value, string $operator = '='): array
    {
        $table = static::$table;
        $rows = Database::getInstance()->fetchAll(
            "SELECT * FROM `{$table}` WHERE `{$column}` {$operator} ?",
            [$value]
        );

        return array_map(fn ($row) => new static($row), $rows);
    }

    public static function firstWhere(string $column, mixed $value): ?static
    {
        $results = static::where($column, $value);
        return $results[0] ?? null;
    }
}
