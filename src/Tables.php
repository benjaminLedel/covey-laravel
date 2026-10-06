<?php

namespace Covey\Laravel;

use Illuminate\Database\ConnectionInterface;

// Table names as two parties spell them. The configuration and Laravel's
// schema builder use the name without the connection's prefix ("sessions");
// the database, Schema::getTables() and a raw statement use it with the
// prefix ("app_sessions"). Every check on a hidden table goes through here,
// so that it holds for both spellings.
class Tables
{
    /** @param string[] $hidden */
    public function __construct(
        private readonly string $prefix,
        private readonly array $hidden,
    ) {}

    public static function for(ConnectionInterface $connection): self
    {
        $prefix = method_exists($connection, 'getTablePrefix') ? (string) $connection->getTablePrefix() : '';

        return new self(strtolower($prefix), array_map('strtolower', (array) config('covey.hidden.tables', [])));
    }

    // The name without the prefix, as the schema builder wants it. A name
    // that does not carry the prefix is taken to be without it already.
    public function unprefixed(string $name): string
    {
        if ($this->prefix !== '' && str_starts_with(strtolower($name), $this->prefix)) {
            return substr($name, strlen($this->prefix));
        }

        return $name;
    }

    // The name as the database knows it.
    public function prefixed(string $name): string
    {
        return $this->prefix === '' ? $name : $this->prefix.$this->unprefixed($name);
    }

    public function isHidden(string $name): bool
    {
        $name = strtolower($name);

        return in_array($name, $this->hidden, true) || in_array(strtolower($this->unprefixed($name)), $this->hidden, true);
    }

    // hiddenIn returns the first hidden table a statement names, or null.
    // Strict rather than clever, like ReadOnlySql: every word of the statement
    // counts — quoted identifiers, schema-qualified names and string literals
    // included, since a string can name a table too (table_to_xml('sessions',
    // …)). Only comments are left out. A column that happens to share a hidden
    // table's name is refused as well; that costs a rephrase, not data.
    public function hiddenIn(string $sql): ?string
    {
        if ($this->hidden === []) {
            return null;
        }
        $words = preg_split('/[^a-z0-9_$]+/', strtolower(ReadOnlySql::withoutComments($sql)), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $w) {
            if ($this->isHidden($w)) {
                return $w;
            }
        }

        return null;
    }
}
