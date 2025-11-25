<?php

namespace TRAW\LuxletterReceiverImport\Events;

class BulkInsertPrepareEvent
{
    public function __construct(
        protected string $table,
        protected array  $columns,
        protected array  $values,
        protected array  $types
    )
    {
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getValues(): array
    {
        return $this->values;
    }

    public function getTypes(): array
    {
        return $this->types;
    }

    public function setColumns(array $columns): void
    {
        $this->columns = $columns;
    }

    public function setValues(array $values): void
    {
        $this->values = $values;
    }

    public function setTypes(array $types): void
    {
        $this->types = $types;
    }
}
