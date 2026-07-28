<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc;

/**
 * Converts between the transcoded native array the Rust plugin exchanges and the
 * generated readonly DTOs (phase 87). No protoc, no protobuf runtime — the
 * mapping is driven entirely by the `FOLK_FIELDS` constant {@see ProtoGenerator}
 * emits on every message DTO.
 *
 * `FOLK_FIELDS` maps each field name to a compact spec:
 *  - `['s']`            scalar (int/float/bool/string) — pass through
 *  - `['b']`            bytes — base64 on the wire, raw string in the DTO
 *  - `['e', Enum::class]` int-backed enum — `tryFrom` with a zero-value fallback
 *  - `['m', Dto::class]`  nested message — recurse
 *  - `['d']`            dynamic well-known (Struct/Value/ListValue/Any/Empty) — as-is
 *  - `['r', <spec>]`    repeated — element spec is one of the singular forms
 *  - `['map', <spec>]`  map — value spec (keys pass through)
 *
 * The wire contract mirrors prost-reflect's canonical JSON (snake_case field
 * names, enums as ints, bytes as base64), locked by the Rust round-trip tests.
 */
final class Hydrator
{
    /**
     * Build a DTO of `$class` from the transcoded request array. Absent keys keep
     * their constructor default (unset proto3 singular → zero, message/oneof →
     * null), so presence semantics survive the round-trip.
     *
     * @template T of object
     * @param class-string<T>     $class
     * @param array<string, mixed> $data
     * @return T
     */
    public function hydrate(string $class, array $data): object
    {
        $fields = self::fields($class);

        /** @var array<string, mixed> $args */
        $args = [];
        foreach ($fields as $name => $spec) {
            if (!array_key_exists($name, $data)) {
                continue;
            }
            $args[$name] = $this->toPhp($data[$name], $spec);
        }

        /** @psalm-suppress MixedMethodCall */
        return new $class(...$args);
    }

    /**
     * Flatten a DTO back into the transcoded response array. Null fields are
     * omitted (unset message / inactive oneof branch / absent optional), so the
     * Rust encoder sees exactly the set fields.
     *
     * @return array<string, mixed>
     */
    public function dehydrate(object $dto): array
    {
        $fields = self::fields($dto::class);

        $out = [];
        foreach ($fields as $name => $spec) {
            /** @var mixed $value */
            $value = $dto->{$name} ?? null;
            if ($value === null) {
                continue;
            }
            $out[$name] = $this->toWire($value, $spec);
        }

        return $out;
    }

    /**
     * @param list<mixed> $spec
     */
    private function toPhp(mixed $value, array $spec): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($spec[0]) {
            'b' => is_string($value) ? (base64_decode($value, true) ?: '') : '',
            'e' => $this->enumToPhp($value, self::classSpec($spec)),
            'm' => is_array($value) ? $this->hydrate(self::classSpec($spec), self::stringKeyed($value)) : null,
            'r' => is_array($value)
                ? array_values(array_map(fn (mixed $v): mixed => $this->toPhp($v, self::innerSpec($spec)), $value))
                : [],
            'map' => is_array($value)
                ? array_map(fn (mixed $v): mixed => $this->toPhp($v, self::innerSpec($spec)), $value)
                : [],
            default => $value, // 's' and 'd'
        };
    }

    /**
     * @param list<mixed> $spec
     */
    private function toWire(mixed $value, array $spec): mixed
    {
        return match ($spec[0]) {
            'b' => base64_encode(is_string($value) ? $value : ''),
            'e' => $value instanceof \BackedEnum ? $value->value : (is_int($value) ? $value : 0),
            'm' => is_object($value) ? $this->dehydrate($value) : $value,
            'r' => is_array($value)
                ? array_values(array_map(fn (mixed $v): mixed => $this->toWire($v, self::innerSpec($spec)), $value))
                : [],
            'map' => is_array($value)
                ? array_map(fn (mixed $v): mixed => $this->toWire($v, self::innerSpec($spec)), $value)
                : [],
            default => $value, // 's' and 'd'
        };
    }

    /**
     * Resolve an int (or already-cast enum) to its enum case, falling back to the
     * zero-value case when the wire carries an unknown number — a forward-compat
     * default, never an exception (criteria: unknown enum → zero, not throw).
     *
     * @param class-string $enumClass
     */
    private function enumToPhp(mixed $value, string $enumClass): \BackedEnum
    {
        if (!is_a($enumClass, \BackedEnum::class, true)) {
            throw new \RuntimeException("{$enumClass} is not a BackedEnum");
        }
        if ($value instanceof $enumClass) {
            return $value;
        }
        $int = is_int($value) ? $value : 0;
        $case = $enumClass::tryFrom($int) ?? $enumClass::tryFrom(0);
        if ($case !== null) {
            return $case;
        }
        $cases = $enumClass::cases();
        if ($cases === []) {
            throw new \RuntimeException("enum {$enumClass} has no cases");
        }
        return $cases[0];
    }

    /**
     * Read and validate the `FOLK_FIELDS` map off a generated DTO class. A class
     * without it (a hand-written or non-generated type) hydrates to no fields.
     *
     * @param class-string $class
     * @return array<string, list<mixed>>
     */
    private static function fields(string $class): array
    {
        if (!defined("{$class}::FOLK_FIELDS")) {
            return [];
        }
        /** @var mixed $raw */
        $raw = constant("{$class}::FOLK_FIELDS");
        if (!is_array($raw)) {
            return [];
        }
        /** @var array<string, list<mixed>> $out */
        $out = [];
        foreach ($raw as $name => $spec) {
            if (is_string($name) && is_array($spec) && array_is_list($spec) && $spec !== []) {
                $out[$name] = $spec;
            }
        }
        return $out;
    }

    /**
     * The nested element/value spec of a `r`/`map` field.
     *
     * @param list<mixed> $spec
     * @return list<mixed>
     */
    private static function innerSpec(array $spec): array
    {
        $inner = $spec[1] ?? null;
        return is_array($inner) && array_is_list($inner) && $inner !== [] ? $inner : ['d'];
    }

    /**
     * The `class-string` payload of an `e`/`m` spec.
     *
     * @param list<mixed> $spec
     * @return class-string
     */
    private static function classSpec(array $spec): string
    {
        $class = $spec[1] ?? null;
        if (!is_string($class) || !class_exists($class) && !enum_exists($class)) {
            throw new \RuntimeException('malformed FOLK_FIELDS class spec');
        }
        /** @var class-string $class */
        return $class;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $value): array
    {
        /** @var array<string, mixed> $out */
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }
}
