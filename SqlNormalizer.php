<?php
declare(strict_types=1);
namespace Perspective\ObservabilityOtel;

/** Bounded SQL tokenizer. Never exports parameter values, comments or raw SQL. */
final class SqlNormalizer
{
    public static function normalize(string $sql): array
    {
        if (strlen($sql) > 65536) { return []; }
        $tokens = [];
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $c = $sql[$i];
            if (ctype_space($c)) { $i++; continue; }
            if (substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i + 2); if ($end === false) { return []; }
                $i = $end + 2; continue;
            }
            if ($c === '#' || (substr($sql, $i, 2) === '--' && ($i + 2 === $length || ctype_space($sql[$i + 2])))) {
                $end = strpos($sql, "\n", $i); $i = $end === false ? $length : $end + 1; continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c; $start = ++$i; $closed = false;
                while ($i < $length) {
                    if ($sql[$i] === '\\') { $i += 2; continue; }
                    if ($sql[$i] === $quote) {
                        if ($i + 1 < $length && $sql[$i + 1] === $quote) { $i += 2; continue; }
                        $closed = true; break;
                    }
                    $i++;
                }
                if (!$closed) { return []; }
                $identifier = substr($sql, $start, $i - $start); $i++;
                $tokens[] = $quote === '`' && preg_match('/^[a-zA-Z_][a-zA-Z0-9_$]*$/D', $identifier) ? '`' . $identifier . '`' : '?';
                continue;
            }
            if (preg_match('/\G(?:0x[0-9a-f]+|0b[01]+|(?:[0-9]+\.?[0-9]*|\.[0-9]+)(?:e[+-]?[0-9]+)?)/Ai', $sql, $m, 0, $i)) {
                $tokens[] = '?'; $i += strlen($m[0]); continue;
            }
            if (preg_match('/\G:[a-zA-Z_][a-zA-Z0-9_]*/A', $sql, $m, 0, $i)) {
                $tokens[] = '?'; $i += strlen($m[0]); continue;
            }
            if (preg_match('/\G[a-zA-Z_][a-zA-Z0-9_$]*/A', $sql, $m, 0, $i)) {
                $word = $m[0]; $upper = strtoupper($word);
                $tokens[] = in_array($upper, ['NULL','TRUE','FALSE'], true) ? '?' : $word;
                $i += strlen($word); continue;
            }
            if (str_contains('(),.=<>!+-*/%?|&^~;', $c)) { $tokens[] = $c; $i++; continue; }
            return []; // Unknown quoting/encoding: omit rather than leak an unparsed fragment.
        }
        $operation = strtoupper($tokens[0] ?? '');
        if (!in_array($operation, ['SELECT','INSERT','UPDATE','DELETE','REPLACE','WITH','SHOW','EXPLAIN','DESCRIBE'], true)) { return []; }
        $table = null;
        foreach ($tokens as $i => $token) {
            if (!in_array(strtoupper($token), ['FROM','JOIN','INTO','UPDATE'], true)) { continue; }
            $next = $tokens[$i+1] ?? '';
            if (preg_match('/^`?[a-zA-Z_][a-zA-Z0-9_$]*`?$/D', $next)) {
                $table = trim($next, '`');
                if (($tokens[$i+2] ?? '') === '.' && preg_match('/^`?[a-zA-Z_][a-zA-Z0-9_$]*`?$/D', $tokens[$i+3] ?? '')) {
                    $table .= '.' . trim($tokens[$i+3], '`');
                }
                break;
            }
        }
        $normalized = implode(' ', $tokens);
        // Reduce cardinality for IN lists and multi-value inserts with varying sizes.
        $normalized = preg_replace('/\?(?:\s*,\s*\?)+/', '?', $normalized);
        return array_filter(['db.operation.name'=>$operation, 'db.collection.name'=>$table,
            'db.query.summary'=>$operation . ($table ? ' ' . $table : ''),
            'db.query.normalized'=>substr($normalized, 0, 4096)], static fn($v) => $v !== null);
    }
}
