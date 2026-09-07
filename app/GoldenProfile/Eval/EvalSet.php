<?php

namespace App\GoldenProfile\Eval;

use InvalidArgumentException;

/**
 * A labeled evaluation set: source records plus the ground-truth clusters they
 * belong to. Loading validates the file, because a malformed answer key scores a
 * matcher against nonsense and reports it as a number.
 */
class EvalSet
{
    /** @param list<array<string,mixed>> $records @param list<list<string>> $truth */
    private function __construct(private array $records, private array $truth, private string $path) {}

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("eval set not found: $path");
        }

        $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);

        return self::loadArray($data, $path);
    }

    /** Same validation, from an already-decoded array. Keeps the rules testable. */
    public static function loadArray(array $data, string $path): self
    {
        foreach (['records', 'truth'] as $key) {
            if (! isset($data[$key]) || ! is_array($data[$key])) {
                throw new InvalidArgumentException("eval set $path is missing '$key'");
            }
        }

        $refs = [];
        foreach ($data['records'] as $i => $r) {
            if (! isset($r['ref']) || ! is_string($r['ref'])) {
                throw new InvalidArgumentException("record #$i has no string 'ref'");
            }
            if (isset($refs[$r['ref']])) {
                throw new InvalidArgumentException("duplicate ref '{$r['ref']}'");
            }
            $refs[$r['ref']] = true;
        }

        $seen = [];
        foreach ($data['truth'] as $i => $cluster) {
            if (! is_array($cluster) || $cluster === []) {
                throw new InvalidArgumentException("truth cluster #$i is empty");
            }
            foreach ($cluster as $ref) {
                if (! isset($refs[$ref])) {
                    throw new InvalidArgumentException("truth references unknown record '$ref'");
                }
                if (isset($seen[$ref])) {
                    throw new InvalidArgumentException("record '$ref' appears in more than one cluster");
                }
                $seen[$ref] = true;
            }
        }

        $missing = array_diff(array_keys($refs), array_keys($seen));
        if ($missing !== []) {
            throw new InvalidArgumentException('records missing from truth: '.implode(', ', $missing));
        }

        return new self(array_values($data['records']), array_values($data['truth']), $path);
    }

    /** @return list<array<string,mixed>> */
    public function records(): array
    {
        return $this->records;
    }

    /** @return list<array{license_number:string,certification_state:?string}> */
    public function licenses(string $ref): array
    {
        foreach ($this->records as $r) {
            if ($r['ref'] === $ref) {
                return $r['licenses'] ?? [];
            }
        }

        return [];
    }

    /**
     * Multi-valued identifiers (DEA, MMIS) for one record. Mirrors licenses(),
     * including its one wart: an unknown ref and a known ref carrying no
     * identifiers are indistinguishable, both returning [].
     *
     * @return list<array{id_type:string,id_value:string,state:?string}>
     */
    public function identifiers(string $ref): array
    {
        foreach ($this->records as $r) {
            if ($r['ref'] === $ref) {
                return $r['identifiers'] ?? [];
            }
        }

        return [];
    }

    /** @return list<list<string>> */
    public function truthClusters(): array
    {
        return $this->truth;
    }

    public function path(): string
    {
        return $this->path;
    }
}
