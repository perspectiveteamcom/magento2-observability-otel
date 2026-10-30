<?php
declare(strict_types=1);
namespace Perspective\ObservabilityOtel;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;

/** Command timing only: never collect Redis keys, values, credentials or Lua source. */
final class RedisInstrumentation
{
    private static array $frames = [];
    private static bool $registered = false;
    private const COMMANDS = 'GET SET SETEX PSETEX SETNX MGET MSET MSETNX GETSET GETDEL GETEX DEL UNLINK EXISTS EXPIRE PEXPIRE EXPIREAT PEXPIREAT TTL PTTL PERSIST TYPE SCAN KEYS INCR INCRBY INCRBYFLOAT DECR DECRBY APPEND STRLEN HGET HSET HMGET HMSET HGETALL HDEL HEXISTS HLEN HKEYS HVALS HINCRBY HSCAN SADD SREM SMEMBERS SISMEMBER SCARD SSCAN SINTER SUNION SDIFF LPUSH RPUSH LPOP RPOP LLEN LRANGE LTRIM LSET BLPOP BRPOP ZADD ZREM ZRANGE ZREVRANGE ZRANGEBYSCORE ZCARD ZSCORE ZCOUNT ZINCRBY ZSCAN EVAL EVALSHA SCRIPT PING AUTH SELECT MULTI EXEC DISCARD WATCH UNWATCH PUBLISH SUBSCRIBE UNSUBSCRIBE INFO TIME FLUSHDB FLUSHALL DBSIZE SORT DUMP RESTORE RENAME RENAMENX SETBIT GETBIT BITCOUNT BITOP';

    public static function register(): void
    {
        if (self::$registered || getenv('OBSERVABILITY_TRACE_REDIS') === 'false') { return; }
        self::$registered = true;
        $otel = new CachedInstrumentation('perspective.redis', '2.1.0');
        $before = static function (string $command) use ($otel): void {
            // Reserve a frame even for skipped nested driver calls or telemetry failure.
            $nested = count(self::$frames) > 0;
            $frame = (object) ['scope'=>null, 'span'=>null];
            self::$frames[] = $frame;
            if ($nested || !Span::getCurrent()->getContext()->isValid()) { return; }
            $command = strtoupper($command);
            if (!in_array($command, explode(' ', self::COMMANDS), true)) { $command = 'COMMAND'; }
            $frame->span = $otel->tracer()->spanBuilder('Redis ' . $command)
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setAttribute('db.system.name', 'redis')->setAttribute('db.system', 'redis')
                ->setAttribute('db.operation.name', $command)->startSpan();
            $frame->scope = Context::storage()->attach($frame->span->storeInContext(Context::getCurrent()));
        };
        $after = static function ($object, array $params, $result, ?\Throwable $error): void {
            $frame = array_pop(self::$frames);
            if (!$frame) { return; }
            if ($frame->scope) { $frame->scope->detach(); }
            if (!$frame->span) { return; }
            if ($error) {
                $frame->span->setStatus(StatusCode::STATUS_ERROR);
                $frame->span->setAttribute('error.type', $error::class);
            }
            $frame->span->end();
        };
        Hook::register('Credis_Client', '__call',
            pre: static function ($object, array $params) use ($before): void {
                $name = (string) ($params[0] ?? 'COMMAND');
                if (strtolower($name) === 'rawcommand') { $name = (string) ($params[1][0] ?? 'COMMAND'); }
                $before($name);
            }, post: $after);
        Hook::register('Predis\Client', 'executeCommand',
            pre: static function ($object, array $params) use ($before): void {
                $before(isset($params[0]) && method_exists($params[0], 'getId') ? (string) $params[0]->getId() : 'COMMAND');
            }, post: $after);
        foreach (['Redis', 'RedisCluster'] as $class) {
            if (!class_exists($class, false)) { continue; }
            foreach (explode(' ', self::COMMANDS) as $command) {
                $method = strtolower($command);
                if (!method_exists($class, $method)) { continue; }
                Hook::register($class, $method,
                    pre: static function () use ($before, $command): void { $before($command); }, post: $after);
            }
            if (method_exists($class, 'rawCommand')) {
                Hook::register($class, 'rawCommand',
                    pre: static function ($object, array $params) use ($before, $class): void {
                        $before((string) ($params[$class === 'RedisCluster' ? 1 : 0] ?? 'COMMAND'));
                    }, post: $after);
            }
        }
    }
}
