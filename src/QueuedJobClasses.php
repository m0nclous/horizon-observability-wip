<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Классы приложения (app/), которые выполняются в очереди: джобы и слушатели с handle(), уведомления и письма
 * с ShouldQueue. Имя в метриках у них — имя класса, поэтому по списку видно и задачи, которые
 * ни разу не выполнялись.
 *
 * Пропускаются абстрактные классы, классы со своим displayName() (в метриках у них другое имя)
 * и ShouldQueue без handle(): например, событие с ShouldQueue само в очередь не ставится.
 *
 * Список меняется только с деплоем, поэтому собирается один раз за процесс (около 100 мс): в статическом
 * свойстве, а не в singleton — Octane на каждый запрос клонирует контейнер, и экземпляр терялся бы.
 */
class QueuedJobClasses
{
    /** @var list<string>|null */
    private static ?array $classes = null;

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return self::$classes ??= $this->discover();
    }

    /**
     * @return list<string>
     */
    private function discover(): array
    {
        $classes = [];
        $files = Finder::create()->files()->in(app_path())->name('*.php')->contains('ShouldQueue');

        foreach ($files as $file) {
            $class = app()->getNamespace() . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if ($this->isQueued($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    private function isQueued(string $class): bool
    {
        if (!class_exists($class) || !is_subclass_of($class, ShouldQueue::class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || $reflection->hasMethod('displayName')) {
            return false;
        }

        return $reflection->hasMethod('handle')
            || $reflection->isSubclassOf(Notification::class)
            || $reflection->isSubclassOf(Mailable::class);
    }
}
