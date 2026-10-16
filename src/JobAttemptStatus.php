<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana;

/**
 * Итог одной попытки выполнения джоба. У каждой попытки ровно один статус.
 *
 * - COMPLETED — джоб снят с очереди как выполненный: не вернулся в неё и не провален.
 * - RELEASED — джоб вернулся в очередь без исключения: release() в коде или middleware.
 * - RETRIED — попытка упала с исключением, джоб вернулся в очередь на следующую попытку.
 * - FAILED — джоб помечен проваленным. Сюда же попадает попытка, на которой попытки
 *   кончились ещё до запуска (MaxAttemptsExceededException): её длительность не пишется.
 * - TIMED_OUT — воркер убит по таймауту. Даже если при этом джоб помечен проваленным,
 *   попытка считается только как TIMED_OUT.
 */
enum JobAttemptStatus: string
{
    case COMPLETED = 'completed';
    case RELEASED = 'released';
    case RETRIED = 'retried';
    case FAILED = 'failed';
    case TIMED_OUT = 'timed_out';
}
