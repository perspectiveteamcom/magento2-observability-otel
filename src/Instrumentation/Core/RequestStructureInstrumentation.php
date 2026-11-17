<?php
declare(strict_types=1);

namespace Mumzworld\OpenTelemetry\Instrumentation\Core;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Layout;
use Mumzworld\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use Throwable;
use WeakMap;
use function OpenTelemetry\Instrumentation\hook;

/** Trace the actual Magento action and the separately executed page rendering. */
class RequestStructureInstrumentation extends AbstractInstrumentation
{
    private static ?WeakMap $contexts = null;
    private static ?object $mainContext = null;

    protected static function getInstrumentationName(): string
    {
        return 'io.opentelemetry.contrib.php.magento.request-structure';
    }

    private static function frames(): WeakMap
    {
        $context = \Fiber::getCurrent() ?? (self::$mainContext ??= new \stdClass());
        $contexts = self::$contexts ??= new WeakMap();
        return $contexts[$context] ??= new WeakMap();
    }

    private static function enter(object $subject, string $method, string $class): bool
    {
        $frames = self::frames();
        $methods = $frames[$subject] ?? [];
        $stack = $methods[$method] ?? [];
        $previous = $stack ? $stack[count($stack) - 1]['class'] : null;
        $inherited = $previous !== null && $previous !== $class && is_subclass_of($previous, $class);
        $stack[] = ['class' => $class, 'started' => false];
        $methods[$method] = $stack;
        $frames[$subject] = $methods;
        return !$inherited;
    }

    private static function started(object $subject, string $method): void
    {
        $frames = self::frames();
        $methods = $frames[$subject];
        $stack = $methods[$method];
        $stack[count($stack) - 1]['started'] = true;
        $methods[$method] = $stack;
        $frames[$subject] = $methods;
    }

    private static function leave(object $subject, string $method, ?Throwable $exception): void
    {
        $frames = self::frames();
        $methods = $frames[$subject] ?? [];
        $stack = $methods[$method] ?? [];
        if (!$stack) { return; }
        $started = array_pop($stack)['started'];
        if ($stack) { $methods[$method] = $stack; } else { unset($methods[$method]); }
        if ($methods) { $frames[$subject] = $methods; } else { unset($frames[$subject]); }
        if ($started) { self::endSpan($exception); }
    }

    private static function registerHook(string $type, string $method, callable $name): void
    {
        hook($type, $method,
            static function (object $subject, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($method, $name): void {
                if (!self::enter($subject, $method, $class)) { return; }
                $builder = self::createSpanBuilder($name($subject, $params), $function, $class, $filename, $lineno);
                self::startSpanAndAttachToContext($builder);
                self::started($subject, $method);
            },
            static function (object $subject, array $params, mixed $result, ?Throwable $exception) use ($method): void {
                self::leave($subject, $method, $exception);
            }
        );
    }

    public static function register(): void
    {
        self::registerHook(ActionInterface::class, 'execute', static fn(object $subject): string =>
            'Controller: ' . str_replace('\\Interceptor', '', get_class($subject)));
        self::registerHook(ResultInterface::class, 'renderResult', static fn(object $subject): string =>
            'Result: ' . str_replace('\\Interceptor', '', get_class($subject)));
        foreach ([
            [\Magento\Framework\App\View::class, 'loadLayout', 'Layout load'],
            [Layout::class, 'generateXml', 'Layout XML'],
            [Layout::class, 'generateElements', 'Layout elements'],
            [\Magento\Framework\View\Layout\GeneratorPool::class, 'process', 'Layout generators'],
            [\Magento\Framework\View\Layout\GeneratorInterface::class, 'process', 'Layout generator'],
            [\Magento\Framework\View\Element\AbstractBlock::class, 'setLayout', 'Block prepare'],
            [\Magento\Catalog\Model\Layer\Filter\FilterInterface::class, 'apply', 'Layer filter'],
            [\Magento\Framework\Data\Collection\AbstractDb::class, 'loadWithFilter', 'Collection fetch'],
        ] as [$type, $method, $label]) {
            self::registerHook($type, $method, static fn(object $subject): string =>
                $label . ': ' . str_replace('\\Interceptor', '', get_class($subject)));
        }
        self::registerHook(Layout::class, 'renderElement', static function (object $subject, array $params): string {
            $name = $params[0] ?? '';
            return is_string($name) && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.:-]{0,127}$/D', $name)
                ? 'Layout: ' . $name : 'Layout: element';
        });
        self::registerHook(Template::class, 'fetchView', static function (object $subject): string {
            try { $name = $subject->getTemplate(); } catch (Throwable) { $name = ''; }
            return is_string($name) && !str_contains($name, '..')
                && preg_match('~^[A-Za-z0-9_][A-Za-z0-9_.:/-]{0,199}\.phtml$~D', $name)
                ? 'Template: ' . $name : 'Template: render';
        });
    }
}
